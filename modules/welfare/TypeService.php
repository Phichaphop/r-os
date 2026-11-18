<?php

declare(strict_types=1);

namespace Blue\Modules\Welfare;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Money;
use Blue\Core\Support\Ulid;
use Blue\Modules\Payroll\PayrollService;

/**
 * Welfare types (M37 · DEC-86) — company policy, not law: HR writes each type with its limits, eligibility and the
 * reference to the company welfare regulation; ANOTHER person with welfare.manage activates it. Nothing is shipped.
 *
 * A type is never edited once active: a change is a new version (same code) that a second person activates; the old
 * version then ends the day before the new one starts, so a claim always uses the version in force on its expense date.
 * Limits: limit_year (per person per calendar year of the expense date, all versions of the code together),
 * limit_event (per claim), max_claims (per person per year). Eligibility: employment types (empty = all), days of
 * service on the expense date, how many days after the expense a claim may be made. Payout: "payroll" (the amount
 * goes into a payroll run on the type's component — an active earning with method "manual") or "payment" (a payment
 * batch to the person's bank account on their pay profile).
 */
final class TypeService
{
    public const CATEGORIES = ['medical', 'assistance', 'other'];
    public const EMPLOYMENT_TYPES = ['permanent', 'probation', 'contract', 'part_time', 'daily'];
    public const PAYOUTS = ['payroll', 'payment'];

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

    public function today(): string
    {
        return PayrollService::of($this->app)->today();
    }

