<?php

declare(strict_types=1);

namespace Blue\Modules\Payroll;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Registry\ResolvedConfig;
use Blue\Core\Support\Money;
use Blue\Core\Support\Ulid;

/**
 * Payroll engine (phase 6A, DEC-83).
 *
 *  components  per company: base pay, fixed amounts, OT, absence, manual, social security (employee / employer).
 *              Payroll drafts one; another person with payroll.approve activates it. Code and method never change.
 *  profiles    per employee, effective-dated: monthly or daily pay, the amount, fixed items, bank account (encrypted, last
 *              4 digits shown). Payroll proposes, another person approves through the central workflow.
 *  runs        a company and a period: draft → (sources: one finalized time period + OT batches, manual amounts) →
 *              calculated (PAYROLL_V1, recorded in the Calculation Ledger) → pending (workflow payroll.run) → approved
 *              (ledger run approved, payroll period locked) → payment batch (core Payments) + posting request (Finance).
 *              Any change after calculating sends the run back to draft. Blocking warnings stop submission.
 *              Approved runs are never edited — corrections are a later run (off-cycle, part 6E).
 */
final class PayrollService
{
    public const METHODS = ['base', 'fixed', 'ot', 'absence', 'manual', 'sso_employee', 'sso_employer', 'wht'];
    /** kinds a method may have */
    public const METHOD_KINDS = ['base' => ['earning'], 'fixed' => ['earning', 'deduction'], 'ot' => ['earning'], 'absence' => ['deduction'],
        'manual' => ['earning', 'deduction'], 'sso_employee' => ['deduction'], 'sso_employer' => ['employer'], 'wht' => ['deduction']];
    public const MAX_ITEMS = 8;

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    public function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    public function today(): string
    {
        return $this->app->clock->now()->setTimezone($this->tz())->format('Y-m-d');
    }

