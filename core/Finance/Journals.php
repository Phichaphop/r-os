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
use Blue\Core\Events\Outbox;
use Blue\Core\Numbering\NumberingService;
use Blue\Core\Registry\ConfigRegistry;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Money;
use Blue\Core\Support\Ulid;
use Blue\Core\Workflow\WorkflowEngine;
use Blue\Core\Workflow\WorkflowSubject;

/**
 * Journals (06 §7A Finance Core, DEC-12): draft → maker review → checker approval → posted.
 * Posted journals are immutable (service + database triggers); corrections are reversal journals that go through the
 * same approval. The journal number is taken only when posting, so posted numbers have no gaps from abandoned drafts.
 */
final class Journals implements WorkflowSubject
{
    public const DEFAULT_PATTERN = 'JV-{YYYY}-{SEQ:5}';
    public const MAX_LINES = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly WorkflowEngine $workflow,
        private readonly Accounts $accounts,
        private readonly NumberingService $numbering,
        private readonly Outbox $outbox,
        private readonly ConfigRegistry $registry,
    ) {
    }

    // ---- reading ------------------------------------------------------------------------------------

    /** @return array<string, mixed> journal + lines (with account code/name), for someone who may view the company */
    public function get(Authorizer $az, string $pid): array
    {
        $j = $this->row($pid);
        if (!$az->scope('core.finance.view')->coversLegalEntity((int) $j['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        $j['lines'] = $this->lines((int) $j['id']);
        $j['reversal_of'] = $j['reversal_of_id'] !== null ? $this->db->one('SELECT public_id, journal_no FROM fin_journals WHERE id = ?', [$j['reversal_of_id']]) : null;
        $j['reversed_by'] = $j['reversed_by_id'] !== null ? $this->db->one('SELECT public_id, journal_no, status FROM fin_journals WHERE id = ?', [$j['reversed_by_id']]) : null;
        $j['pending_reversal'] = $this->db->one("SELECT public_id, status FROM fin_journals WHERE reversal_of_id = ? AND status IN ('draft','in_review','returned')", [$j['id']]);
        $j['request'] = $j['posting_request_id'] !== null ? $this->db->one('SELECT public_id, source_module, source_document_type, source_document_id FROM fin_posting_requests WHERE id = ?', [$j['posting_request_id']]) : null;
        $j['names'] = $this->db->one(
            'SELECT (SELECT display_name FROM users WHERE id = ?) AS prepared, (SELECT display_name FROM users WHERE id = ?) AS submitted, (SELECT display_name FROM users WHERE id = ?) AS approved',
            [$j['prepared_by'], $j['submitted_by'], $j['approved_by']]
        );
        return $j;
    }

    /** @return array<string, mixed> */
    public function row(string $pid): array
    {
        return $this->db->one('SELECT * FROM fin_journals WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $journalId): array
    {
        return $this->db->all(
            'SELECT l.*, a.code AS account_code, a.name AS account_name, s.code AS site_code FROM fin_journal_lines l JOIN fin_accounts a ON a.id = l.account_id
               LEFT JOIN sites s ON s.id = l.site_id WHERE l.journal_id = ? ORDER BY l.line_no',
            [$journalId]
        );
    }

    /**
     * @param array{legal_entity: int, status: string, q: string, from: string, to: string} $f
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(Authorizer $az, array $f, int $page, int $per): array
    {
        [$scopeSql, $params] = $az->scope('core.finance.view')->sql('j.legal_entity_id');
        $where = [$scopeSql];
        if ($f['legal_entity'] > 0) {
            $where[] = 'j.legal_entity_id = ?';
            $params[] = $f['legal_entity'];
        }
        if ($f['status'] !== '') {
            $where[] = 'j.status = ?';
            $params[] = $f['status'];
        }
        if ($f['q'] !== '') {
            $where[] = '(j.journal_no LIKE ? OR j.description LIKE ? OR j.source_ref LIKE ?)';
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        if ($f['from'] !== '') {
            $where[] = 'j.journal_date >= ?';
            $params[] = $f['from'];
        }
        if ($f['to'] !== '') {
            $where[] = 'j.journal_date <= ?';
            $params[] = $f['to'];
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM fin_journals j WHERE {$w}", $params);
        $rows = $this->db->all(
            "SELECT j.*, le.code AS le_code FROM fin_journals j JOIN legal_entities le ON le.id = j.legal_entity_id WHERE {$w}
              ORDER BY j.journal_date DESC, j.id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per),
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Trial balance of posted journals (including reversed originals and their reversals — they net to zero).
     *
     * @return array{rows: list<array<string, mixed>>, debit: int, credit: int}
     */
    public function trialBalance(Authorizer $az, int $legalEntityId, string $from, string $to): array
    {
        if (!$az->scope('core.finance.view')->coversLegalEntity($legalEntityId)) {
            throw HttpException::notFound();
        }
        $rows = $this->db->all(
            "SELECT a.code, a.name, a.account_type, SUM(l.debit) AS debit, SUM(l.credit) AS credit
               FROM fin_journal_lines l JOIN fin_journals j ON j.id = l.journal_id JOIN fin_accounts a ON a.id = l.account_id
              WHERE j.legal_entity_id = ? AND j.status IN ('posted','reversed') AND j.journal_date BETWEEN ? AND ?
              GROUP BY a.id, a.code, a.name, a.account_type ORDER BY a.code",
            [$legalEntityId, $from, $to]
        );
        $d = 0;
        $c = 0;
        foreach ($rows as &$r) {
            $r['debit_units'] = Money::fromDb($r['debit']);
            $r['credit_units'] = Money::fromDb($r['credit']);
            $r['balance_units'] = $r['debit_units'] - $r['credit_units'];
            $d += $r['debit_units'];
            $c += $r['credit_units'];
        }
        return ['rows' => $rows, 'debit' => $d, 'credit' => $c];
    }

    // ---- writing ------------------------------------------------------------------------------------

    /**
     * Manual journal by a finance maker.
     *
     * @param list<array{account: string, debit: string, credit: string, description: string, site: ?int}> $lines
     */
    public function createManual(Authorizer $az, int $legalEntityId, string $date, string $description, array $lines): string
    {
        $this->accounts->requireLe($az, 'core.finance.manage', $legalEntityId);
        [$prepared, $errors] = $this->prepareLines($legalEntityId, $lines);
        $errors += $this->headerErrors($legalEntityId, $date, $description);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $currency = (string) $this->db->scalar('SELECT base_currency FROM legal_entities WHERE id = ?', [$legalEntityId]);
        return $this->insert($legalEntityId, $date, 'manual', null, null, $description, $currency, $prepared, $az->userId, null);
    }

    /**
     * Replaces the lines of a draft (or returned) journal.
     *
     * @param list<array{account: string, debit: string, credit: string, description: string, site: ?int}> $lines
     */
    public function updateDraft(Authorizer $az, string $pid, string $date, string $description, array $lines): void
    {
        $j = $this->row($pid);
        $le = (int) $j['legal_entity_id'];
        $this->accounts->requireLe($az, 'core.finance.manage', $le);
        if (!in_array($j['status'], ['draft', 'returned'], true)) {
            throw new BusinessException('finance.error.not_editable', [], 409);
        }
        [$prepared, $errors] = $this->prepareLines($le, $lines);
        $errors += $this->headerErrors($le, $date, $description);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->db->transactional(function () use ($j, $date, $description, $prepared, $az, $le): void {
            $this->db->run('DELETE FROM fin_journal_lines WHERE journal_id = ?', [$j['id']]);
            $this->writeLines((int) $j['id'], $prepared);
            [$d, $c] = $this->totals($prepared);
            $period = $this->accounts->periodFor($le, $date);
            $this->db->run(
                'UPDATE fin_journals SET journal_date = ?, period_id = ?, description = ?, total_debit = ?, total_credit = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                [$date, $period['id'] ?? null, mb_substr($description, 0, 300), Money::toDb($d), Money::toDb($c), $this->now(), $az->userId, $j['id']]
            );
            $this->audit->record('finance.journal_edited', 'success', 'journal', (string) $j['public_id'], $le, null, ['lines' => count($prepared)]);
        });
    }

    /**
     * Draft journal built by the posting service from a mapped posting request (prepared by the system).
     *
     * @param array<string, mixed> $request
     * @param list<array{account_id: int, debit: int, credit: int, site_id: ?int, cost_center: ?string, description: ?string, line_type: ?string}> $lines
     */
    public function createFromRequest(array $request, array $lines): string
    {
        return $this->insert(
            (int) $request['legal_entity_id'],
            (string) $request['period_date'],
            (string) $request['source_module'],
            $request['source_document_type'] . ':' . $request['source_document_id'],
            (int) $request['id'],
            (string) ($request['description'] ?: $request['source_module'] . ' ' . $request['source_document_type'] . ' ' . $request['source_document_id']),
            (string) $request['currency_code'],
            $lines,
            null,
            null,
        );
    }

    /** Maker review done → the journal goes to the finance checker. */
    public function submit(Authorizer $az, string $pid): string
    {
        $j = $this->row($pid);
        $le = (int) $j['legal_entity_id'];
        $this->accounts->requireLe($az, 'core.finance.manage', $le);
        if (!in_array($j['status'], ['draft'], true)) {
            throw new BusinessException('finance.error.not_draft', [], 409);
        }
        $this->validateForPosting($j, 'ui', $az->userId);
        $now = $this->now();
        $this->db->run(
            "UPDATE fin_journals SET status = 'in_review', submitted_by = ?, submitted_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'draft'",
            [$az->userId, $now, $now, $az->userId, $j['id']]
        );
        $title = ($j['reversal_of_id'] !== null ? '↩ ' : '') . mb_substr((string) $j['description'], 0, 80) . ' · ' . Money::format(Money::fromDb($j['total_debit']));
        $inst = $this->workflow->start('core.journal', $pid, $title, $le, $az->userId);
        $this->db->run('UPDATE fin_journals SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE id = ?', [$inst, $j['id']]);
        $this->audit->record('finance.journal_submitted', 'success', 'journal', $pid, $le, null, ['total' => Money::toDb(Money::fromDb($j['total_debit']))]);
        return $inst;
    }

    public function cancelDraft(Authorizer $az, string $pid, string $reason): void
    {
        $j = $this->row($pid);
        $le = (int) $j['legal_entity_id'];
        $this->accounts->requireLe($az, 'core.finance.manage', $le);
        if (!in_array($j['status'], ['draft', 'returned'], true) || trim($reason) === '') {
            throw new BusinessException('finance.error.not_editable', [], 409);
        }
        $this->db->transactional(function () use ($j, $az, $reason, $le): void {
            $this->db->run("UPDATE fin_journals SET status = 'cancelled', reason = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [mb_substr($reason, 0, 500), $this->now(), $az->userId, $j['id']]);
            if ($j['posting_request_id'] !== null) {
                $this->db->run(
                    "UPDATE fin_posting_requests SET status = 'needs_attention', journal_id = NULL, error_key = 'finance.error.journal_cancelled', error_detail = ?, updated_at = ? WHERE id = ? AND status = 'draft_journal'",
                    [mb_substr($reason, 0, 500), $this->now(), $j['posting_request_id']]
                );
            }
            $this->audit->record('finance.journal_cancelled', 'success', 'journal', (string) $j['public_id'], $le, $reason);
        });
    }

    /** Prepares a reversal journal (debit ↔ credit) for a posted journal; it is posted through the same approval. */
    public function reverse(Authorizer $az, string $pid, string $date, string $reason): string
    {
        $j = $this->row($pid);
        $le = (int) $j['legal_entity_id'];
        $this->accounts->requireLe($az, 'core.finance.manage', $le);
        if ($j['status'] !== 'posted') {
            throw new BusinessException('finance.error.not_posted', [], 409);
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $errors = $this->headerErrors($le, $date, 'x');
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $this->db->transactional(function () use ($j, $date, $reason, $az, $le): string {
            $this->db->one('SELECT id FROM fin_journals WHERE id = ? FOR UPDATE', [$j['id']]);
            if ($this->db->scalar("SELECT id FROM fin_journals WHERE reversal_of_id = ? AND status IN ('draft','in_review','returned','posted')", [$j['id']]) !== null) {
                throw new BusinessException('finance.error.reversal_exists', [], 409);
            }
            $lines = [];
            foreach ($this->lines((int) $j['id']) as $l) {
                $lines[] = [
                    'account_id' => (int) $l['account_id'], 'debit' => Money::fromDb($l['credit']), 'credit' => Money::fromDb($l['debit']),
                    'site_id' => $l['site_id'] !== null ? (int) $l['site_id'] : null, 'cost_center' => $l['cost_center_code'], 'description' => $l['description'], 'line_type' => $l['source_line_type'],
                ];
            }
            $new = $this->insert(
                $le,
                $date,
                (string) $j['source_module'],
                $j['source_ref'],
                $j['posting_request_id'] !== null ? (int) $j['posting_request_id'] : null,
                mb_substr('↩ ' . $j['journal_no'] . ' — ' . $reason, 0, 300),
                (string) $j['currency_code'],
                $lines,
                $az->userId,
                (int) $j['id']
            );
            $this->db->run('UPDATE fin_journals SET reason = ? WHERE public_id = ?', [mb_substr($reason, 0, 500), $new]);
            $this->audit->record('finance.reversal_prepared', 'success', 'journal', (string) $j['public_id'], $le, $reason, ['reversal' => $new]);
            return $new;
        });
    }

    // ---- WorkflowSubject ---------------------------------------------------------------------------

    public function type(): string
    {
        return 'journal';
    }

    public function link(string $subjectId): string
    {
        return '/finance/journals/' . $subjectId;
    }

    /** Checker approved → post: re-validate, take the number, freeze, tell the source module. */
    public function onApproved(array $instance, int $approverId): void
    {
        $j = $this->row((string) $instance['subject_id']);
        if (in_array($j['status'], ['posted', 'reversed'], true)) {
            return;
        }
        if ($j['status'] !== 'in_review') {
            throw new BusinessException('finance.error.not_in_review', [], 409);
        }
        $le = (int) $j['legal_entity_id'];
        if ($approverId === (int) $j['submitted_by'] || ($j['prepared_by'] !== null && $approverId === (int) $j['prepared_by'])) {
            throw new BusinessException('workflow.error.self_approval', [], 403);
        }
        if (!(new Authorizer($this->db, $this->clock, $approverId))->scope('core.finance.approve')->coversLegalEntity($le)) {
            throw new BusinessException('finance.error.checker_right', [], 403);
        }
        $this->db->transactional(function () use ($j, $approverId, $le): void {
            $period = $this->validateForPosting($j, 'ui', $approverId);
            $date = new DateTimeImmutable((string) $j['journal_date']);
            $pattern = (string) ($this->registry->resolveOrNull('finance.journal_number_pattern', $le)?->value ?? self::DEFAULT_PATTERN);
            $no = $this->numbering->next($le, null, 'fin.journal', $date->format('Y'), $pattern, 'journal', (string) $j['public_id'], $approverId);
            $now = $this->now();
            $this->db->run(
                "UPDATE fin_journals SET status = 'posted', journal_no = ?, period_id = ?, approved_by = ?, posted_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'in_review'",
                [$no, $period['id'], $approverId, $now, $now, $approverId, $j['id']]
            );
            $request = $j['posting_request_id'] !== null ? $this->db->one('SELECT * FROM fin_posting_requests WHERE id = ?', [$j['posting_request_id']]) : null;
            if ($j['reversal_of_id'] !== null) {
                $orig = $this->db->one('SELECT * FROM fin_journals WHERE id = ? FOR UPDATE', [$j['reversal_of_id']]) ?? [];
                if (($orig['status'] ?? '') !== 'posted') {
                    throw new BusinessException('finance.error.not_posted', [], 409);
                }
                $this->db->run("UPDATE fin_journals SET status = 'reversed', reversed_by_id = ?, updated_at = ? WHERE id = ? AND status = 'posted'", [$j['id'], $now, $j['reversal_of_id']]);
                if ($request !== null) {
                    $this->db->run("UPDATE fin_posting_requests SET status = 'reversed', updated_at = ? WHERE id = ?", [$now, $request['id']]);
                    $this->outbox->record('posting.reversed', [
                        'posting_request_id' => $request['public_id'], 'source_module' => $request['source_module'], 'source_document_id' => $request['source_document_id'],
                        'journal_public_id' => $orig['public_id'] ?? null, 'journal_no' => $orig['journal_no'] ?? null, 'reversal_public_id' => $j['public_id'], 'reversal_no' => $no,
                    ], 'posting.reversed:' . $j['public_id'], $le);
                }
            } elseif ($request !== null) {
                $this->db->run("UPDATE fin_posting_requests SET status = 'posted', updated_at = ? WHERE id = ?", [$now, $request['id']]);
                $this->outbox->record('posting.posted', [
                    'posting_request_id' => $request['public_id'], 'source_module' => $request['source_module'], 'source_document_type' => $request['source_document_type'],
                    'source_document_id' => $request['source_document_id'], 'journal_public_id' => $j['public_id'], 'journal_no' => $no,
                ], 'posting.posted:' . $j['public_id'], $le);
            }
            $this->audit->record('finance.journal_posted', 'success', 'journal', (string) $j['public_id'], $le, null, [
                'journal_no' => $no, 'total' => Money::toDb(Money::fromDb($j['total_debit'])), 'reversal_of' => $j['reversal_of_id'] !== null,
            ], $approverId);
        });
    }

    public function onClosed(array $instance, string $outcome): void
    {
        $j = $this->db->one('SELECT * FROM fin_journals WHERE public_id = ?', [$instance['subject_id']]);
        if ($j === null || !in_array($j['status'], ['in_review', 'returned'], true)) {
            return;
        }
        $now = $this->now();
        if ($outcome === 'returned') {
            $this->db->run("UPDATE fin_journals SET status = 'returned', updated_at = ? WHERE id = ?", [$now, $j['id']]);
        } elseif ($outcome === 'cancelled') {
            $this->db->run("UPDATE fin_journals SET status = 'draft', submitted_by = NULL, submitted_at = NULL, updated_at = ? WHERE id = ?", [$now, $j['id']]);
        } else { // rejected — this journal will never be posted
            $this->db->run("UPDATE fin_journals SET status = 'cancelled', reason = 'rejected by checker', updated_at = ? WHERE id = ?", [$now, $j['id']]);
            if ($j['posting_request_id'] !== null && $j['reversal_of_id'] === null) {
                $this->db->run(
                    "UPDATE fin_posting_requests SET status = 'needs_attention', journal_id = NULL, error_key = 'finance.error.journal_rejected', updated_at = ? WHERE id = ? AND status = 'draft_journal'",
                    [$now, $j['posting_request_id']]
                );
            }
        }
    }

    /** A returned journal sent again (after the maker fixed it). */
    public function onResubmitted(array $instance): void
    {
        $j = $this->row((string) $instance['subject_id']);
        $this->validateForPosting($j, 'ui', null);
        $this->db->run("UPDATE fin_journals SET status = 'in_review', updated_at = ? WHERE id = ? AND status = 'returned'", [$this->now(), $j['id']]);
    }

    /** Re-submitting a returned journal goes through the workflow "resubmit" so the history stays in one request. */
    public function resubmit(Authorizer $az, string $pid): void
    {
        $j = $this->row($pid);
        $this->accounts->requireLe($az, 'core.finance.manage', (int) $j['legal_entity_id']);
        if ($j['status'] !== 'returned' || $j['workflow_instance_id'] === null) {
            throw new BusinessException('finance.error.not_returned', [], 409);
        }
        $inst = (string) $this->db->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$j['workflow_instance_id']]);
        $this->workflow->resubmit($inst, $az->userId);
    }

    // ---- internals ---------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $j
     * @return array<string, mixed> the open period
     */
    private function validateForPosting(array $j, string $channel, ?int $actorId): array
    {
        $le = (int) $j['legal_entity_id'];
        $lines = $this->lines((int) $j['id']);
        if (count($lines) < 2) {
            throw new BusinessException('finance.error.min_lines', [], 422);
        }
        $d = 0;
        $c = 0;
        foreach ($lines as $l) {
            $acc = $this->accounts->accountById((int) $l['account_id']);
            if ((int) $acc['legal_entity_id'] !== $le || $acc['status'] !== 'active' || (int) $acc['is_postable'] !== 1) {
                throw new BusinessException('finance.error.account_inactive', ['code' => (string) $acc['code']], 422);
            }
            $d += Money::fromDb($l['debit']);
            $c += Money::fromDb($l['credit']);
        }
        if ($d !== $c || $d === 0 || $d !== Money::fromDb($j['total_debit']) || $c !== Money::fromDb($j['total_credit'])) {
            throw new BusinessException('finance.error.unbalanced', [], 422);
        }
        /** @var 'ui'|'api'|'import'|'job'|'integration' $channel */
        return $this->accounts->assertOpen($le, (string) $j['journal_date'], $channel, $actorId);
    }

    /** @return array<string, string> */
    private function headerErrors(int $le, string $date, string $description): array
    {
        $errors = [];
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            $errors['date'] = 'records.error.date';
        } elseif ($this->accounts->periodFor($le, $date) === null) {
            $errors['date'] = 'finance.error.no_period_short';
        }
        if (trim($description) === '') {
            $errors['description'] = 'form.required';
        }
        return $errors;
    }

    /**
     * @param list<array{account: string, debit: string, credit: string, description: string, site: ?int}> $lines
     * @return array{0: list<array{account_id: int, debit: int, credit: int, site_id: ?int, cost_center: ?string, description: ?string, line_type: ?string}>, 1: array<string, string>}
     */
    private function prepareLines(int $le, array $lines): array
    {
        $out = [];
        $errors = [];
        foreach ($lines as $i => $l) {
            if (trim($l['account']) === '' && trim($l['debit']) === '' && trim($l['credit']) === '') {
                continue; // blank row on the form
            }
            $acc = $this->accounts->accountByCode($le, trim($l['account']));
            if ($acc === null || $acc['status'] !== 'active' || (int) $acc['is_postable'] !== 1) {
                $errors['line_' . $i] = 'finance.error.account_postable';
                continue;
            }
            $dr = trim($l['debit']) === '' ? 0 : Money::parse($l['debit']);
            $cr = trim($l['credit']) === '' ? 0 : Money::parse($l['credit']);
            if ($dr === null || $cr === null || $dr < 0 || $cr < 0 || ($dr > 0) === ($cr > 0)) {
                $errors['line_' . $i] = 'finance.error.line_amount';
                continue;
            }
            if ($l['site'] !== null && (int) $this->db->scalar('SELECT legal_entity_id FROM sites WHERE id = ?', [$l['site']]) !== $le) {
                $errors['line_' . $i] = 'finance.error.site';
                continue;
            }
            $out[] = ['account_id' => (int) $acc['id'], 'debit' => $dr, 'credit' => $cr, 'site_id' => $l['site'], 'cost_center' => null,
                'description' => mb_substr(trim($l['description']), 0, 300) ?: null, 'line_type' => null];
        }
        if ($errors === []) {
            if (count($out) < 2) {
                $errors['lines'] = 'finance.error.min_lines';
            } elseif (count($out) > self::MAX_LINES) {
                $errors['lines'] = 'finance.error.max_lines';
            } else {
                [$d, $c] = $this->totals($out);
                if ($d !== $c) {
                    $errors['lines'] = 'finance.error.unbalanced';
                }
            }
        }
        return [$out, $errors];
    }

    /**
     * @param list<array{account_id: int, debit: int, credit: int, site_id: ?int, cost_center: ?string, description: ?string, line_type: ?string}> $lines
     */
    private function insert(int $le, string $date, string $module, ?string $sourceRef, ?int $requestId, string $description, string $currency, array $lines, ?int $preparedBy, ?int $reversalOf): string
    {
        return $this->db->transactional(function () use ($le, $date, $module, $sourceRef, $requestId, $description, $currency, $lines, $preparedBy, $reversalOf): string {
            [$d, $c] = $this->totals($lines);
            $period = $this->accounts->periodFor($le, $date);
            $pid = Ulid::generate($this->clock);
            $now = $this->now();
            $this->db->run(
                "INSERT INTO fin_journals (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, journal_date, period_id, source_module, source_ref,
                    posting_request_id, description, currency_code, total_debit, total_credit, status, prepared_by, reversal_of_id, is_demo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, (SELECT is_demo FROM legal_entities WHERE id = ?))",
                [$pid, $now, $preparedBy, $now, $preparedBy, $le, $date, $period['id'] ?? null, $module, $sourceRef, $requestId, mb_substr($description, 0, 300), $currency,
                    Money::toDb($d), Money::toDb($c), $preparedBy, $reversalOf, $le]
            );
            $this->writeLines((int) $this->db->pdo()->lastInsertId(), $lines);
            $this->audit->record($reversalOf === null ? 'finance.journal_drafted' : 'finance.reversal_drafted', 'success', 'journal', $pid, $le, null, [
                'source' => $module, 'lines' => count($lines), 'total' => Money::toDb($d),
            ], $preparedBy);
            return $pid;
        });
    }

    /** @param list<array{account_id: int, debit: int, credit: int, site_id: ?int, cost_center: ?string, description: ?string, line_type: ?string}> $lines */
    private function writeLines(int $journalId, array $lines): void
    {
        $cur = (string) $this->db->scalar('SELECT currency_code FROM fin_journals WHERE id = ?', [$journalId]);
        foreach (array_values($lines) as $i => $l) {
            $this->db->run(
                'INSERT INTO fin_journal_lines (journal_id, line_no, account_id, debit, credit, currency_code, site_id, cost_center_code, description, source_line_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$journalId, $i + 1, $l['account_id'], Money::toDb($l['debit']), Money::toDb($l['credit']), $cur, $l['site_id'], $l['cost_center'], $l['description'], $l['line_type']]
            );
        }
    }

    /**
     * @param list<array{debit: int, credit: int}> $lines
     * @return array{0: int, 1: int}
     */
    private function totals(array $lines): array
    {
        $d = 0;
        $c = 0;
        foreach ($lines as $l) {
            $d += $l['debit'];
            $c += $l['credit'];
        }
        return [$d, $c];
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
