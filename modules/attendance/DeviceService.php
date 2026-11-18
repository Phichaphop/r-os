<?php

declare(strict_types=1);

namespace Blue\Modules\Attendance;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;

/**
 * Time clocks and "who is user code X on the clock" (DEC-64, P0-03 device_user_mappings).
 *  - Devices appear by themselves when the first punch arrives (status "new"); IT/HR with attendance.collector names
 *    them and assigns a site.
 *  - HR (attendance.manage) maps a device user code to an employee from a date; a mapping can be ended, never edited.
 *    Two mappings of the same code (same device, or "any device") may not overlap in time.
 *  - Unmapped codes are listed so nobody's punches silently disappear.
 */
final class DeviceService
{
    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    // ---- devices ---------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function devices(): array
    {
        return $this->app->db()->all(
            'SELECT d.*, s.code AS site_code,
                    (SELECT COUNT(*) FROM att_raw_punches p WHERE p.device_id = d.id) AS punches,
                    (SELECT MAX(occurred_at_utc) FROM att_raw_punches p WHERE p.device_id = d.id) AS last_punch
               FROM att_devices d LEFT JOIN sites s ON s.id = d.site_id ORDER BY d.status = \'retired\', d.device_code'
        );
    }

    /** Device of a collector punch — created on first sight. */
    public function ensureDevice(string $code, ?string $serial, ?string $tz): int
    {
        $db = $this->app->db();
        $id = $db->scalar('SELECT id FROM att_devices WHERE device_code = ?', [$code]);
        if ($id !== null) {
            return (int) $id;
        }
        $now = $this->now();
        $db->run(
            "INSERT IGNORE INTO att_devices (public_id, created_at, updated_at, device_code, serial_no, timezone, status) VALUES (?, ?, ?, ?, ?, ?, 'new')",
            [Ulid::generate($this->app->clock), $now, $now, mb_substr($code, 0, 40), $serial !== null ? mb_substr($serial, 0, 40) : null, $tz !== null ? mb_substr($tz, 0, 40) : null]
        );
        return (int) $db->scalar('SELECT id FROM att_devices WHERE device_code = ?', [$code]);
    }