    public static function ymd(string $d): ?DateTimeImmutable
    {
        $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d, new DateTimeZone('UTC'));
        return $x !== false && $x->format('Y-m-d') === $d ? $x : null;
    }

    private function require(Authorizer $az, string $perm, int $le): void
    {
        if (!$az->scope($perm)->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
    }

    /** @return list<int> */
    public function companies(Authorizer $az, string $perm): array
    {
        return PayrollService::of($this->app)->companies($az, $perm);
    }

    // ---- reading ------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function row(string $pid): array
    {
        return $this->app->db()->one('SELECT * FROM wf_types WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /** @return array<string, mixed>|null the version of a code in force on a day */
    public function inForceOn(int $le, string $code, string $date): ?array
    {
        return $this->app->db()->one(
            "SELECT * FROM wf_types WHERE legal_entity_id = ? AND code = ? AND status = 'active' AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY version_no DESC LIMIT 1",
            [$le, $code, $date, $date]
        );
    }

    /** @return list<array<string, mixed>> the versions in force on a day, one per code */
    public function inForce(int $le, string $date): array
    {
        return $this->app->db()->all(
            "SELECT * FROM wf_types t WHERE legal_entity_id = ? AND status = 'active' AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
               AND version_no = (SELECT MAX(version_no) FROM wf_types x WHERE x.legal_entity_id = t.legal_entity_id AND x.code = t.code AND x.status = 'active' AND x.effective_from <= ? AND (x.effective_to IS NULL OR x.effective_to >= ?))
             ORDER BY code",
            [$le, $date, $date, $date, $date]
        );
    }

    /**
     * Every code of the company with its versions (newest first) — for the types screen.
     *
     * @return list<array{code: string, current: ?array<string, mixed>, draft: ?array<string, mixed>, versions: list<array<string, mixed>>}>
     */
    public function overview(int $le): array
    {
        $today = $this->today();
        $by = [];
        foreach ($this->app->db()->all("SELECT t.*, u.display_name AS creator FROM wf_types t LEFT JOIN users u ON u.id = t.created_by WHERE t.legal_entity_id = ? AND t.status <> 'discarded' ORDER BY t.code, t.version_no DESC", [$le]) as $t) {
            $c = (string) $t['code'];
            $by[$c] ??= ['code' => $c, 'current' => null, 'draft' => null, 'versions' => []];
            $by[$c]['versions'][] = $t;
            if ($t['status'] === 'draft') {
                $by[$c]['draft'] = $t;
            } elseif ($t['status'] === 'active' && $by[$c]['current'] === null && ($t['effective_to'] === null || (string) $t['effective_to'] >= $today)) {
                $by[$c]['current'] = $t;
            }
        }
        return array_values($by);
    }

    /** @return list<array<string, mixed>> active pay components a "payroll" type may use (earnings, method manual) */
    public function components(int $le): array
    {
        return $this->app->db()->all("SELECT code, name_th FROM pay_components WHERE legal_entity_id = ? AND status = 'active' AND kind = 'earning' AND method = 'manual' ORDER BY sort_order, code", [$le]);
    }

    // ---- writing ------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $in
     * @return array{0: array<string, mixed>|null, 1: array<string, string>}
     */
    public function validate(int $le, array $in, bool $codeFixed): array
    {
        $e = [];
        $o = [];
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if (!$codeFixed && preg_match('/^[A-Z][A-Z0-9_]{1,19}$/', $code) !== 1) {
            $e['code'] = 'welfare.error.code';
        }
        $o['code'] = $code;
        foreach (['name_th' => [true, 120], 'name_en' => [false, 120], 'description' => [false, 1000]] as $k => [$req, $max]) {
            $v = trim((string) ($in[$k] ?? ''));
            if (($req && $v === '') || mb_strlen($v) > $max) {
                $e[$k] = 'welfare.error.text';
            }
            $o[$k] = $v === '' ? null : $v;
        }
        $cat = (string) ($in['category'] ?? '');
        if (!in_array($cat, self::CATEGORIES, true)) {
            $e['category'] = 'form.invalid_choice';
        }
        $o['category'] = $cat;
        foreach (['limit_year', 'limit_event'] as $k) {
            $raw = trim((string) ($in[$k] ?? ''));
            $u = $raw === '' ? null : Money::parse($raw, 2);
            if ($raw !== '' && ($u === null || $u <= 0)) {
                $e[$k] = 'welfare.error.money';
            }
            $o[$k] = $u;
        }
        if ($o['limit_year'] === null && $o['limit_event'] === null && !isset($e['limit_year'], $e['limit_event'])) {
            $e['limit_year'] = 'welfare.error.no_limit';
        }
        foreach (['max_claims' => [1, 999], 'window_days' => [1, 3650], 'min_service_days' => [0, 3650]] as $k => [$min, $max]) {
            $raw = trim((string) ($in[$k] ?? ''));
            if ($raw !== '' && (!ctype_digit($raw) || (int) $raw < $min || (int) $raw > $max)) {
                $e[$k] = 'welfare.error.number';
            }
            $o[$k] = $raw === '' || !ctype_digit($raw) ? null : (int) $raw;
        }
        $types = array_values(array_intersect(self::EMPLOYMENT_TYPES, array_map('strval', (array) ($in['employment_types'] ?? []))));
        $o['employment_types'] = $types === [] || count($types) === count(self::EMPLOYMENT_TYPES) ? null : implode(',', $types);
        $o['receipt_required'] = !empty($in['receipt_required']) ? 1 : 0;
        $payout = (string) ($in['payout'] ?? '');
        if (!in_array($payout, self::PAYOUTS, true)) {
            $e['payout'] = 'form.invalid_choice';
        }
        $o['payout'] = $payout;
        $comp = strtoupper(trim((string) ($in['component_code'] ?? '')));
        if ($payout === 'payroll' && !in_array($comp, array_column($this->components($le), 'code'), true)) {
            $e['component_code'] = 'welfare.error.component';
        }
        $o['component_code'] = $payout === 'payroll' ? $comp : null;
        $ref = trim((string) ($in['policy_ref'] ?? ''));
        if (mb_strlen($ref) < 5 || mb_strlen($ref) > 300) {
            $e['policy_ref'] = 'welfare.error.policy_ref';
        }
        $o['policy_ref'] = $ref;
        $from = trim((string) ($in['effective_from'] ?? ''));
        if (self::ymd($from) === null || $from < '2000-01-01') {
            $e['effective_from'] = 'welfare.error.date';
        }
        $o['effective_from'] = $from;
        $note = trim((string) ($in['change_note'] ?? ''));
        $o['change_note'] = $note === '' ? null : mb_substr($note, 0, 500);
        return $e !== [] ? [null, $e] : [$o, []];
    }

    /**
     * Saves a draft: a new code, or (with $basePid) the next version of an active one. One draft per code — its
     * author keeps editing it until someone else activates or discards it.
     *
     * @param array<string, mixed> $in
     */
    public function save(Authorizer $az, int $le, array $in, string $basePid = ''): string
    {
        $this->require($az, 'welfare.manage', $le);
        $db = $this->app->db();
        $base = $basePid !== '' ? $this->row($basePid) : null;
        if ($base !== null && ((int) $base['legal_entity_id'] !== $le || $base['status'] === 'discarded')) {
            throw HttpException::notFound();
        }
        if ($base !== null) {
            $in['code'] = (string) $base['code'];
        }
        [$o, $errors] = $this->validate($le, $in, $base !== null);
        if ($o === null) {
            throw new ValidationException($errors);
        }
        $code = (string) $o['code'];
        $draft = $base !== null && $base['status'] === 'draft' ? $base : $db->one("SELECT * FROM wf_types WHERE legal_entity_id = ? AND code = ? AND status = 'draft'", [$le, $code]);
        if ($base === null && $draft === null && $db->scalar('SELECT id FROM wf_types WHERE legal_entity_id = ? AND code = ? LIMIT 1', [$le, $code]) !== null) {
            throw new ValidationException(['code' => 'welfare.error.code_taken']);
        }
        if ($draft !== null && (int) $draft['created_by'] !== $az->userId) {
            throw new BusinessException('welfare.error.draft_other', [], 409);
        }
        $prev = $db->one("SELECT * FROM wf_types WHERE legal_entity_id = ? AND code = ? AND status = 'active' ORDER BY version_no DESC LIMIT 1", [$le, $code]);
        if ($prev !== null && (string) $o['effective_from'] <= (string) $prev['effective_from']) {
            throw new ValidationException(['effective_from' => 'welfare.error.after_previous']);
        }
        $now = $this->now();
        $cols = ['name_th', 'name_en', 'category', 'description', 'max_claims', 'window_days', 'min_service_days', 'employment_types', 'receipt_required', 'payout', 'component_code', 'policy_ref', 'effective_from', 'change_note'];
        $vals = array_map(static fn (string $c): mixed => $o[$c], $cols);
        $money = [$o['limit_year'] !== null ? Money::toDb((int) $o['limit_year']) : null, $o['limit_event'] !== null ? Money::toDb((int) $o['limit_event']) : null];
        if ($draft !== null) {
            $db->run(
                'UPDATE wf_types SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = ?', $cols)) . ', limit_year = ?, limit_event = ?, supersedes_id = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                [...$vals, ...$money, $prev['id'] ?? null, $now, $az->userId, $draft['id']]
            );
            $pid = (string) $draft['public_id'];
        } else {
            $pid = Ulid::generate($this->app->clock);
            $no = (int) $db->scalar('SELECT COALESCE(MAX(version_no), 0) + 1 FROM wf_types WHERE legal_entity_id = ? AND code = ?', [$le, $code]);
            $db->run(
                'INSERT INTO wf_types (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, code, version_no, ' . implode(', ', $cols) . ', limit_year, limit_event, supersedes_id, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ", ?, ?, ?, 'draft')",
                [$pid, $now, $az->userId, $now, $az->userId, $le, $code, $no, ...$vals, ...$money, $prev['id'] ?? null]
            );
        }
        $this->app->audit()->record('welfare.type_saved', 'success', 'wf_type', $pid, $le, $o['change_note'], ['code' => $code, 'limit_year' => $money[0], 'limit_event' => $money[1], 'payout' => $o['payout']]);
        return $pid;
    }

    /** Another person with welfare.manage activates a draft; the version it replaces ends the day before. */
    public function activate(Authorizer $az, string $pid): void
    {
        $t = $this->row($pid);
        $le = (int) $t['legal_entity_id'];
        $this->require($az, 'welfare.manage', $le);
        if ($t['status'] !== 'draft') {
            throw new BusinessException('welfare.error.not_draft', [], 409);
        }
        if ((int) $t['created_by'] === $az->userId || (int) $t['updated_by'] === $az->userId) {
            throw new BusinessException('welfare.error.own_draft', [], 403);
        }
        if ($t['payout'] === 'payroll' && !in_array((string) $t['component_code'], array_column($this->components($le), 'code'), true)) {
            throw new BusinessException('welfare.error.component', [], 409);
        }
        $db = $this->app->db();
        $db->transactional(function () use ($db, $t, $az, $le): void {
            $now = $this->now();
            $prev = $db->one("SELECT * FROM wf_types WHERE legal_entity_id = ? AND code = ? AND status = 'active' AND id <> ? ORDER BY version_no DESC LIMIT 1 FOR UPDATE", [$le, $t['code'], $t['id']]);
            if ($prev !== null) {
                if ((string) $t['effective_from'] <= (string) $prev['effective_from']) {
                    throw new BusinessException('welfare.error.after_previous', [], 409);
                }
                $end = (self::ymd((string) $t['effective_from']) ?? throw new BusinessException('welfare.error.date', [], 409))->modify('-1 day')->format('Y-m-d');
                if ($prev['effective_to'] === null || (string) $prev['effective_to'] > $end) {
                    $db->run('UPDATE wf_types SET effective_to = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$end, $now, $az->userId, $prev['id']]);
                }
            }
            $db->run("UPDATE wf_types SET status = 'active', activated_by = ?, activated_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'draft'", [$az->userId, $now, $now, $t['id']]);
        });
        $this->app->audit()->record('welfare.type_activated', 'success', 'wf_type', $pid, $le, null, ['code' => $t['code'], 'version' => (int) $t['version_no']]);
    }

    /** The author (or another manager) drops a draft. */
    public function discard(Authorizer $az, string $pid, string $reason): void
    {
        $t = $this->row($pid);
        $le = (int) $t['legal_entity_id'];
        $this->require($az, 'welfare.manage', $le);
        if ($t['status'] !== 'draft') {
            throw new BusinessException('welfare.error.not_draft', [], 409);
        }
        $this->app->db()->run("UPDATE wf_types SET status = 'discarded', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $az->userId, $t['id']]);
        $this->app->audit()->record('welfare.type_discarded', 'success', 'wf_type', $pid, $le, $reason !== '' ? mb_substr($reason, 0, 300) : null, ['code' => $t['code']]);
    }

    /**
     * Ends an active type on a day (no new claims for expenses after it; claims already made are not touched).
     * Needs a reason; recorded in the audit log.
     */
    public function retire(Authorizer $az, string $pid, string $lastDay, string $reason): void
    {
        $t = $this->row($pid);
        $le = (int) $t['legal_entity_id'];
        $this->require($az, 'welfare.manage', $le);
        if ($t['status'] !== 'active') {
            throw new BusinessException('welfare.error.not_active', [], 409);
        }
        $errors = [];
        if (self::ymd($lastDay) === null || $lastDay < (string) $t['effective_from'] || $lastDay < $this->today()) {
            $errors['last_day'] = 'welfare.error.last_day';
        }
        if (mb_strlen(trim($reason)) < 5) {
            $errors['reason'] = 'welfare.error.reason';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->app->db()->run('UPDATE wf_types SET effective_to = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$lastDay, $this->now(), $az->userId, $t['id']]);
        $this->app->audit()->record('welfare.type_retired', 'success', 'wf_type', $pid, $le, mb_substr(trim($reason), 0, 300), ['code' => $t['code'], 'last_day' => $lastDay]);
    }
}
