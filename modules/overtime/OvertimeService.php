<?php

declare(strict_types=1);

namespace Blue\Modules\Overtime;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\Xlsx;
use Blue\Core\Support\Ulid;
use Blue\Modules\Attendance\AttendanceService;
use Blue\Modules\Attendance\WorkSchedule;
use Blue\Modules\Employees\EmployeeService;

/**
 * M33 OT Request (phase 5B, DEC-70).
 *
 *  request    the employee (or their line manager / HR for several people) asks for OT on a work date, start–end (end ≤
 *             start = past midnight). Checked: outside the working time of that day (shift or schedule), no overlap with
 *             other OT, not in a closed attendance period, the company's backdate / minimum / monthly-cap rules.
 *  day type   workday · dayoff · holiday — from the calendar and the published shift (a shift day off = dayoff,
 *             a public holiday stays holiday). The rate class / multiplier is NOT applied here: payroll gets them.
 *  approve    line manager, else overtime.approve of the employee's branch/unit (central inbox).
 *  verify     after the end time, the line manager or HR certifies payable minutes: proposed = requested ∩ scans
 *             (rounded down by the company rule); paying more than the scans show, or less than requested, needs a
 *             note; "disputed" holds a line for HR.
 *  batch      HR (overtime.export) freezes verified lines of a company and date range into a numbered batch with
 *             control totals and a hash; a line goes out once. Files are rebuilt only from the frozen lines.
 * Statuses: pending → approved → verified → sent · returned/rejected/cancelled · disputed (→ verified / rejected)
 */
final class OvertimeService
{
    public const OPEN = ['pending', 'returned', 'approved', 'disputed', 'verified', 'sent'];
    public const MAX_MINUTES = 16 * 60;
    public const MAX_BATCH_DAYS = 62;

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    public function emp(): EmployeeService
    {
        return EmployeeService::of($this->app);
    }

    public function att(): AttendanceService
    {
        return AttendanceService::of($this->app);
    }

    public function today(): string
    {
        return $this->emp()->today();
    }

