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
use Blue\Core\Notify\Notifier;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Money;
use Blue\Core\Support\Ulid;
use Throwable;

/**
 * Accounting Posting Interface (DEC-12, P0-01 §3.3, P0-02 §6): the ONLY way a module with financial effect reaches the ledger.
 *
 *   request()  — called by the source module inside its own transaction; stores the request + a `posting.requested` event (outbox).
 *   process()  — the Finance Core consumer: maps each line through the approved posting rules into a DRAFT journal.
 *                No rule / no fiscal period / closed period → the request waits as "needs attention" (fail safely, 6.25.2)
 *                and is retried when a rule is approved or someone presses retry. Never posts on its own: a finance maker
 *                reviews the draft and a different finance checker approves it (Journals).
 * The idempotency key makes a repeated request (double click, retry, replay) return the first request — never a second journal.
 * Lines carry aggregates only — no account numbers or per-person pay in this interface (P0-03 §11).
 */
final class PostingService
{
    public const CONSUMER = 'finance.core';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly Outbox $outbox,
        private readonly Accounts $accounts,
        private readonly PostingRules $rules,
        private readonly Journals $journals,
        private readonly Notifier $notifier,
    ) {
    }

    /**
     * @param array{source_module: string, source_document_type: string, source_document_id: string, legal_entity_id: int, site_id?: ?int,
     *              period_date: string, currency_code: string, lines: list<array{line_type: string, amount: string, site_id?: ?int, cost_center_code?: ?string, description?: ?string}>,
     *              calculation_id?: ?string, description?: ?string, idempotency_key: string, actor_user_id?: ?int} $r
     * @return string posting request public id (the existing one for a repeated idempotency key)
     */
    public function request(array $r): string
    {
        $existing = $this->db->scalar('SELECT public_id FROM fin_posting_requests WHERE idempotency_key = ?', [$r['idempotency_key']]);
        if ($existing !== null) {
            return (string) $existing;
        }
        $errors = [];
        if (!preg_match('/^[a-z][a-z0-9_]{1,19}(\.[a-z0-9_]{1,30}){1,2}$/', $r['source_module'])) {
            $errors['source_module'] = 'finance.error.module';
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $r['source_document_type']) || !preg_match('/^[0-9A-Za-z\-_.:]{1,60}$/', $r['source_document_id'])) {
            $errors['source_document'] = 'finance.error.source_document';
        }
        if (strlen($r['idempotency_key']) < 8 || strlen($r['idempotency_key']) > 120) {
            $errors['idempotency_key'] = 'finance.error.idempotency_key';
        }
        if ($this->db->scalar('SELECT id FROM legal_entities WHERE id = ?', [$r['legal_entity_id']]) === null) {
            $errors['legal_entity_id'] = 'finance.error.legal_entity';
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $r['period_date']);
        if ($d === false || $d->format('Y-m-d') !== $r['period_date']) {
            $errors['period_date'] = 'records.error.date';
        }
        if (!preg_match('/^[A-Z]{3}$/', $r['currency_code'])) {
            $errors['currency_code'] = 'finance.error.currency';
        }
        $lines = [];
        $total = 0;
        if ($r['lines'] === [] || count($r['lines']) > Journals::MAX_LINES / 2) {
            $errors['lines'] = 'finance.error.request_lines';
        }
        foreach ($r['lines'] as $i => $l) {
            $amount = Money::parse((string) $l['amount'], 4);
            if (!preg_match('/^[a-z][a-z0-9_.]{1,59}$/', (string) $l['line_type']) || $amount === null || $amount <= 0) {
                $errors['lines.' . $i] = 'finance.error.request_line';
                continue;
            }
            $site = $l['site_id'] ?? null;
            if ($site !== null && (int) $this->db->scalar('SELECT legal_entity_id FROM sites WHERE id = ?', [$site]) !== $r['legal_entity_id']) {
                $errors['lines.' . $i] = 'finance.error.site';
                continue;
            }
            $total += $amount;
            $lines[] = ['line_type' => $l['line_type'], 'amount' => Money::toDb($amount), 'site_id' => $site,
                'cost_center_code' => isset($l['cost_center_code']) ? mb_substr((string) $l['cost_center_code'], 0, 30) : null,
                'description' => isset($l['description']) ? mb_substr((string) $l['description'], 0, 300) : null];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $this->db->transactional(function () use ($r, $lines, $total): string {
            $pid = Ulid::generate($this->clock);
            $now = $this->now();
            $actor = $r['actor_user_id'] ?? null;
            $this->db->run(
                "INSERT INTO fin_posting_requests (public_id, created_at, created_by, updated_at, updated_by, idempotency_key, source_module, source_document_type, source_document_id,
                    legal_entity_id, site_id, period_date, currency_code, lines_json, total_amount, calculation_id, description, status, requested_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'received', ?)",
                [$pid, $now, $actor, $now, $actor, $r['idempotency_key'], $r['source_module'], $r['source_document_type'], $r['source_document_id'],
                    $r['legal_entity_id'], $r['site_id'] ?? null, $r['period_date'], $r['currency_code'], json_encode($lines, JSON_UNESCAPED_UNICODE),
                    Money::toDb($total), $r['calculation_id'] ?? null, isset($r['description']) ? mb_substr((string) $r['description'], 0, 300) : null, $actor]
            );
            $event = $this->outbox->record(
                'posting.requested',
                ['posting_request_id' => $pid, 'source_module' => $r['source_module'],
                'source_document_type' => $r['source_document_type'], 'source_document_id' => $r['source_document_id'], 'total' => Money::toDb($total)],
                'posting.requested:' . $r['idempotency_key'],
                $r['legal_entity_id'],
                $r['site_id'] ?? null
            );
            $this->db->run('UPDATE fin_posting_requests SET event_id = ? WHERE public_id = ?', [$event, $pid]);
            $this->audit->record('finance.posting_requested', 'success', 'posting_request', $pid, $r['legal_entity_id'], null, [
                'source' => $r['source_module'] . ' ' . $r['source_document_type'] . ' ' . $r['source_document_id'], 'total' => Money::toDb($total), 'lines' => count($lines),
            ], $actor);
            return $pid;
        });
    }

    /**
     * Outbox consumer: `posting.requested` → draft journal (or "needs attention").
     *
     * @param array<string, mixed> $envelope
     */
    public function consume(array $envelope): void
    {
        $pid = (string) ($envelope['payload']['posting_request_id'] ?? '');
        $id = $this->db->scalar('SELECT id FROM fin_posting_requests WHERE public_id = ?', [$pid]);
        if ($id !== null) {
            $this->process((int) $id);
        }
    }

    /** Maps a received / waiting request into a draft journal. Returns the new status. */
    public function process(int $requestId): string
    {
        return $this->db->transactional(function () use ($requestId): string {
            $req = $this->db->one('SELECT * FROM fin_posting_requests WHERE id = ? FOR UPDATE', [$requestId]) ?? throw HttpException::notFound();
            if (!in_array($req['status'], ['received', 'needs_attention'], true)) {
                return (string) $req['status']; // already mapped / posted — a repeated event does nothing
            }
            $le = (int) $req['legal_entity_id'];
            try {
                $this->accounts->assertOpen($le, (string) $req['period_date'], 'integration', null);
                $lines = [];
                $missing = [];
                foreach ((array) json_decode((string) $req['lines_json'], true) as $l) {
                    $rule = $this->rules->resolve($le, (string) $req['source_module'], (string) $l['line_type'], (string) $req['period_date']);
                    if ($rule === null) {
                        $missing[] = (string) $l['line_type'];
                        continue;
                    }
                    if ($rule['debit_status'] !== 'active' || $rule['credit_status'] !== 'active' || (int) $rule['debit_postable'] !== 1 || (int) $rule['credit_postable'] !== 1) {
                        throw new BusinessException('finance.error.rule_account_inactive', ['type' => (string) $l['line_type']], 422);
                    }
                    $amount = Money::fromDb($l['amount']);
                    $site = isset($l['site_id']) ? (int) $l['site_id'] : ($req['site_id'] !== null ? (int) $req['site_id'] : null);
                    $common = ['site_id' => $site, 'cost_center' => $l['cost_center_code'] ?? null, 'description' => $l['description'] ?? null, 'line_type' => (string) $l['line_type']];
                    $lines[] = ['account_id' => (int) $rule['debit_account_id'], 'debit' => $amount, 'credit' => 0] + $common;
                    $lines[] = ['account_id' => (int) $rule['credit_account_id'], 'debit' => 0, 'credit' => $amount] + $common;
                }
                if ($missing !== []) {
                    throw new BusinessException('finance.error.no_rule', ['types' => implode(', ', array_unique($missing))], 422);
                }
                $journal = $this->journals->createFromRequest($req, $lines);
                $this->db->run(
                    "UPDATE fin_posting_requests SET status = 'draft_journal', journal_id = (SELECT id FROM fin_journals WHERE public_id = ?), error_key = NULL, error_detail = NULL,
                        attempts = attempts + 1, updated_at = ? WHERE id = ?",
                    [$journal, $this->now(), $requestId]
                );
                $this->notifyMakers($le, 'notify.finance.draft_ready', (string) $req['source_module'] . ' ' . $req['source_document_id'], '/finance/journals/' . $journal, 'fin-draft-' . $journal);
                return 'draft_journal';
            } catch (BusinessException $e) {
                $this->db->run(
                    "UPDATE fin_posting_requests SET status = 'needs_attention', error_key = ?, error_detail = ?, attempts = attempts + 1, updated_at = ? WHERE id = ?",
                    [$e->messageKey, mb_substr(json_encode($e->params, JSON_UNESCAPED_UNICODE) ?: '', 0, 500), $this->now(), $requestId]
                );
                if ($req['status'] !== 'needs_attention' || (string) $req['error_key'] !== $e->messageKey) {
                    $this->audit->record('finance.posting_waiting', 'failure', 'posting_request', (string) $req['public_id'], $le, $e->messageKey, $e->params);
                    $this->notifyMakers($le, 'notify.finance.posting_waiting', (string) $req['source_module'] . ' ' . $req['source_document_id'], '/finance/requests/' . $req['public_id'], 'fin-wait-' . $req['public_id']);
                }
                return 'needs_attention';
            }
        });
    }

    /** Finance maker presses "try again" after fixing a rule / period. */
    public function retry(Authorizer $az, string $pid): string
    {
        $req = $this->get($az, $pid);
        $this->accounts->requireLe($az, 'core.finance.manage', (int) $req['legal_entity_id']);
        return $this->process((int) $req['id']);
    }

    /** Called after a posting rule is approved: retries the requests that were waiting for a rule of that company. */
    public function retryWaiting(int $legalEntityId, string $sourceModule, string $lineType): int
    {
        $n = 0;
        foreach ($this->db->all("SELECT id FROM fin_posting_requests WHERE legal_entity_id = ? AND source_module = ? AND status = 'needs_attention'", [$legalEntityId, $sourceModule]) as $r) {
            try {
                if ($this->process((int) $r['id']) === 'draft_journal') {
                    $n++;
                }
            } catch (Throwable) {
                // stays waiting; visible on the requests page
            }
        }
        unset($lineType);
        return $n;
    }

    /** Finance refuses a request that should not be posted at all; the source module is told (posting.rejected). */
    public function reject(Authorizer $az, string $pid, string $reason): void
    {
        $req = $this->get($az, $pid);
        $le = (int) $req['legal_entity_id'];
        $this->accounts->requireLe($az, 'core.finance.manage', $le);
        if (!in_array($req['status'], ['received', 'needs_attention'], true)) {
            throw new BusinessException('finance.error.request_state', [], 409);
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $this->db->transactional(function () use ($req, $reason, $az, $le): void {
            $this->db->run("UPDATE fin_posting_requests SET status = 'rejected', error_key = 'finance.error.rejected', error_detail = ?, updated_at = ?, updated_by = ? WHERE id = ?", [mb_substr($reason, 0, 500), $this->now(), $az->userId, $req['id']]);
            $this->outbox->record(
                'posting.rejected',
                ['posting_request_id' => $req['public_id'], 'source_module' => $req['source_module'], 'source_document_id' => $req['source_document_id']],
                'posting.rejected:' . $req['public_id'],
                $le
            );
            $this->audit->record('finance.posting_rejected', 'success', 'posting_request', (string) $req['public_id'], $le, $reason);
        });
    }

    /** @return array<string, mixed> */
    public function get(Authorizer $az, string $pid): array
    {
        $r = $this->db->one('SELECT * FROM fin_posting_requests WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        if (!$az->scope('core.finance.view')->coversLegalEntity((int) $r['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        $r['lines'] = (array) json_decode((string) $r['lines_json'], true);
        $r['journal'] = $r['journal_id'] !== null ? $this->db->one('SELECT public_id, journal_no, status FROM fin_journals WHERE id = ?', [$r['journal_id']]) : null;
        return $r;
    }

    /**
     * @param array{legal_entity: int, status: string} $f
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(Authorizer $az, array $f, int $page, int $per): array
    {
        [$sql, $params] = $az->scope('core.finance.view')->sql('r.legal_entity_id');
        $where = [$sql];
        if ($f['legal_entity'] > 0) {
            $where[] = 'r.legal_entity_id = ?';
            $params[] = $f['legal_entity'];
        }
        if ($f['status'] !== '') {
            $where[] = 'r.status = ?';
            $params[] = $f['status'];
        }
        $w = implode(' AND ', $where);
        return [
            'total' => (int) $this->db->scalar("SELECT COUNT(*) FROM fin_posting_requests r WHERE {$w}", $params),
            'rows' => $this->db->all(
                "SELECT r.*, le.code AS le_code, j.public_id AS journal_pid, j.journal_no, j.status AS journal_status FROM fin_posting_requests r
                   JOIN legal_entities le ON le.id = r.legal_entity_id LEFT JOIN fin_journals j ON j.id = r.journal_id
                  WHERE {$w} ORDER BY r.id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per),
                $params
            ),
        ];
    }

    /** @return array<string, int> waiting counts for the finance home page */
    public function counts(Authorizer $az): array
    {
        [$sql, $params] = $az->scope('core.finance.view')->sql('legal_entity_id');
        $out = ['needs_attention' => 0, 'draft_journal' => 0];
        foreach ($this->db->all("SELECT status, COUNT(*) AS n FROM fin_posting_requests WHERE {$sql} GROUP BY status", $params) as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    private function notifyMakers(int $le, string $titleKey, string $title, string $link, string $dedupe): void
    {
        $today = $this->clock->now()->format('Y-m-d');
        $users = $this->db->all(
            "SELECT DISTINCT a.user_id FROM user_role_assignments a JOIN users u ON u.id = a.user_id JOIN access_scopes s ON s.assignment_id = a.id
              WHERE a.status = 'active' AND u.status = 'active' AND a.valid_from <= ? AND (a.valid_to IS NULL OR a.valid_to >= ?)
                AND a.role_id IN (SELECT role_id FROM role_permissions WHERE permission_code = 'core.finance.manage')
                AND (s.scope_level = 'organization' OR (s.scope_level = 'legal_entity' AND s.scope_id = ?))",
            [$today, $today, $le]
        );
        foreach ($users as $u) {
            $this->notifier->notify((int) $u['user_id'], 'finance.posting', $titleKey, ['title' => $title], $link, $dedupe);
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
