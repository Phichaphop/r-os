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
use Blue\Core\Locks\LockService;
use Blue\Core\Registry\ConfigRegistry;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * Minimal Finance Core master data (06 §7A, DEC-12): chart of accounts, fiscal years and periods per company.
 * The chart of accounts belongs to the company's accountant — the system ships no real chart (only DEMO data).
 * Period close is a hard lock in the shared Lock Service (6.27) — every posting path calls assertOpen().
 */
final class Accounts
{
    public const LOCK_TYPE = 'finance.period';
    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly LockService $locks,
        private readonly ConfigRegistry $registry,
    ) {
    }

    /** DEC-47/50: finance stays "Built — Demo only" for a company until the activation gate approves "live". */
    public function mode(int $legalEntityId): string
    {
        return (string) ($this->registry->resolveOrNull('finance.activation', $legalEntityId)?->value ?? 'demo_only');
    }

    // ---- chart of accounts --------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function accounts(int $legalEntityId, bool $onlyPostable = false): array
    {
        return $this->db->all(
            'SELECT a.*, p.code AS parent_code FROM fin_accounts a LEFT JOIN fin_accounts p ON p.id = a.parent_id WHERE a.legal_entity_id = ?'
            . ($onlyPostable ? " AND a.is_postable = 1 AND a.status = 'active'" : '') . ' ORDER BY a.code',
            [$legalEntityId]
        );
    }

    /** @return array<string, mixed>|null */
    public function accountByCode(int $legalEntityId, string $code): ?array
    {
        return $this->db->one('SELECT * FROM fin_accounts WHERE legal_entity_id = ? AND code = ?', [$legalEntityId, $code]);
    }

    /** @return array<string, mixed> */
    public function accountById(int $id): array
    {
        return $this->db->one('SELECT * FROM fin_accounts WHERE id = ?', [$id]) ?? throw HttpException::notFound();
    }

    /**
     * @param array{code: string, name: string, type: string, parent: string, postable: bool} $in
     */
    public function createAccount(Authorizer $az, int $legalEntityId, array $in): string
    {
        $this->requireLe($az, 'core.finance.manage', $legalEntityId);
        $errors = [];
        if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z.\-]{0,19}$/', $in['code'])) {
            $errors['code'] = 'finance.error.account_code';
        } elseif ($this->accountByCode($legalEntityId, $in['code']) !== null) {
            $errors['code'] = 'finance.error.account_exists';
        }
        if (trim($in['name']) === '') {
            $errors['name'] = 'form.required';
        }
        if (!in_array($in['type'], self::TYPES, true)) {
            $errors['type'] = 'finance.error.account_type';
        }
        $parentId = null;
        if ($in['parent'] !== '') {
            $parent = $this->accountByCode($legalEntityId, $in['parent']);
            if ($parent === null || (int) $parent['is_postable'] === 1) {
                $errors['parent'] = 'finance.error.parent';
            } elseif ($parent['account_type'] !== $in['type']) {
                $errors['parent'] = 'finance.error.parent_type';
            } else {
                $parentId = (int) $parent['id'];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $pid = Ulid::generate($this->clock);
        $now = $this->now();
        $this->db->run(
            'INSERT INTO fin_accounts (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, code, name, account_type, parent_id, is_postable, is_demo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, (SELECT is_demo FROM legal_entities WHERE id = ?))',
            [$pid, $now, $az->userId, $now, $az->userId, $legalEntityId, $in['code'], mb_substr(trim($in['name']), 0, 200), $in['type'], $parentId, $in['postable'] ? 1 : 0, $legalEntityId]
        );
        $this->audit->record('finance.account_created', 'success', 'fin_account', $pid, $legalEntityId, null, ['code' => $in['code'], 'type' => $in['type']]);
        return $pid;
    }

    /** Deactivating keeps history; an account used by an approved posting rule cannot be switched off. */
    public function setStatus(Authorizer $az, string $pid, string $status, string $reason): void
    {
        $a = $this->db->one('SELECT * FROM fin_accounts WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $this->requireLe($az, 'core.finance.manage', (int) $a['legal_entity_id']);
        if (!in_array($status, ['active', 'inactive'], true) || trim($reason) === '') {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        if ($status === 'inactive') {
            $used = (int) $this->db->scalar(
                "SELECT COUNT(*) FROM fin_posting_rules WHERE status IN ('approved','in_review') AND (debit_account_id = ? OR credit_account_id = ?)",
                [$a['id'], $a['id']]
            );
            if ($used > 0) {
                throw new BusinessException('finance.error.account_in_rule', [], 409);
            }
        }
        $this->db->run('UPDATE fin_accounts SET status = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$status, $this->now(), $az->userId, $a['id']]);
        $this->audit->record('finance.account_' . $status, 'success', 'fin_account', $pid, (int) $a['legal_entity_id'], $reason);
    }

    // ---- fiscal years & periods ---------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function fiscalYears(int $legalEntityId): array
    {
        return $this->db->all('SELECT * FROM fin_fiscal_years WHERE legal_entity_id = ? ORDER BY start_date DESC', [$legalEntityId]);
    }

    /**
     * Creates a fiscal year of monthly periods. The first month comes from the company (accounting policy), never assumed.
     */
    public function createFiscalYear(Authorizer $az, int $legalEntityId, string $code, string $startDate, int $months): string
    {
        $this->requireLe($az, 'core.finance.manage', $legalEntityId);
        $errors = [];
        if (!preg_match('/^[0-9A-Za-z\-]{2,20}$/', $code)) {
            $errors['code'] = 'finance.error.fy_code';
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        if ($start === false || $start->format('Y-m-d') !== $startDate || $start->format('d') !== '01') {
            $errors['start_date'] = 'finance.error.fy_start';
        }
        if ($months < 1 || $months > 24) {
            $errors['months'] = 'finance.error.fy_months';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        /** @var DateTimeImmutable $start */
        $end = $start->modify('+' . $months . ' months')->modify('-1 day');
        return $this->db->transactional(function () use ($az, $legalEntityId, $code, $start, $end, $months): string {
            if ($this->db->scalar('SELECT id FROM fin_fiscal_years WHERE legal_entity_id = ? AND code = ?', [$legalEntityId, $code]) !== null) {
                throw new ValidationException(['code' => 'finance.error.fy_exists']);
            }
            $overlap = $this->db->scalar(
                'SELECT code FROM fin_fiscal_years WHERE legal_entity_id = ? AND start_date <= ? AND end_date >= ? LIMIT 1',
                [$legalEntityId, $end->format('Y-m-d'), $start->format('Y-m-d')]
            );
            if ($overlap !== null) {
                throw new ValidationException(['start_date' => 'finance.error.fy_overlap']);
            }
            $pid = Ulid::generate($this->clock);
            $now = $this->now();
            $this->db->run(
                'INSERT INTO fin_fiscal_years (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, code, start_date, end_date, is_demo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, (SELECT is_demo FROM legal_entities WHERE id = ?))',
                [$pid, $now, $az->userId, $now, $az->userId, $legalEntityId, $code, $start->format('Y-m-d'), $end->format('Y-m-d'), $legalEntityId]
            );
            $fy = (int) $this->db->pdo()->lastInsertId();
            for ($i = 0; $i < $months; $i++) {
                $ps = $start->modify('+' . $i . ' months');
                $this->db->run(
                    'INSERT INTO fin_fiscal_periods (fiscal_year_id, legal_entity_id, period_no, period_code, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?)',
                    [$fy, $legalEntityId, $i + 1, $code . '-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), $ps->format('Y-m-d'), $ps->modify('+1 month')->modify('-1 day')->format('Y-m-d')]
                );
            }
            $this->audit->record('finance.fiscal_year_created', 'success', 'fin_fiscal_year', $pid, $legalEntityId, null, ['code' => $code, 'start' => $start->format('Y-m-d'), 'months' => $months]);
            return $pid;
        });
    }

    /**
     * Periods of a fiscal year with their lock state.
     *
     * @return list<array<string, mixed>>
     */
    public function periods(int $fiscalYearId): array
    {
        $rows = $this->db->all(
            "SELECT p.*, l.public_id AS lock_id, l.status AS lock_status, l.locked_at, l.reason AS lock_reason,
                    (SELECT COUNT(*) FROM fin_journals j WHERE j.period_id = p.id AND j.status IN ('posted','reversed')) AS posted_count,
                    (SELECT COUNT(*) FROM fin_journals j WHERE j.period_id = p.id AND j.status IN ('draft','in_review','returned')) AS open_count
               FROM fin_fiscal_periods p
               LEFT JOIN locks l ON l.lock_type = ? AND l.scope_level = 'legal_entity' AND l.scope_id = p.legal_entity_id AND l.period_ref = p.period_code
              WHERE p.fiscal_year_id = ? ORDER BY p.period_no",
            [self::LOCK_TYPE, $fiscalYearId]
        );
        foreach ($rows as &$r) {
            $r['closed'] = in_array($r['lock_status'], ['locked', 'reopen_requested', 'closed'], true);
        }
        return $rows;
    }

    /** @return array<string, mixed>|null the period containing a date */
    public function periodFor(int $legalEntityId, string $date): ?array
    {
        return $this->db->one('SELECT * FROM fin_fiscal_periods WHERE legal_entity_id = ? AND start_date <= ? AND end_date >= ?', [$legalEntityId, $date, $date]);
    }

    /**
     * Refuses a posting into a date without a fiscal period or into a closed period (every channel).
     *
     * @param 'ui'|'api'|'import'|'job'|'integration' $channel
     * @return array<string, mixed> the period
     */
    public function assertOpen(int $legalEntityId, string $date, string $channel, ?int $actorId): array
    {
        $p = $this->periodFor($legalEntityId, $date) ?? throw new BusinessException('finance.error.no_period', ['date' => $date], 422);
        $this->locks->assertWritable(self::LOCK_TYPE, (string) $p['period_code'], $legalEntityId, null, $channel, $actorId);
        return $p;
    }

    /** Closes a period (hard lock). Requests still waiting for approval in the period must be finished first. */
    public function closePeriod(Authorizer $az, int $legalEntityId, string $periodCode, string $reason): string
    {
        $this->requireLe($az, 'core.finance.close', $legalEntityId);
        $p = $this->db->one('SELECT * FROM fin_fiscal_periods WHERE legal_entity_id = ? AND period_code = ?', [$legalEntityId, $periodCode]) ?? throw HttpException::notFound();
        $pending = (int) $this->db->scalar("SELECT COUNT(*) FROM fin_journals WHERE period_id = ? AND status = 'in_review'", [$p['id']]);
        if ($pending > 0) {
            throw new BusinessException('finance.error.period_pending', ['n' => $pending], 409);
        }
        $lock = $this->locks->lock(self::LOCK_TYPE, 'legal_entity', $legalEntityId, $periodCode, 'hard', $az->userId, $reason);
        $this->audit->record('finance.period_closed', 'success', 'fin_period', $periodCode, $legalEntityId, $reason);
        return $lock;
    }

    /** Asks to reopen a closed period — someone else with lock rights decides (central workflow). */
    public function requestReopen(Authorizer $az, int $legalEntityId, string $periodCode, string $reason): string
    {
        $this->requireLe($az, 'core.finance.close', $legalEntityId);
        $lock = $this->db->scalar(
            "SELECT public_id FROM locks WHERE lock_type = ? AND scope_level = 'legal_entity' AND scope_id = ? AND period_ref = ?",
            [self::LOCK_TYPE, $legalEntityId, $periodCode]
        ) ?? throw HttpException::notFound();
        return $this->locks->requestReopen((string) $lock, $az->userId, $reason, $legalEntityId);
    }

    public function requireLe(Authorizer $az, string $permission, int $legalEntityId): void
    {
        if (!$az->scope($permission)->coversLegalEntity($legalEntityId)) {
            throw $az->can($permission) ? HttpException::notFound() : HttpException::forbidden();
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