    // ---- rules ------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null rules in force on the date (+ version_pid) */
    public function rules(int $le, string $date): ?array
    {
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' 12:00:00', $this->tz()) ?: $this->app->clock->now();
        $r = $this->app->registry()->resolveOrNull('overtime.rules', $le, null, $at);
        return $r !== null && is_array($r->value) ? $r->value + ['version_pid' => $r->versionPublicId, 'from' => $r->effectiveFrom] : null;
    }

    /** @param array<string, mixed> $in */
    public function proposeRules(Authorizer $az, int $le, array $in, string $from, string $source, string $reason): string
    {
        if (!$az->scope('overtime.policy')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        [$value, $errors] = OtRules::validate($in);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $this->tz());
        if ($d === false || $d->format('Y-m-d') !== $from) {
            $errors['effective_from'] = 'overtime.error.date';
        }
        if (mb_strlen(trim($source)) < 5) {
            $errors['source_reference'] = 'overtime.rules_error.source';
        }
        if (mb_strlen(trim($reason)) < 3) {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ($errors !== [] || $value === null || $d === false) {
            throw new ValidationException($errors);
        }
        $reg = $this->app->registry();
        $pid = $reg->propose($az, 'overtime.rules', 'legal_entity', $le, (string) json_encode($value, JSON_UNESCAPED_UNICODE), $d, null, trim($reason), trim($source));
        $reg->submit($az, $pid);
        return $pid;
    }

    // ---- requests ---------------------------------------------------------------------------------------------------

    /**
     * Who the user may file OT for: themselves; their direct reports; anyone HR (overtime.manage) covers.
     *
     * @param list<string> $employeePids empty = myself
     * @return list<array<string, mixed>> employees (with job on $date)
     */
    private function targets(Authorizer $az, array $employeePids, string $date): array
    {
        $me = $this->emp()->forUser($az->userId, $date);
        if ($employeePids === []) {
            if ($me === null) {
                throw new BusinessException('overtime.error.no_employee', [], 409);
            }
            return [$me];
        }
        $reports = $me !== null ? $this->emp()->directReports((int) $me['id'], $date) : [];
        $out = [];
        foreach (array_unique($employeePids) as $pid) {
            $id = $this->app->db()->scalar("SELECT id FROM employees WHERE public_id = ? AND status <> 'former'", [(string) $pid]);
            $e = $id !== null ? $this->emp()->byId((int) $id, $date) : null;
            if ($e === null || $e['job'] === null) {
                throw new ValidationException(['employees' => 'overtime.error.employee']);
            }
            $ok = ($me !== null && (int) $me['id'] === (int) $e['id']) || in_array((int) $e['id'], $reports, true) || EmployeeService::covers($az->scope('overtime.manage'), $e['job']);
            if (!$ok) {
                throw new ValidationException(['employees' => 'overtime.error.not_yours']);
            }
            $out[] = $e;
        }
        return $out;
    }

    /**
     * Check one employee's request; returns what would be stored.
     *
     * @param array<string, mixed> $employee with job
     * @param array<string, mixed> $in work_date, start, end
     * @return array<string, mixed>
     */
    public function evaluate(array $employee, array $in, ?int $exceptId = null): array
    {
        $errors = [];
        $date = (string) ($in['work_date'] ?? '');
        $sHm = trim((string) ($in['start'] ?? ''));
        $eHm = trim((string) ($in['end'] ?? ''));
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($dt === false || $dt->format('Y-m-d') !== $date) {
            throw new ValidationException(['work_date' => 'overtime.error.date']);
        }
        $s = WorkSchedule::minutes($sHm);
        $e = WorkSchedule::minutes($eHm);
        if ($s === null) {
            $errors['start'] = 'overtime.error.time';
        }
        if ($e === null) {
            $errors['end'] = 'overtime.error.time';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $endM = $e <= $s ? $e + 1440 : $e;
        $minutes = $endM - $s;
        if ($minutes > self::MAX_MINUTES) {
            $errors['end'] = 'overtime.error.too_long';
        }
        $att = $this->att();
        $plan = $att->plannedDay((int) $employee['id'], $date);
        if ($plan === null) {
            throw new ValidationException(['work_date' => 'overtime.error.not_employed']);
        }
        $job = $plan['job'];
        $le = (int) $job['legal_entity_id'];
        $rules = $this->rules($le, $date);
        // OT is time outside the working window of the day
        if ($plan['window'] !== null && $s < $plan['window'][1] && $endM > $plan['window'][0]) {
            $errors['start'] = 'overtime.error.in_working_time';
        }
        $today = $this->today();
        if ($rules !== null && ($rules['backdate_days'] ?? null) !== null && $date < EmployeeService::ymd($today)->modify('-' . (int) $rules['backdate_days'] . ' day')->format('Y-m-d')) {
            $errors['work_date'] = 'overtime.error.backdate';
        }
        if ($rules !== null && ($rules['min_minutes'] ?? null) !== null && $minutes < (int) $rules['min_minutes']) {
            $errors['end'] = 'overtime.error.too_short';
        }
        if ($this->frozen($le, $date)) {
            $errors['work_date'] = 'overtime.error.locked';
        }
        $tz = $plan['tz'];
        $startUtc = $this->utc($date, $s, $tz);
        $endUtc = $this->utc($date, $endM, $tz);
        $place = implode(',', array_fill(0, count(self::OPEN), '?'));
        $clash = $this->app->db()->scalar(
            "SELECT id FROM ot_requests WHERE employee_id = ? AND status IN ({$place}) AND start_utc < ? AND end_utc > ?" . ($exceptId !== null ? ' AND id <> ?' : ''),
            array_merge([$employee['id']], self::OPEN, [$endUtc, $startUtc], $exceptId !== null ? [$exceptId] : [])
        );
        if ($clash !== null) {
            $errors['start'] ??= 'overtime.error.overlap';
        }
        $type = match (true) {
            $plan['type'] === 'holiday' => 'holiday',
            $plan['working'] === null => 'unknown',
            $plan['working'] === false => 'dayoff',
            default => 'workday',
        };
        // the monthly cap counts what is asked, approved, verified or sent in the calendar month of the work date
        $capWarn = false;
        if ($rules !== null && ($rules['monthly_cap_hours'] ?? null) !== null) {
            $month = substr($date, 0, 7);
            $used = (int) $this->app->db()->scalar(
                "SELECT COALESCE(SUM(COALESCE(payable_minutes, minutes_requested)), 0) FROM ot_requests WHERE employee_id = ? AND status IN ({$place}) AND work_date BETWEEN ? AND ?" . ($exceptId !== null ? ' AND id <> ?' : ''),
                array_merge([$employee['id']], self::OPEN, [$month . '-01', $month . '-31'], $exceptId !== null ? [$exceptId] : [])
            );
            if ($used + $minutes > (int) $rules['monthly_cap_hours'] * 60) {
                if (($rules['cap_mode'] ?? 'warn') === 'block') {
                    $errors['end'] = 'overtime.error.cap';
                }
                $capWarn = true;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return ['job' => $job, 'date' => $date, 'start' => WorkSchedule::hm((int) $s), 'end' => WorkSchedule::hm((int) $e), 'start_utc' => $startUtc, 'end_utc' => $endUtc,
            'minutes' => $minutes, 'day_type' => $type, 'shift_code' => $plan['shift']['code'] ?? null, 'cap_warning' => $capWarn, 'rules_pid' => $rules['version_pid'] ?? null,
            'window' => $plan['window']];
    }

    /**
     * File OT for myself (employees = []) or for several people at once; each person gets their own request and approval.
     *
     * @param array<string, mixed> $in work_date, start, end, reason, cost_center (department pid)
     * @param list<string> $employeePids
     * @return list<string> request public ids
     */
    public function submit(Authorizer $az, array $in, array $employeePids = []): array
    {
        $reason = trim((string) ($in['reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $date = (string) ($in['work_date'] ?? '');
        $targets = $this->targets($az, $employeePids, self::isDate($date) ? $date : $this->today());
        $db = $this->app->db();
        $cost = null;
        if (($in['cost_center'] ?? '') !== '') {
            $cost = $db->one('SELECT id, legal_entity_id FROM departments WHERE public_id = ?', [(string) $in['cost_center']]);
            if ($cost === null) {
                throw new ValidationException(['cost_center' => 'form.invalid_choice']);
            }
        }
        $evals = [];
        foreach ($targets as $emp) {
            try {
                $evals[] = [$emp, $this->evaluate($emp, $in)];
            } catch (ValidationException $e) {
                if (count($targets) === 1) {
                    throw $e;
                }
                throw new ValidationException(['employees' => 'overtime.error.for_employee'] + $e->errors + ['_employee' => (string) $emp['employee_code']]);
            }
            if ($cost !== null && (int) $cost['legal_entity_id'] !== (int) end($evals)[1]['job']['legal_entity_id']) {
                throw new ValidationException(['cost_center' => 'form.invalid_choice']);
            }
        }
        $group = count($evals) > 1 ? Ulid::generate($this->app->clock) : null;
        $out = [];
        $db->transactional(function () use ($db, $evals, $az, $reason, $cost, $group, &$out): void {
            foreach ($evals as [$emp, $ev]) {
                $pid = Ulid::generate($this->app->clock);
                $job = $ev['job'];
                $now = $this->now();
                $db->run(
                    "INSERT INTO ot_requests (public_id, created_at, created_by, updated_at, updated_by, employee_id, legal_entity_id, site_id, department_id, cost_center_id, group_pid,
                        work_date, start_local, end_local, start_utc, end_utc, minutes_requested, day_type, shift_code, reason, status, cap_warning, rules_version_pid, requested_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)",
                    [$pid, $now, $az->userId, $now, $az->userId, $emp['id'], $job['legal_entity_id'], $job['site_id'], $job['department_id'], $cost['id'] ?? $job['department_id'], $group,
                        $ev['date'], $ev['start'], $ev['end'], $ev['start_utc'], $ev['end_utc'], $ev['minutes'], $ev['day_type'], $ev['shift_code'], mb_substr($reason, 0, 500),
                        $ev['cap_warning'] ? 1 : 0, $ev['rules_pid'], $az->userId]
                );
                $title = $this->app->translator()->get('overtime.title_line', ['code' => (string) $emp['employee_code'], 'date' => $ev['date'], 'from' => $ev['start'], 'to' => $ev['end']]);
                $inst = $this->app->workflow()->start(
                    'overtime.request',
                    $pid,
                    $title,
                    (int) $job['legal_entity_id'],
                    $az->userId,
                    $job['site_id'] !== null ? (int) $job['site_id'] : null,
                    $job['department_id'] !== null ? (int) $job['department_id'] : null
                );
                $db->run('UPDATE ot_requests SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE public_id = ?', [$inst, $pid]);
                $this->app->audit()->record('overtime.requested', 'success', 'ot_request', $pid, (int) $job['legal_entity_id'], null, [
                    'date' => $ev['date'], 'from' => $ev['start'], 'to' => $ev['end'], 'minutes' => $ev['minutes'], 'day_type' => $ev['day_type'], 'cap_warning' => $ev['cap_warning'], 'group' => $group,
                ]);
                $out[] = $pid;
            }
        });
        return $out;
    }

    /** The requester (or the employee) withdraws a request that is not approved yet. */
    public function cancel(Authorizer $az, string $pid, string $reason): void
    {
        $r = $this->request($az, $pid);
        if (!in_array($r['access'], ['own', 'requester'], true)) {
            throw HttpException::notFound();
        }
        if (!in_array($r['status'], ['pending', 'returned'], true)) {
            throw new BusinessException('overtime.error.state', [], 409);
        }
        $inst = (string) $this->app->db()->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$r['workflow_instance_id']]);
        $this->app->workflow()->cancel($inst, $az->userId, mb_strlen(trim($reason)) >= 3 ? trim($reason) : 'withdrawn'); // onClosed → cancelled
        $this->app->audit()->record('overtime.cancelled', 'success', 'ot_request', $pid, (int) $r['legal_entity_id']);
    }

    // ---- workflow callbacks ----------------------------------------------------------------------------------------

    public function onApproved(string $pid, int $approverId): void
    {
        $this->app->db()->run(
            "UPDATE ot_requests SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = ?, row_version = row_version + 1 WHERE public_id = ? AND status = 'pending'",
            [$approverId, $this->now(), $this->now(), $pid]
        );
    }

    public function onClosed(string $pid, string $outcome): void
    {
        $status = match ($outcome) {
            'rejected' => 'rejected',
            'returned' => 'returned',
            default => 'cancelled',
        };
        $this->app->db()->run("UPDATE ot_requests SET status = ?, updated_at = ?, row_version = row_version + 1 WHERE public_id = ? AND status IN ('pending','returned')", [$status, $this->now(), $pid]);
    }

    /** @return list<int> */
    public function managerUserIds(string $pid): array
    {
        $emp = $this->app->db()->scalar('SELECT employee_id FROM ot_requests WHERE public_id = ?', [$pid]);
        return $emp === null ? [] : $this->emp()->managerUserIds((int) $emp, $this->today());
    }

    // ---- verify -----------------------------------------------------------------------------------------------------

    /**
     * Minutes the scans show inside the approved window (null = no in or out on that work date).
     *
     * @param array<string, mixed> $r request row
     */
    public function actualMinutes(array $r): ?int
    {
        $day = $this->att()->dayResult((int) $r['employee_id'], (string) $r['work_date']);
        if ($day === null || $day['first_in'] === null || $day['last_out'] === null) {
            return null;
        }
        $ts = static fn (string $v): int => (DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($v, 0, 19), new DateTimeZone('UTC')) ?: DateTimeImmutable::createFromFormat('!Y-m-d', '1970-01-01', new DateTimeZone('UTC')))->getTimestamp();
        $from = max($ts((string) $day['first_in']), $ts((string) $r['start_utc']));
        $to = min($ts((string) $day['last_out']), $ts((string) $r['end_utc']));
        return $to > $from ? intdiv($to - $from, 60) : 0;
    }

    /**
     * Proposed payable minutes: requested ∩ scans, rounded down by the company rule.
     *
     * @param array<string, mixed> $r
     * @return array{actual: ?int, payable: int, round: int}
     */
    public function proposal(array $r): array
    {
        $actual = $this->actualMinutes($r);
        $rules = $this->rules((int) $r['legal_entity_id'], (string) $r['work_date']);
        $round = max(1, (int) ($rules['round_minutes'] ?? 1));
        $base = min((int) $r['minutes_requested'], $actual ?? 0);
        return ['actual' => $actual, 'payable' => intdiv($base, $round) * $round, 'round' => $round];
    }

    /**
     * May the user certify / dispute this line? (line manager or HR covering the employee)
     *
     * @param array<string, mixed> $r
     */
    public function canVerify(Authorizer $az, array $r): bool
    {
        return in_array($r['access'], ['manager', 'hr'], true);
    }

    /**
     * Certify payable minutes (≤ requested). A figure above the scans, or below what was requested, needs a note.
     * action = verify | dispute | reject (reject only from disputed, HR).
     */
    public function verify(Authorizer $az, string $pid, string $action, string $minutesRaw, string $note): void
    {
        $r = $this->request($az, $pid);
        if (!$this->canVerify($az, $r)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $now = $this->now();
        $note = trim($note);
        if (!in_array($r['status'], ['approved', 'disputed'], true)) {
            throw new BusinessException('overtime.error.state', [], 409);
        }
        if ($this->nowUtc() < (string) $r['end_utc']) {
            throw new BusinessException('overtime.error.not_ended', [], 409);
        }
        $p = $this->proposal($r);
        if ($action === 'dispute' || $action === 'reject') {
            if (mb_strlen($note) < 5) {
                throw new ValidationException(['note' => 'overtime.error.note']);
            }
            if ($action === 'reject' && ($r['status'] !== 'disputed' || $r['access'] !== 'hr')) {
                throw new BusinessException('overtime.error.state', [], 409);
            }
            $status = $action === 'dispute' ? 'disputed' : 'rejected';
            $db->run(
                "UPDATE ot_requests SET status = ?, actual_minutes = ?, verify_note = ?, verified_by = ?, verified_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?",
                [$status, $p['actual'], mb_substr($note, 0, 500), $az->userId, $now, $now, $az->userId, $r['id']]
            );
            $this->app->audit()->record('overtime.' . $status, 'success', 'ot_request', $pid, (int) $r['legal_entity_id'], mb_substr($note, 0, 255), ['actual' => $p['actual']]);
            return;
        }
        $m = trim($minutesRaw) === '' ? $p['payable'] : (ctype_digit(trim($minutesRaw)) ? (int) trim($minutesRaw) : -1);
        if ($m < 0 || $m > (int) $r['minutes_requested']) {
            throw new ValidationException(['payable_minutes' => 'overtime.error.payable']);
        }
        // the proposal (requested ∩ scans) needs no explanation; any other figure does
        if ($m !== $p['payable'] && mb_strlen($note) < 5) {
            throw new ValidationException(['note' => $m > (int) ($p['actual'] ?? 0) ? 'overtime.error.note_no_scan' : 'overtime.error.note_differs']);
        }
        $db->run(
            "UPDATE ot_requests SET status = 'verified', actual_minutes = ?, payable_minutes = ?, verify_note = ?, verified_by = ?, verified_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?",
            [$p['actual'], $m, $note !== '' ? mb_substr($note, 0, 500) : null, $az->userId, $now, $now, $az->userId, $r['id']]
        );
        $this->app->audit()->record('overtime.verified', 'success', 'ot_request', $pid, (int) $r['legal_entity_id'], $note !== '' ? mb_substr($note, 0, 255) : null, [
            'requested' => (int) $r['minutes_requested'], 'actual' => $p['actual'], 'payable' => $m,
        ]);
    }

    // ---- reading ----------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> request with access = own | requester | manager | hr | approver */
    public function request(Authorizer $az, string $pid): array
    {
        $r = $this->app->db()->one(
            'SELECT r.*, e.public_id AS employee_pid, e.employee_code, e.name_th, e.name_en, e.preferred_name, w.public_id AS instance_pid, w.status AS instance_status,
                    b.public_id AS batch_pid, b.batch_no, d.code AS cost_center_code, s.code AS site_code
               FROM ot_requests r JOIN employees e ON e.id = r.employee_id LEFT JOIN workflow_instances w ON w.id = r.workflow_instance_id
               LEFT JOIN ot_batches b ON b.id = r.batch_id LEFT JOIN departments d ON d.id = r.cost_center_id LEFT JOIN sites s ON s.id = r.site_id WHERE r.public_id = ?',
            [$pid]
        ) ?? throw HttpException::notFound();
        $r['access'] = $this->access($az, $r) ?? throw HttpException::notFound();
        return $r;
    }

    /** @param array<string, mixed> $r */
    private function access(Authorizer $az, array $r): ?string
    {
        $me = $this->emp()->forUser($az->userId);
        if (EmployeeService::covers($az->scope('overtime.manage'), $r)) {
            return 'hr';
        }
        if (in_array($az->userId, $this->emp()->managerUserIds((int) $r['employee_id'], $this->today()), true)) {
            return 'manager';
        }
        if ($me !== null && (int) $me['id'] === (int) $r['employee_id']) {
            return 'own';
        }
        if ((int) $r['requested_by'] === $az->userId) {
            return 'requester';
        }
        if ($r['workflow_instance_id'] !== null && $this->app->db()->scalar('SELECT id FROM workflow_tasks WHERE instance_id = ? AND assignee_id = ?', [$r['workflow_instance_id'], $az->userId]) !== null) {
            return 'approver';
        }
        return null;
    }

    /** @return list<array<string, mixed>> */
    public function forEmployee(int $employeeId, int $limit = 50): array
    {
        return $this->app->db()->all('SELECT r.* FROM ot_requests r WHERE r.employee_id = ? ORDER BY r.work_date DESC, r.id DESC LIMIT ' . max(1, min(500, $limit)), [$employeeId]);
    }

    /**
     * Approved lines whose time is over and that the user may certify (direct reports, or HR scope), oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function toVerify(Authorizer $az, int $limit = 200): array
    {
        $db = $this->app->db();
        $now = $this->nowUtc();
        $me = $this->emp()->forUser($az->userId);
        $reports = $me !== null ? $this->emp()->directReports((int) $me['id'], $this->today()) : [];
        $scope = $az->scope('overtime.manage');
        $where = [];
        $p = [];
        if (!$scope->isEmpty()) {
            [$sql, $sp] = $scope->sql('r.legal_entity_id', 'r.site_id', 'r.department_id');
            $where[] = "({$sql})";
            $p = array_merge($p, $sp);
        }
        if ($reports !== []) {
            $where[] = 'r.employee_id IN (' . implode(',', array_map('intval', $reports)) . ')';
        }
        if ($where === []) {
            return [];
        }
        return $db->all(
            "SELECT r.*, e.employee_code, e.name_th FROM ot_requests r JOIN employees e ON e.id = r.employee_id
              WHERE r.status IN ('approved','disputed') AND r.end_utc <= ? AND (" . implode(' OR ', $where) . ') ORDER BY r.work_date, r.id LIMIT ' . max(1, min(1000, $limit)),
            array_merge([$now], $p)
        );
    }

    // ---- payroll batches --------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function batches(int $le): array
    {
        return $this->app->db()->all('SELECT b.*, u.display_name AS created_name FROM ot_batches b LEFT JOIN users u ON u.id = b.created_by WHERE b.legal_entity_id = ? ORDER BY b.batch_no DESC LIMIT 100', [$le]);
    }

    /**
     * Verified lines not yet in a batch for a company and range (preview of the next batch).
     *
     * @return list<array<string, mixed>>
     */
    public function pending(int $le, string $from, string $to): array
    {
        return $this->app->db()->all(
            "SELECT r.*, e.employee_code, e.name_th, s.code AS site_code, d.code AS dept_code, c.code AS cost_code FROM ot_requests r JOIN employees e ON e.id = r.employee_id
               LEFT JOIN sites s ON s.id = r.site_id LEFT JOIN departments d ON d.id = r.department_id LEFT JOIN departments c ON c.id = r.cost_center_id
              WHERE r.legal_entity_id = ? AND r.status = 'verified' AND r.batch_id IS NULL AND r.work_date BETWEEN ? AND ? ORDER BY e.employee_code, r.work_date, r.start_utc",
            [$le, $from, $to]
        );
    }

    /** Freeze verified lines into the next numbered batch (a line goes out once). */
    public function createBatch(Authorizer $az, int $le, string $from, string $to, string $note): string
    {
        if (!$az->scope('overtime.export')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $errors = [];
        foreach (['period_start' => $from, 'period_end' => $to] as $f => $d) {
            if (!self::isDate($d)) {
                $errors[$f] = 'overtime.error.date';
            }
        }
        if ($errors === [] && ($to < $from || (int) EmployeeService::ymd($from)->diff(EmployeeService::ymd($to))->days + 1 > self::MAX_BATCH_DAYS)) {
            $errors['period_end'] = 'overtime.error.batch_range';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $db = $this->app->db();
        $pid = Ulid::generate($this->app->clock);
        $db->transactional(function () use ($db, $az, $le, $from, $to, $note, $pid): void {
            $rows = $db->all("SELECT id FROM ot_requests WHERE legal_entity_id = ? AND status = 'verified' AND batch_id IS NULL AND work_date BETWEEN ? AND ? FOR UPDATE", [$le, $from, $to]);
            if ($rows === []) {
                throw new BusinessException('overtime.error.batch_empty', [], 409);
            }
            $lines = [];
            $totals = ['workday' => 0, 'dayoff' => 0, 'holiday' => 0, 'unknown' => 0];
            foreach ($this->pending($le, $from, $to) as $r) {
                $rules = $this->rules($le, (string) $r['work_date']);
                $rate = (array) ($rules['rates'][$r['day_type']] ?? []);
                $lines[] = [
                    'request_id' => (string) $r['public_id'], 'employee_code' => (string) $r['employee_code'], 'employee_name' => (string) $r['name_th'],
                    'site' => (string) ($r['site_code'] ?? ''), 'department' => (string) ($r['dept_code'] ?? ''), 'cost_center' => (string) ($r['cost_code'] ?? ''),
                    'work_date' => (string) $r['work_date'], 'day_type' => (string) $r['day_type'], 'rate_class' => (string) ($rate['class'] ?? ''), 'multiplier' => (string) ($rate['multiplier'] ?? ''),
                    'start_local' => (string) $r['start_local'], 'end_local' => (string) $r['end_local'], 'requested_minutes' => (int) $r['minutes_requested'],
                    'actual_minutes' => $r['actual_minutes'] !== null ? (int) $r['actual_minutes'] : null, 'payable_minutes' => (int) $r['payable_minutes'],
                    'rules_version' => (string) ($rules['version_pid'] ?? ''),
                ];
                $totals[(string) $r['day_type']] += (int) $r['payable_minutes'];
            }
            $json = (string) json_encode($lines, JSON_UNESCAPED_UNICODE);
            $no = (int) $db->scalar('SELECT COALESCE(MAX(batch_no), 0) + 1 FROM ot_batches WHERE legal_entity_id = ?', [$le]);
            $now = $this->now();
            $db->run(
                'INSERT INTO ot_batches (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, batch_no, period_start, period_end, line_count, total_minutes, totals_json, lines_json, lines_hash, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, $no, $from, $to, count($lines), array_sum($totals), json_encode($totals), $json, hash('sha256', $json), mb_substr(trim($note), 0, 255) ?: null]
            );
            $bid = (int) $db->scalar('SELECT id FROM ot_batches WHERE public_id = ?', [$pid]);
            $ids = array_map(static fn (array $x): int => (int) $x['id'], $rows);
            $db->run("UPDATE ot_requests SET status = 'sent', batch_id = ?, updated_at = ?, row_version = row_version + 1 WHERE id IN (" . implode(',', $ids) . ") AND status = 'verified' AND batch_id IS NULL", [$bid, $now]);
            $this->app->audit()->record('overtime.batch_created', 'success', 'ot_batch', $pid, $le, null, ['no' => $no, 'lines' => count($lines), 'minutes' => array_sum($totals), 'from' => $from, 'to' => $to]);
        });
        return $pid;
    }

    /** @return array<string, mixed> */
    public function batch(Authorizer $az, string $pid): array
    {
        $b = $this->app->db()->one('SELECT b.*, l.code AS le_code FROM ot_batches b JOIN legal_entities l ON l.id = b.legal_entity_id WHERE b.public_id = ?', [$pid]) ?? throw HttpException::notFound();
        if (!$az->scope('overtime.export')->coversLegalEntity((int) $b['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        $b['lines'] = json_decode((string) $b['lines_json'], true) ?: [];
        $b['totals'] = json_decode((string) $b['totals_json'], true) ?: [];
        $b['intact'] = hash_equals((string) $b['lines_hash'], hash('sha256', (string) $b['lines_json']));
        return $b;
    }

    /**
     * BLUE_PAYROLL_OT_INPUT_XLSX_v1 (or CSV) rebuilt from the frozen lines; every download is logged.
     *
     * @return array{bytes: string, name: string, mime: string}
     */
    public function export(Authorizer $az, string $pid, string $format): array
    {
        $b = $this->batch($az, $pid);
        if (!$b['intact']) {
            throw new BusinessException('overtime.error.batch_damaged', [], 409);
        }
        $headers = ['employee_code', 'employee_name', 'site', 'department', 'cost_center', 'work_date', 'day_type', 'rate_class', 'multiplier', 'start_local', 'end_local',
            'requested_minutes', 'actual_minutes', 'payable_minutes', 'payable_hours', 'request_id', 'rules_version'];
        $rows = [];
        foreach ($b['lines'] as $l) {
            $rows[] = [$l['employee_code'], $l['employee_name'], $l['site'], $l['department'], $l['cost_center'], $l['work_date'], $l['day_type'], $l['rate_class'], $l['multiplier'],
                $l['start_local'], $l['end_local'], (int) $l['requested_minutes'], $l['actual_minutes'] === null ? '' : (int) $l['actual_minutes'], (int) $l['payable_minutes'],
                round((int) $l['payable_minutes'] / 60, 2), $l['request_id'], $l['rules_version']];
        }
        $t = $this->app->translator();
        $base = 'BLUE_PAYROLL_OT_INPUT_v1_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $b['le_code']) . '_B' . str_pad((string) $b['batch_no'], 4, '0', STR_PAD_LEFT) . '_' . $b['period_start'] . '_' . $b['period_end'];
        if ($format === 'csv') {
            $fh = fopen('php://temp', 'w+');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, $headers, ',', '"', '');
            foreach ($rows as $r) {
                fputcsv($fh, array_map(static fn ($v): string => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : (string) $v, $r), ',', '"', '');
            }
            rewind($fh);
            $bytes = (string) stream_get_contents($fh);
            fclose($fh);
            $mime = 'text/csv; charset=utf-8';
            $name = $base . '.csv';
        } else {
            $tot = (array) $b['totals'];
            $meta = [
                [$t->get('overtime.meta.format'), 'BLUE_PAYROLL_OT_INPUT_XLSX_v1'],
                [$t->get('overtime.meta.company'), (string) $b['le_code']],
                [$t->get('overtime.meta.batch'), (string) $b['batch_no']],
                [$t->get('overtime.meta.period'), $b['period_start'] . ' – ' . $b['period_end']],
                [$t->get('overtime.meta.lines'), (string) $b['line_count']],
                [$t->get('overtime.meta.total'), (string) $b['total_minutes'] . ' min'],
                [$t->get('overtime.day_type.workday'), (string) ($tot['workday'] ?? 0) . ' min'],
                [$t->get('overtime.day_type.dayoff'), (string) ($tot['dayoff'] ?? 0) . ' min'],
                [$t->get('overtime.day_type.holiday'), (string) ($tot['holiday'] ?? 0) . ' min'],
                [$t->get('overtime.meta.hash'), (string) $b['lines_hash']],
                [$t->get('overtime.meta.created_at'), (string) $b['created_at'] . ' UTC'],
                [$t->get('overtime.meta.note'), $t->get('overtime.meta.note_text')],
            ];
            $bytes = Xlsx::write([
                'ot_input' => ['headers' => $headers, 'rows' => $rows, 'numeric' => [11, 12, 13, 14], 'widths' => [14, 26, 8, 12, 12, 12, 10, 10, 9, 8, 8, 10, 10, 10, 10, 28, 28]],
                'meta' => ['headers' => [$t->get('overtime.meta.item'), $t->get('overtime.meta.value')], 'rows' => $meta, 'freeze' => false, 'widths' => [26, 70]],
            ], 'Blue OS payroll OT input');
            $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            $name = $base . '.xlsx';
        }
        $this->app->db()->run(
            'INSERT INTO ot_batch_exports (batch_id, format, file_sha256, exported_by, exported_at) VALUES (?, ?, ?, ?, ?)',
            [$b['id'], $format === 'csv' ? 'csv' : 'xlsx', hash('sha256', $bytes), $az->userId, $this->now()]
        );
        $this->app->audit()->record('overtime.batch_exported', 'success', 'ot_batch', $pid, (int) $b['legal_entity_id'], null, ['format' => $format, 'sha256' => hash('sha256', $bytes)]);
        return ['bytes' => $bytes, 'name' => $name, 'mime' => $mime];
    }

    // ---- report -----------------------------------------------------------------------------------------------------

    /**
     * Month report by employee (with site/unit) for what the user may see (overtime.manage scope).
     *
     * @return list<array<string, mixed>>
     */
    public function report(Authorizer $az, string $ym, string $group): array
    {
        $scope = $az->scope('overtime.manage');
        if ($scope->isEmpty()) {
            throw HttpException::forbidden();
        }
        [$sql, $p] = $scope->sql('r.legal_entity_id', 'r.site_id', 'r.department_id');
        $key = match ($group) {
            'department' => "COALESCE(d.code, '—')",
            'site' => "COALESCE(s.code, '—')",
            default => "CONCAT(e.employee_code, ' · ', e.name_th)",
        };
        return $this->app->db()->all(
            "SELECT {$key} AS label, COUNT(*) AS requests,
                    SUM(CASE WHEN r.status IN ('pending','returned') THEN r.minutes_requested ELSE 0 END) AS pending_minutes,
                    SUM(CASE WHEN r.status IN ('approved','disputed') THEN r.minutes_requested ELSE 0 END) AS approved_minutes,
                    SUM(CASE WHEN r.status IN ('verified','sent') AND r.day_type = 'workday' THEN r.payable_minutes ELSE 0 END) AS workday_minutes,
                    SUM(CASE WHEN r.status IN ('verified','sent') AND r.day_type = 'dayoff' THEN r.payable_minutes ELSE 0 END) AS dayoff_minutes,
                    SUM(CASE WHEN r.status IN ('verified','sent') AND r.day_type IN ('holiday','unknown') THEN r.payable_minutes ELSE 0 END) AS holiday_minutes,
                    SUM(CASE WHEN r.status = 'sent' THEN r.payable_minutes ELSE 0 END) AS sent_minutes,
                    SUM(r.cap_warning) AS cap_warnings
               FROM ot_requests r JOIN employees e ON e.id = r.employee_id LEFT JOIN departments d ON d.id = r.department_id LEFT JOIN sites s ON s.id = r.site_id
              WHERE {$sql} AND r.work_date BETWEEN ? AND ? AND r.status NOT IN ('rejected','cancelled') GROUP BY label ORDER BY label",
            array_merge($p, [$ym . '-01', $ym . '-31'])
        );
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------

    public function frozen(int $le, string $date): bool
    {
        foreach ($this->app->locks()->lockedRanges('attendance', $le) as [$a, $b]) {
            if ($date >= $a && $date <= $b) {
                return true;
            }
        }
        return false;
    }

    private function utc(string $date, int $minutes, DateTimeZone $tz): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
        return ($d !== false ? $d : $this->app->clock->now())->modify('+' . $minutes . ' minutes')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function isDate(string $d): bool
    {
        $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $x !== false && $x->format('Y-m-d') === $d;
    }

    private function nowUtc(): string
    {
        return $this->app->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }
}