    public static function isDate(string $d): bool
    {
        $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d, new DateTimeZone('UTC'));
        return $x !== false && $x->format('Y-m-d') === $d;
    }

    private static function ymd(string $d): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('!Y-m-d', $d, new DateTimeZone('UTC')) ?: throw new \InvalidArgumentException('date');
    }

    private static function days(string $from, string $to): int
    {
        return (int) self::ymd($from)->diff(self::ymd($to))->format('%r%a') + 1;
    }

    /** @return list<int> companies the person covers with a permission */
    public function companies(Authorizer $az, string $perm = 'payroll.view'): array
    {
        $s = $az->scope($perm);
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $s->coversLegalEntity($id)));
    }

    private function require(Authorizer $az, string $perm, int $le): void
    {
        if (!$az->scope($perm)->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
    }

    /** @param array<string, mixed> $diff */
    private function audit(string $action, string $type, string $pid, int $le, ?string $reason = null, array $diff = []): void
    {
        $this->app->audit()->record($action, 'success', $type, $pid, $le, $reason, $diff);
    }

    // ---- components -----------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function components(int $le, bool $activeOnly = false): array
    {
        return $this->app->db()->all('SELECT * FROM pay_components WHERE legal_entity_id = ?' . ($activeOnly ? " AND status = 'active'" : '') . ' ORDER BY sort_order, code', [$le]);
    }

    /** @param array<string, mixed> $in */
    public function saveComponent(Authorizer $az, int $le, array $in): string
    {
        $this->require($az, 'payroll.prepare', $le);
        $db = $this->app->db();
        $errors = [];
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if (preg_match('/^[A-Z][A-Z0-9_-]{1,19}$/', $code) !== 1) {
            $errors['code'] = 'payroll.error.code';
        } elseif ($db->scalar('SELECT id FROM pay_components WHERE legal_entity_id = ? AND code = ?', [$le, $code]) !== null) {
            $errors['code'] = 'payroll.error.code_taken';
        }
        $name = trim((string) ($in['name_th'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name_th'] = 'payroll.error.name';
        }
        $method = (string) ($in['method'] ?? '');
        $kind = (string) ($in['kind'] ?? '');
        if (!in_array($method, self::METHODS, true)) {
            $errors['method'] = 'form.invalid_choice';
        } elseif (!in_array($kind, self::METHOD_KINDS[$method], true)) {
            $errors['kind'] = 'payroll.error.kind';
        }
        $single = ['base', 'ot', 'absence', 'sso_employee', 'sso_employer', 'wht'];
        if (
            !isset($errors['method']) && in_array($method, $single, true)
            && $db->scalar("SELECT id FROM pay_components WHERE legal_entity_id = ? AND method = ? AND status <> 'retired'", [$le, $method]) !== null
        ) {
            $errors['method'] = 'payroll.error.method_taken';
        }
        $statutory = (string) ($in['statutory'] ?? 'none');
        $statutory = match ($method) {
            'sso_employee' => 'sso_employee',
            'sso_employer' => 'sso_employer',
            'wht' => 'wht',
            default => $statutory === 'wht' && $method === 'manual' && $kind === 'deduction' ? 'wht' : 'none',
        };
        $sort = trim((string) ($in['sort_order'] ?? '100'));
        if (!ctype_digit($sort) || (int) $sort > 999) {
            $errors['sort_order'] = 'payroll.error.sort';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $flags = in_array($kind, ['earning', 'deduction'], true);
        $db->run(
            "INSERT INTO pay_components (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, code, name_th, name_en, kind, method, taxable, sso_base, statutory, sort_order, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')",
            [$pid, $now, $az->userId, $now, $az->userId, $le, $code, $name, trim((string) ($in['name_en'] ?? '')) ?: null, $kind, $method,
                $flags && ($in['taxable'] ?? '') === '1' ? 1 : 0, $flags && ($in['sso_base'] ?? '') === '1' ? 1 : 0, $statutory, (int) $sort]
        );
        $this->audit('payroll.component_drafted', 'pay_component', $pid, $le, null, ['code' => $code, 'method' => $method]);
        return $pid;
    }

    /** activate (another person with payroll.approve) or retire (payroll.approve) */
    public function decideComponent(Authorizer $az, string $pid, string $action): void
    {
        $db = $this->app->db();
        $c = $db->one('SELECT * FROM pay_components WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $le = (int) $c['legal_entity_id'];
        if (!$az->scope('payroll.view')->coversLegalEntity($le)) {
            throw HttpException::notFound();
        }
        $this->require($az, 'payroll.approve', $le);
        if ($action === 'activate') {
            if ($c['status'] !== 'draft') {
                throw new BusinessException('payroll.error.state', [], 409);
            }
            if ((int) $c['created_by'] === $az->userId) {
                throw new BusinessException('payroll.error.same_person', [], 403);
            }
            $db->run("UPDATE pay_components SET status = 'active', activated_by = ?, activated_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$az->userId, $this->now(), $this->now(), $az->userId, $c['id']]);
        } else {
            if ($c['status'] === 'retired') {
                throw new BusinessException('payroll.error.state', [], 409);
            }
            $db->run("UPDATE pay_components SET status = 'retired', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $az->userId, $c['id']]);
        }
        $this->audit('payroll.component_' . ($action === 'activate' ? 'activated' : 'retired'), 'pay_component', $pid, $le, null, ['code' => $c['code']]);
    }

    // ---- pay profiles -----------------------------------------------------------------------------------------------------

    /**
     * Employees of the company (employment on $date) with their approved profile in force and pending proposals.
     *
     * @return list<array<string, mixed>>
     */
    public function staff(int $le, string $date): array
    {
        $db = $this->app->db();
        $rows = $db->all(
            "SELECT e.id, e.public_id, e.employee_code, e.name_th, e.status, e.hire_date, e.termination_date, h.site_id, h.department_id, h.employment_type
               FROM employees e JOIN employment_history h ON h.employee_id = e.id
              WHERE h.legal_entity_id = ? AND h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?) AND e.status <> 'former'
              ORDER BY e.employee_code",
            [$le, $date, $date]
        );
        foreach ($rows as &$r) {
            $r['profile'] = $this->profileAt((int) $r['id'], $le, $date);
            $r['pending'] = (int) $db->scalar("SELECT COUNT(*) FROM pay_profiles WHERE employee_id = ? AND legal_entity_id = ? AND status = 'pending'", [$r['id'], $le]);
        }
        return $rows;
    }

    /** @return array<string, mixed>|null the approved profile in force on a date */
    public function profileAt(int $employeeId, int $le, string $date): ?array
    {
        return $this->app->db()->one(
            "SELECT * FROM pay_profiles WHERE employee_id = ? AND legal_entity_id = ? AND status = 'approved' AND effective_from <= ? ORDER BY effective_from DESC, id DESC LIMIT 1",
            [$employeeId, $le, $date]
        );
    }

    /** @return array<string, mixed> employee + company it belongs to now, checked against the person's scope */
    public function employee(Authorizer $az, string $empPid, string $perm = 'payroll.view'): array
    {
        $db = $this->app->db();
        $e = $db->one('SELECT * FROM employees WHERE public_id = ?', [$empPid]) ?? throw HttpException::notFound();
        $h = $db->one('SELECT * FROM employment_history WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 1', [$e['id']]) ?? throw HttpException::notFound();
        if (!$az->scope($perm)->coversLegalEntity((int) $h['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        $e['legal_entity_id'] = (int) $h['legal_entity_id'];
        return $e;
    }

    /** @return list<array<string, mixed>> */
    public function profiles(int $employeeId, int $le): array
    {
        return $this->app->db()->all(
            'SELECT p.*, u.display_name AS proposed_name, d.display_name AS decided_name FROM pay_profiles p LEFT JOIN users u ON u.id = p.proposed_by LEFT JOIN users d ON d.id = p.decided_by
              WHERE p.employee_id = ? AND p.legal_entity_id = ? ORDER BY p.effective_from DESC, p.id DESC',
            [$employeeId, $le]
        );
    }

    /**
     * Proposes a new profile version (salary / daily rate, fixed items, bank account). Nothing changes until another
     * person approves. A blank account keeps the account of the profile in force.
     *
     * @param array<string, mixed> $in effective_from, pay_basis, base_amount, bank_code, account_no, items[{component, amount}], reason
     */
    public function proposeProfile(Authorizer $az, string $empPid, array $in): string
    {
        $e = $this->employee($az, $empPid);
        $le = (int) $e['legal_entity_id'];
        $this->require($az, 'payroll.prepare', $le);
        $db = $this->app->db();
        if ($db->scalar("SELECT id FROM pay_profiles WHERE employee_id = ? AND legal_entity_id = ? AND status = 'pending'", [$e['id'], $le]) !== null) {
            throw new BusinessException('payroll.error.profile_pending', [], 409);
        }
        $errors = [];
        $from = (string) ($in['effective_from'] ?? '');
        if (!self::isDate($from)) {
            $errors['effective_from'] = 'payroll.error.date';
        } elseif ($from < (string) $e['hire_date']) {
            $errors['effective_from'] = 'payroll.error.before_hire';
        } elseif ($this->pendingRunCovers($le, $from)) {
            $errors['effective_from'] = 'payroll.error.in_pending_run';
        } elseif ($this->lockedRunCovers($le, $from) && empty($in['retro'])) {
            $errors['effective_from'] = 'payroll.error.in_approved_run';
        }
        $retro = self::isDate($from) && $this->lockedRunCovers($le, $from) ? 1 : 0;
        $basis = (string) ($in['pay_basis'] ?? '');
        if (!in_array($basis, ['monthly', 'daily'], true)) {
            $errors['pay_basis'] = 'form.invalid_choice';
        }
        $amount = Money::parse((string) ($in['base_amount'] ?? ''), 2);
        if ($amount === null || $amount <= 0) {
            $errors['base_amount'] = 'payroll.error.amount';
        }
        $bank = trim((string) ($in['bank_code'] ?? ''));
        $acc = preg_replace('/[\s-]/', '', (string) ($in['account_no'] ?? '')) ?? '';
        $current = self::isDate($from) ? $this->profileAt((int) $e['id'], $le, $from) : null;
        if ($acc !== '') {
            if (preg_match('/^\d{6,20}$/', $acc) !== 1) {
                $errors['account_no'] = 'payroll.error.account';
            }
            if (preg_match('/^[0-9A-Za-z][0-9A-Za-z _\-.]{0,19}$/', $bank) !== 1) {
                $errors['bank_code'] = 'payroll.error.bank';
            }
        }
        $items = [];
        $active = [];
        foreach ($this->components($le, true) as $c) {
            if ($c['method'] === 'fixed') {
                $active[(string) $c['public_id']] = $c;
            }
        }
        foreach (array_slice(array_values(array_filter((array) ($in['items'] ?? []), 'is_array')), 0, self::MAX_ITEMS) as $i => $it) {
            $cp = (string) ($it['component'] ?? '');
            $raw = trim((string) ($it['amount'] ?? ''));
            if ($cp === '' && $raw === '') {
                continue;
            }
            $u = Money::parse($raw, 2);
            if (!isset($active[$cp])) {
                $errors["items.{$i}.component"] = 'form.invalid_choice';
            } elseif ($u === null || $u <= 0) {
                $errors["items.{$i}.amount"] = 'payroll.error.amount';
            } else {
                $items[] = ['component_id' => (int) $active[$cp]['id'], 'code' => (string) $active[$cp]['code'], 'amount' => $u];
            }
        }
        if (count(array_unique(array_column($items, 'component_id'))) !== count($items)) {
            $errors['items'] = 'payroll.error.item_twice';
        }
        $reason = trim((string) ($in['reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $crypto = $this->app->crypto();
        [$enc, $last4, $bankCode] = $acc !== ''
            ? [$crypto->encrypt($acc), substr($acc, -4), $bank]
            : [$current['account_enc'] ?? null, $current['account_last4'] ?? null, $current['bank_code'] ?? null];
        $db->transactional(function () use ($db, $pid, $now, $az, $e, $le, $from, $basis, $amount, $bankCode, $enc, $last4, $items, $reason, $acc, $retro): void {
            $db->run(
                "INSERT INTO pay_profiles (public_id, created_at, created_by, updated_at, updated_by, employee_id, legal_entity_id, effective_from, pay_basis, base_amount, bank_code, account_enc, account_last4, items_json, status, reason, retro, proposed_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)",
                [$pid, $now, $az->userId, $now, $az->userId, $e['id'], $le, $from, $basis, Money::toDb((int) $amount), $bankCode, $enc, $last4,
                    $items === [] ? null : json_encode(array_map(static fn (array $x): array => ['component_id' => $x['component_id'], 'code' => $x['code'], 'amount' => Money::toDb($x['amount'])], $items)), $reason, $retro, $az->userId]
            );
            $title = $this->app->translator()->get('payroll.profile_title', ['code' => (string) $e['employee_code']]);
            $inst = $this->app->workflow()->start('payroll.profile', $pid, $title, $le, $az->userId);
            $db->run('UPDATE pay_profiles SET workflow_instance_pid = ? WHERE public_id = ?', [$inst, $pid]);
            // no amounts or account numbers in the audit trail — only what changed
            $this->audit('payroll.profile_proposed', 'pay_profile', $pid, $le, $reason, ['employee' => (string) $e['public_id'], 'from' => $from, 'basis' => $basis, 'bank_changed' => $acc !== '', 'retro' => $retro === 1]);
        });
        return $pid;
    }

    /** @return array<string, mixed> */
    public function profile(string $pid): array
    {
        return $this->app->db()->one('SELECT p.*, e.employee_code, e.name_th, e.public_id AS employee_pid FROM pay_profiles p JOIN employees e ON e.id = p.employee_id WHERE p.public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    public function onProfileApproved(string $pid, int $approverId): void
    {
        $db = $this->app->db();
        $p = $this->profile($pid);
        if ($p['status'] !== 'pending') {
            return;
        }
        if ((int) $p['proposed_by'] === $approverId) {
            throw new BusinessException('workflow.error.self_approval', [], 403);
        }
        if ($this->pendingRunCovers((int) $p['legal_entity_id'], (string) $p['effective_from'])) {
            throw new BusinessException('payroll.error.in_pending_run', [], 409);
        }
        if ((int) $p['retro'] !== 1 && $this->lockedRunCovers((int) $p['legal_entity_id'], (string) $p['effective_from'])) {
            throw new BusinessException('payroll.error.in_approved_run', [], 409);
        }
        $db->run("UPDATE pay_profiles SET status = 'approved', decided_by = ?, decided_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$approverId, $this->now(), $this->now(), $p['id']]);
        $this->app->audit()->record('payroll.profile_approved', 'success', 'pay_profile', $pid, (int) $p['legal_entity_id'], null, ['employee' => (string) $p['employee_pid']], $approverId);
        $this->staleRuns((int) $p['legal_entity_id'], 'profile');
    }

    public function onProfileClosed(string $pid, string $outcome): void
    {
        $this->app->db()->run("UPDATE pay_profiles SET status = ?, updated_at = ?, row_version = row_version + 1 WHERE public_id = ? AND status = 'pending'", [$outcome === 'cancelled' ? 'cancelled' : 'rejected', $this->now(), $pid]);
    }

    /** an approved run of the company covers this day or a later one (its pay is paid — a change reaches back: retro, 6F) */
    private function lockedRunCovers(int $le, string $date): bool
    {
        return $this->app->db()->scalar("SELECT id FROM pay_runs WHERE legal_entity_id = ? AND status = 'approved' AND run_type <> 'off_cycle' AND period_end >= ? LIMIT 1", [$le, $date]) !== null;
    }

    /** a run waiting for approval covers this day — no pay change may slip under it */
    private function pendingRunCovers(int $le, string $date): bool
    {
        return $this->app->db()->scalar("SELECT id FROM pay_runs WHERE legal_entity_id = ? AND status = 'pending' AND run_type <> 'off_cycle' AND period_start <= ? AND period_end >= ? LIMIT 1", [$le, $date, $date]) !== null;
    }

    /**
     * The bank account on the person's approved pay profile in force on a day (for other modules that pay people, e.g.
     * welfare claims). The account number is returned in clear for a payment batch only — never shown or logged.
     *
     * @return array{bank_code: string, account_no: string, last4: string}|null
     */
    public function bankAccount(int $employeeId, int $le, string $date): ?array
    {
        $p = $this->profileAt($employeeId, $le, $date);
        $acc = $p !== null ? $this->account($p) : null;
        return $p !== null && $acc !== null && $p['bank_code'] !== null ? ['bank_code' => (string) $p['bank_code'], 'account_no' => $acc, 'last4' => (string) $p['account_last4']] : null;
    }

    /**
     * decrypted account of a profile — only for the payment batch (never shown or logged)
     *
     * @param array<string, mixed> $profile
     */
    private function account(array $profile): ?string
    {
        return $profile['account_enc'] !== null ? $this->app->crypto()->decrypt((string) $profile['account_enc']) : null;
    }

    // ---- rules (Config Registry) --------------------------------------------------------------------------------------------

    /** @return array{policy: ?ResolvedConfig, sso: ?ResolvedConfig, wht: ?ResolvedConfig} values in force for a company on a day */
    public function rules(int $le, string $date): array
    {
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' 12:00:00', $this->tz()) ?: $this->app->clock->now();
        $reg = $this->app->registry();
        return ['policy' => $reg->resolveOrNull('payroll.policy', $le, null, $at), 'sso' => $reg->resolveOrNull('payroll.sso', $le, null, $at), 'wht' => $reg->resolveOrNull('payroll.wht', $le, null, $at)];
    }

    public const RULE_KEYS = ['payroll.policy', 'payroll.sso', 'payroll.wht', 'payroll.wht_payer', 'payroll.sso_employer', 'payroll.filing_due'];

    /** @param array<string, mixed> $in */
    public function proposeRules(Authorizer $az, int $le, string $key, array $in, string $from, string $source, string $reason): string
    {
        $this->require($az, 'payroll.prepare', $le);
        if (!in_array($key, self::RULE_KEYS, true)) {
            throw HttpException::notFound();
        }
        [$value, $errors] = match ($key) {
            'payroll.policy' => PayrollRules::validatePolicy($in),
            'payroll.sso' => PayrollRules::validateSso($in),
            'payroll.wht' => TaxRules::validateWht($in),
            'payroll.sso_employer' => TaxRules::validateSsoEmployer($in),
            'payroll.filing_due' => TaxRules::validateFilingDue($in),
            default => TaxRules::validatePayer($in),
        };
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $this->tz());
        if ($d === false || $d->format('Y-m-d') !== $from) {
            $errors['effective_from'] = 'payroll.error.date';
        }
        if (mb_strlen(trim($source)) < 5) {
            $errors['source_reference'] = 'payroll.rules_error.source';
        }
        if (mb_strlen(trim($reason)) < 3) {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ($errors !== [] || $value === null || $d === false) {
            throw new ValidationException($errors);
        }
        $reg = $this->app->registry();
        $pid = $reg->propose($az, $key, 'legal_entity', $le, (string) json_encode($value, JSON_UNESCAPED_UNICODE), $d, null, trim($reason), trim($source));
        $reg->submit($az, $pid);
        return $pid;
    }

    // ---- runs ------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function runs(int $le): array
    {
        return $this->app->db()->all('SELECT * FROM pay_runs WHERE legal_entity_id = ? ORDER BY period_start DESC, run_no DESC LIMIT 60', [$le]);
    }

    public const RUN_TYPES = ['regular', 'off_cycle', 'correction'];

    /**
     * A new run. Regular: one per period (the finalized time period that matches is picked up). Off-cycle (6F): e.g. a
     * bonus — any period (the month the money belongs to), may share it with the regular run, needs a purpose.
     *
     * @param array<string, mixed> $in label, period_start, period_end, pay_date, type (regular|off_cycle), purpose
     */
    public function createRun(Authorizer $az, int $le, array $in): string
    {
        $this->require($az, 'payroll.prepare', $le);
        $type = (string) ($in['type'] ?? 'regular') === 'off_cycle' ? 'off_cycle' : 'regular';
        $purpose = trim((string) ($in['purpose'] ?? ''));
        $errors = [];
        if ($type === 'off_cycle' && (mb_strlen($purpose) < 5 || mb_strlen($purpose) > 300)) {
            $errors['purpose'] = 'payroll.error.purpose';
        }
        foreach (['period_start', 'period_end', 'pay_date'] as $f) {
            if (!self::isDate((string) ($in[$f] ?? ''))) {
                $errors[$f] = 'payroll.error.date';
            }
        }
        if ($errors === [] && ($in['period_end'] < $in['period_start'] || self::days((string) $in['period_start'], (string) $in['period_end']) > 31)) {
            $errors['period_end'] = 'payroll.error.range';
        }
        if ($errors === [] && $in['pay_date'] < $in['period_start']) {
            $errors['pay_date'] = 'payroll.error.pay_date';
        }
        $label = trim((string) ($in['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 120) {
            $errors['label'] = 'payroll.error.label';
        }
        $db = $this->app->db();
        if ($errors === [] && $type === 'regular' && $db->scalar("SELECT id FROM pay_runs WHERE legal_entity_id = ? AND run_type = 'regular' AND status <> 'cancelled' AND period_start <= ? AND period_end >= ? LIMIT 1", [$le, $in['period_end'], $in['period_start']]) !== null) {
            $errors['period_start'] = 'payroll.error.overlap';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $pid = Ulid::generate($this->app->clock);
        $db->transactional(function () use ($db, $pid, $az, $le, $in, $label, $type, $purpose): void {
            $no = (int) $db->scalar('SELECT COALESCE(MAX(run_no), 0) + 1 FROM pay_runs WHERE legal_entity_id = ? FOR UPDATE', [$le]);
            $now = $this->now();
            $db->run(
                "INSERT INTO pay_runs (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, run_no, run_type, label, purpose, period_start, period_end, pay_date, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')",
                [$pid, $now, $az->userId, $now, $az->userId, $le, $no, $type, $label, $type === 'off_cycle' ? $purpose : null, $in['period_start'], $in['period_end'], $in['pay_date']]
            );
            $id = (int) $db->pdo()->lastInsertId();
            // the finalized time period that matches exactly is picked up by itself (regular runs only)
            $att = $type === 'regular' ? $db->one("SELECT id, revision FROM att_periods WHERE legal_entity_id = ? AND status = 'finalized' AND period_start = ? AND period_end = ?", [$le, $in['period_start'], $in['period_end']]) : null;
            if ($att !== null) {
                $db->run('UPDATE pay_runs SET att_period_id = ?, att_revision = ? WHERE id = ?', [$att['id'], $att['revision'], $id]);
            }
            $this->event($id, 'created', $az->userId);
            $this->audit('payroll.run_created', 'pay_run', $pid, $le, null, ['period' => $in['period_start'] . '..' . $in['period_end'], 'type' => $type]);
        });
        return $pid;
    }

    /**
     * A correction run of an approved run (6F, DEC-87): same period, recalculated with today's approved inputs (latest
     * finalized time revision, pay profiles incl. retroactive changes, the same OT batches, manual amounts and welfare
     * already paid); it pays only the difference. One open correction per run at a time.
     *
     * @param array<string, mixed> $in pay_date, purpose, label (optional)
     */
    public function createCorrection(Authorizer $az, string $originalPid, array $in): string
    {
        $o = $this->run($az, $originalPid);
        $le = (int) $o['legal_entity_id'];
        $this->require($az, 'payroll.prepare', $le);
        if ($o['status'] !== 'approved' || $o['run_type'] !== 'regular') {
            throw new BusinessException('payroll.error.correct_approved_only', [], 409);
        }
        $db = $this->app->db();
        if ($db->scalar("SELECT id FROM pay_runs WHERE corrects_run_id = ? AND status IN ('draft','calculated','pending') LIMIT 1", [$o['id']]) !== null) {
            throw new BusinessException('payroll.error.correction_open', [], 409);
        }
        $errors = [];
        $pay = (string) ($in['pay_date'] ?? '');
        if (!self::isDate($pay) || $pay < (string) $o['pay_date']) {
            $errors['pay_date'] = 'payroll.error.correction_pay_date';
        }
        $purpose = trim((string) ($in['purpose'] ?? ''));
        if (mb_strlen($purpose) < 5 || mb_strlen($purpose) > 300) {
            $errors['purpose'] = 'payroll.error.purpose';
        }
        $label = trim((string) ($in['label'] ?? ''));
        $label = $label !== '' ? mb_substr($label, 0, 120) : mb_substr($this->app->translator()->get('payroll.correction_label', ['label' => (string) $o['label']]), 0, 120);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $pid = Ulid::generate($this->app->clock);
        $db->transactional(function () use ($db, $pid, $az, $le, $o, $pay, $purpose, $label): void {
            $no = (int) $db->scalar('SELECT COALESCE(MAX(run_no), 0) + 1 FROM pay_runs WHERE legal_entity_id = ? FOR UPDATE', [$le]);
            $now = $this->now();
            $att = $db->one("SELECT id, revision FROM att_periods WHERE legal_entity_id = ? AND status = 'finalized' AND period_start = ? AND period_end = ? ORDER BY revision DESC LIMIT 1", [$le, $o['period_start'], $o['period_end']]);
            $db->run(
                "INSERT INTO pay_runs (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, run_no, run_type, corrects_run_id, label, purpose, period_start, period_end, pay_date,
                    att_period_id, att_revision, ot_batch_ids, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'correction', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')",
                [$pid, $now, $az->userId, $now, $az->userId, $le, $no, $o['id'], $label, $purpose, $o['period_start'], $o['period_end'], $pay, $att['id'] ?? null, $att['revision'] ?? null, $o['ot_batch_ids']]
            );
            $id = (int) $db->pdo()->lastInsertId();
            $this->event($id, 'created', $az->userId, $purpose);
            $this->event((int) $o['id'], 'correction_created', $az->userId, $label);
            $this->audit('payroll.correction_created', 'pay_run', $pid, $le, $purpose, ['corrects' => (string) $o['public_id']]);
        });
        return $pid;
    }

    /**
     * The runs that pay one period's regular pay: the original and its approved corrections (oldest first).
     *
     * @param array<string, mixed> $r a correction run or an original
     * @return list<array<string, mixed>>
     */
    public function family(array $r): array
    {
        $origId = $r['run_type'] === 'correction' ? (int) $r['corrects_run_id'] : (int) $r['id'];
        return $this->app->db()->all(
            "SELECT * FROM pay_runs WHERE (id = ? OR corrects_run_id = ?) AND status = 'approved' ORDER BY approved_at, id",
            [$origId, $origId]
        );
    }

    /**
     * Why an approved run may need a correction: pay profiles changed back into its period after it was approved
     * (retro), or a newer revision of its time period — unless an approved correction came after that.
     *
     * @param array<string, mixed> $r
     * @return list<array{key: string, params: array<string, string>}>
     */
    public function correctionReasons(array $r): array
    {
        if ($r['status'] !== 'approved' || $r['run_type'] !== 'regular') {
            return [];
        }
        $db = $this->app->db();
        $last = (string) ($db->scalar("SELECT MAX(approved_at) FROM pay_runs WHERE corrects_run_id = ? AND status = 'approved'", [$r['id']]) ?? $r['approved_at']);
        $out = [];
        foreach (
            $db->all(
                "SELECT p.effective_from, e.employee_code FROM pay_profiles p JOIN employees e ON e.id = p.employee_id
                  WHERE p.legal_entity_id = ? AND p.status = 'approved' AND p.retro = 1 AND p.effective_from <= ? AND p.decided_at > ? ORDER BY e.employee_code",
                [$r['legal_entity_id'], $r['period_end'], $last]
            ) as $p
        ) {
            $out[] = ['key' => 'payroll.correct_reason.profile', 'params' => ['code' => (string) $p['employee_code'], 'from' => (string) $p['effective_from']]];
        }
        if ($r['att_period_id'] !== null) {
            $rev = $db->scalar("SELECT MAX(revision) FROM att_periods WHERE legal_entity_id = ? AND status = 'finalized' AND period_start = ? AND period_end = ?", [$r['legal_entity_id'], $r['period_start'], $r['period_end']]);
            $used = (int) ($db->scalar("SELECT MAX(att_revision) FROM pay_runs WHERE (id = ? OR corrects_run_id = ?) AND status = 'approved'", [$r['id'], $r['id']]) ?? 0);
            if ($rev !== null && (int) $rev > $used) {
                $out[] = ['key' => 'payroll.correct_reason.time', 'params' => ['rev' => (string) $rev]];
            }
        }
        return $out;
    }

    /**
     * Warnings about the whole run from its latest calculation (no policy, OT or welfare without a person, off-cycle,
     * correction without tax) — the part after "payroll.warn.".
     *
     * @param array<string, mixed> $r
     * @return list<string>
     */
    public function runWarnings(array $r): array
    {
        if ($r['calc_run_pid'] === null) {
            return [];
        }
        $json = $this->app->db()->scalar('SELECT warnings_json FROM calculation_runs WHERE public_id = ?', [$r['calc_run_pid']]);
        $out = [];
        foreach ((array) json_decode((string) ($json ?? '[]'), true) as $k) {
            if (is_string($k) && str_starts_with($k, 'payroll.warn.')) {
                $out[] = substr($k, strlen('payroll.warn.'));
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function row(string $pid): array
    {
        return $this->app->db()->one('SELECT r.*, l.code AS le_code FROM pay_runs r JOIN legal_entities l ON l.id = r.legal_entity_id WHERE r.public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /** @return array<string, mixed> a run the person may see */
    public function run(Authorizer $az, string $pid): array
    {
        $r = $this->row($pid);
        if (!$az->scope('payroll.view')->coversLegalEntity((int) $r['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        $r['can_prepare'] = $az->scope('payroll.prepare')->coversLegalEntity((int) $r['legal_entity_id']);
        $r['ot_ids'] = array_values(array_filter(array_map('intval', explode(',', (string) ($r['ot_batch_ids'] ?? '')))));
        $r['welfare_ids'] = array_values(array_filter(array_map('intval', explode(',', (string) ($r['welfare_batch_ids'] ?? '')))));
        return $r;
    }

    /** @param array<string, mixed> $r */
    private function editable(array $r): void
    {
        if (!in_array($r['status'], ['draft', 'calculated'], true)) {
            throw new BusinessException('payroll.error.not_editable', [], 409);
        }
    }

    /** any change after calculating → back to draft (must calculate again) */
    private function touch(int $runId): void
    {
        $this->app->db()->run("UPDATE pay_runs SET status = 'draft', updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'calculated'", [$this->now(), $runId]);
    }

    /** a profile / rule changed: open calculated runs of the company must be calculated again */
    public function staleRuns(int $le, string $why): void
    {
        $this->app->db()->run("UPDATE pay_runs SET status = 'draft', updated_at = ? WHERE legal_entity_id = ? AND status = 'calculated'", [$this->now(), $le]);
    }

    /**
     * @param array<string, mixed> $r
     * @return array{periods: list<array<string, mixed>>, batches: list<array<string, mixed>>, welfare: list<array<string, mixed>>} what the run may use
     */
    public function sourceChoices(array $r): array
    {
        $db = $this->app->db();
        // welfare payout batches (M37) not taken by another run that is still open or approved
        $welfare = $db->tableExists('wf_batches') ? $db->all(
            "SELECT b.id, b.public_id, b.batch_no, b.claim_count, b.total_amount, b.created_at FROM wf_batches b
              WHERE b.legal_entity_id = ? AND b.method = 'payroll' AND b.status IN ('ready','sent')
                AND NOT EXISTS (SELECT 1 FROM pay_runs o WHERE o.legal_entity_id = b.legal_entity_id AND o.id <> ? AND o.status <> 'cancelled' AND FIND_IN_SET(b.id, o.welfare_batch_ids) > 0)
              ORDER BY b.batch_no",
            [$r['legal_entity_id'], $r['id']]
        ) : [];
        if ($r['run_type'] === 'off_cycle') {
            return ['periods' => [], 'batches' => [], 'welfare' => $welfare];
        }
        if ($r['run_type'] === 'correction') {
            return ['periods' => $db->all(
                "SELECT id, public_id, period_start, period_end, label, revision FROM att_periods WHERE legal_entity_id = ? AND status = 'finalized' AND period_start = ? AND period_end = ? ORDER BY revision DESC",
                [$r['legal_entity_id'], $r['period_start'], $r['period_end']]
            ), 'batches' => [], 'welfare' => []];
        }
        return [
            'welfare' => $welfare,
            'periods' => $db->all(
                "SELECT id, public_id, period_start, period_end, label, revision FROM att_periods WHERE legal_entity_id = ? AND status = 'finalized' AND period_start <= ? AND period_end >= ? ORDER BY period_start",
                [$r['legal_entity_id'], $r['period_end'], $r['period_start']]
            ),
            'batches' => $db->all(
                'SELECT id, public_id, batch_no, period_start, period_end, line_count, total_minutes FROM ot_batches WHERE legal_entity_id = ? AND period_start <= ? AND period_end >= ? ORDER BY batch_no',
                [$r['legal_entity_id'], $r['period_end'], $r['period_start']]
            ),
        ];
    }

    /**
     * @param list<string> $batchPids
     * @param list<string> $welfarePids
     */
    public function setSources(Authorizer $az, string $pid, string $periodPid, array $batchPids, array $welfarePids = []): void
    {
        $r = $this->run($az, $pid);
        $this->require($az, 'payroll.prepare', (int) $r['legal_entity_id']);
        $this->editable($r);
        $choices = $this->sourceChoices($r);
        $period = null;
        if ($periodPid !== '') {
            foreach ($choices['periods'] as $p) {
                if ($p['public_id'] === $periodPid) {
                    $period = $p;
                }
            }
            if ($period === null) {
                throw new ValidationException(['att_period' => 'form.invalid_choice']);
            }
        }
        $ids = [];
        foreach ($choices['batches'] as $b) {
            if (in_array($b['public_id'], $batchPids, true)) {
                $ids[] = (int) $b['id'];
            }
        }
        if (count($ids) !== count(array_unique($batchPids))) {
            throw new ValidationException(['ot_batches' => 'form.invalid_choice']);
        }
        if ($r['run_type'] === 'correction') {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($r['ot_batch_ids'] ?? ''))))); // the original's OT stays
        }
        $wids = [];
        foreach ($choices['welfare'] as $b) {
            if (in_array($b['public_id'], $welfarePids, true)) {
                $wids[] = (int) $b['id'];
            }
        }
        if (count($wids) !== count(array_unique($welfarePids))) {
            throw new ValidationException(['welfare_batches' => 'form.invalid_choice']);
        }
        $this->app->db()->run(
            'UPDATE pay_runs SET att_period_id = ?, att_revision = ?, ot_batch_ids = ?, welfare_batch_ids = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$period['id'] ?? null, $period['revision'] ?? null, $ids === [] ? null : implode(',', $ids), $wids === [] ? null : implode(',', $wids), $this->now(), $az->userId, $r['id']]
        );
        $this->touch((int) $r['id']);
        $this->event((int) $r['id'], 'sources', $az->userId);
    }

    /** @return list<array<string, mixed>> */
    public function manualEntries(int $runId): array
    {
        return $this->app->db()->all(
            'SELECT m.*, e.employee_code, e.name_th, e.public_id AS employee_pid, c.code AS component_code, c.name_th AS component_name, c.kind FROM pay_manual_entries m
               JOIN employees e ON e.id = m.employee_id JOIN pay_components c ON c.id = m.component_id WHERE m.run_id = ? ORDER BY e.employee_code, c.sort_order',
            [$runId]
        );
    }

    /** @param array<string, mixed> $in employee (public id), component (public id), amount, note */
    public function addManual(Authorizer $az, string $pid, array $in): void
    {
        $r = $this->run($az, $pid);
        $le = (int) $r['legal_entity_id'];
        $this->require($az, 'payroll.prepare', $le);
        $this->editable($r);
        $db = $this->app->db();
        $errors = [];
        $emp = $db->scalar('SELECT id FROM employees WHERE public_id = ?', [(string) ($in['employee'] ?? '')]);
        $inRun = $emp !== null && in_array((int) $emp, array_map(static fn (array $s): int => (int) $s['id'], $this->eligible($r)), true);
        if (!$inRun) {
            $errors['employee'] = 'payroll.error.employee';
        }
        $comp = $db->one("SELECT * FROM pay_components WHERE public_id = ? AND legal_entity_id = ? AND status = 'active' AND method = 'manual'", [(string) ($in['component'] ?? ''), $le]);
        if ($comp === null) {
            $errors['component'] = 'form.invalid_choice';
        }
        $amount = Money::parse((string) ($in['amount'] ?? ''), 2);
        if ($amount === null || $amount <= 0) {
            $errors['amount'] = 'payroll.error.amount';
        }
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) < 3 || mb_strlen($note) > 255) {
            $errors['note'] = 'payroll.error.note';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $now = $this->now();
        $epid = Ulid::generate($this->app->clock);
        $db->run(
            'INSERT INTO pay_manual_entries (public_id, created_at, created_by, updated_at, updated_by, run_id, employee_id, component_id, amount, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$epid, $now, $az->userId, $now, $az->userId, $r['id'], $emp, $comp['id'], Money::toDb((int) $amount), $note]
        );
        $this->touch((int) $r['id']);
        $this->event((int) $r['id'], 'manual_added', $az->userId, (string) $comp['code']);
    }

    public function removeManual(Authorizer $az, string $pid, string $entryPid): void
    {
        $r = $this->run($az, $pid);
        $this->require($az, 'payroll.prepare', (int) $r['legal_entity_id']);
        $this->editable($r);
        $n = $this->app->db()->run('DELETE FROM pay_manual_entries WHERE public_id = ? AND run_id = ?', [$entryPid, $r['id']])->rowCount();
        if ($n === 0) {
            throw HttpException::notFound();
        }
        $this->touch((int) $r['id']);
        $this->event((int) $r['id'], 'manual_removed', $az->userId);
    }

    /**
     * People paid by this run: employed by the company at some point of the period (latest employment row inside it).
     *
     * @param array<string, mixed> $r
     * @return list<array<string, mixed>>
     */
    private function eligible(array $r): array
    {
        $rows = $this->app->db()->all(
            "SELECT e.id, e.public_id, e.employee_code, e.name_th, e.hire_date, e.termination_date, h.site_id, h.department_id, h.effective_from
               FROM employees e JOIN employment_history h ON h.employee_id = e.id
              WHERE h.legal_entity_id = ? AND h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?)
                AND e.status <> 'pre_hire' AND e.hire_date <= ? AND (e.termination_date IS NULL OR e.termination_date > ?)
              ORDER BY e.employee_code, h.effective_from DESC",
            [$r['legal_entity_id'], $r['period_end'], $r['period_start'], $r['period_end'], $r['period_start']]
        );
        $out = [];
        foreach ($rows as $x) {
            $out[(int) $x['id']] ??= $x;
        }
        return array_values($out);
    }

    /**
     * Gathers the inputs of a run (people, profiles, time, OT, manual amounts, components).
     *
     * @param array<string, mixed> $r
     * @return array{inputs: array<string, mixed>, configs: list<ResolvedConfig>, values: array<string, mixed>}
     */
    private function gather(array $r): array
    {
        $db = $this->app->db();
        $le = (int) $r['legal_entity_id'];
        $start = (string) $r['period_start'];
        $end = (string) $r['period_end'];
        $components = array_map(static fn (array $c): array => ['id' => (int) $c['id'], 'code' => (string) $c['code'], 'kind' => (string) $c['kind'], 'method' => (string) $c['method'],
            'taxable' => (int) $c['taxable'], 'sso_base' => (int) $c['sso_base'], 'statutory' => (string) $c['statutory']], $this->components($le, true));
        // time: the finalized snapshot, checked against its hash
        $time = [];
        $timeRef = null;
        if ($r['att_period_id'] !== null && $r['run_type'] !== 'off_cycle') {
            $p = $db->one('SELECT * FROM att_periods WHERE id = ?', [$r['att_period_id']]);
            if ($p === null || $p['status'] !== 'finalized' || (int) $p['revision'] !== (int) $r['att_revision']) {
                throw new BusinessException('payroll.error.time_changed', [], 409);
            }
            $snap = \Blue\Modules\Attendance\PeriodService::of($this->app)->snapshot($p);
            if (!$snap['intact']) {
                throw new BusinessException('payroll.error.time_damaged', [], 409);
            }
            $timeRef = (string) $p['snapshot_run_pid'];
            foreach ($snap['lines'] as $l) {
                $time[(string) $l['employee_code']] = ['worked_days' => (string) (int) $l['worked_days'], 'absent_days' => (string) (int) $l['absent_days'],
                    'holiday_days' => (string) (int) $l['holiday_days'], 'leave_days' => number_format((float) $l['leave_days'], 2, '.', '')];
            }
        }
        // OT: frozen batch lines, checked against their hash
        $ot = [];
        $otHashes = [];
        foreach (array_values(array_filter(array_map('intval', explode(',', (string) ($r['ot_batch_ids'] ?? ''))))) as $bid) {
            $b = $db->one('SELECT * FROM ot_batches WHERE id = ?', [$bid]) ?? throw new BusinessException('payroll.error.ot_missing', [], 409);
            if (!hash_equals((string) $b['lines_hash'], hash('sha256', (string) $b['lines_json']))) {
                throw new BusinessException('payroll.error.ot_damaged', [], 409);
            }
            $otHashes[(string) $b['public_id']] = (string) $b['lines_hash'];
            foreach ((array) json_decode((string) $b['lines_json'], true) as $l) {
                if ((string) $l['work_date'] >= $start && (string) $l['work_date'] <= $end) {
                    $ot[(string) $l['employee_code']][] = ['multiplier' => (string) $l['multiplier'], 'minutes' => (int) $l['payable_minutes'], 'batch' => 'B' . $b['batch_no']];
                }
            }
        }
        // welfare (M37): frozen batch lines, checked against their hash
        $welfare = [];
        $welfareHashes = [];
        foreach (array_values(array_filter(array_map('intval', explode(',', (string) ($r['welfare_batch_ids'] ?? ''))))) as $bid) {
            $b = $db->one('SELECT * FROM wf_batches WHERE id = ?', [$bid]) ?? throw new BusinessException('payroll.error.welfare_missing', [], 409);
            if (!hash_equals((string) $b['lines_hash'], hash('sha256', (string) $b['lines_json'])) || in_array($b['status'], ['closed'], true)) {
                throw new BusinessException('payroll.error.welfare_damaged', [], 409);
            }
            $welfareHashes[(string) $b['public_id']] = (string) $b['lines_hash'];
            foreach ((array) json_decode((string) $b['lines_json'], true) as $l) {
                $welfare[(string) $l['employee_code']][] = ['code' => (string) $l['component_code'], 'amount' => Money::fromDb($l['amount']), 'ref' => (string) $l['claim_no'], 'note' => (string) $b['batch_no'] . ' · ' . $l['type']];
            }
        }
        $manual = [];
        foreach ($this->manualEntries((int) $r['id']) as $m) {
            $manual[(int) $m['employee_id']][] = ['component_id' => (int) $m['component_id'], 'code' => (string) $m['component_code'], 'amount' => Money::fromDb($m['amount']), 'note' => (string) $m['note'], 'ref' => (string) $m['public_id']];
        }
        // correction (6F): recalculate with everything the period already paid — the manual amounts and welfare of the
        // original and its earlier corrections — and compare with what those runs paid, per person and component
        $mode = (string) ($r['run_type'] ?? 'regular');
        $original = [];
        $family = [];
        $correctionOf = '';
        if ($mode === 'correction') {
            $family = $this->family($r);
            $correctionOf = (string) (($family[0]['label'] ?? ''));
            foreach ($family as $f) {
                foreach ($this->manualEntries((int) $f['id']) as $m) {
                    $manual[(int) $m['employee_id']][] = ['component_id' => (int) $m['component_id'], 'code' => (string) $m['component_code'], 'amount' => Money::fromDb($m['amount']), 'note' => (string) $m['note'], 'ref' => (string) $m['public_id']];
                }
            }
            if ($family !== []) {
                $ids = implode(',', array_map(static fn (array $f): int => (int) $f['id'], $family));
                foreach (
                    $db->all(
                        "SELECT e.employee_code, l.component_id, l.component_code, l.kind, l.amount, l.source_type, l.source_ref, l.note FROM pay_result_lines l
                           JOIN pay_run_employees re ON re.id = l.run_employee_id JOIN employees e ON e.id = re.employee_id WHERE re.run_id IN ({$ids}) ORDER BY l.id"
                    ) as $l
                ) {
                    $code = (string) $l['employee_code'];
                    $original[$code][(string) $l['component_code']] ??= ['amount' => 0, 'kind' => (string) $l['kind'], 'component_id' => (int) $l['component_id']];
                    $original[$code][(string) $l['component_code']]['amount'] += Money::fromDb($l['amount']);
                    if ($l['source_type'] === 'welfare') {
                        $welfare[$code][] = ['code' => (string) $l['component_code'], 'amount' => Money::fromDb($l['amount']), 'ref' => (string) $l['source_ref'], 'note' => (string) $l['note']];
                    }
                }
            }
        }
        $periodDays = self::days($start, $end);
        $employees = [];
        $staffCodes = [];
        $familyRefs = array_map(static fn (array $f): string => (string) $f['public_id'], $family);
        $taxRun = $mode === 'correction' && $family !== [] ? $family[0] : $r; // the month being recalculated
        // a correction keeps the tax position the original month was worked out with (year to date, allowances), so its
        // tax difference comes only from the pay that changed; later payments are taken up by the next regular run
        $origTax = [];
        $workOutTax = true;
        if ($mode === 'correction' && $family !== [] && $family[0]['calc_run_pid'] !== null) {
            $origSnap = $this->app->ledger()->open((string) $family[0]['calc_run_pid']);
            // tax is worked out only if the original month worked it out (its rule set had a withholding tax rule)
            $workOutTax = in_array('payroll.wht', array_map(static fn (array $c): string => (string) $c['config_key'], $origSnap['config_versions']), true);
            foreach ((array) ($origSnap['inputs']['employees'] ?? []) as $oe) {
                if (isset($oe['tax']) && is_array($oe['tax'])) {
                    $origTax[(string) $oe['code']] = $oe['tax'];
                }
            }
        }
        foreach ($this->eligible($r) as $e) {
            if ($mode === 'off_cycle' && !isset($manual[(int) $e['id']]) && !isset($welfare[(string) $e['employee_code']])) {
                continue; // an off-cycle run pays only the people it has amounts for
            }
            $staffCodes[(string) $e['employee_code']] = true;
            $p = $this->profileAt((int) $e['id'], $le, $end);
            $first = max($start, (string) $e['hire_date']);
            $last = $e['termination_date'] !== null ? min($end, self::ymd((string) $e['termination_date'])->modify('-1 day')->format('Y-m-d')) : $end;
            $profile = null;
            if ($p !== null) {
                $items = [];
                foreach ((array) json_decode((string) ($p['items_json'] ?? '[]'), true) as $it) {
                    $items[] = ['component_id' => (int) $it['component_id'], 'code' => (string) $it['code'], 'amount' => Money::fromDb($it['amount'])];
                }
                $profile = ['pid' => (string) $p['public_id'], 'basis' => (string) $p['pay_basis'], 'base' => Money::fromDb($p['base_amount']), 'items' => $items,
                    'has_bank' => $p['account_enc'] !== null, 'effective_from' => (string) $p['effective_from'], 'changed_in_period' => (string) $p['effective_from'] > $start];
            }
            $employees[] = ['employee_id' => (int) $e['id'], 'code' => (string) $e['employee_code'], 'name' => (string) $e['name_th'], 'site_id' => $e['site_id'] !== null ? (int) $e['site_id'] : null,
                'days_employed' => max(0, self::days($first, $last)), 'profile' => $profile, 'time' => $time[(string) $e['employee_code']] ?? null, 'time_ref' => $timeRef,
                'ot' => $ot[(string) $e['employee_code']] ?? [], 'manual' => $manual[(int) $e['id']] ?? [], 'welfare' => $welfare[(string) $e['employee_code']] ?? [], 'tax' => $origTax[(string) $e['employee_code']] ?? $this->taxInputs((int) $e['id'], $le, $taxRun, $familyRefs)];
        }
        $rules = $this->rules($le, $end);
        $configs = array_values(array_filter([$rules['policy'], $rules['sso'], $rules['wht']]));
        $values = ['payroll.policy' => $rules['policy']?->value, 'payroll.sso' => $rules['sso']?->value, 'payroll.wht' => $rules['wht']?->value];
        $inputs = ['mode' => $mode, 'period' => ['start' => $start, 'end' => $end, 'days' => $periodDays, 'pay_date' => (string) $r['pay_date']], 'components' => $components, 'employees' => $employees,
            'original' => $original, 'correction_of' => $correctionOf, 'work_out_tax' => $workOutTax,
            'has_time' => $timeRef !== null, 'time_snapshot' => $timeRef, 'ot_batches' => $otHashes, 'welfare_batches' => $welfareHashes,
            'ot_without_staff' => array_values(array_diff(array_keys($ot), array_keys($staffCodes))),
            'welfare_without_staff' => array_values(array_diff(array_keys($welfare), array_keys($staffCodes)))];
        return ['inputs' => $inputs, 'configs' => $configs, 'values' => $values];
    }

    /**
     * What the tax calculation needs about a person (6C): year-to-date before this run, the month, the approved
     * allowance declaration of the tax year.
     *
     * @param array<string, mixed> $r
     * @param list<string> $excludeRefs year-to-date entries left out as well (a correction: the runs of the month it recalculates)
     * @return array<string, mixed>
     */
    private function taxInputs(int $employeeId, int $le, array $r, array $excludeRefs = []): array
    {
        $db = $this->app->db();
        $year = (int) substr((string) $r['pay_date'], 0, 4);
        $ex = array_values(array_unique(array_merge([(string) $r['public_id']], $excludeRefs)));
        $y = $db->one(
            "SELECT COALESCE(SUM(taxable_income), 0) AS t, COALESCE(SUM(wht), 0) AS w, COALESCE(SUM(sso_employee), 0) AS s FROM pay_ytd_entries
              WHERE employee_id = ? AND legal_entity_id = ? AND tax_year = ? AND status = 'approved' AND as_of <= ? AND source_ref NOT IN (" . implode(',', array_fill(0, count($ex), '?')) . ')',
            [$employeeId, $le, $year, $r['pay_date'], ...$ex]
        ) ?? [];
        $decl = $db->one("SELECT items_json FROM pay_tax_declarations WHERE employee_id = ? AND legal_entity_id = ? AND tax_year = ? AND status = 'approved' ORDER BY id DESC LIMIT 1", [$employeeId, $le, $year]);
        return ['ytd_taxable' => Money::fromDb($y['t'] ?? 0), 'ytd_wht' => Money::fromDb($y['w'] ?? 0), 'ytd_sso' => Money::fromDb($y['s'] ?? 0), 'month' => (int) substr((string) $r['pay_date'], 5, 2),
            'declared' => $decl !== null ? array_values((array) json_decode((string) $decl['items_json'], true)) : [], 'declared_ok' => $decl !== null];
    }

    /** Calculates (or recalculates) a draft run; the result is recorded in the Calculation Ledger. */
    public function calculate(Authorizer $az, string $pid): void
    {
        $r = $this->run($az, $pid);
        $le = (int) $r['legal_entity_id'];
        $this->require($az, 'payroll.prepare', $le);
        $this->editable($r);
        $g = $this->gather($r);
        $out = PayCalc::run($g['inputs'], $g['values']);
        $runWarnings = [];
        if ($g['values']['payroll.policy'] === null) {
            $runWarnings[] = 'payroll.warn.run_policy_missing';
        }
        if ($g['inputs']['ot_without_staff'] !== []) {
            $runWarnings[] = 'payroll.warn.ot_without_staff';
        }
        if ($g['inputs']['welfare_without_staff'] !== []) {
            $runWarnings[] = 'payroll.warn.welfare_without_staff';
        }
        if ($r['run_type'] === 'off_cycle') {
            $runWarnings[] = 'payroll.warn.offcycle_statutory';
        }
        if (!(bool) ($g['inputs']['work_out_tax'] ?? true)) {
            $runWarnings[] = 'payroll.warn.correction_no_tax';
        }
        $calcPid = $this->app->ledger()->record('payroll.run', PayCalc::ENGINE, PayCalc::ENGINE, $this->app->clock->now(), $g['configs'], $g['inputs'], [], $out, [
            'legal_entity_id' => $le, 'period_ref' => $r['period_start'] . '..' . $r['period_end'], 'source_type' => 'pay_run', 'source_id' => (string) $r['public_id'],
            'initiated_by' => $az->userId, 'supersedes' => $r['calc_run_pid'] !== null ? (string) $r['calc_run_pid'] : null, 'warnings' => $runWarnings,
        ]);
        $db = $this->app->db();
        $db->transactional(function () use ($db, $r, $out, $calcPid, $az, $g): void {
            $ids = array_column($db->all('SELECT id FROM pay_run_employees WHERE run_id = ?', [$r['id']]), 'id');
            if ($ids !== []) {
                $db->run('DELETE FROM pay_result_lines WHERE run_employee_id IN (' . implode(',', array_map('intval', $ids)) . ')');
                $db->run('DELETE FROM pay_run_employees WHERE run_id = ?', [$r['id']]);
            }
            $byId = [];
            foreach ($g['inputs']['employees'] as $e) {
                $byId[(int) $e['employee_id']] = $e;
            }
            foreach ($out['employees'] as $e) {
                $in = $byId[(int) $e['employee_id']];
                $snap = ['code' => $in['code'], 'name' => $in['name'], 'site_id' => $in['site_id'], 'days_employed' => $in['days_employed'],
                    'basis' => $in['profile']['basis'] ?? null, 'profile' => $in['profile']['pid'] ?? null, 'has_bank' => $in['profile']['has_bank'] ?? false];
                $db->run(
                    'INSERT INTO pay_run_employees (public_id, run_id, employee_id, snapshot_json, gross, deductions, net, employer, warnings_json, blocking) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [Ulid::generate($this->app->clock), $r['id'], $e['employee_id'], json_encode($snap, JSON_UNESCAPED_UNICODE), Money::toDb($e['gross']), Money::toDb($e['deductions']), Money::toDb($e['net']),
                    Money::toDb($e['employer']),
                    $e['warnings'] === [] ? null : json_encode($e['warnings'], JSON_UNESCAPED_UNICODE),
                    count(array_filter($e['warnings'], static fn (array $w): bool => $w['blocking'])) > 0 ? 1 : 0]
                );
                $reId = (int) $db->pdo()->lastInsertId();
                foreach ($e['lines'] as $l) {
                    $db->run(
                        'INSERT INTO pay_result_lines (run_employee_id, component_id, component_code, kind, quantity, rate, amount, source_type, source_ref, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$reId, $l['component_id'], $l['code'], $l['kind'], $l['quantity'], $l['rate'] !== null ? (Money::parse((string) $l['rate'], 4) !== null ? Money::toDb((int) Money::parse((string) $l['rate'], 4)) : null) : null,
                        Money::toDb($l['amount']),
                        $l['source_type'],
                        $l['source_ref'] !== null ? mb_substr((string) $l['source_ref'], 0, 120) : null,
                        $l['note'] !== null ? mb_substr((string) $l['note'], 0, 255) : null]
                    );
                }
            }
            $t = $out['totals'];
            $blocking = $t['blocking'];
            $db->run(
                "UPDATE pay_runs SET status = 'calculated', calc_run_pid = ?, calc_hash = ?, calculated_at = ?, calculated_by = ?, employee_count = ?, gross_total = ?, deduction_total = ?, net_total = ?,
                    employer_total = ?, warning_count = ?, blocking_count = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?",
                [$calcPid, \Blue\Core\Ledger\CalculationLedger::hash($out), $this->now(), $az->userId, $t['employees'], Money::toDb($t['gross']), Money::toDb($t['deductions']), Money::toDb($t['net']),
                    Money::toDb($t['employer']), $t['warnings'] + count($g['inputs']['ot_without_staff']), $blocking, $this->now(), $az->userId, $r['id']]
            );
            $this->event((int) $r['id'], 'calculated', $az->userId);
            $this->audit('payroll.run_calculated', 'pay_run', (string) $r['public_id'], (int) $r['legal_entity_id'], null, ['employees' => $t['employees'], 'calculation' => $calcPid, 'blocking' => $blocking]);
        });
    }

    /** Payroll sends a calculated run without blocking warnings to an approver (another person). */
    public function submit(Authorizer $az, string $pid, string $note): void
    {
        $r = $this->run($az, $pid);
        $le = (int) $r['legal_entity_id'];
        $this->require($az, 'payroll.prepare', $le);
        if ($r['status'] !== 'calculated') {
            throw new BusinessException('payroll.error.calculate_first', [], 409);
        }
        if ((int) $r['blocking_count'] > 0) {
            throw new BusinessException('payroll.error.blocking', ['n' => (string) $r['blocking_count']], 409);
        }
        if ((int) $r['employee_count'] === 0) {
            throw new BusinessException('payroll.error.empty', [], 409);
        }
        $this->assertSourcesFree($r);
        $this->assertRulesUnchanged($r);
        $db = $this->app->db();
        $db->transactional(function () use ($db, $r, $az, $note): void {
            $title = $this->app->translator()->get('payroll.run_title', ['le' => (string) $r['le_code'], 'label' => (string) $r['label']]);
            $inst = $this->app->workflow()->start('payroll.run', (string) $r['public_id'], $title, (int) $r['legal_entity_id'], $az->userId);
            $db->run(
                "UPDATE pay_runs SET status = 'pending', submitted_by = ?, submitted_at = ?, workflow_instance_pid = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?",
                [$az->userId, $this->now(), $inst, $this->now(), $r['id']]
            );
            $this->event((int) $r['id'], 'submitted', $az->userId, mb_substr(trim($note), 0, 500) ?: null);
            $this->audit('payroll.run_submitted', 'pay_run', (string) $r['public_id'], (int) $r['legal_entity_id']);
        });
    }

    /** the rule versions the calculation used must still be the ones in force (a newly approved value → calculate again) */
    /** @param array<string, mixed> $r */
    private function assertRulesUnchanged(array $r): void
    {
        $used = [];
        foreach ($this->app->ledger()->open((string) $r['calc_run_pid'])['config_versions'] as $c) {
            $used[(string) $c['config_key']] = (string) $c['version_id'];
        }
        $now = $this->rules((int) $r['legal_entity_id'], (string) $r['period_end']);
        foreach (['payroll.policy' => $now['policy'], 'payroll.sso' => $now['sso'], 'payroll.wht' => $now['wht']] as $k => $rc) {
            if (($used[$k] ?? null) !== $rc?->versionPublicId) {
                throw new BusinessException('payroll.error.rules_changed', [], 409);
            }
        }
    }

    /** the time period, OT batches and welfare batches must not already be paid by another approved / pending run */
    /** @param array<string, mixed> $r */
    private function assertSourcesFree(array $r): void
    {
        $db = $this->app->db();
        $origId = $r['run_type'] === 'correction' ? (int) $r['corrects_run_id'] : (int) $r['id'];
        foreach (
            $db->all(
                "SELECT id, att_period_id, ot_batch_ids, welfare_batch_ids FROM pay_runs WHERE legal_entity_id = ? AND status IN ('pending','approved') AND id <> ? AND id <> ? AND (corrects_run_id IS NULL OR corrects_run_id <> ?)",
                [$r['legal_entity_id'], $r['id'], $origId, $origId]
            ) as $o
        ) {
            if (array_intersect(array_filter(explode(',', (string) $o['welfare_batch_ids'])), array_filter(explode(',', (string) ($r['welfare_batch_ids'] ?? '')))) !== []) {
                throw new BusinessException('payroll.error.source_used', [], 409);
            }
            if ($r['att_period_id'] !== null && (int) $o['att_period_id'] === (int) $r['att_period_id']) {
                throw new BusinessException('payroll.error.source_used', [], 409);
            }
            if (array_intersect(array_filter(explode(',', (string) $o['ot_batch_ids'])), array_filter(explode(',', (string) $r['ot_batch_ids']))) !== []) {
                throw new BusinessException('payroll.error.source_used', [], 409);
            }
        }
    }

    public function onRunApproved(string $pid, int $approverId): void
    {
        $db = $this->app->db();
        $r = $this->row($pid);
        if ($r['status'] === 'approved') {
            return;
        }
        if ($r['status'] !== 'pending') {
            throw new BusinessException('payroll.error.state', [], 409);
        }
        if ((int) $r['submitted_by'] === $approverId || (int) $r['calculated_by'] === $approverId) {
            throw new BusinessException('workflow.error.self_approval', [], 403);
        }
        // the stored result must still be the calculated one
        $ledger = $this->app->ledger()->open((string) $r['calc_run_pid']);
        if (!$ledger['intact'] || \Blue\Core\Ledger\CalculationLedger::hash($ledger['outputs']) !== (string) $r['calc_hash']) {
            throw new BusinessException('payroll.error.result_changed', [], 409);
        }
        $this->assertSourcesFree($r);
        $this->assertRulesUnchanged($r);
        $this->app->ledger()->transition((string) $r['calc_run_pid'], 'approved');
        // the regular run locks the period; off-cycle and correction runs are fixed by their own approval (6F)
        $lock = $r['run_type'] !== 'regular' ? null : $this->app->locks()->lock(
            'payroll',
            'legal_entity',
            (int) $r['legal_entity_id'],
            $r['period_start'] . '..' . $r['period_end'],
            'hard',
            $approverId,
            'payroll run ' . $r['le_code'] . '-' . $r['run_no'],
            (string) $r['calc_run_pid']
        );
        $db->run(
            "UPDATE pay_runs SET status = 'approved', approved_by = ?, approved_at = ?, lock_pid = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?",
            [$approverId, $this->now(), $lock, $this->now(), $r['id']]
        );
        $this->event((int) $r['id'], 'approved', $approverId);
        PayslipService::of($this->app)->ytdFromRun($this->row($pid)); // year-to-date totals of everyone in the run (6B)
        $this->app->audit()->record('payroll.run_approved', 'success', 'pay_run', $pid, (int) $r['legal_entity_id'], null, ['calculation' => (string) $r['calc_run_pid']], $approverId);
        $this->app->notifier()->notify((int) $r['submitted_by'], 'payroll.run_approved', 'payroll.notify.approved', ['label' => (string) $r['label']], '/payroll/runs/' . $pid, 'pay-approved-' . $pid);
    }

    public function onRunClosed(string $pid, string $outcome): void
    {
        $db = $this->app->db();
        $r = $this->row($pid);
        if ($r['status'] !== 'pending') {
            return;
        }
        // returned / rejected → editable again (must calculate again); cancelled by the maker → draft as well
        $db->run("UPDATE pay_runs SET status = 'draft', workflow_instance_pid = NULL, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $r['id']]);
        $this->event((int) $r['id'], 'returned:' . $outcome, null);
    }

    public function cancel(Authorizer $az, string $pid, string $reason): void
    {
        $r = $this->run($az, $pid);
        $this->require($az, 'payroll.prepare', (int) $r['legal_entity_id']);
        $this->editable($r);
        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $this->app->db()->run("UPDATE pay_runs SET status = 'cancelled', cancel_reason = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [trim($reason), $this->now(), $az->userId, $r['id']]);
        $this->event((int) $r['id'], 'cancelled', $az->userId, trim($reason));
        $this->audit('payroll.run_cancelled', 'pay_run', $pid, (int) $r['legal_entity_id'], trim($reason));
    }

    // ---- after approval ---------------------------------------------------------------------------------------------

    /**
     * Hands the net pay of people with a bank account to a payment batch (core Payments: draft → approval → bank file).
     * People without an account are left out and listed (paid another way).
     *
     * @return array{batch: string, items: int, left_out: int}
     */
    public function createPayment(Authorizer $az, string $pid): array
    {
        $r = $this->run($az, $pid);
        $le = (int) $r['legal_entity_id'];
        $this->require($az, 'payroll.prepare', $le);
        if ($r['status'] !== 'approved') {
            throw new BusinessException('payroll.error.not_approved', [], 409);
        }
        if (!$az->scope('core.payment.prepare')->coversLegalEntity($le)) {
            throw new BusinessException('payroll.error.no_payment_right', [], 403);
        }
        $items = [];
        $sum = 0;
        $left = 0;
        foreach ($this->results((int) $r['id']) as $e) {
            $net = Money::fromDb($e['net']);
            if ($net <= 0) {
                continue;
            }
            $p = $e['snap']['profile'] !== null ? $this->app->db()->one('SELECT * FROM pay_profiles WHERE public_id = ?', [$e['snap']['profile']]) : null;
            $acc = $p !== null ? $this->account($p) : null;
            if ($acc === null) {
                $left++;
                continue;
            }
            $items[] = ['payee_type' => 'employee', 'payee_code' => (string) $e['snap']['code'], 'payee_name' => (string) $e['snap']['name'], 'site_id' => $e['snap']['site_id'],
                'bank_code' => (string) $p['bank_code'], 'account_no' => $acc, 'amount' => Money::format($net, 2, false)];
            $sum += $net;
        }
        if ($items === []) {
            throw new BusinessException('payroll.error.no_payable', [], 409);
        }
        $batch = $this->app->payments()->createFromSource([
            'legal_entity_id' => $le, 'payment_type' => 'payroll', 'source_type' => 'pay_run', 'source_id' => (string) $r['public_id'], 'idempotency_key' => 'payroll.run:' . $r['public_id'],
            'pay_date' => (string) $r['pay_date'], 'period_label' => mb_substr((string) $r['label'], 0, 60), 'currency_code' => 'THB', 'expected_total' => Money::format($sum, 2, false),
            'actor_user_id' => $az->userId, 'items' => $items,
        ]);
        $this->app->db()->run('UPDATE pay_runs SET payment_batch_pid = ?, updated_at = ? WHERE id = ?', [$batch, $this->now(), $r['id']]);
        $this->event((int) $r['id'], 'payment_batch', $az->userId);
        $this->audit('payroll.payment_created', 'pay_run', $pid, $le, null, ['batch' => $batch, 'items' => count($items), 'left_out' => $left]);
        return ['batch' => $batch, 'items' => count($items), 'left_out' => $left];
    }

    /** Totals per component to Finance (posting request: earnings, deductions, employer costs; accounts come from Finance's rules). */
    public function requestPosting(Authorizer $az, string $pid): string
    {
        $r = $this->run($az, $pid);
        $le = (int) $r['legal_entity_id'];
        $this->require($az, 'payroll.prepare', $le);
        if ($r['status'] !== 'approved') {
            throw new BusinessException('payroll.error.not_approved', [], 409);
        }
        $lines = [];
        foreach (
            $this->app->db()->all(
                'SELECT l.component_code, l.kind, SUM(l.amount) AS total FROM pay_result_lines l JOIN pay_run_employees e ON e.id = l.run_employee_id WHERE e.run_id = ? GROUP BY l.component_code, l.kind ORDER BY l.kind, l.component_code',
                [$r['id']]
            ) as $x
        ) {
            $u = Money::fromDb($x['total']);
            if ($u === 0) {
                continue;
            }
            // a correction may lower an amount: posted as a separate ".reduce" line (Finance maps it — no rule = waits for Finance)
            $lines[] = ['line_type' => 'payroll.' . $x['kind'] . '.' . strtolower(str_replace('-', '_', (string) $x['component_code'])) . ($u < 0 ? '.reduce' : ''), 'amount' => Money::toDb(abs($u)),
                'description' => (string) $x['component_code']];
        }
        if ($lines === []) {
            throw new BusinessException('payroll.error.no_payable', [], 409);
        }
        $req = $this->app->posting()->request([
            'source_module' => 'payroll.run', 'source_document_type' => 'pay_run', 'source_document_id' => (string) $r['public_id'], 'legal_entity_id' => $le,
            'period_date' => (string) ($r['run_type'] === 'regular' ? $r['period_end'] : $r['pay_date']), 'currency_code' => 'THB', 'lines' => $lines, 'calculation_id' => (string) $r['calc_run_pid'],
            'description' => mb_substr((string) $r['le_code'] . ' ' . $r['label'], 0, 300), 'idempotency_key' => 'payroll.run:' . $r['public_id'], 'actor_user_id' => $az->userId,
        ]);
        $this->app->db()->run('UPDATE pay_runs SET posting_request_pid = ?, updated_at = ? WHERE id = ?', [$req, $this->now(), $r['id']]);
        $this->event((int) $r['id'], 'posting', $az->userId);
        $this->audit('payroll.posting_requested', 'pay_run', $pid, $le, null, ['request' => $req, 'lines' => count($lines)]);
        return $req;
    }

    // ---- reading ----------------------------------------------------------------------------------------------------

    /**
     * What the monthly government filings (M58 · 6E) are made from: the approved runs of a company paid in a date range
     * (regular, off-cycle and correction runs by pay date), per person the taxable income, withholding tax, social
     * security of both sides, the wage that social security was worked out on (social security lines) and the wage
     * of the components marked as the social security base; the year-to-date entries the approvals wrote; runs of the
     * range that are not approved yet; the people employed by the company in the range. Read only.
     *
     * @return array{runs: list<array<string, mixed>>, open_runs: list<array<string, mixed>>, people: array<int, array<string, mixed>>, ytd: array<int, array{taxable: int, wht: int, sso: int}>, employed: array<int, string>}
     */
    public function filingSource(int $le, string $from, string $to): array
    {
        $db = $this->app->db();
        $runs = $db->all(
            "SELECT r.id, r.public_id, r.run_no, r.run_type, r.label, r.pay_date, r.period_start, r.period_end, r.calc_run_pid, c.output_hash
               FROM pay_runs r LEFT JOIN calculation_runs c ON c.public_id = r.calc_run_pid
              WHERE r.legal_entity_id = ? AND r.status = 'approved' AND r.pay_date BETWEEN ? AND ? ORDER BY r.pay_date, r.run_no",
            [$le, $from, $to]
        );
        $open = $db->all(
            "SELECT public_id, run_no, run_type, label, status, pay_date FROM pay_runs
              WHERE legal_entity_id = ? AND status IN ('draft','calculated','pending') AND pay_date BETWEEN ? AND ? ORDER BY run_no",
            [$le, $from, $to]
        );
        $people = [];
        $ytd = [];
        if ($runs !== []) {
            $ids = implode(',', array_map(static fn (array $x): int => (int) $x['id'], $runs));
            $pids = [];
            foreach ($runs as $x) {
                $pids[(int) $x['id']] = (string) $x['public_id'];
            }
            foreach (
                $db->all(
                    "SELECT re.run_id, re.employee_id, e.employee_code, e.name_th, l.kind, l.amount, l.source_type, l.source_ref, c.taxable, c.sso_base, c.statutory
                       FROM pay_result_lines l JOIN pay_run_employees re ON re.id = l.run_employee_id JOIN employees e ON e.id = re.employee_id JOIN pay_components c ON c.id = l.component_id
                      WHERE re.run_id IN ({$ids}) ORDER BY e.employee_code, l.id"
                ) as $l
            ) {
                $emp = (int) $l['employee_id'];
                $p = $people[$emp] ?? ['code' => (string) $l['employee_code'], 'name' => (string) $l['name_th'], 'gross' => 0, 'taxable' => 0, 'wht' => 0, 'sso_ee' => 0, 'sso_er' => 0,
                    'sso_wage_used' => 0, 'sso_wage_unknown' => false, 'sso_base' => 0, 'runs' => []];
                $u = Money::fromDb($l['amount']);
                $sign = $l['kind'] === 'earning' ? 1 : ($l['kind'] === 'deduction' ? -1 : 0);
                if ($l['kind'] === 'earning') {
                    $p['gross'] += $u;
                }
                if ((int) $l['taxable'] === 1) {
                    $p['taxable'] += $sign * $u;
                }
                if ((int) $l['sso_base'] === 1) {
                    $p['sso_base'] += $sign * $u;
                }
                if ($l['statutory'] === 'wht') {
                    $p['wht'] += $u;
                } elseif ($l['statutory'] === 'sso_employer') {
                    $p['sso_er'] += $u;
                } elseif ($l['statutory'] === 'sso_employee') {
                    $p['sso_ee'] += $u;
                    if ($l['source_type'] === 'sso' && $l['source_ref'] !== null && is_numeric((string) $l['source_ref'])) {
                        $p['sso_wage_used'] += Money::parse((string) $l['source_ref'], 4) ?? 0;
                    } else {
                        $p['sso_wage_unknown'] = true; // a correction or manual line: the wage it was worked out on is not on the line
                    }
                }
                $run = $pids[(int) $l['run_id']];
                if (!in_array($run, $p['runs'], true)) {
                    $p['runs'][] = $run;
                }
                $people[$emp] = $p;
            }
            $refs = array_values($pids);
            foreach (
                $db->all(
                    "SELECT employee_id, SUM(taxable_income) AS t, SUM(wht) AS w, SUM(sso_employee) AS s FROM pay_ytd_entries
                      WHERE legal_entity_id = ? AND source_type = 'run' AND status = 'approved' AND source_ref IN (" . implode(',', array_fill(0, count($refs), '?')) . ') GROUP BY employee_id',
                    [$le, ...$refs]
                ) as $y
            ) {
                $ytd[(int) $y['employee_id']] = ['taxable' => Money::fromDb($y['t']), 'wht' => Money::fromDb($y['w']), 'sso' => Money::fromDb($y['s'])];
            }
        }
        foreach ($runs as &$x) {
            unset($x['id']);
        }
        unset($x);
        $employed = [];
        foreach ($this->eligible(['legal_entity_id' => $le, 'period_start' => $from, 'period_end' => $to]) as $x) {
            $employed[(int) $x['id']] = (string) $x['employee_code'];
        }
        return ['runs' => $runs, 'open_runs' => $open, 'people' => $people, 'ytd' => $ytd, 'employed' => $employed];
    }

    /**
     * Payroll ↔ payment reconciliation of an approved run (6F): per person the net pay of the result against the item of
     * the run's payment batch (amount and bank result), and the totals of the run, the batch and what the bank paid.
     *
     * @param array<string, mixed> $r
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>, batch: array<string, mixed>|null, ok: bool, intact: bool}
     */
    public function reconciliation(array $r): array
    {
        $db = $this->app->db();
        $batch = $r['payment_batch_pid'] !== null ? $db->one('SELECT id, public_id, batch_no, status, total_amount, paid_amount, posting_status FROM pmt_batches WHERE public_id = ?', [$r['payment_batch_pid']]) : null;
        $items = [];
        if ($batch !== null) {
            foreach ($db->all('SELECT payee_code, amount, status FROM pmt_batch_items WHERE batch_id = ? AND reissued_in_batch_id IS NULL', [$batch['id']]) as $i) {
                $items[(string) $i['payee_code']] = $i;
            }
        }
        $rows = [];
        $tot = ['net' => 0, 'batch' => 0, 'paid' => 0, 'left_out' => 0, 'diff' => 0];
        foreach ($this->results((int) $r['id']) as $e) {
            $net = Money::fromDb($e['net']);
            $code = (string) $e['snap']['code'];
            $it = $items[$code] ?? null;
            unset($items[$code]);
            $amt = $it !== null ? Money::fromDb($it['amount']) : null;
            $state = $net <= 0 ? 'nothing_due' : ($it === null ? 'left_out' : ($amt === $net ? 'match' : 'differs'));
            $tot['net'] += max(0, $net);
            $tot['batch'] += $amt ?? 0;
            $tot['paid'] += $it !== null && $it['status'] === 'paid' ? (int) $amt : 0;
            $tot['left_out'] += $state === 'left_out' ? $net : 0;
            $tot['diff'] += $state === 'differs' ? 1 : 0;
            $rows[] = ['code' => $code, 'name' => (string) $e['snap']['name'], 'net' => $net, 'item' => $amt, 'bank' => $it['status'] ?? null, 'state' => $state];
        }
        foreach ($items as $code => $it) { // paid to someone not in the run — must never happen
            $rows[] = ['code' => (string) $code, 'name' => '', 'net' => 0, 'item' => Money::fromDb($it['amount']), 'bank' => $it['status'], 'state' => 'not_in_run'];
            $tot['diff']++;
        }
        $ledger = $r['calc_run_pid'] !== null ? $this->app->ledger()->open((string) $r['calc_run_pid']) : null;
        $intact = $ledger !== null && $ledger['intact'] && \Blue\Core\Ledger\CalculationLedger::hash($ledger['outputs']) === (string) $r['calc_hash'];
        return ['rows' => $rows, 'totals' => $tot, 'batch' => $batch, 'intact' => $intact,
            'ok' => $intact && $batch !== null && $tot['diff'] === 0 && $tot['net'] - $tot['left_out'] === $tot['batch']];
    }

    /** @return list<array<string, mixed>> people of a calculated run (snapshot decoded) */
    public function results(int $runId): array
    {
        $rows = $this->app->db()->all('SELECT re.*, e.public_id AS employee_pid FROM pay_run_employees re JOIN employees e ON e.id = re.employee_id WHERE re.run_id = ? ORDER BY e.employee_code', [$runId]);
        foreach ($rows as &$x) {
            $x['snap'] = (array) json_decode((string) $x['snapshot_json'], true);
            $x['warnings'] = (array) json_decode((string) ($x['warnings_json'] ?? '[]'), true);
        }
        return $rows;
    }

    /** @return array{run: array<string, mixed>, employee: array<string, mixed>, lines: list<array<string, mixed>>} */
    public function result(Authorizer $az, string $runPid, string $empPid): array
    {
        $r = $this->run($az, $runPid);
        $db = $this->app->db();
        $re = $db->one('SELECT re.*, e.public_id AS employee_pid FROM pay_run_employees re JOIN employees e ON e.id = re.employee_id WHERE re.run_id = ? AND e.public_id = ?', [$r['id'], $empPid]) ?? throw HttpException::notFound();
        $re['snap'] = (array) json_decode((string) $re['snapshot_json'], true);
        $re['warnings'] = (array) json_decode((string) ($re['warnings_json'] ?? '[]'), true);
        $lines = $db->all('SELECT l.*, c.name_th, c.name_en FROM pay_result_lines l JOIN pay_components c ON c.id = l.component_id WHERE l.run_employee_id = ? ORDER BY FIELD(l.kind, \'earning\', \'deduction\', \'employer\'), c.sort_order, l.id', [$re['id']]);
        $this->app->audit()->record('payroll.result_viewed', 'success', 'pay_run', $runPid, (int) $r['legal_entity_id'], null, ['employee' => $empPid]);
        return ['run' => $r, 'employee' => $re, 'lines' => $lines];
    }

    /** @return list<array<string, mixed>> */
    public function events(int $runId): array
    {
        return $this->app->db()->all('SELECT v.*, u.display_name AS actor FROM pay_run_events v LEFT JOIN users u ON u.id = v.actor_id WHERE v.run_id = ? ORDER BY v.id', [$runId]);
    }

    private function event(int $runId, string $action, ?int $actor, ?string $note = null): void
    {
        $this->app->db()->run('INSERT INTO pay_run_events (run_id, action, actor_id, note, occurred_at) VALUES (?, ?, ?, ?, ?)', [$runId, $action, $actor, $note, $this->now()]);
    }

    /**
     * BLUE_PAYROLL_REGISTER_XLSX_v1: one row per person, one column per component, totals; needs fresh re-authentication
     * (controller) and every download is audited.
     *
     * @return array{bytes: string, name: string, mime: string}
     */
    public function register(Authorizer $az, string $pid): array
    {
        $r = $this->run($az, $pid);
        $le = (int) $r['legal_entity_id'];
        $this->require($az, 'payroll.export', $le);
        if (!in_array($r['status'], ['calculated', 'pending', 'approved'], true)) {
            throw new BusinessException('payroll.error.calculate_first', [], 409);
        }
        $db = $this->app->db();
        $codes = array_column($db->all(
            'SELECT DISTINCT l.component_code, l.kind, c.sort_order FROM pay_result_lines l JOIN pay_run_employees e ON e.id = l.run_employee_id JOIN pay_components c ON c.id = l.component_id
              WHERE e.run_id = ? ORDER BY FIELD(l.kind, \'earning\', \'deduction\', \'employer\'), c.sort_order, l.component_code',
            [$r['id']]
        ), 'component_code');
        $headers = array_merge(['employee_code', 'employee_name'], $codes, ['gross', 'deductions', 'net', 'employer_cost']);
        $rows = [];
        foreach ($this->results((int) $r['id']) as $e) {
            $amounts = [];
            foreach ($db->all('SELECT component_code, SUM(amount) AS a FROM pay_result_lines WHERE run_employee_id = ? GROUP BY component_code', [$e['id']]) as $l) {
                $amounts[(string) $l['component_code']] = Money::fromDb($l['a']);
            }
            $row = [(string) $e['snap']['code'], (string) $e['snap']['name']];
            foreach ($codes as $c) {
                $row[] = (float) Money::format($amounts[$c] ?? 0, 2, false);
            }
            foreach (['gross', 'deductions', 'net', 'employer'] as $k) {
                $row[] = (float) Money::format(Money::fromDb($e[$k]), 2, false);
            }
            $rows[] = $row;
        }
        $t = $this->app->translator();
        $meta = [
            [$t->get('payroll.meta.format'), 'BLUE_PAYROLL_REGISTER_XLSX_v1'],
            [$t->get('payroll.meta.company'), (string) $r['le_code']],
            [$t->get('payroll.meta.run'), (string) $r['run_no'] . ' · ' . $r['label']],
            [$t->get('payroll.meta.period'), $r['period_start'] . ' – ' . $r['period_end']],
            [$t->get('payroll.meta.status'), $t->get('payroll.status.' . $r['status'])],
            [$t->get('payroll.meta.calculation'), (string) $r['calc_run_pid']],
            [$t->get('payroll.meta.hash'), (string) $r['calc_hash']],
            [$t->get('payroll.meta.exported_at'), $this->now() . ' UTC'],
            [$t->get('payroll.meta.note'), $t->get('payroll.meta.note_text')],
        ];
        $bytes = \Blue\Core\Files\Xlsx::write([
            'register' => ['headers' => $headers, 'rows' => $rows, 'numeric' => range(2, count($headers) - 1), 'widths' => array_merge([14, 28], array_fill(0, count($headers) - 2, 13))],
            'meta' => ['headers' => [$t->get('payroll.meta.item'), $t->get('payroll.meta.value')], 'rows' => $meta, 'freeze' => false, 'widths' => [24, 70]],
        ], 'Blue OS payroll register');
        $this->audit('payroll.register_exported', 'pay_run', $pid, $le, null, ['rows' => count($rows), 'sha256' => hash('sha256', $bytes)]);
        return ['bytes' => $bytes, 'name' => 'BLUE_PAYROLL_REGISTER_v1_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $r['le_code']) . '_R' . str_pad((string) $r['run_no'], 4, '0', STR_PAD_LEFT) . '.xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    /**
     * readiness of a company for a run (shown on the payroll home page)
     *
     * @return array<string, int|bool>
     */
    public function readiness(int $le): array
    {
        $today = $this->today();
        $rules = $this->rules($le, $today);
        $staff = $this->staff($le, $today);
        $methods = array_column($this->components($le, true), 'method');
        return ['policy' => $rules['policy'] !== null, 'sso' => $rules['sso'] !== null, 'components' => count($methods), 'has_base' => in_array('base', $methods, true),
            'staff' => count($staff), 'no_profile' => count(array_filter($staff, static fn (array $s): bool => $s['profile'] === null)),
            'pending_profiles' => array_sum(array_column($staff, 'pending'))];
    }

    /** replays the stored calculation from the ledger snapshot (proof that the stored result is what PAYROLL_V1 gives) */
    public function replay(Authorizer $az, string $pid): bool
    {
        $r = $this->run($az, $pid);
        if ($r['calc_run_pid'] === null) {
            return false;
        }
        return $this->app->ledger()->replay((string) $r['calc_run_pid'], static fn (array $inputs, array $config): array => PayCalc::run($inputs, $config + ['payroll.policy' => null, 'payroll.sso' => null, 'payroll.wht' => null]))['matches'];
    }
}