    /** @param array<string, string> $in name, site (id), status */
    public function updateDevice(Authorizer $az, string $pid, array $in): void
    {
        if (!$az->scope('attendance.collector')->all && $az->scope('attendance.collector')->isEmpty()) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $d = $db->one('SELECT * FROM att_devices WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $errors = [];
        $site = (int) ($in['site'] ?? 0);
        if ($site > 0 && $db->scalar("SELECT id FROM sites WHERE id = ? AND status = 'active'", [$site]) === null) {
            $errors['site'] = 'attendance.error.site';
        }
        $status = in_array($in['status'] ?? '', ['new', 'active', 'retired'], true) ? (string) $in['status'] : (string) $d['status'];
        if ($status === 'active' && $site === 0) {
            $errors['site'] = 'attendance.error.site_needed';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $name = trim((string) ($in['name'] ?? ''));
        $db->run(
            'UPDATE att_devices SET name = ?, site_id = ?, status = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$name === '' ? null : mb_substr($name, 0, 80), $site > 0 ? $site : null, $status, $this->now(), $az->userId, $d['id']]
        );
        $this->app->audit()->record('attendance.device_updated', 'success', 'att_device', $pid, null, null, [
            'device' => $d['device_code'], 'before' => ['name' => $d['name'], 'site' => $d['site_id'], 'status' => $d['status']],
            'after' => ['name' => $name, 'site' => $site ?: null, 'status' => $status],
        ]);
    }

    /** Update last contact / clock drift from the Collector health answer. */
    public function touch(string $code, ?string $lastSeenUtc, ?int $drift): void
    {
        $this->app->db()->run('UPDATE att_devices SET last_seen_at = COALESCE(?, last_seen_at), clock_drift_seconds = ? WHERE device_code = ?', [$lastSeenUtc, $drift, $code]);
    }

    // ---- mappings --------------------------------------------------------------------------------------

    /**
     * Mappings of employees HR may manage (optionally one company).
     *
     * @return list<array<string, mixed>>
     */
    public function mappings(Authorizer $az, ?int $legalEntityId): array
    {
        $scope = $az->scope('attendance.manage');
        if ($scope->isEmpty()) {
            return [];
        }
        $today = EmployeeService::of($this->app)->today();
        [$sql, $p] = $scope->sql('h.legal_entity_id', 'h.site_id', 'h.department_id');
        $args = array_merge([$today, $today], $p);
        $extra = '';
        if ($legalEntityId !== null) {
            $extra = ' AND h.legal_entity_id = ?';
            $args[] = $legalEntityId;
        }
        return $this->app->db()->all(
            "SELECT m.*, e.public_id AS employee_pid, e.employee_code, e.name_th, e.name_en, e.preferred_name, d.device_code, d.name AS device_name
               FROM att_device_users m JOIN employees e ON e.id = m.employee_id LEFT JOIN att_devices d ON d.id = m.device_id
               JOIN employment_history h ON h.employee_id = e.id AND h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?)
              WHERE {$sql}{$extra} ORDER BY m.device_user_code, m.valid_from LIMIT 1000",
            $args
        );
    }

    /**
     * @param array<string, string> $in device (public id or ''), code, employee_code, valid_from, note
     */
    public function map(Authorizer $az, array $in): string
    {
        $db = $this->app->db();
        $emp = EmployeeService::of($this->app);
        $errors = [];
        $code = trim((string) ($in['code'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $code) !== 1) {
            $errors['code'] = 'attendance.error.user_code';
        }
        $e = $db->one('SELECT * FROM employees WHERE employee_code = ?', [strtoupper(trim((string) ($in['employee_code'] ?? '')))]);
        $job = $e !== null ? $emp->employment((int) $e['id'], $emp->today()) : null;
        if ($e === null || $job === null) {
            $errors['employee_code'] = 'attendance.error.employee';
        } elseif (!EmployeeService::covers($az->scope('attendance.manage'), $job)) {
            throw HttpException::forbidden();
        }
        $deviceId = null;
        if (($in['device'] ?? '') !== '') {
            $deviceId = $db->scalar('SELECT id FROM att_devices WHERE public_id = ?', [(string) $in['device']]);
            if ($deviceId === null) {
                $errors['device'] = 'attendance.error.device';
            }
        }
        $from = (string) ($in['valid_from'] ?? '');
        $fd = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        if ($fd === false || $fd->format('Y-m-d') !== $from) {
            $errors['valid_from'] = 'attendance.error.date';
        } elseif ($e !== null && $from < (string) $e['hire_date']) {
            $errors['valid_from'] = 'attendance.error.before_hire';
        }
        if ($errors === []) {
            $clash = $db->one(
                'SELECT m.*, e.employee_code FROM att_device_users m JOIN employees e ON e.id = m.employee_id WHERE m.device_user_code = ?
                   AND (m.device_id IS NULL OR ? IS NULL OR m.device_id = ?) AND (m.valid_to IS NULL OR m.valid_to >= ?) LIMIT 1',
                [$code, $deviceId, $deviceId, $from]
            );
            if ($clash !== null) {
                $errors['code'] = 'attendance.error.code_taken';
            }
        }
        if ($errors !== [] || $e === null) {
            throw new ValidationException($errors);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $note = trim((string) ($in['note'] ?? ''));
        $db->run(
            'INSERT INTO att_device_users (public_id, created_at, created_by, updated_at, updated_by, device_id, device_user_code, employee_id, valid_from, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $deviceId, $code, $e['id'], $from, $note === '' ? null : mb_substr($note, 0, 200)]
        );
        $this->app->audit()->record('attendance.mapped', 'success', 'att_device_user', $pid, (int) $job['legal_entity_id'], null, [
            'code' => $code, 'employee' => $e['employee_code'], 'device' => $deviceId, 'from' => $from,
        ]);
        AttendanceService::of($this->app)->recalc((int) $e['id'], max($from, EmployeeService::ymd($emp->today())->modify('-' . (AttendanceService::MAX_RANGE - 1) . ' days')->format('Y-m-d')), $emp->today());
        return $pid;
    }

    /** Ends a mapping on a date (the code may then be given to someone else from the next day). */
    public function endMapping(Authorizer $az, string $pid, string $to): void
    {
        $db = $this->app->db();
        $m = $db->one('SELECT * FROM att_device_users WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $emp = EmployeeService::of($this->app);
        $job = $emp->employment((int) $m['employee_id'], $emp->today()) ?? $db->one('SELECT * FROM employment_history WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 1', [$m['employee_id']]);
        if (!EmployeeService::covers($az->scope('attendance.manage'), $job)) {
            throw HttpException::notFound();
        }
        $td = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($td === false || $td->format('Y-m-d') !== $to || $to < (string) $m['valid_from']) {
            throw new ValidationException(['valid_to' => 'attendance.error.end_date']);
        }
        if ($m['valid_to'] !== null) {
            throw new BusinessException('attendance.error.mapping_ended', [], 409);
        }
        $db->run("UPDATE att_device_users SET valid_to = ?, status = 'ended', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$to, $this->now(), $az->userId, $m['id']]);
        $this->app->audit()->record('attendance.mapping_ended', 'success', 'att_device_user', $pid, $job !== null ? (int) $job['legal_entity_id'] : null, null, ['code' => $m['device_user_code'], 'to' => $to]);
        AttendanceService::of($this->app)->recalc((int) $m['employee_id'], $to, $emp->today());
    }

    /**
     * Device user codes with punches but no mapping (within a day of the punch). HR sees codes from devices of its
     * sites and from devices without a site yet.
     *
     * @return list<array<string, mixed>>
     */
    public function unmapped(Authorizer $az): array
    {
        $scope = $az->scope('attendance.manage');
        if ($scope->isEmpty()) {
            return [];
        }
        $rows = $this->app->db()->all(
            "SELECT p.device_user_code, p.device_id, d.public_id AS device_pid, d.device_code, d.name AS device_name, d.site_id, s.legal_entity_id,
                    COUNT(*) AS punches, MIN(p.occurred_at_utc) AS first_at, MAX(p.occurred_at_utc) AS last_at
               FROM att_raw_punches p LEFT JOIN att_devices d ON d.id = p.device_id LEFT JOIN sites s ON s.id = d.site_id
              WHERE p.source = 'api' AND p.employee_id IS NULL AND NOT EXISTS (
                    SELECT 1 FROM att_device_users m WHERE m.device_user_code = p.device_user_code AND (m.device_id IS NULL OR m.device_id = p.device_id)
                       AND m.valid_from <= DATE(p.occurred_at_utc + INTERVAL 1 DAY) AND (m.valid_to IS NULL OR m.valid_to >= DATE(p.occurred_at_utc - INTERVAL 1 DAY)))
              GROUP BY p.device_user_code, p.device_id, d.public_id, d.device_code, d.name, d.site_id, s.legal_entity_id ORDER BY last_at DESC LIMIT 200"
        );
        return array_values(array_filter($rows, static fn (array $r): bool => $r['site_id'] === null || $scope->all
            || in_array((int) $r['site_id'], $scope->siteIds, true) || in_array((int) $r['legal_entity_id'], $scope->legalEntityIds, true)));
    }

    /**
     * Which employee a device user code means on a date (for the pull: whose days to recalculate).
     */
    public function employeeFor(string $code, ?int $deviceId, string $date): ?int
    {
        $v = $this->app->db()->scalar(
            'SELECT employee_id FROM att_device_users WHERE device_user_code = ? AND (device_id IS NULL OR device_id = ?) AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?) ORDER BY id DESC LIMIT 1',
            [$code, $deviceId, $date, $date]
        );
        return $v !== null ? (int) $v : null;
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }
}
