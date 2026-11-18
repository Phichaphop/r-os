<?php

declare(strict_types=1);

namespace Blue\Core\Records;

use DateTimeImmutable;
use Blue\Core\Audit\AuditLogger;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;
use Blue\Core\Workflow\WorkflowEngine;
use Blue\Core\Workflow\WorkflowSubject;

/**
 * Retention Schedule Register (04 §6.17.7): versioned rules per record class, never hard-coded.
 * A rule only takes effect after a second person approves it through the central workflow, and only with a
 * source reference (who confirmed it — Legal / accountant). AI drafts (DEC-48) are seeded as drafts and do nothing
 * until someone adopts, confirms and submits them.
 */
final class RetentionRules implements WorkflowSubject
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly WorkflowEngine $workflow,
        private readonly DocumentService $documents,
    ) {
    }

    /** Seeds the AI draft schedule once (only for record classes that have no rule at all). */
    public static function seedDrafts(Connection $db, Clock $clock): int
    {
        $n = 0;
        foreach (DocumentCatalog::AI_DRAFT_RULES as $class => [$trigger, $months, $action, $note]) {
            if ($db->scalar('SELECT id FROM retention_rules WHERE record_class = ? LIMIT 1', [$class]) !== null) {
                continue;
            }
            $now = $clock->now()->format('Y-m-d H:i:s.u');
            $db->run(
                "INSERT INTO retention_rules (public_id, created_at, updated_at, record_class, version_no, trigger_event, duration_months, disposition_action,
                    legal_basis_note, status, effective_from, is_ai_draft, reason) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, 'draft', ?, 1, 'AI draft — DEC-48')",
                [Ulid::generate($clock), $now, $now, $class, $trigger, $months, $action, $note, $clock->now()->format('Y-m-d')]
            );
            $n++;
        }
        return $n;
    }

    /** @return list<array<string, mixed>> one row per record class: the rule in force + the newest pending version */
    public function register(): array
    {
        $out = [];
        foreach (DocumentCatalog::RECORD_CLASSES as $class) {
            $out[] = [
                'record_class' => $class,
                'current' => $this->current($class),
                'pending' => $this->db->one("SELECT * FROM retention_rules WHERE record_class = ? AND status IN ('draft','in_review','returned') ORDER BY version_no DESC LIMIT 1", [$class]),
                'documents' => (int) $this->db->scalar("SELECT COUNT(*) FROM documents d JOIN document_classes c ON c.code = d.document_class WHERE c.record_class = ? AND d.lifecycle_status <> 'disposed'", [$class]),
            ];
        }
        return $out;
    }

    /** @return array<string, mixed>|null the approved rule in force today */
    public function current(string $recordClass): ?array
    {
        return $this->db->one(
            "SELECT * FROM retention_rules WHERE record_class = ? AND status = 'approved' AND effective_from <= ? ORDER BY version_no DESC LIMIT 1",
            [$recordClass, $this->clock->now()->format('Y-m-d')]
        );
    }

    /** @return list<array<string, mixed>> */
    public function history(string $recordClass): array
    {
        return $this->db->all(
            'SELECT r.*, p.display_name AS proposed_name, d.display_name AS decided_name FROM retention_rules r
               LEFT JOIN users p ON p.id = r.proposed_by LEFT JOIN users d ON d.id = r.decided_by WHERE r.record_class = ? ORDER BY r.version_no DESC',
            [$recordClass]
        );
    }

    /** @return array<string, mixed> */
    public function get(string $pid): array
    {
        return $this->db->one('SELECT * FROM retention_rules WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /**
     * Creates (or replaces) the draft for a record class.
     *
     * @param array{trigger: string, months: string, action: string, note: string, source: string, effective_from: string, reason: string} $in
     */
    public function propose(Authorizer $az, string $recordClass, array $in): string
    {
        $this->require($az, 'core.records.manage');
        if (!in_array($recordClass, DocumentCatalog::RECORD_CLASSES, true)) {
            throw HttpException::notFound();
        }
        $errors = [];
        if (!in_array($in['trigger'], DocumentCatalog::TRIGGERS, true)) {
            $errors['trigger'] = 'records.error.trigger';
        }
        if (!preg_match('/^\d{1,4}$/', $in['months']) || (int) $in['months'] < 1 || (int) $in['months'] > 1200) {
            $errors['months'] = 'records.error.months';
        }
        if (!in_array($in['action'], ['review', 'destroy', 'anonymize', 'archive'], true)) {
            $errors['action'] = 'records.error.action';
        }
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $in['effective_from']);
        if ($from === false) {
            $errors['effective_from'] = 'records.error.date';
        }
        if (trim($in['reason']) === '') {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $this->db->transactional(function () use ($az, $recordClass, $in): string {
            $open = $this->db->one("SELECT id, status FROM retention_rules WHERE record_class = ? AND status IN ('in_review') FOR UPDATE", [$recordClass]);
            if ($open !== null) {
                throw new BusinessException('records.error.pending', [], 409);
            }
            $this->db->run("UPDATE retention_rules SET status = 'cancelled', updated_at = ? WHERE record_class = ? AND status IN ('draft','returned')", [$this->now(), $recordClass]);
            $no = (int) $this->db->scalar('SELECT COALESCE(MAX(version_no), 0) + 1 FROM retention_rules WHERE record_class = ?', [$recordClass]);
            $pid = Ulid::generate($this->clock);
            $now = $this->now();
            $this->db->run(
                "INSERT INTO retention_rules (public_id, created_at, created_by, updated_at, updated_by, record_class, version_no, trigger_event, duration_months,
                    disposition_action, legal_basis_note, source_reference, status, effective_from, is_ai_draft, reason, proposed_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, 0, ?, ?)",
                [$pid, $now, $az->userId, $now, $az->userId, $recordClass, $no, $in['trigger'], (int) $in['months'], $in['action'],
                    mb_substr(trim($in['note']), 0, 1000) ?: null, mb_substr(trim($in['source']), 0, 255) ?: null, $in['effective_from'], mb_substr($in['reason'], 0, 500), $az->userId]
            );
            $this->audit->record('records.rule_proposed', 'success', 'retention_rule', $pid, null, $in['reason'], ['class' => $recordClass, 'months' => (int) $in['months'], 'trigger' => $in['trigger']]);
            return $pid;
        });
    }

    /** Sends a draft for approval. A source reference (who confirmed the period) is required here. */
    public function submit(Authorizer $az, string $pid): string
    {
        $this->require($az, 'core.records.manage');
        $r = $this->get($pid);
        if ($r['status'] !== 'draft') {
            throw new BusinessException('records.error.not_draft', [], 409);
        }
        if ((int) $r['is_ai_draft'] === 1 || (int) $r['proposed_by'] !== $az->userId) {
            throw new BusinessException('records.error.adopt_first', [], 409); // an AI draft must be adopted (re-proposed) by a person first
        }
        if (trim((string) $r['source_reference']) === '') {
            throw new ValidationException(['source' => 'records.error.source_required']);
        }
        $this->db->run("UPDATE retention_rules SET status = 'in_review', updated_at = ? WHERE id = ?", [$this->now(), $r['id']]);
        $inst = $this->workflow->start('core.retention_rule', $pid, $r['record_class'] . ' → ' . $r['duration_months'] . ' m', null, $az->userId);
        $this->db->run('UPDATE retention_rules SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE id = ?', [$inst, $r['id']]);
        $this->audit->record('records.rule_submitted', 'success', 'retention_rule', $pid);
        return $inst;
    }

    // ---- WorkflowSubject ---------------------------------------------------------------------------

    public function type(): string
    {
        return 'retention_rule';
    }

    public function link(string $subjectId): string
    {
        return '/admin/records/rule/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        $r = $this->get((string) $instance['subject_id']);
        if ($r['status'] === 'approved') {
            return;
        }
        if ((int) $r['proposed_by'] === $approverId) {
            throw new BusinessException('workflow.error.self_approval', [], 403);
        }
        $now = $this->now();
        $this->db->run("UPDATE retention_rules SET status = 'superseded', updated_at = ? WHERE record_class = ? AND status = 'approved'", [$now, $r['record_class']]);
        $this->db->run("UPDATE retention_rules SET status = 'approved', decided_by = ?, decided_at = ?, updated_at = ? WHERE id = ?", [$approverId, $now, $now, $r['id']]);
        $n = $this->documents->recomputeSchedules((string) $r['record_class']);
        $this->audit->record('records.rule_approved', 'success', 'retention_rule', (string) $r['public_id'], null, null, ['class' => $r['record_class'], 'documents_rescheduled' => $n], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        $status = ['rejected' => 'rejected', 'returned' => 'returned', 'cancelled' => 'cancelled'][$outcome] ?? null;
        if ($status !== null) {
            $this->db->run("UPDATE retention_rules SET status = ?, decided_at = ?, updated_at = ? WHERE public_id = ? AND status IN ('in_review','returned')", [$status, $this->now(), $this->now(), $instance['subject_id']]);
        }
    }

    public function onResubmitted(array $instance): void
    {
        $this->db->run("UPDATE retention_rules SET status = 'in_review', updated_at = ? WHERE public_id = ? AND status = 'returned'", [$this->now(), $instance['subject_id']]);
    }

    private function require(Authorizer $az, string $perm): void
    {
        if (!$az->scope($perm)->all) {
            throw HttpException::forbidden();
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
