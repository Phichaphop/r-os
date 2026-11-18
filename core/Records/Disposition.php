<?php

declare(strict_types=1);

namespace Blue\Core\Records;

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
 * Disposition (04 §6.17.7): nothing is ever deleted automatically.
 * Due list (approved rule + trigger date reached) → a records manager requests disposal of selected documents →
 * a second person approves (maker ≠ checker, password confirmation) → execution re-checks legal holds and that each
 * document is still due, deletes the files of every version, keeps a minimal stub, and writes a Certificate of
 * Disposition (class, counts, rule, approval, date, method, actor, outcome) without personal data.
 */
final class Disposition implements WorkflowSubject
{
    public const METHOD = 'Deleted every stored version from private storage (unlink); no OCR/export derivatives are kept by Blue OS; backups expire on their own cycle (DEC-39) and a restore must replay this certificate.';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly WorkflowEngine $workflow,
        private readonly DocumentService $documents,
    ) {
    }

    /** @return list<array<string, mixed>> documents due for disposal that the user may handle (holds shown, not hidden) */
    public function due(Authorizer $az): array
    {
        $scope = $az->scope('core.records.manage');
        if ($scope->isEmpty()) {
            return [];
        }
        [$sql, $params] = $scope->sql('d.legal_entity_id', 'd.site_id');
        $rows = $this->db->all(
            "SELECT d.*, c.record_class, r.duration_months, r.version_no AS rule_version, r.disposition_action, l.code AS le_code
               FROM documents d JOIN document_classes c ON c.code = d.document_class
               LEFT JOIN retention_rules r ON r.id = d.retention_rule_id LEFT JOIN legal_entities l ON l.id = d.legal_entity_id
              WHERE {$sql} AND d.scheduled_disposition_date IS NOT NULL AND d.scheduled_disposition_date <= ? AND d.disposition_status = 'none'
                AND d.lifecycle_status IN ('final','superseded','archived') ORDER BY d.scheduled_disposition_date, d.id LIMIT 300",
            [...$params, $this->clock->now()->format('Y-m-d')]
        );
        foreach ($rows as &$r) {
            $r['holds'] = count($this->documents->activeHolds($r));
        }
        return $rows;
    }

    /** @param list<string> $documentPids */
    public function request(Authorizer $az, array $documentPids, string $reason): string
    {
        $scope = $az->scope('core.records.manage');
        if ($scope->isEmpty()) {
            throw HttpException::forbidden();
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $due = [];
        foreach ($this->due($az) as $d) {
            $due[(string) $d['public_id']] = $d;
        }
        $picked = [];
        foreach (array_unique($documentPids) as $pid) {
            $d = $due[$pid] ?? null;
            if ($d === null) {
                throw new BusinessException('records.error.not_due', [], 409);
            }
            if ((int) $d['holds'] > 0) {
                throw new BusinessException('records.error.on_hold', [], 409);
            }
            $picked[] = $d;
        }
        if ($picked === []) {
            throw new ValidationException(['documents' => 'records.error.none_selected']);
        }
        $les = array_unique(array_map(static fn (array $d): ?int => $d['legal_entity_id'] !== null ? (int) $d['legal_entity_id'] : null, $picked));
        $le = count($les) === 1 ? reset($les) : null;
        $pid = $this->db->transactional(function () use ($az, $picked, $reason, $le): string {
            $pid = Ulid::generate($this->clock);
            $now = $this->now();
            $this->db->run(
                "INSERT INTO disposition_batches (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, status, reason, item_count, requested_by)
                 VALUES (?, ?, ?, ?, ?, ?, 'in_review', ?, ?, ?)",
                [$pid, $now, $az->userId, $now, $az->userId, $le, mb_substr($reason, 0, 500), count($picked), $az->userId]
            );
            $batchId = (int) $this->db->scalar('SELECT id FROM disposition_batches WHERE public_id = ?', [$pid]);
            foreach ($picked as $d) {
                $n = $this->db->run("UPDATE documents SET disposition_status = 'requested' WHERE id = ? AND disposition_status = 'none'", [$d['id']])->rowCount();
                if ($n !== 1) {
                    throw new BusinessException('records.error.not_due', [], 409); // someone else requested it meanwhile
                }
                $this->db->run('INSERT INTO disposition_items (batch_id, document_id, record_class, retention_rule_id) VALUES (?, ?, ?, ?)', [$batchId, $d['id'], $d['record_class'], $d['retention_rule_id']]);
            }
            return $pid;
        });
        $inst = $this->workflow->start('core.disposition', $pid, 'Disposition × ' . count($picked), $le, $az->userId);
        $this->db->run('UPDATE disposition_batches SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE public_id = ?', [$inst, $pid]);
        $this->audit->record('records.disposition_requested', 'success', 'disposition', $pid, $le, $reason, ['documents' => count($picked)]);
        return $pid;
    }

    /** @return array{batch: array<string, mixed>, items: list<array<string, mixed>>} */
    public function get(Authorizer $az, string $pid): array
    {
        $b = $this->db->one('SELECT b.*, u.display_name AS requested_name FROM disposition_batches b LEFT JOIN users u ON u.id = b.requested_by WHERE b.public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $scope = $az->scope('core.records.manage');
        if ($scope->isEmpty()) {
            $scope = $az->scope('core.records.approve');
        }
        if (!$scope->all && ($b['legal_entity_id'] === null || !in_array((int) $b['legal_entity_id'], $scope->legalEntityIds, true))) {
            throw HttpException::notFound();
        }
        $items = $this->db->all(
            'SELECT i.*, d.public_id AS document_public_id, d.title, d.document_number, d.document_class, d.scheduled_disposition_date, d.lifecycle_status
               FROM disposition_items i JOIN documents d ON d.id = i.document_id WHERE i.batch_id = ? ORDER BY i.id',
            [$b['id']]
        );
        return ['batch' => $b, 'items' => $items];
    }

    /** @return list<array<string, mixed>> */
    public function batches(Authorizer $az): array
    {
        $scope = $az->scope('core.records.manage');
        if ($scope->isEmpty()) {
            $scope = $az->scope('core.records.approve');
        }
        if ($scope->isEmpty()) {
            return [];
        }
        [$sql, $params] = $scope->all ? ['1=1', []] : $scope->sql('b.legal_entity_id');
        return $this->db->all("SELECT b.*, u.display_name AS requested_name FROM disposition_batches b LEFT JOIN users u ON u.id = b.requested_by WHERE {$sql} ORDER BY b.id DESC LIMIT 100", $params);
    }

    // ---- WorkflowSubject ---------------------------------------------------------------------------

    public function type(): string
    {
        return 'disposition';
    }

    public function link(string $subjectId): string
    {
        return '/admin/records/disposition/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        $b = $this->db->one('SELECT * FROM disposition_batches WHERE public_id = ?', [$instance['subject_id']]) ?? throw HttpException::notFound();
        if (in_array($b['status'], ['executed', 'partly_executed'], true)) {
            return; // idempotent retry
        }
        if ((int) $b['requested_by'] === $approverId) {
            throw new BusinessException('workflow.error.self_approval', [], 403);
        }
        $today = $this->clock->now()->format('Y-m-d');
        $done = 0;
        $skipped = 0;
        $classes = [];
        $rules = [];
        foreach ($this->db->all('SELECT i.id, i.result, i.record_class, i.retention_rule_id, d.* FROM disposition_items i JOIN documents d ON d.id = i.document_id WHERE i.batch_id = ?', [$b['id']]) as $row) {
            if ($row['result'] !== 'pending') {
                $done += $row['result'] === 'disposed' ? 1 : 0;
                continue;
            }
            $itemId = (int) $this->db->scalar('SELECT id FROM disposition_items WHERE batch_id = ? AND document_id = ?', [$b['id'], $row['id']]);
            $doc = $this->db->one('SELECT * FROM documents WHERE id = ?', [$row['id']]) ?? [];
            if ($this->documents->activeHolds($doc) !== []) {
                $this->db->run("UPDATE disposition_items SET result = 'skipped_hold' WHERE id = ?", [$itemId]);
                $this->db->run("UPDATE documents SET disposition_status = 'none' WHERE id = ?", [$row['id']]);
                $skipped++;
                continue;
            }
            if ($doc['scheduled_disposition_date'] === null || (string) $doc['scheduled_disposition_date'] > $today) {
                $this->db->run("UPDATE disposition_items SET result = 'skipped_changed' WHERE id = ?", [$itemId]);
                $this->db->run("UPDATE documents SET disposition_status = 'none' WHERE id = ?", [$row['id']]);
                $skipped++;
                continue;
            }
            $this->documents->purge((int) $row['id'], $approverId);
            $this->db->run("UPDATE disposition_items SET result = 'disposed' WHERE id = ?", [$itemId]);
            $classes[(string) $row['record_class']] = ($classes[(string) $row['record_class']] ?? 0) + 1;
            if ($row['retention_rule_id'] !== null) {
                $rules[] = (string) $this->db->scalar('SELECT CONCAT(record_class, " v", version_no, " (", duration_months, " m)") FROM retention_rules WHERE id = ?', [$row['retention_rule_id']]);
            }
            $done++;
        }
        $cert = [
            'certificate' => 'Certificate of Disposition', 'batch' => $b['public_id'], 'executed_at_utc' => $this->now(),
            'requested_by' => (int) $b['requested_by'], 'approved_by' => $approverId, 'workflow' => $instance['public_id'],
            'record_classes' => $classes, 'rules' => array_values(array_unique($rules)), 'disposed' => $done, 'skipped' => $skipped,
            'method' => self::METHOD, 'reason' => $b['reason'],
        ];
        $this->db->run(
            'UPDATE disposition_batches SET status = ?, executed_at = ?, certificate_json = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?',
            [$skipped > 0 ? 'partly_executed' : 'executed', $this->now(), json_encode($cert, JSON_UNESCAPED_UNICODE), $this->now(), $b['id']]
        );
        $this->audit->record('records.disposed', 'success', 'disposition', (string) $b['public_id'], $b['legal_entity_id'] !== null ? (int) $b['legal_entity_id'] : null, null, ['disposed' => $done, 'skipped' => $skipped], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        $b = $this->db->one('SELECT id FROM disposition_batches WHERE public_id = ?', [$instance['subject_id']]);
        if ($b === null) {
            return;
        }
        $status = ['rejected' => 'rejected', 'returned' => 'returned', 'cancelled' => 'cancelled'][$outcome] ?? 'cancelled';
        $this->db->run('UPDATE disposition_batches SET status = ?, updated_at = ? WHERE id = ?', [$status, $this->now(), $b['id']]);
        if ($status !== 'returned') {
            $this->db->run("UPDATE documents d JOIN disposition_items i ON i.document_id = d.id SET d.disposition_status = 'none' WHERE i.batch_id = ? AND d.disposition_status = 'requested'", [$b['id']]);
        }
    }

    public function onResubmitted(array $instance): void
    {
        $this->db->run("UPDATE disposition_batches SET status = 'in_review', updated_at = ? WHERE public_id = ? AND status = 'returned'", [$this->now(), $instance['subject_id']]);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
