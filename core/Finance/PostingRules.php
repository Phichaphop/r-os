<?php

declare(strict_types=1);

namespace Blue\Core\Finance;

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
 * Posting rules (P0-01 §3.3, P0-03 §8): which accounts a source line type is debited/credited to, per company,
 * effective-dated and versioned. A rule takes effect only after the finance checker approves it — with a source
 * reference (the accountant who confirmed the mapping). No rule = the posting request waits ("fail safely", 6.25.2).
 */
final class PostingRules implements WorkflowSubject
{
    /** @var (\Closure(int, string, string): int)|null called after approval to retry requests that were waiting for this rule */
    private ?\Closure $onApprovedHook = null;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly WorkflowEngine $workflow,
        private readonly Accounts $accounts,
    ) {
    }

    /** @param \Closure(int, string, string): int $hook */
    public function afterApproval(\Closure $hook): void
    {
        $this->onApprovedHook = $hook;
    }

    /**
     * Rule in force for a line type on a date (latest effective_from, then latest version).
     *
     * @return array<string, mixed>|null
     */
    public function resolve(int $legalEntityId, string $sourceModule, string $lineType, string $date): ?array
    {
        return $this->db->one(
            "SELECT r.*, d.code AS debit_code, d.status AS debit_status, d.is_postable AS debit_postable, c.code AS credit_code, c.status AS credit_status, c.is_postable AS credit_postable
               FROM fin_posting_rules r JOIN fin_accounts d ON d.id = r.debit_account_id JOIN fin_accounts c ON c.id = r.credit_account_id
              WHERE r.legal_entity_id = ? AND r.source_module = ? AND r.line_type = ? AND r.status = 'approved' AND r.effective_from <= ?
              ORDER BY r.effective_from DESC, r.version_no DESC LIMIT 1",
            [$legalEntityId, $sourceModule, $lineType, $date]
        );
    }

    /** @return list<array<string, mixed>> every version for the company, newest first */
    public function list(int $legalEntityId): array
    {
        return $this->db->all(
            "SELECT r.*, d.code AS debit_code, d.name AS debit_name, c.code AS credit_code, c.name AS credit_name, p.display_name AS proposed_name, x.display_name AS decided_name
               FROM fin_posting_rules r JOIN fin_accounts d ON d.id = r.debit_account_id JOIN fin_accounts c ON c.id = r.credit_account_id
               LEFT JOIN users p ON p.id = r.proposed_by LEFT JOIN users x ON x.id = r.decided_by
              WHERE r.legal_entity_id = ? AND r.status <> 'cancelled' ORDER BY r.source_module, r.line_type, r.version_no DESC",
            [$legalEntityId]
        );
    }

    /** @return array<string, mixed> */
    public function get(string $pid): array
    {
        return $this->db->one('SELECT * FROM fin_posting_rules WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /**
     * @param array{module: string, line_type: string, debit: string, credit: string, effective_from: string, reason: string, source: string} $in
     */
    public function propose(Authorizer $az, int $legalEntityId, array $in): string
    {
        $this->accounts->requireLe($az, 'core.finance.rules', $legalEntityId);
        $errors = [];
        if (!preg_match('/^[a-z][a-z0-9_]{1,19}(\.[a-z0-9_]{1,30}){1,2}$/', $in['module'])) {
            $errors['module'] = 'finance.error.module';
        }
        if (!preg_match('/^[a-z][a-z0-9_.]{1,59}$/', $in['line_type'])) {
            $errors['line_type'] = 'finance.error.line_type';
        }
        $debit = $this->accounts->accountByCode($legalEntityId, $in['debit']);
        $credit = $this->accounts->accountByCode($legalEntityId, $in['credit']);
        foreach (['debit' => $debit, 'credit' => $credit] as $f => $acc) {
            if ($acc === null || (int) $acc['is_postable'] !== 1 || $acc['status'] !== 'active') {
                $errors[$f] = 'finance.error.account_postable';
            }
        }
        if ($debit !== null && $credit !== null && (int) $debit['id'] === (int) $credit['id']) {
            $errors['credit'] = 'finance.error.same_account';
        }
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $in['effective_from']);
        if ($from === false || $from->format('Y-m-d') !== $in['effective_from']) {
            $errors['effective_from'] = 'records.error.date';
        }
        if (trim($in['reason']) === '') {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        /** @var array<string, mixed> $debit */
        /** @var array<string, mixed> $credit */
        return $this->db->transactional(function () use ($az, $legalEntityId, $in, $debit, $credit): string {
            $open = $this->db->scalar(
                "SELECT id FROM fin_posting_rules WHERE legal_entity_id = ? AND source_module = ? AND line_type = ? AND status = 'in_review' FOR UPDATE",
                [$legalEntityId, $in['module'], $in['line_type']]
            );
            if ($open !== null) {
                throw new BusinessException('finance.error.rule_pending', [], 409);
            }
            $this->db->run(
                "UPDATE fin_posting_rules SET status = 'cancelled', updated_at = ? WHERE legal_entity_id = ? AND source_module = ? AND line_type = ? AND status IN ('draft','returned')",
                [$this->now(), $legalEntityId, $in['module'], $in['line_type']]
            );
            $no = (int) $this->db->scalar(
                'SELECT COALESCE(MAX(version_no), 0) + 1 FROM fin_posting_rules WHERE legal_entity_id = ? AND source_module = ? AND line_type = ?',
                [$legalEntityId, $in['module'], $in['line_type']]
            );
            $pid = Ulid::generate($this->clock);
            $now = $this->now();
            $this->db->run(
                "INSERT INTO fin_posting_rules (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, source_module, line_type, version_no,
                    debit_account_id, credit_account_id, effective_from, status, reason, source_reference, proposed_by, is_demo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, (SELECT is_demo FROM legal_entities WHERE id = ?))",
                [$pid, $now, $az->userId, $now, $az->userId, $legalEntityId, $in['module'], $in['line_type'], $no, $debit['id'], $credit['id'],
                    $in['effective_from'], mb_substr(trim($in['reason']), 0, 500), mb_substr(trim($in['source']), 0, 255) ?: null, $az->userId, $legalEntityId]
            );
            $this->audit->record('finance.rule_proposed', 'success', 'posting_rule', $pid, $legalEntityId, $in['reason'], [
                'module' => $in['module'], 'line_type' => $in['line_type'], 'debit' => $in['debit'], 'credit' => $in['credit'], 'version' => $no,
            ]);
            return $pid;
        });
    }

    /** Sends a draft to the finance checker. Only the proposer submits, and only with a source reference. */
    public function submit(Authorizer $az, string $pid): string
    {
        $r = $this->get($pid);
        $this->accounts->requireLe($az, 'core.finance.rules', (int) $r['legal_entity_id']);
        if ($r['status'] !== 'draft') {
            throw new BusinessException('finance.error.not_draft', [], 409);
        }
        if ((int) $r['proposed_by'] !== $az->userId) {
            throw new BusinessException('finance.error.not_proposer', [], 403);
        }
        if (trim((string) $r['source_reference']) === '') {
            throw new ValidationException(['source' => 'finance.error.source_required']);
        }
        $this->db->run("UPDATE fin_posting_rules SET status = 'in_review', updated_at = ? WHERE id = ?", [$this->now(), $r['id']]);
        $inst = $this->workflow->start('core.posting_rule', $pid, $r['source_module'] . ' / ' . $r['line_type'] . ' v' . $r['version_no'], (int) $r['legal_entity_id'], $az->userId);
        $this->db->run('UPDATE fin_posting_rules SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE id = ?', [$inst, $r['id']]);
        $this->audit->record('finance.rule_submitted', 'success', 'posting_rule', $pid, (int) $r['legal_entity_id']);
        return $inst;
    }

    public function cancelDraft(Authorizer $az, string $pid): void
    {
        $r = $this->get($pid);
        $this->accounts->requireLe($az, 'core.finance.rules', (int) $r['legal_entity_id']);
        if (!in_array($r['status'], ['draft', 'returned'], true)) {
            throw new BusinessException('finance.error.not_draft', [], 409);
        }
        $this->db->run("UPDATE fin_posting_rules SET status = 'cancelled', updated_at = ?, updated_by = ? WHERE id = ?", [$this->now(), $az->userId, $r['id']]);
    }

    // ---- WorkflowSubject ---------------------------------------------------------------------------

    public function type(): string
    {
        return 'posting_rule';
    }

    public function link(string $subjectId): string
    {
        return '/finance/rules/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        $r = $this->get((string) $instance['subject_id']);
        if ($r['status'] === 'approved') {
            return;
        }
        $le = (int) $r['legal_entity_id'];
        if ((int) $r['proposed_by'] === $approverId) {
            throw new BusinessException('workflow.error.self_approval', [], 403);
        }
        if (!(new Authorizer($this->db, $this->clock, $approverId))->scope('core.finance.approve')->coversLegalEntity($le)) {
            throw new BusinessException('finance.error.checker_right', [], 403); // defence in depth when a route was edited
        }
        foreach ([$r['debit_account_id'], $r['credit_account_id']] as $acc) {
            $a = $this->accounts->accountById((int) $acc);
            if ($a['status'] !== 'active' || (int) $a['is_postable'] !== 1) {
                throw new BusinessException('finance.error.account_postable', [], 409);
            }
        }
        $now = $this->now();
        $this->db->run("UPDATE fin_posting_rules SET status = 'approved', decided_by = ?, decided_at = ?, updated_at = ? WHERE id = ?", [$approverId, $now, $now, $r['id']]);
        $this->audit->record('finance.rule_approved', 'success', 'posting_rule', (string) $r['public_id'], $le, null, ['module' => $r['source_module'], 'line_type' => $r['line_type'], 'version' => (int) $r['version_no']], $approverId);
        if ($this->onApprovedHook !== null) {
            ($this->onApprovedHook)($le, (string) $r['source_module'], (string) $r['line_type']);
        }
    }

    public function onClosed(array $instance, string $outcome): void
    {
        $status = ['rejected' => 'rejected', 'returned' => 'returned', 'cancelled' => 'cancelled'][$outcome] ?? null;
        if ($status !== null) {
            $this->db->run("UPDATE fin_posting_rules SET status = ?, decided_at = ?, updated_at = ? WHERE public_id = ? AND status IN ('in_review','returned')", [$status, $this->now(), $this->now(), $instance['subject_id']]);
        }
    }

    public function onResubmitted(array $instance): void
    {
        $this->db->run("UPDATE fin_posting_rules SET status = 'in_review', updated_at = ? WHERE public_id = ? AND status = 'returned'", [$this->now(), $instance['subject_id']]);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
