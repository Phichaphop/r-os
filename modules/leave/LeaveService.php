<?php

declare(strict_types=1);

namespace Blue\Modules\Leave;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;

/**
 * M32 Leave (DEC-63). Who may do what:
 *  - the employee (leave.use + an employee record linked to the account) requests, edits a returned request, withdraws
 *    a pending one, and asks to cancel an approved one that has not started;
 *  - the line manager (employment history) approves through the central inbox; with no manager it goes to HR
 *    (leave.approve) of the company — never to the system administrator;
 *  - HR (leave.manage) keeps types, adjusts balances with a reason, imports opening balances and may cancel any request;
 *    HR with leave.policy proposes the policy (Config Registry) and another HR with leave.policy_approve approves it.
 * Balances come from the append-only ledger only; every entry has an idempotency key.
 */
final class LeaveService
{
    public const MAX_DAYS = 120;
    private const OPEN = ['pending', 'approved', 'taken', 'returned', 'cancel_pending'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function emp(): EmployeeService
    {
        return EmployeeService::of($this->app);
    }

    public function today(): string
    {
        return $this->emp()->today();
    }

    // ---- types & policy ----------------------------------------------------------------------------

    /** @return list<array<string, mixed>> active (or all) types of a company */
    public function types(int $legalEntityId, bool $all = false): array
    {
        return $this->app->db()->all(
            'SELECT * FROM lv_types WHERE legal_entity_id = ?' . ($all ? '' : " AND status = 'active'") . ' ORDER BY sort_order, code',
            [$legalEntityId]
        );
    }

    /** @return array{value: array<string, mixed>, version_id: int, version_pid: string, from: string}|null policy in force for a company on a date */
    public function policy(int $legalEntityId, string $date): ?array
    {
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' 12:00:00', $this->tz()) ?: $this->app->clock->now();
        $r = $this->app->registry()->resolveOrNull('leave.policy', $legalEntityId, null, $at);
        if ($r === null || !is_array($r->value)) {
            return null;
        }
        return ['value' => $r->value, 'version_id' => $r->versionId, 'version_pid' => $r->versionPublicId, 'from' => $r->effectiveFrom];
    }

