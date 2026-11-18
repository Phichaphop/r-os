<?php

declare(strict_types=1);

namespace Blue\Modules\Attendance;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Modules\Employees\EmployeeService;
use Blue\Modules\Leave\LeaveService;

/**
 * M34 Attendance (DEC-64) — public service: daily results, work schedule, who may see whose time.
 *
 *  - the employee sees only their own days and raw punches (attendance.use + linked employee record);
 *  - the line manager sees direct reports; HR (attendance.approve / attendance.manage) sees employees of its scope;
 *  - raw punches are never changed — results come from punches + approved corrections + schedule + calendar + leave
 *    and are rebuilt by recalc() whenever one of those changes.
 */
final class AttendanceService
{
    /** Longest range one recalculation covers (days). */
    public const MAX_RANGE = 62;
    /** minutes after an overnight shift ends during which morning punches still belong to it (M35) */
    private const OVERNIGHT_TAIL = 240;

    /** @var array<string, mixed> */
    private array $cache = [];

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

    public function today(): string
    {
        return $this->emp()->today();
    }

    // ---- schedule (Config Registry) ----------------------------------------------------------------------

    /** @return array{value: array<string, mixed>, version_id: int, version_pid: string, from: string}|null */
    public function schedule(int $legalEntityId, string $date): ?array
    {
        $key = 'sched:' . $legalEntityId . ':' . $date;
        if (!array_key_exists($key, $this->cache)) {
            $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' 12:00:00', $this->displayTz()) ?: $this->app->clock->now();
            $r = $this->app->registry()->resolveOrNull('attendance.schedule', $legalEntityId, null, $at);
            $this->cache[$key] = $r !== null && is_array($r->value) ? ['value' => $r->value, 'version_id' => $r->versionId, 'version_pid' => $r->versionPublicId, 'from' => $r->effectiveFrom] : null;
        }
        return $this->cache[$key];
    }

    /** @param array<string, mixed> $in day_start, correction_max_days, groups */
    public function proposeSchedule(Authorizer $az, int $legalEntityId, array $in, string $from, string $source, string $reason): string
    {
        if (!$az->scope('attendance.policy')->coversLegalEntity($legalEntityId)) {
            throw HttpException::forbidden();
        }
        [$value, $errors] = WorkSchedule::validate($in);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $this->displayTz());
        if ($d === false || $d->format('Y-m-d') !== $from) {
            $errors['effective_from'] = 'attendance.schedule_error.date';
        }
        if (mb_strlen(trim($source)) < 5) {
            $errors['source_reference'] = 'attendance.schedule_error.source';
        }
        if (mb_strlen(trim($reason)) < 3) {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ($errors !== [] || $value === null || $d === false) {
            throw new ValidationException($errors);
        }
        $reg = $this->app->registry();
        $pid = $reg->propose($az, 'attendance.schedule', 'legal_entity', $legalEntityId, (string) json_encode($value, JSON_UNESCAPED_UNICODE), $d, null, trim($reason), trim($source));
        $reg->submit($az, $pid);
        return $pid;
    }

    /** @return list<array<string, mixed>> */
    public function scheduleVersions(int $legalEntityId): array
    {
        return $this->app->db()->all(
            "SELECT v.public_id, v.status, v.effective_from, v.effective_to, v.source_reference, v.reason, v.created_at, v.value_json,
                    p.display_name AS proposed_name, d.display_name AS decided_name, i.public_id AS instance_pid
               FROM config_versions v JOIN config_definitions c ON c.id = v.definition_id
               LEFT JOIN users p ON p.id = v.proposed_by LEFT JOIN users d ON d.id = v.decided_by LEFT JOIN workflow_instances i ON i.id = v.workflow_instance_id
              WHERE c.config_key = 'attendance.schedule' AND v.scope_level = 'legal_entity' AND v.scope_id = ? ORDER BY v.id DESC LIMIT 20",
            [$legalEntityId]
        );
    }

    // ---- daily results -----------------------------------------------------------------------------------

    /**
     * Rebuilds att_days of one employee for [from, to] (dates after today are skipped). Returns days written.
     */
    public function recalc(int $employeeId, string $from, string $to): int
    {
        $db = $this->app->db();
        $today = $this->today();
        $to = min($to, $today);
        if ($from > $to) {
            return 0;
        }
        $fromD = EmployeeService::ymd($from);
        if ((int) $fromD->diff(EmployeeService::ymd($to))->days + 1 > self::MAX_RANGE) {
            $fromD = EmployeeService::ymd($to)->modify('-' . (self::MAX_RANGE - 1) . ' days');
            $from = $fromD->format('Y-m-d');
        }
        $byDate = $this->punchesByDate($employeeId, $from, $to);
        $corr = $this->approvedCorrections($employeeId, $from, $to);
        $leave = $this->leaveDays($employeeId, $from, $to);
        $now = $this->now();
        $n = 0;
        for ($d = $fromD; $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            $job = $this->emp()->employment($employeeId, $ymd);
            if ($job === null) {
                $db->run('DELETE FROM att_days WHERE employee_id = ? AND work_date = ? AND NOT EXISTS (SELECT 1 FROM att_periods p WHERE p.legal_entity_id = att_days.legal_entity_id
                    AND p.status IN (\'pending\', \'finalized\', \'reopen_pending\') AND att_days.work_date BETWEEN p.period_start AND p.period_end)', [$employeeId, $ymd]);
                continue;
            }
            $le = (int) $job['legal_entity_id'];
            if (PeriodService::of($this->app)->frozen($le, $ymd)) {
                continue; // the day belongs to a period sent for close / closed — its result is part of the payroll snapshot (DEC-66)
            }
            $site = $job['site_id'] !== null ? (int) $job['site_id'] : null;
            $tz = $this->siteTz($site);
            $day = $this->calendarDay($site, $le, $ymd);
            $sched = $this->schedule($le, $ymd);
            $rule = WorkSchedule::forGroup($sched['value'] ?? null, (string) ($job['employee_group'] ?? ''));
            // a published shift (M35) replaces the fixed working day: its times, or a day off (DEC-70)
            $shift = $this->shift($employeeId, $ymd);
            if ($shift !== null) {
                if ($shift['off']) {
                    $day = ['working' => false, 'type' => $day['type'] === 'holiday' ? 'holiday' : 'weekend', 'name' => $day['name']];
                } else {
                    $day = ['working' => true, 'type' => $day['type'] === 'holiday' ? 'holiday' : 'working', 'name' => $day['name']];
                    $rule = ['start' => $shift['start'], 'end' => $shift['end'], 'break_minutes' => $shift['break_minutes'],
                        'late_grace_minutes' => $shift['late_grace_minutes'], 'early_grace_minutes' => $shift['early_grace_minutes']];
                }
            }
            $c = null;
            if (isset($corr[$ymd])) {
                $c = ['in' => self::localToUtc($ymd, $corr[$ymd]['in'], $tz), 'out' => self::localToUtc($ymd, $corr[$ymd]['out'], $tz)];
            }
            $lv = isset($leave[$ymd]) ? ['part' => $leave[$ymd]['part'], 'name' => $leave[$ymd]['name']] : null;
            $r = DayCalculator::calculate($ymd, $tz, $day, $lv, $byDate[$ymd] ?? [], $c, $rule, $today);
            $fmt = static fn (?DateTimeImmutable $t): ?string => $t?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $db->run(
                'INSERT INTO att_days (employee_id, work_date, legal_entity_id, site_id, department_id, day_type, day_name, shift_code, leave_part, leave_type, first_in, last_out, in_source, out_source,
                    punch_count, worked_minutes, late_minutes, early_minutes, status, schedule_version_id, calc_version, calculated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE legal_entity_id = VALUES(legal_entity_id), site_id = VALUES(site_id), department_id = VALUES(department_id), day_type = VALUES(day_type),
                    day_name = VALUES(day_name), shift_code = VALUES(shift_code), leave_part = VALUES(leave_part), leave_type = VALUES(leave_type), first_in = VALUES(first_in), last_out = VALUES(last_out),
                    in_source = VALUES(in_source), out_source = VALUES(out_source), punch_count = VALUES(punch_count), worked_minutes = VALUES(worked_minutes),
                    late_minutes = VALUES(late_minutes), early_minutes = VALUES(early_minutes), status = VALUES(status), schedule_version_id = VALUES(schedule_version_id),
                    calc_version = VALUES(calc_version), calculated_at = VALUES(calculated_at)',
                [$employeeId, $ymd, $le, $site, $job['department_id'], (string) ($day['type'] ?? 'no_calendar'), $day['name'] !== null ? mb_substr((string) $day['name'], 0, 120) : null,
                    $shift !== null ? $shift['code'] : null, $lv['part'] ?? null, $lv !== null ? mb_substr($lv['name'], 0, 80) : null, $fmt($r['first_in']), $fmt($r['last_out']), $r['in_source'], $r['out_source'],
                    $r['punch_count'], $r['worked_minutes'], $r['late_minutes'], $r['early_minutes'], $r['status'], $sched['version_id'] ?? null, DayCalculator::VERSION, $now]
            );
            $n++;
        }
        return $n;
    }

    /**
     * Raw punches of an employee grouped by work date — punches imported for the employee plus device punches whose
     * user code was mapped to the employee on that date (the mapping, not the raw row, says who it is).
     *
     * @return array<string, list<DateTimeImmutable>>
     */
    public function punchesByDate(int $employeeId, string $from, string $to): array
    {
        $out = [];
        foreach ($this->rawPunches($employeeId, $from, $to) as $p) {
            $out[(string) $p['work_date']][] = $p['at'];
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>> raw rows (+ at: UTC DateTimeImmutable, work_date) in time order
     */
    public function rawPunches(int $employeeId, string $from, string $to): array
    {
        $db = $this->app->db();
        $utc = new DateTimeZone('UTC');
        $lo = EmployeeService::ymd($from)->modify('-1 day')->format('Y-m-d 00:00:00');
        $hi = EmployeeService::ymd($to)->modify('+2 day')->format('Y-m-d 00:00:00');
        $rows = $db->all('SELECT p.*, d.device_code AS dev_code, d.name AS device_name FROM att_raw_punches p LEFT JOIN att_devices d ON d.id = p.device_id
            WHERE p.employee_id = ? AND p.occurred_at_utc >= ? AND p.occurred_at_utc < ?', [$employeeId, $lo, $hi]);
        $maps = $db->all('SELECT * FROM att_device_users WHERE employee_id = ?', [$employeeId]);
        $codes = array_values(array_unique(array_map(static fn (array $m): string => (string) $m['device_user_code'], $maps)));
        if ($codes !== []) {
            $place = implode(',', array_fill(0, count($codes), '?'));
            foreach (
                $db->all(
                    "SELECT p.*, d.device_code AS dev_code, d.name AS device_name FROM att_raw_punches p LEFT JOIN att_devices d ON d.id = p.device_id
                      WHERE p.employee_id IS NULL AND p.device_user_code IN ({$place}) AND p.occurred_at_utc >= ? AND p.occurred_at_utc < ?",
                    array_merge($codes, [$lo, $hi])
                ) as $r
            ) {
                $r['_mapped'] = true;
                $rows[] = $r;
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $r['occurred_at_utc'], $utc);
            if ($at === false) {
                continue;
            }
            $localDate = $at->setTimezone($this->displayTz())->format('Y-m-d');
            $job = $this->emp()->employment($employeeId, $localDate);
            $tz = $this->siteTz($job !== null && $job['site_id'] !== null ? (int) $job['site_id'] : null);
            $sched = $job !== null ? $this->schedule((int) $job['legal_entity_id'], $localDate) : null;
            $dayStart = WorkSchedule::minutes((string) ($sched['value']['day_start'] ?? '00:00')) ?? 0;
            $wd = DayCalculator::workDate($at, $tz, $dayStart);
            // an overnight shift (M35) owns the morning punches of the next day until a while after it ends
            $local = $at->setTimezone($tz);
            $prevDate = $local->modify('-1 day')->format('Y-m-d');
            if ($wd !== $prevDate) {
                $prev = $this->shift($employeeId, $prevDate);
                if ($prev !== null && !$prev['off'] && $prev['overnight']) {
                    $sinceMidnight = (int) $local->format('G') * 60 + (int) $local->format('i');
                    if ($sinceMidnight <= (int) WorkSchedule::minutes($prev['end']) + self::OVERNIGHT_TAIL) {
                        $wd = $prevDate;
                    }
                }
            }
            if (!empty($r['_mapped']) && !self::mappingCovers($maps, (string) $r['device_user_code'], $r['device_id'] !== null ? (int) $r['device_id'] : null, $wd)) {
                continue;
            }
            if ($wd < $from || $wd > $to) {
                continue;
            }
            $r['at'] = $at;
            $r['work_date'] = $wd;
            $out[] = $r;
        }
        usort($out, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        return $out;
    }

    /** @param list<array<string, mixed>> $maps */
    private static function mappingCovers(array $maps, string $code, ?int $deviceId, string $date): bool
    {
        foreach ($maps as $m) {
            if (
                (string) $m['device_user_code'] === $code && ($m['device_id'] === null || (int) $m['device_id'] === $deviceId)
                && (string) $m['valid_from'] <= $date && ($m['valid_to'] === null || (string) $m['valid_to'] >= $date)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Approved corrections merged per date (later decisions override earlier ones field by field).
     *
     * @return array<string, array{in: ?string, out: ?string}>
     */
    private function approvedCorrections(int $employeeId, string $from, string $to): array
    {
        $out = [];
        foreach (
            $this->app->db()->all(
                "SELECT work_date, proposed_in, proposed_out FROM att_corrections WHERE employee_id = ? AND status = 'approved' AND work_date BETWEEN ? AND ? ORDER BY decided_at, id",
                [$employeeId, $from, $to]
            ) as $c
        ) {
            $d = (string) $c['work_date'];
            $out[$d] ??= ['in' => null, 'out' => null];
            if ($c['proposed_in'] !== null) {
                $out[$d]['in'] = substr((string) $c['proposed_in'], 0, 5);
            }
            if ($c['proposed_out'] !== null) {
                $out[$d]['out'] = substr((string) $c['proposed_out'], 0, 5);
            }
        }
        return $out;
    }

    /** @return array<string, array{part: string, name: string}> */
    private function leaveDays(int $employeeId, string $from, string $to): array
    {
        if (!$this->app->modules()->isEnabled('leave') || !$this->app->db()->tableExists('lv_requests')) {
            return [];
        }
        $en = $this->app->translator()->locale() === 'en';
        $out = [];
        foreach (LeaveService::of($this->app)->approvedDays($employeeId, $from, $to) as $d => $l) {
            $out[$d] = ['part' => $l['part'], 'name' => $en && $l['type_en'] !== null ? $l['type_en'] : $l['type_th']];
        }
        return $out;
    }

    /** @return array{working: ?bool, type: ?string, name: ?string} */
    private function calendarDay(?int $siteId, int $legalEntityId, string $ymd): array
    {
        $key = 'cal:' . ($siteId ?? 0) . ':' . $legalEntityId;
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $this->app->calendars()->resolve($siteId, $legalEntityId);
        }
        $cal = $this->cache[$key];
        if ($cal === null) {
            return ['working' => null, 'type' => 'no_calendar', 'name' => null];
        }
        $d = $this->app->calendars()->day($cal, $ymd);
        return ['working' => $d['working'], 'type' => $d['type'], 'name' => $d['name']];
    }

    /**
     * What a date is for an employee — calendar day type, published shift (M35) and the working window — without
     * writing anything (used by M33 OT to classify the day and keep OT outside working time). Null when not employed.
     *
     * @return array{job: array<string, mixed>, tz: DateTimeZone, working: ?bool, type: string, shift: ?array<string, mixed>, window: ?array{0: int, 1: int}}|null
     *         window = [start, end] in minutes after the date's local midnight (end may pass 1440)
     */
    public function plannedDay(int $employeeId, string $ymd): ?array
    {
        $job = $this->emp()->employment($employeeId, $ymd);
        if ($job === null) {
            return null;
        }
        $le = (int) $job['legal_entity_id'];
        $site = $job['site_id'] !== null ? (int) $job['site_id'] : null;
        $day = $this->calendarDay($site, $le, $ymd);
        $shift = $this->shift($employeeId, $ymd);
        $rule = WorkSchedule::forGroup($this->schedule($le, $ymd)['value'] ?? null, (string) ($job['employee_group'] ?? ''));
        $working = $day['working'];
        $type = (string) ($day['type'] ?? 'no_calendar');
        if ($shift !== null) {
            $working = !$shift['off'];
            $type = $type === 'holiday' ? 'holiday' : ($shift['off'] ? 'weekend' : 'working');
            $rule = $shift['off'] ? null : ['start' => $shift['start'], 'end' => $shift['end']];
        }
        $window = $rule !== null && $working === true ? [(int) WorkSchedule::minutes((string) $rule['start']), DayCalculator::endMinutes(['start' => (string) $rule['start'], 'end' => (string) $rule['end']])] : null;
        return ['job' => $job, 'tz' => $this->siteTz($site), 'working' => $working, 'type' => $type, 'shift' => $shift, 'window' => $window];
    }

    /**
     * A punch handed over by another module (M57 GPS check-in, DEC-71): appended to att_raw_punches with a unique
     * event id (a repeat is ignored) and the day recalculated — a day in a closed period keeps its result (the raw
     * punch is still kept, DEC-66). Returns true when the punch is new.
     */
    public function acceptPunch(string $source, string $eventId, int $employeeId, string $utc, string $local, ?string $direction, ?string $note, ?int $actorId): bool
    {
        if ($source !== 'gps' || preg_match('/^[A-Za-z0-9:._-]{8,80}$/', $eventId) !== 1) {
            throw new \InvalidArgumentException('bad punch source or event id');
        }
        $ins = $this->app->db()->run(
            "INSERT IGNORE INTO att_raw_punches (source, source_event_id, employee_id, occurred_at_utc, device_local_time, verify_method, direction, note, received_at, created_by)
             VALUES (?, ?, ?, ?, ?, 'gps', ?, ?, ?, ?)",
            [$source, $eventId, $employeeId, $utc, mb_substr($local, 0, 19), in_array($direction, ['in', 'out'], true) ? $direction : null, $note !== null ? mb_substr($note, 0, 200) : null,
                $this->app->clock->now()->format('Y-m-d H:i:s.u'), $actorId]
        )->rowCount();
        if ($ins === 1) {
            $date = substr($local, 0, 10);
            $this->recalc($employeeId, EmployeeService::ymd($date)->modify('-1 day')->format('Y-m-d'), $date);
        }
        return $ins === 1;
    }

    /**
     * One day's stored result (after recalculating it), or null.
     *
     * @return array<string, mixed>|null
     */
    public function dayResult(int $employeeId, string $ymd): ?array
    {
        $this->recalc($employeeId, $ymd, $ymd);
        return $this->app->db()->one('SELECT * FROM att_days WHERE employee_id = ? AND work_date = ?', [$employeeId, $ymd]);
    }

    /**
     * The employee's published shift on a date (M35 shifts module), or null when the module is off / no roster.
     *
     * @return array{off: bool, code: string, start: string, end: string, overnight: bool, break_minutes: int, late_grace_minutes: int, early_grace_minutes: int}|null
     */
    public function shift(int $employeeId, string $ymd): ?array
    {
        if (!array_key_exists('shifts_on', $this->cache)) {
            $this->cache['shifts_on'] = $this->app->modules()->isEnabled('shifts') && $this->app->db()->tableExists('sh_assignments');
        }
        if (!$this->cache['shifts_on']) {
            return null;
        }
        return \Blue\Modules\Shifts\ShiftService::of($this->app)->shiftFor($employeeId, $ymd);
    }

    public function siteTz(?int $siteId): DateTimeZone
    {
        $key = 'tz:' . ($siteId ?? 0);
        if (!isset($this->cache[$key])) {
            $name = $siteId !== null ? $this->app->db()->scalar('SELECT timezone FROM sites WHERE id = ?', [$siteId]) : null;
            try {
                $this->cache[$key] = new DateTimeZone(is_string($name) && $name !== '' ? $name : $this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
            } catch (\Exception) {
                $this->cache[$key] = $this->displayTz();
            }
        }
        return $this->cache[$key];
    }

    private static function localToUtc(string $ymd, ?string $hm, DateTimeZone $tz): ?DateTimeImmutable
    {
        if ($hm === null) {
            return null;
        }
        $t = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $ymd . ' ' . $hm, $tz);
        return $t === false ? null : $t->setTimezone(new DateTimeZone('UTC'));
    }

    /** Forget cached schedules/calendars (after an approval changes them inside the same request). */
    public function forget(): void
    {
        $this->cache = [];
    }

    // ---- reading --------------------------------------------------------------------------------------------

    /**
     * One month of days for an employee (recalculated first — cheap for one person).
     *
     * @return array<string, array<string, mixed>> work_date => att_days row
     */
    public function month(int $employeeId, string $ym): array
    {
        $first = $ym . '-01';
        $last = EmployeeService::ymd($first)->modify('last day of this month')->format('Y-m-d');
        $this->recalc($employeeId, $first, $last);
        $out = [];
        foreach ($this->app->db()->all('SELECT * FROM att_days WHERE employee_id = ? AND work_date BETWEEN ? AND ? ORDER BY work_date', [$employeeId, $first, $last]) as $r) {
            $out[(string) $r['work_date']] = $r;
        }
        return $out;
    }

    /**
     * Totals of a month from att_days rows.
     *
     * @param array<string, array<string, mixed>> $days
     * @return array{worked_days: int, worked_minutes: int, late_count: int, late_minutes: int, early_count: int, absent: int, missing: int, leave: float}
     */
    public static function totals(array $days): array
    {
        $t = ['worked_days' => 0, 'worked_minutes' => 0, 'late_count' => 0, 'late_minutes' => 0, 'early_count' => 0, 'absent' => 0, 'missing' => 0, 'leave' => 0.0];
        foreach ($days as $d) {
            if ($d['first_in'] !== null || $d['last_out'] !== null) {
                $t['worked_days']++;
            }
            $t['worked_minutes'] += (int) ($d['worked_minutes'] ?? 0);
            if ((int) ($d['late_minutes'] ?? 0) > 0) {
                $t['late_count']++;
                $t['late_minutes'] += (int) $d['late_minutes'];
            }
            if ((int) ($d['early_minutes'] ?? 0) > 0) {
                $t['early_count']++;
            }
            $t['absent'] += $d['status'] === 'absent' ? 1 : 0;
            $t['missing'] += in_array($d['status'], ['missing_in', 'missing_out'], true) ? 1 : 0;
            $t['leave'] += $d['leave_part'] === null ? 0.0 : ($d['leave_part'] === 'full' ? 1.0 : 0.5);
        }
        return $t;
    }

    /**
     * Whose time may this user see? 'own' | 'manager' | 'hr' | null.
     *
     * @param array<string, mixed> $employee employee row
     */
    public function accessTo(Authorizer $az, array $employee): ?string
    {
        $me = $this->emp()->forUser($az->userId);
        if ($me !== null && (int) $me['id'] === (int) $employee['id']) {
            return $az->can('attendance.use') ? 'own' : null;
        }
        $job = $this->emp()->employment((int) $employee['id'], $this->today())
            ?? $this->app->db()->one('SELECT * FROM employment_history WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 1', [$employee['id']]);
        if (EmployeeService::covers($az->scope('attendance.manage'), $job) || EmployeeService::covers($az->scope('attendance.approve'), $job)) {
            return 'hr';
        }
        if ($me !== null && in_array((int) $employee['id'], $this->emp()->directReports((int) $me['id'], $this->today()), true)) {
            return 'manager';
        }
        return null;
    }

    /**
     * Employee by public id that the user may look at (404 otherwise — never "exists but forbidden").
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    public function visibleEmployee(Authorizer $az, string $pid): array
    {
        $e = $this->app->db()->one('SELECT * FROM employees WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $access = $this->accessTo($az, $e);
        if ($access === null) {
            throw HttpException::notFound();
        }
        $e['job'] = $this->emp()->employment((int) $e['id'], $this->today());
        return [$e, $access];
    }

    /** @return array<string, mixed> the signed-in user's employee record or a clear refusal */
    public function self(Authorizer $az): array
    {
        if (!$az->can('attendance.use')) {
            throw HttpException::forbidden();
        }
        return $this->emp()->forUser($az->userId) ?? throw new BusinessException('attendance.error.no_employee', [], 409);
    }

    /**
     * Team month: direct reports (as line manager) and, for HR, employees of the chosen company in scope.
     * Reads stored results — "recalculate" refreshes them.
     *
     * @return array{employees: list<array<string, mixed>>, totals: array<int, array<string, mixed>>, team: bool, hr: bool, calculated_at: ?string}
     */
    public function team(Authorizer $az, string $ym, ?int $legalEntityId): array
    {
        $db = $this->app->db();
        $today = $this->today();
        $ids = [];
        $me = $this->emp()->forUser($az->userId);
        if ($me !== null) {
            $ids = $this->emp()->directReports((int) $me['id'], $today);
        }
        $team = $ids !== [];
        $scope = $az->scope('attendance.approve');
        $hr = !$scope->isEmpty();
        if ($hr) {
            [$sql, $p] = $scope->sql('h.legal_entity_id', 'h.site_id', 'h.department_id');
            $args = array_merge([$today, $today], $p);
            $extra = '';
            if ($legalEntityId !== null) {
                $extra = ' AND h.legal_entity_id = ?';
                $args[] = $legalEntityId;
            }
            foreach (
                $db->all("SELECT h.employee_id FROM employment_history h JOIN employees e ON e.id = h.employee_id WHERE h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?)
                AND e.status <> 'former' AND {$sql}{$extra} LIMIT 500", $args) as $r
            ) {
                $ids[] = (int) $r['employee_id'];
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return ['employees' => [], 'totals' => [], 'team' => $team, 'hr' => $hr, 'calculated_at' => null];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $emps = $db->all("SELECT id, public_id, employee_code, name_th, name_en, preferred_name FROM employees WHERE id IN ({$place}) ORDER BY employee_code", $ids);
        $first = $ym . '-01';
        $last = EmployeeService::ymd($first)->modify('last day of this month')->format('Y-m-d');
        $totals = [];
        foreach ($db->all("SELECT * FROM att_days WHERE employee_id IN ({$place}) AND work_date BETWEEN ? AND ?", array_merge($ids, [$first, $last])) as $d) {
            $totals[(int) $d['employee_id']][(string) $d['work_date']] = $d;
        }
        $sum = [];
        foreach ($emps as $e) {
            $sum[(int) $e['id']] = self::totals($totals[(int) $e['id']] ?? []);
            $sum[(int) $e['id']]['pending'] = (int) $db->scalar("SELECT COUNT(*) FROM att_corrections WHERE employee_id = ? AND status = 'pending'", [$e['id']]);
        }
        $calc = $db->scalar("SELECT MAX(calculated_at) FROM att_days WHERE employee_id IN ({$place}) AND work_date BETWEEN ? AND ?", array_merge($ids, [$first, $last]));
        return ['employees' => $emps, 'totals' => $sum, 'team' => $team, 'hr' => $hr, 'calculated_at' => is_string($calc) ? $calc : null];
    }

    /**
     * Recalculates a month for the team/HR list the user can see (button "recalculate") — capped.
     */
    public function recalcVisible(Authorizer $az, string $ym, ?int $legalEntityId): int
    {
        $team = $this->team($az, $ym, $legalEntityId);
        if (!$team['team'] && !$team['hr']) {
            throw HttpException::forbidden();
        }
        $first = $ym . '-01';
        $last = EmployeeService::ymd($first)->modify('last day of this month')->format('Y-m-d');
        $n = 0;
        foreach (array_slice($team['employees'], 0, 200) as $e) {
            $this->recalc((int) $e['id'], $first, $last);
            $n++;
        }
        $this->app->audit()->record('attendance.recalculated', 'success', 'attendance_month', $ym, $legalEntityId, null, ['employees' => $n]);
        return $n;
    }

    /** Time of the last successful pull from the Punch API (honest "data as of", 6.30.14). */
    public function lastPull(): ?string
    {
        $v = $this->app->db()->scalar("SELECT MAX(finished_at) FROM att_pull_runs WHERE status IN ('success','partial')");
        return is_string($v) ? $v : null;
    }

    /**
     * Scheduled: results of yesterday and today for everyone employed (absences appear without anyone opening a page).
     */
    public static function dailyJob(App $app): int
    {
        if (!$app->db()->tableExists('att_days')) {
            return 0;
        }
        $svc = self::of($app);
        $today = $svc->today();
        $yesterday = EmployeeService::ymd($today)->modify('-1 day')->format('Y-m-d');
        $n = 0;
        foreach (
            $app->db()->all("SELECT DISTINCT h.employee_id FROM employment_history h JOIN employees e ON e.id = h.employee_id
            WHERE e.status = 'active' AND h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?) LIMIT 3000", [$today, $yesterday]) as $r
        ) {
            $n += $svc->recalc((int) $r['employee_id'], $yesterday, $today);
        }
        return $n;
    }

    private function displayTz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }
}