    /**
     * HR proposes a new policy version (effective from a date) → central approval by another HR approver.
     *
     * @param array<string, mixed> $in types => code => fields
     */
    public function proposePolicy(Authorizer $az, int $legalEntityId, array $in, string $from, string $source, string $reason): string
    {
        if (!$az->scope('leave.policy')->coversLegalEntity($legalEntityId)) {
            throw HttpException::forbidden();
        }
        $codes = array_map(static fn (array $t): string => (string) $t['code'], $this->types($legalEntityId));
        [$policy, $errors] = LeavePolicy::validate(['types' => $in], $codes);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $this->tz());
        if ($d === false) {
            $errors['effective_from'] = 'leave.policy_error.date';
        }
        if (mb_strlen(trim($source)) < 5) {
            $errors['source_reference'] = 'leave.policy_error.source';
        }
        if (mb_strlen(trim($reason)) < 3) {
            $errors['reason'] = 'leave.policy_error.reason';
        }
        if ($errors !== [] || $policy === null || $d === false) {
            throw new ValidationException($errors);
        }
        $reg = $this->app->registry();
        $pid = $reg->propose($az, 'leave.policy', 'legal_entity', $legalEntityId, (string) json_encode($policy, JSON_UNESCAPED_UNICODE), $d, null, trim($reason), trim($source));
        $reg->submit($az, $pid);
        return $pid;
    }

    /** @return list<array<string, mixed>> policy versions of a company (newest first) */
    public function policyVersions(int $legalEntityId): array
    {
        return $this->app->db()->all(
            "SELECT v.public_id, v.status, v.effective_from, v.effective_to, v.source_reference, v.reason, v.created_at, v.value_json,
                    p.display_name AS proposed_name, d.display_name AS decided_name, i.public_id AS instance_pid
               FROM config_versions v JOIN config_definitions c ON c.id = v.definition_id
               LEFT JOIN users p ON p.id = v.proposed_by LEFT JOIN users d ON d.id = v.decided_by LEFT JOIN workflow_instances i ON i.id = v.workflow_instance_id
              WHERE c.config_key = 'leave.policy' AND v.scope_level = 'legal_entity' AND v.scope_id = ? ORDER BY v.id DESC LIMIT 20",
            [$legalEntityId]
        );
    }

    /** @param array<string, mixed> $in */
    public function saveType(Authorizer $az, int $legalEntityId, ?string $pid, array $in): string
    {
        if (!$az->scope('leave.manage')->coversLegalEntity($legalEntityId)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $errors = [];
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if (preg_match('/^[A-Z][A-Z0-9_]{0,19}$/', $code) !== 1) {
            $errors['code'] = 'leave.error.type_code';
        }
        $th = trim((string) ($in['name_th'] ?? ''));
        if ($th === '' || mb_strlen($th) > 80) {
            $errors['name_th'] = 'leave.error.type_name';
        }
        $existing = $pid !== null ? $db->one('SELECT * FROM lv_types WHERE public_id = ? AND legal_entity_id = ?', [$pid, $legalEntityId]) ?? throw HttpException::notFound() : null;
        if ($existing === null && $code !== '' && $db->scalar('SELECT id FROM lv_types WHERE legal_entity_id = ? AND code = ?', [$legalEntityId, $code]) !== null) {
            $errors['code'] = 'leave.error.type_exists';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $vals = [
            'name_th' => mb_substr($th, 0, 80), 'name_en' => trim((string) ($in['name_en'] ?? '')) === '' ? null : mb_substr(trim((string) $in['name_en']), 0, 80),
            'is_statutory' => !empty($in['is_statutory']) ? 1 : 0, 'allow_document' => !empty($in['allow_document']) ? 1 : 0,
            'sort_order' => max(0, min(999, (int) ($in['sort_order'] ?? 100))), 'status' => ($in['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        ];
        $now = $this->now();
        if ($existing === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO lv_types (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, code, name_th, name_en, is_statutory, allow_document, sort_order, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $legalEntityId, $code, $vals['name_th'], $vals['name_en'], $vals['is_statutory'], $vals['allow_document'], $vals['sort_order'], $vals['status']]
            );
            $this->app->audit()->record('leave.type_created', 'success', 'lv_type', $pid, $legalEntityId, null, ['code' => $code] + $vals);
            return $pid;
        }
        $db->run(
            'UPDATE lv_types SET name_th = ?, name_en = ?, is_statutory = ?, allow_document = ?, sort_order = ?, status = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$vals['name_th'], $vals['name_en'], $vals['is_statutory'], $vals['allow_document'], $vals['sort_order'], $vals['status'], $now, $az->userId, $existing['id']]
        );
        $this->app->audit()->record('leave.type_updated', 'success', 'lv_type', (string) $pid, $legalEntityId, null, ['code' => $existing['code'], 'before' => array_intersect_key($existing, $vals), 'after' => $vals]);
        return (string) $pid;
    }

    // ---- balances ------------------------------------------------------------------------------------

    /**
     * Balance of one type for a leave year: creates the year's grant (and carry-forward) on first use, from the policy
     * in force on the first day of the year (or the hire date / today when the policy started later).
     *
     * @param array<string, mixed> $employee employee row with 'job'
     * @param array<string, mixed> $type lv_types row
     * @return array{tracked: bool, granted: float, opening: float, carried: float, taken: float, adjusted: float, balance: float, pending: float, available: float, rule: ?array<string, mixed>, policy: ?array<string, mixed>}
     */
    public function balance(array $employee, array $type, int $year): array
    {
        $policy = $this->policyForYear((int) $type['legal_entity_id'], $year, (string) $employee['hire_date']);
        $job = $this->emp()->employment((int) $employee['id'], $this->clampToYear($this->today(), $year)) ?? $employee['job'];
        $rule = LeavePolicy::rule($policy['value'] ?? null, (string) $type['code'], (string) ($job['employee_group'] ?? ''));
        if ($rule !== null) {
            $this->ensureGrant($employee, $type, $year, $rule, $policy);
        }
        $sum = [];
        foreach (
            $this->app->db()->all(
                'SELECT entry_type, SUM(units) AS u FROM lv_ledger WHERE employee_id = ? AND lv_type_id = ? AND leave_year = ? GROUP BY entry_type',
                [$employee['id'], $type['id'], $year]
            ) as $r
        ) {
            $sum[(string) $r['entry_type']] = (float) $r['u'];
        }
        $pending = (float) ($this->app->db()->scalar(
            "SELECT COALESCE(SUM(units), 0) FROM lv_requests WHERE employee_id = ? AND lv_type_id = ? AND leave_year = ? AND status IN ('pending','returned')",
            [$employee['id'], $type['id'], $year]
        ) ?? 0);
        $balance = array_sum($sum);
        $tracked = $rule !== null && $rule['entitlement'] !== null;
        return [
            'tracked' => $tracked, 'granted' => $sum['grant'] ?? 0.0, 'opening' => $sum['opening_balance'] ?? 0.0, 'carried' => $sum['carry_forward'] ?? 0.0,
            'taken' => -(($sum['taken'] ?? 0.0) + ($sum['cancel_reversal'] ?? 0.0)), 'adjusted' => $sum['adjustment'] ?? 0.0,
            'balance' => $balance, 'pending' => $pending, 'available' => $balance - $pending, 'rule' => $rule, 'policy' => $policy,
        ];
    }

    /** @return array<string, mixed>|null */
    private function policyForYear(int $legalEntityId, int $year, string $hireDate): ?array
    {
        $start = max(sprintf('%04d-01-01', $year), $hireDate);
        $p = $start <= sprintf('%04d-12-31', $year) ? $this->policy($legalEntityId, $start) : null;
        if ($p === null) {
            $today = $this->today();
            if ((int) substr($today, 0, 4) === $year) {
                $p = $this->policy($legalEntityId, $today); // the policy began during the year
            }
        }
        return $p;
    }

    private function clampToYear(string $date, int $year): string
    {
        $y = (int) substr($date, 0, 4);
        return $y === $year ? $date : ($y > $year ? sprintf('%04d-12-31', $year) : sprintf('%04d-01-01', $year));
    }

    /**
     * @param array<string, mixed> $employee
     * @param array<string, mixed> $type
     * @param array<string, mixed> $rule
     * @param array<string, mixed>|null $policy
     */
    private function ensureGrant(array $employee, array $type, int $year, array $rule, ?array $policy): void
    {
        $g = LeavePolicy::grant($rule, $year, (string) $employee['hire_date']);
        if ($g === null) {
            return;
        }
        $vid = $policy['version_id'] ?? null;
        $this->post(
            $employee,
            $type,
            $year,
            'grant',
            $g['units'],
            'policy',
            $policy['version_pid'] ?? null,
            "grant:{$employee['id']}:{$type['id']}:{$year}",
            sprintf('%04d-01-01', $year),
            $vid,
            $g['statutory'],
            null,
            null
        );
        $max = (float) ($rule['carry_forward_max_days'] ?? 0);
        if ($max > 0) {
            $prev = $this->app->db()->one(
                'SELECT COUNT(*) AS n, COALESCE(SUM(units), 0) AS u FROM lv_ledger WHERE employee_id = ? AND lv_type_id = ? AND leave_year = ?',
                [$employee['id'], $type['id'], $year - 1]
            );
            if ($prev !== null && (int) $prev['n'] > 0) {
                $carry = min(max(0.0, floor((float) $prev['u'] * 2) / 2), $max);
                $this->post(
                    $employee,
                    $type,
                    $year,
                    'carry_forward',
                    $carry,
                    'policy',
                    $policy['version_pid'] ?? null,
                    "carry:{$employee['id']}:{$type['id']}:{$year}",
                    sprintf('%04d-01-01', $year),
                    $vid,
                    null,
                    null,
                    null
                );
            }
        }
    }

    /**
     * Writes one ledger entry once (idempotency key). Returns true when it was written now.
     *
     * @param array<string, mixed> $employee
     * @param array<string, mixed> $type
     */
    private function post(
        array $employee,
        array $type,
        int $year,
        string $entry,
        float $units,
        string $sourceType,
        ?string $sourceId,
        string $key,
        string $entryDate,
        ?int $policyVersionId,
        ?float $statutory,
        ?string $note,
        ?int $actor
    ): bool {
        $db = $this->app->db();
        if ($db->scalar('SELECT id FROM lv_ledger WHERE idempotency_key = ?', [$key]) !== null) {
            return false;
        }
        $db->run(
            'INSERT IGNORE INTO lv_ledger (public_id, created_at, created_by, employee_id, legal_entity_id, lv_type_id, leave_year, entry_date, entry_type, units, statutory_units,
                source_type, source_id, policy_version_id, note, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [Ulid::generate($this->app->clock), $this->now(), $actor, $employee['id'], $type['legal_entity_id'], $type['id'], $year, $entryDate, $entry, $units, $statutory,
                $sourceType, $sourceId, $policyVersionId, $note, $key]
        );
        return true;
    }

    /** @return list<array<string, mixed>> ledger of an employee for a year (with type names) */
    public function ledger(int $employeeId, int $year): array
    {
        return $this->app->db()->all(
            'SELECT l.*, t.code, t.name_th, t.name_en, u.display_name AS actor_name FROM lv_ledger l JOIN lv_types t ON t.id = l.lv_type_id LEFT JOIN users u ON u.id = l.created_by
              WHERE l.employee_id = ? AND l.leave_year = ? ORDER BY t.sort_order, t.code, l.id',
            [$employeeId, $year]
        );
    }

    /** HR correction of a balance (+/−, half days), with a reason — a new ledger entry, never an edit. */
    public function adjust(Authorizer $az, string $employeePid, string $typePid, int $year, string $units, string $reason): void
    {
        [$employee, $type] = $this->hrTarget($az, $employeePid, $typePid);
        if ((int) ($this->emp()->forUser($az->userId)['id'] ?? 0) === (int) $employee['id']) {
            throw new BusinessException('leave.error.own_adjust', [], 403); // nobody corrects their own balance
        }
        $errors = [];
        $u = is_numeric($units) ? (float) $units : null;
        if ($u === null || $u === 0.0 || abs($u) > 366 || fmod(abs($u) * 2, 1.0) !== 0.0) {
            $errors['units'] = 'leave.error.adjust_units';
        }
        if ($year < 2000 || $year > 2100) {
            $errors['year'] = 'leave.error.year';
        }
        if (mb_strlen(trim($reason)) < 3) {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $key = 'adjust:' . Ulid::generate($this->app->clock);
        $this->post($employee, $type, $year, 'adjustment', (float) $u, 'adjustment', null, $key, $this->today(), null, null, mb_substr(trim($reason), 0, 255), $az->userId);
        $this->app->audit()->record('leave.adjusted', 'success', 'employee', $employeePid, (int) $type['legal_entity_id'], trim($reason), ['type' => $type['code'], 'year' => $year, 'units' => $u]);
    }

    /**
     * Opening balance (DEC-27) for one employee/type/year — once; a second import of the same is refused.
     *
     * @param array<string, mixed> $employee
     * @param array<string, mixed> $type
     */
    public function postOpening(array $employee, array $type, int $year, float $units, int $actorId, int $batchId): bool
    {
        return $this->post(
            $employee,
            $type,
            $year,
            'opening_balance',
            $units,
            'import',
            (string) $batchId,
            "opening:{$employee['id']}:{$type['id']}:{$year}",
            sprintf('%04d-01-01', $year),
            null,
            null,
            'opening balance import #' . $batchId,
            $actorId
        );
    }

    public function hasOpening(int $employeeId, int $typeId, int $year): bool
    {
        return $this->app->db()->scalar('SELECT id FROM lv_ledger WHERE idempotency_key = ?', ["opening:{$employeeId}:{$typeId}:{$year}"]) !== null;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function hrTarget(Authorizer $az, string $employeePid, string $typePid): array
    {
        $e = $this->app->db()->one('SELECT * FROM employees WHERE public_id = ?', [$employeePid]) ?? throw HttpException::notFound();
        $e['job'] = $this->emp()->employment((int) $e['id'], $this->today()) ?? $this->app->db()->one('SELECT * FROM employment_history WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 1', [$e['id']]);
        if (!EmployeeService::covers($az->scope('leave.manage'), $e['job'])) {
            throw HttpException::forbidden();
        }
        $t = $this->app->db()->one('SELECT * FROM lv_types WHERE public_id = ? AND legal_entity_id = ?', [$typePid, (int) ($e['job']['legal_entity_id'] ?? 0)]) ?? throw HttpException::notFound();
        return [$e, $t];
    }

    // ---- requests -------------------------------------------------------------------------------------

    /**
     * Checks a request and counts its working days without saving (live preview and the first step of submit).
     *
     * @param array<string, mixed> $employee with 'job'
     * @param array<string, mixed> $in type (public id), start_date, end_date, part, reason
     * @return array{type: array<string, mixed>, start: string, end: string, part: string, days: list<string>, units: float, year: int, balance: array<string, mixed>, needs_document: bool, warnings: list<string>}
     */
    public function evaluate(array $employee, array $in, ?int $exceptRequestId = null): array
    {
        $db = $this->app->db();
        $errors = [];
        $job = $employee['job'];
        if ($job === null || $employee['status'] !== 'active') {
            throw new BusinessException('leave.error.not_employed', [], 409);
        }
        $le = (int) $job['legal_entity_id'];
        $type = $db->one("SELECT * FROM lv_types WHERE public_id = ? AND legal_entity_id = ? AND status = 'active'", [(string) ($in['type'] ?? ''), $le]);
        if ($type === null) {
            throw new ValidationException(['type' => 'leave.error.type_required']);
        }
        $start = (string) ($in['start_date'] ?? '');
        $end = trim((string) ($in['end_date'] ?? '')) === '' ? $start : (string) $in['end_date'];
        $part = in_array($in['part'] ?? 'full', ['full', 'am', 'pm'], true) ? (string) ($in['part'] ?? 'full') : 'full';
        $ds = DateTimeImmutable::createFromFormat('!Y-m-d', $start, new DateTimeZone('UTC'));
        $de = DateTimeImmutable::createFromFormat('!Y-m-d', $end, new DateTimeZone('UTC'));
        if ($ds === false || $ds->format('Y-m-d') !== $start) {
            throw new ValidationException(['start_date' => 'leave.error.date']);
        }
        if ($de === false || $de->format('Y-m-d') !== $end || $end < $start) {
            throw new ValidationException(['end_date' => 'leave.error.end_before_start']);
        }
        if (substr($start, 0, 4) !== substr($end, 0, 4)) {
            throw new ValidationException(['end_date' => 'leave.error.cross_year']);
        }
        if ((int) $ds->diff($de)->days + 1 > self::MAX_DAYS) {
            throw new ValidationException(['end_date' => 'leave.error.too_long']);
        }
        $year = (int) substr($start, 0, 4);
        $bal = $this->balance($employee, $type, $year);
        $rule = $bal['rule'];
        if ($rule === null) {
            throw new BusinessException('leave.error.no_policy', ['type' => (string) $type['name_th']], 409);
        }
        if ($part !== 'full' && ($start !== $end || !$rule['half_day'])) {
            $errors['part'] = $start !== $end ? 'leave.error.half_single_day' : 'leave.error.half_not_allowed';
        }
        $today = $this->today();
        if ($rule['max_backdate_days'] !== null && $start < EmployeeService::ymd($today)->modify('-' . (int) $rule['max_backdate_days'] . ' days')->format('Y-m-d')) {
            $errors['start_date'] = 'leave.error.backdate';
        }
        if ($rule['min_notice_days'] !== null && (int) $rule['min_notice_days'] > 0 && $start < EmployeeService::ymd($today)->modify('+' . (int) $rule['min_notice_days'] . ' days')->format('Y-m-d')) {
            $errors['start_date'] = 'leave.error.notice';
        }
        if ((int) $rule['min_service_months'] > 0 && LeavePolicy::serviceMonths((string) $employee['hire_date'], $start) < (int) $rule['min_service_months']) {
            $errors['start_date'] = 'leave.error.service';
        }
        // 0.7.0: days of a closed attendance period cannot change (payroll already took them) — reopen the period first
        foreach ($this->app->locks()->lockedRanges('attendance', $le) as [$la, $lb]) {
            if ($start <= $lb && $end >= $la) {
                $errors['start_date'] = 'leave.error.period_closed';
            }
        }
        if ($start < (string) $employee['hire_date']) {
            $errors['start_date'] = 'leave.error.before_hire';
        }
        // working days from the work calendar of the site (or company) on each date — never guessed
        $days = [];
        $cal = $this->app->calendars();
        for ($d = $ds; $d <= $de; $d = $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            $jobOn = $this->emp()->employment((int) $employee['id'], $ymd) ?? $job;
            $w = $cal->isWorkingDay($jobOn['site_id'] !== null ? (int) $jobOn['site_id'] : null, (int) $jobOn['legal_entity_id'], $ymd);
            if ($w['working'] === null) {
                throw new BusinessException('leave.error.no_calendar', [], 409);
            }
            if ($w['working']) {
                $days[] = $ymd;
            }
        }
        $units = $part === 'full' ? (float) count($days) : ($days === [] ? 0.0 : 0.5);
        if ($units <= 0) {
            $errors['end_date'] = 'leave.error.no_working_days';
        }
        // overlap with the employee's other open requests (two half days of the same date may coexist)
        foreach (
            $db->all(
                'SELECT id, start_date, end_date, part FROM lv_requests WHERE employee_id = ? AND status IN (' . implode(',', array_fill(0, count(self::OPEN), '?')) . ')
               AND start_date <= ? AND end_date >= ?' . ($exceptRequestId !== null ? ' AND id <> ?' : ''),
                array_merge([$employee['id']], self::OPEN, [$end, $start], $exceptRequestId !== null ? [$exceptRequestId] : [])
            ) as $o
        ) {
            $halves = $part !== 'full' && $o['part'] !== 'full' && $o['part'] !== $part && $o['start_date'] === $start && $o['end_date'] === $start;
            if (!$halves) {
                $errors['start_date'] = 'leave.error.overlap';
            }
        }
        $available = $bal['available'] + ($exceptRequestId !== null ? (float) ($db->scalar("SELECT units FROM lv_requests WHERE id = ? AND status IN ('pending','returned')", [$exceptRequestId]) ?? 0) : 0.0);
        if ($bal['tracked'] && !$rule['allow_negative'] && $units > $available) {
            $errors['end_date'] = 'leave.error.balance';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $needsDoc = $rule['document_from_days'] !== null && $units >= (float) $rule['document_from_days'];
        $warnings = [];
        if ($bal['tracked'] && $rule['allow_negative'] && $units > $available) {
            $warnings[] = 'leave.warn.negative';
        }
        return ['type' => $type, 'start' => $start, 'end' => $end, 'part' => $part, 'days' => $days, 'units' => $units, 'year' => $year,
            'balance' => $bal + ['available_for_this' => $available], 'needs_document' => $needsDoc, 'warnings' => $warnings];
    }

    /**
     * Employee submits a request (own record only) → central approval (line manager, else HR).
     *
     * @param array<string, mixed> $in
     */
    public function submit(Authorizer $az, array $in, ?UploadedFile $file): string
    {
        $employee = $this->self($az);
        $db = $this->app->db();
        return $db->transactional(function () use ($az, $in, $file, $employee, $db): string {
            $db->one('SELECT id FROM employees WHERE id = ? FOR UPDATE', [$employee['id']]); // one request at a time per employee (balance, overlap)
            $ev = $this->evaluate($employee, $in);
            $docPid = $this->storeDocument($az, $ev, $file, null);
            $pid = Ulid::generate($this->app->clock);
            $job = $employee['job'];
            $now = $this->now();
            $db->run(
                'INSERT INTO lv_requests (public_id, created_at, created_by, updated_at, updated_by, employee_id, legal_entity_id, site_id, department_id, lv_type_id, start_date, end_date,
                    part, units, leave_year, days_json, reason, document_pid, status, policy_version_id, requested_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $employee['id'], $job['legal_entity_id'], $job['site_id'], $job['department_id'], $ev['type']['id'], $ev['start'], $ev['end'],
                    $ev['part'], $ev['units'], $ev['year'], json_encode($ev['days']), $this->reason($in), $docPid, $ev['balance']['policy']['version_id'] ?? null, $az->userId]
            );
            $title = $this->title($employee, $ev['type'], $ev['start'], $ev['end'], $ev['units']);
            $inst = $this->app->workflow()->start(
                'leave.request',
                $pid,
                $title,
                (int) $job['legal_entity_id'],
                $az->userId,
                $job['site_id'] !== null ? (int) $job['site_id'] : null,
                $job['department_id'] !== null ? (int) $job['department_id'] : null
            );
            $db->run('UPDATE lv_requests SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE public_id = ?', [$inst, $pid]);
            $this->app->audit()->record('leave.requested', 'success', 'lv_request', $pid, (int) $job['legal_entity_id'], null, [
                'type' => $ev['type']['code'], 'start' => $ev['start'], 'end' => $ev['end'], 'units' => $ev['units'], 'document' => $docPid !== null,
            ]);
            return $pid;
        });
    }

    /**
     * Employee edits a request that was returned for changes and sends it again.
     *
     * @param array<string, mixed> $in
     */
    public function resubmit(Authorizer $az, string $pid, array $in, ?UploadedFile $file): void
    {
        $employee = $this->self($az);
        $db = $this->app->db();
        $db->transactional(function () use ($az, $pid, $in, $file, $employee, $db): void {
            $req = $db->one('SELECT * FROM lv_requests WHERE public_id = ? FOR UPDATE', [$pid]) ?? throw HttpException::notFound();
            if ((int) $req['employee_id'] !== (int) $employee['id']) {
                throw HttpException::notFound();
            }
            if ($req['status'] !== 'returned') {
                throw new BusinessException('leave.error.not_returned', [], 409);
            }
            $ev = $this->evaluate($employee, $in, (int) $req['id']);
            $docPid = $this->storeDocument($az, $ev, $file, $req['document_pid'] !== null ? (string) $req['document_pid'] : null);
            $db->run(
                'UPDATE lv_requests SET lv_type_id = ?, start_date = ?, end_date = ?, part = ?, units = ?, leave_year = ?, days_json = ?, reason = ?, document_pid = ?, policy_version_id = ?,
                    updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                [$ev['type']['id'], $ev['start'], $ev['end'], $ev['part'], $ev['units'], $ev['year'], json_encode($ev['days']), $this->reason($in), $docPid,
                    $ev['balance']['policy']['version_id'] ?? null, $this->now(), $az->userId, $req['id']]
            );
            $inst = (string) $db->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$req['workflow_instance_id']]);
            $db->run('UPDATE workflow_instances SET title = ? WHERE id = ?', [mb_substr($this->title($employee, $ev['type'], $ev['start'], $ev['end'], $ev['units']), 0, 200), $req['workflow_instance_id']]);
            $this->app->workflow()->resubmit($inst, $az->userId, $this->reason($in) ?? '');
            $this->app->audit()->record('leave.resubmitted', 'success', 'lv_request', $pid, (int) $req['legal_entity_id'], null, ['start' => $ev['start'], 'end' => $ev['end'], 'units' => $ev['units']]);
        });
    }

    /**
     * Cancelling:
     *  - pending/returned, by the employee → withdrawn (the approval request is cancelled);
     *  - approved and not started, by the employee → a cancellation request to the manager (balance returned when approved);
     *  - by HR (leave.manage) for SOMEONE ELSE at any stage except already cancelled/rejected → cancelled at once, balance returned.
     */
    public function cancel(Authorizer $az, string $pid, string $reason): string
    {
        $db = $this->app->db();
        $req = $db->one('SELECT * FROM lv_requests WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $own = (int) ($this->emp()->forUser($az->userId)['id'] ?? 0) === (int) $req['employee_id'];
        $hr = EmployeeService::covers($az->scope('leave.manage'), $req);
        if (!$own && !$hr) {
            throw HttpException::notFound();
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $reason = mb_substr(trim($reason), 0, 300);
        if ($own && in_array($req['status'], ['pending', 'returned'], true)) {
            $inst = (string) $db->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$req['workflow_instance_id']]);
            $this->app->workflow()->cancel($inst, $az->userId, $reason); // onClosed → cancelled
            $db->run('UPDATE lv_requests SET cancel_reason = ? WHERE id = ?', [$reason, $req['id']]);
            $this->app->audit()->record('leave.withdrawn', 'success', 'lv_request', $pid, (int) $req['legal_entity_id'], $reason);
            return 'withdrawn';
        }
        if ($own && $req['status'] === 'approved') { // own leave always goes to the manager, also for HR staff
            if ((string) $req['start_date'] <= $this->today()) {
                throw new BusinessException('leave.error.cancel_started', [], 409);
            }
            return $db->transactional(function () use ($db, $req, $pid, $az, $reason): string {
                $db->run("UPDATE lv_requests SET status = 'cancel_pending', cancel_reason = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'approved'", [$reason, $this->now(), $req['id']]);
                $employee = $this->emp()->byId((int) $req['employee_id']) ?? throw HttpException::notFound();
                $type = $db->one('SELECT * FROM lv_types WHERE id = ?', [$req['lv_type_id']]) ?? [];
                $title = $this->app->translator()->get('leave.cancel_title') . ' · ' . $this->title($employee, $type, (string) $req['start_date'], (string) $req['end_date'], (float) $req['units']);
                $inst = $this->app->workflow()->start('leave.cancel', $pid, $title, (int) $req['legal_entity_id'], $az->userId);
                $db->run('UPDATE lv_requests SET cancel_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE id = ?', [$inst, $req['id']]);
                $this->app->audit()->record('leave.cancel_requested', 'success', 'lv_request', $pid, (int) $req['legal_entity_id'], $reason);
                return 'cancel_requested';
            });
        }
        if ($hr && !$own && in_array($req['status'], ['pending', 'returned', 'approved', 'taken', 'cancel_pending'], true)) {
            if (in_array($req['status'], ['approved', 'taken', 'cancel_pending'], true)) {
                $this->app->locks()->assertRangeWritable('attendance', (string) $req['start_date'], (string) $req['end_date'], (int) $req['legal_entity_id'], null, 'ui', $az->userId);
            }
            return $db->transactional(function () use ($db, $req, $pid, $az, $reason): string {
                foreach ([$req['workflow_instance_id'], $req['cancel_instance_id']] as $iid) {
                    if ($iid !== null) { // close an open approval quietly — HR's cancellation decides
                        $db->run("UPDATE workflow_tasks SET status = 'void' WHERE instance_id = ? AND status = 'open'", [$iid]);
                        $db->run("UPDATE workflow_instances SET status = 'cancelled', finished_at = ?, updated_at = ? WHERE id = ? AND status IN ('pending','returned','needs_attention')", [$this->now(), $this->now(), $iid]);
                    }
                }
                $wasTaken = in_array($req['status'], ['approved', 'taken', 'cancel_pending'], true);
                $db->run("UPDATE lv_requests SET status = 'cancelled', cancel_reason = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$reason, $this->now(), $az->userId, $req['id']]);
                if ($wasTaken) {
                    $this->reverse($req, $az->userId);
                }
                $this->app->audit()->record('leave.cancelled_by_hr', 'success', 'lv_request', $pid, (int) $req['legal_entity_id'], $reason, ['status_before' => $req['status']]);
                return 'cancelled';
            });
        }
        throw new BusinessException('leave.error.cannot_cancel', [], 409);
    }

    // ---- called by the workflow subjects ---------------------------------------------------------------

    /** Request approved → ledger "taken" + leave.request.approved (idempotent). */
    public function onApproved(string $pid, int $approverId): void
    {
        $db = $this->app->db();
        $req = $db->one('SELECT * FROM lv_requests WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        if (!in_array($req['status'], ['pending', 'approved'], true)) {
            throw new BusinessException('leave.error.not_pending', [], 409);
        }
        // 0.7.0: the days must not be in a closed attendance period (refused before anything changes)
        $this->app->locks()->assertRangeWritable('attendance', (string) $req['start_date'], (string) $req['end_date'], (int) $req['legal_entity_id'], null, 'ui', $approverId);
        $db->run("UPDATE lv_requests SET status = 'approved', decided_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'pending'", [$this->now(), $this->now(), $req['id']]);
        $employee = ['id' => (int) $req['employee_id']];
        $type = $db->one('SELECT * FROM lv_types WHERE id = ?', [$req['lv_type_id']]) ?? throw HttpException::notFound();
        $this->post(
            $employee,
            $type,
            (int) $req['leave_year'],
            'taken',
            -(float) $req['units'],
            'lv_request',
            $pid,
            'taken:' . $pid,
            (string) $req['start_date'],
            $req['policy_version_id'] !== null ? (int) $req['policy_version_id'] : null,
            null,
            null,
            $approverId
        );
        $this->app->outbox()->record('leave.request.approved', [
            'request_public_id' => $pid, 'employee_public_id' => (string) $db->scalar('SELECT public_id FROM employees WHERE id = ?', [$req['employee_id']]),
            'lv_type_code' => (string) $type['code'], 'start_at' => (string) $req['start_date'], 'end_at' => (string) $req['end_date'], 'part' => (string) $req['part'],
            'units' => (float) $req['units'], 'unit' => 'day', 'days' => json_decode((string) $req['days_json'], true), 'rule_version_id' => $req['policy_version_id'],
        ], 'leave.request.approved:' . $pid, (int) $req['legal_entity_id'], $req['site_id'] !== null ? (int) $req['site_id'] : null);
        $this->app->audit()->record('leave.approved', 'success', 'lv_request', $pid, (int) $req['legal_entity_id'], null, [], $approverId);
    }

    public function onClosed(string $pid, string $outcome): void
    {
        $status = ['rejected' => 'rejected', 'returned' => 'returned', 'cancelled' => 'cancelled'][$outcome] ?? null;
        if ($status !== null) {
            $this->app->db()->run(
                "UPDATE lv_requests SET status = ?, decided_at = ?, updated_at = ?, row_version = row_version + 1 WHERE public_id = ? AND status IN ('pending','returned')",
                [$status, $this->now(), $this->now(), $pid]
            );
        }
    }

    public function onResubmitted(string $pid): void
    {
        $this->app->db()->run("UPDATE lv_requests SET status = 'pending', updated_at = ?, row_version = row_version + 1 WHERE public_id = ? AND status = 'returned'", [$this->now(), $pid]);
    }

    /** Cancellation approved → cancelled + ledger reversal + leave.request.cancelled. */
    public function onCancelApproved(string $pid, int $approverId): void
    {
        $db = $this->app->db();
        $req = $db->one('SELECT * FROM lv_requests WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        if ($req['status'] === 'cancelled') {
            return;
        }
        if ($req['status'] !== 'cancel_pending') {
            throw new BusinessException('leave.error.not_cancel_pending', [], 409);
        }
        $this->app->locks()->assertRangeWritable('attendance', (string) $req['start_date'], (string) $req['end_date'], (int) $req['legal_entity_id'], null, 'ui', $approverId);
        $db->run("UPDATE lv_requests SET status = 'cancelled', updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $req['id']]);
        $this->reverse($req, $approverId);
        $this->app->audit()->record('leave.cancel_approved', 'success', 'lv_request', $pid, (int) $req['legal_entity_id'], null, [], $approverId);
    }

    /** Cancellation refused/withdrawn → the leave stays approved. */
    public function onCancelClosed(string $pid): void
    {
        $this->app->db()->run("UPDATE lv_requests SET status = 'approved', updated_at = ?, row_version = row_version + 1 WHERE public_id = ? AND status = 'cancel_pending'", [$this->now(), $pid]);
    }

    /** A returned cancellation sent again → waiting again (unless the leave has started in between). */
    public function onCancelResubmitted(string $pid): void
    {
        $this->app->db()->run(
            "UPDATE lv_requests SET status = 'cancel_pending', updated_at = ?, row_version = row_version + 1 WHERE public_id = ? AND status = 'approved' AND start_date > ?",
            [$this->now(), $pid, $this->today()]
        );
    }

    /** @param array<string, mixed> $req */
    private function reverse(array $req, int $actorId): void
    {
        $type = $this->app->db()->one('SELECT * FROM lv_types WHERE id = ?', [$req['lv_type_id']]) ?? throw HttpException::notFound();
        $pid = (string) $req['public_id'];
        if ($this->app->db()->scalar('SELECT id FROM lv_ledger WHERE idempotency_key = ?', ['taken:' . $pid]) === null) {
            return; // never posted (approval failed) — nothing to give back
        }
        $this->post(
            ['id' => (int) $req['employee_id']],
            $type,
            (int) $req['leave_year'],
            'cancel_reversal',
            (float) $req['units'],
            'lv_request',
            $pid,
            'cancel:' . $pid,
            $this->today(),
            $req['policy_version_id'] !== null ? (int) $req['policy_version_id'] : null,
            null,
            null,
            $actorId
        );
        $this->app->outbox()->record('leave.request.cancelled', [
            'request_public_id' => $pid, 'reverses_event_key' => 'leave.request.approved:' . $pid, 'start_at' => (string) $req['start_date'], 'end_at' => (string) $req['end_date'],
            'units' => (float) $req['units'],
        ], 'leave.request.cancelled:' . $pid, (int) $req['legal_entity_id'], $req['site_id'] !== null ? (int) $req['site_id'] : null);
    }

    /**
     * Line managers (active accounts) of the request's employee — workflow approver type "manager".
     *
     * @return list<int>
     */
    public function managerUserIds(string $pid): array
    {
        $empId = $this->app->db()->scalar('SELECT employee_id FROM lv_requests WHERE public_id = ?', [$pid]);
        return $empId === null ? [] : $this->emp()->managerUserIds((int) $empId, $this->today());
    }

    // ---- reading ----------------------------------------------------------------------------------------

    /**
     * A request with the right to see it: the employee, their line manager, an approver of its open task, or HR
     * (leave.approve / leave.manage) covering the employee's company.
     *
     * @return array<string, mixed>
     */
    public function request(Authorizer $az, string $pid): array
    {
        $db = $this->app->db();
        $r = $db->one(
            'SELECT r.*, t.code AS type_code, t.name_th AS type_th, t.name_en AS type_en, t.is_statutory, e.public_id AS employee_pid, e.employee_code, e.name_th, e.name_en,
                    e.preferred_name, e.hire_date, w.public_id AS instance_pid, w.status AS instance_status, c.public_id AS cancel_instance_pid
               FROM lv_requests r JOIN lv_types t ON t.id = r.lv_type_id JOIN employees e ON e.id = r.employee_id
               LEFT JOIN workflow_instances w ON w.id = r.workflow_instance_id LEFT JOIN workflow_instances c ON c.id = r.cancel_instance_id WHERE r.public_id = ?',
            [$pid]
        ) ?? throw HttpException::notFound();
        $access = $this->access($az, $r);
        if ($access === null) {
            throw HttpException::notFound();
        }
        $r['access'] = $access;
        $r['days'] = json_decode((string) $r['days_json'], true) ?: [];
        return $r;
    }

    /** @param array<string, mixed> $r request row (+ instance ids) @return 'own'|'manager'|'approver'|'hr'|null */
    private function access(Authorizer $az, array $r): ?string
    {
        $me = $this->emp()->forUser($az->userId);
        if ($me !== null && (int) $me['id'] === (int) $r['employee_id']) {
            return 'own';
        }
        if (EmployeeService::covers($az->scope('leave.manage'), $r) || EmployeeService::covers($az->scope('leave.approve'), $r)) {
            return 'hr';
        }
        if (in_array($az->userId, $this->emp()->managerUserIds((int) $r['employee_id'], $this->today()), true)) {
            return 'manager';
        }
        foreach ([$r['workflow_instance_id'] ?? null, $r['cancel_instance_id'] ?? null] as $iid) {
            if ($iid !== null && $this->app->db()->scalar('SELECT id FROM workflow_tasks WHERE instance_id = ? AND assignee_id = ?', [$iid, $az->userId]) !== null) {
                return 'approver';
            }
        }
        return null;
    }

    /**
     * May this user open the supporting document (medical certificate)? Owner, deciding manager/approver, HR with leave.medical.
     *
     * @param array<string, mixed> $r
     */
    public function canSeeDocument(Authorizer $az, array $r): bool
    {
        return in_array($r['access'], ['own', 'manager', 'approver'], true) || EmployeeService::covers($az->scope('leave.medical'), $r);
    }

    /** @return array{name: string, bytes: string, mime: string} */
    public function document(Authorizer $az, string $pid): array
    {
        $r = $this->request($az, $pid);
        if ($r['document_pid'] === null || !$this->canSeeDocument($az, $r)) {
            throw HttpException::notFound();
        }
        $doc = $this->app->documents()->moduleRead((string) $r['document_pid'], 'leave', $az->userId, 'leave request ' . $pid, true);
        return ['name' => (string) $doc['filename'], 'bytes' => (string) $doc['bytes'], 'mime' => (string) $doc['mime']];
    }

    /**
     * Public read API for Attendance (DEC-64): approved leave of an employee per working day in [from, to].
     * Two half days of the same date are merged into "full".
     *
     * @return array<string, array{part: string, type_th: string, type_en: ?string}>
     */
    public function approvedDays(int $employeeId, string $from, string $to): array
    {
        $out = [];
        foreach (
            $this->app->db()->all(
                "SELECT r.part, r.days_json, t.name_th, t.name_en FROM lv_requests r JOIN lv_types t ON t.id = r.lv_type_id
                  WHERE r.employee_id = ? AND r.status IN ('approved','taken','cancel_pending') AND r.start_date <= ? AND r.end_date >= ?",
                [$employeeId, $to, $from]
            ) as $r
        ) {
            foreach ((array) json_decode((string) $r['days_json'], true) as $d) {
                if (!is_string($d) || $d < $from || $d > $to) {
                    continue;
                }
                $part = isset($out[$d]) && $out[$d]['part'] !== 'full' && $out[$d]['part'] !== $r['part'] ? 'full' : (string) $r['part'];
                $out[$d] = ['part' => $part, 'type_th' => (string) $r['name_th'], 'type_en' => $r['name_en'] !== null ? (string) $r['name_en'] : null];
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> the employee's requests (newest first) */
    public function myRequests(int $employeeId, int $limit = 50): array
    {
        return $this->app->db()->all(
            'SELECT r.*, t.code AS type_code, t.name_th AS type_th, t.name_en AS type_en FROM lv_requests r JOIN lv_types t ON t.id = r.lv_type_id
              WHERE r.employee_id = ? ORDER BY r.start_date DESC, r.id DESC LIMIT ' . (int) $limit,
            [$employeeId]
        );
    }

    /**
     * Who is off when: the user's direct reports (as line manager) and, for HR, everyone in scope.
     *
     * @return array{employees: array<int, array<string, mixed>>, leaves: list<array<string, mixed>>, team: bool, hr: bool}
     */
    public function calendar(Authorizer $az, int $year, int $month, ?int $legalEntityId): array
    {
        $db = $this->app->db();
        $first = sprintf('%04d-%02d-01', $year, $month);
        $last = EmployeeService::ymd($first)->modify('last day of this month')->format('Y-m-d');
        $ids = [];
        $me = $this->emp()->forUser($az->userId);
        if ($me !== null) {
            $ids = $this->emp()->directReports((int) $me['id'], $this->today());
        }
        $team = $ids !== [];
        $scope = $az->scope('leave.approve');
        $hr = !$scope->isEmpty();
        $where = [];
        $args = [$last, $first];
        if ($ids !== []) {
            $where[] = 'r.employee_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($args, ...$ids);
        }
        if ($hr) {
            [$sql, $p] = $scope->sql('r.legal_entity_id', 'r.site_id', 'r.department_id');
            $where[] = $legalEntityId !== null ? "({$sql} AND r.legal_entity_id = ?)" : $sql;
            array_push($args, ...$p);
            if ($legalEntityId !== null) {
                $args[] = $legalEntityId;
            }
        }
        if ($where === []) {
            return ['employees' => [], 'leaves' => [], 'team' => false, 'hr' => false];
        }
        $leaves = $db->all(
            "SELECT r.public_id, r.employee_id, r.start_date, r.end_date, r.part, r.units, r.status, r.days_json, t.code AS type_code, t.name_th AS type_th, t.name_en AS type_en
               FROM lv_requests r JOIN lv_types t ON t.id = r.lv_type_id
              WHERE r.status IN ('pending','approved','taken','cancel_pending') AND r.start_date <= ? AND r.end_date >= ? AND (" . implode(' OR ', $where) . ')
              ORDER BY r.start_date LIMIT 2000',
            $args
        );
        $empIds = array_values(array_unique(array_merge($ids, array_map(static fn (array $l): int => (int) $l['employee_id'], $leaves))));
        $emps = [];
        if ($empIds !== []) {
            foreach ($db->all('SELECT id, public_id, employee_code, name_th, name_en, preferred_name FROM employees WHERE id IN (' . implode(',', array_fill(0, count($empIds), '?')) . ') ORDER BY employee_code', $empIds) as $e) {
                $emps[(int) $e['id']] = $e;
            }
        }
        return ['employees' => $emps, 'leaves' => $leaves, 'team' => $team, 'hr' => $hr];
    }

    /**
     * Employees HR may look up (leave.manage scope) — the balance search.
     *
     * @return list<array<string, mixed>>
     */
    public function searchEmployees(Authorizer $az, string $q): array
    {
        $scope = $az->scope('leave.manage');
        if ($scope->isEmpty() || mb_strlen(trim($q)) < 1) {
            return [];
        }
        [$sql, $p] = $scope->sql('h.legal_entity_id', 'h.site_id', 'h.department_id');
        $today = $this->today();
        $like = '%' . addcslashes(trim($q), '%_\\') . '%';
        return $this->app->db()->all(
            "SELECT e.public_id, e.employee_code, e.name_th, e.preferred_name FROM employees e JOIN employment_history h ON h.employee_id = e.id
              WHERE h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?) AND {$sql} AND (e.employee_code LIKE ? OR e.name_th LIKE ? OR e.name_en LIKE ?)
              ORDER BY e.employee_code LIMIT 20",
            array_merge([$today, $today], $p, [$like, $like, $like])
        );
    }

    /** @return array<string, mixed> employee (with job) HR may manage */
    public function hrEmployee(Authorizer $az, string $pid): array
    {
        $e = $this->app->db()->one('SELECT * FROM employees WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $e['job'] = $this->emp()->employment((int) $e['id'], $this->today()) ?? $this->app->db()->one('SELECT * FROM employment_history WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 1', [$e['id']]);
        if (!EmployeeService::covers($az->scope('leave.manage'), $e['job'])) {
            throw HttpException::notFound();
        }
        return $e;
    }

    /**
     * The signed-in user's own employee record (with today's job) or a clear refusal.
     *
     * @return array<string, mixed>
     */
    public function self(Authorizer $az): array
    {
        if (!$az->can('leave.use')) {
            throw HttpException::forbidden();
        }
        return $this->emp()->forUser($az->userId) ?? throw new BusinessException('leave.error.no_employee', [], 409);
    }

    /** Scheduled: approved leave whose last day has passed becomes "taken" (status only — the ledger already has it). */
    public static function completeJob(App $app): int
    {
        if (!$app->db()->tableExists('lv_requests')) {
            return 0;
        }
        $svc = self::of($app);
        return $app->db()->run("UPDATE lv_requests SET status = 'taken', updated_at = ? WHERE status = 'approved' AND end_date < ?", [$svc->now(), $svc->today()])->rowCount();
    }

    // ---- helpers --------------------------------------------------------------------------------------

    /** @param array<string, mixed> $ev */
    private function storeDocument(Authorizer $az, array $ev, ?UploadedFile $file, ?string $existing): ?string
    {
        if ($file !== null && !$file->ok() && $file->errorKey() !== 'file.error.missing') {
            throw new ValidationException(['document' => $file->errorKey()]);
        }
        $hasFile = $file !== null && $file->ok();
        if (!$hasFile) {
            if ($ev['needs_document'] && $existing === null) {
                throw new ValidationException(['document' => 'leave.error.document_required']);
            }
            return $existing;
        }
        if ((int) $ev['type']['allow_document'] !== 1 && !$ev['needs_document']) {
            throw new ValidationException(['document' => 'leave.error.document_not_allowed']);
        }
        try {
            $stored = $this->app->documents()->storeGenerated($az->userId, 'leave.medical', $file->bytes(), $file->safeName(), [
                'title' => 'Leave document', 'legal_entity_id' => (int) $ev['type']['legal_entity_id'],
            ], 'upload', true);
        } catch (ValidationException $e) {
            throw new ValidationException(['document' => array_values($e->errors)[0] ?? 'leave.error.document_type']);
        }
        return (string) $stored['document'];
    }

    /** @param array<string, mixed> $in */
    private function reason(array $in): ?string
    {
        $r = trim((string) ($in['reason'] ?? ''));
        return $r === '' ? null : mb_substr($r, 0, 500);
    }

    /**
     * @param array<string, mixed> $employee
     * @param array<string, mixed> $type
     */
    private function title(array $employee, array $type, string $start, string $end, float $units): string
    {
        $t = $this->app->translator();
        $name = $this->emp()->displayName($employee);
        $typeName = $t->locale() === 'en' && ($type['name_en'] ?? '') !== '' ? (string) $type['name_en'] : (string) ($type['name_th'] ?? '');
        return $t->get('leave.wf_title', ['name' => $name, 'type' => $typeName, 'from' => $start, 'to' => $end, 'units' => self::units($units)]);
    }

    public static function units(float $u): string
    {
        return rtrim(rtrim(number_format($u, 1, '.', ''), '0'), '.');
    }

    private function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }
}
