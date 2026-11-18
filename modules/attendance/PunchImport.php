<?php

declare(strict_types=1);

namespace Blue\Modules\Attendance;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\HttpException;
use Blue\Core\Import\CalendarDaysImport;
use Blue\Core\Import\ImportDefinition;
use Blue\Modules\Employees\EmployeeService;

/**
 * BLUE_PUNCH_IMPORT_XLSX_v1 (P0-03 §12, DEC-30) — the second way in when the Collector cannot be reached:
 * employee code · date · time · in/out (optional) · device or place code (optional) · note (optional).
 * Each row becomes one raw punch (source "file") with source_event_id = hash(employee + date time + device), so the
 * same file imported twice adds nothing. Times are local time of the employee's site. Future times are refused.
 */
final class PunchImport implements ImportDefinition
{
    public const TEMPLATE = 'BLUE_PUNCH_IMPORT_XLSX_v1';

    private function __construct(private readonly App $app)
    {
    }

    public static function create(App $app): self
    {
        return new self($app);
    }

    public function code(): string
    {
        return 'attendance.punches';
    }

    public function version(): string
    {
        return 'v1';
    }

    /** File name of the template (the import controller uses it when a definition has one). */
    public function templateName(): string
    {
        return self::TEMPLATE;
    }

    public function titleKey(): string
    {
        return 'attendance.import.title';
    }

    public function columns(): array
    {
        return [
            ['key' => 'employee_code', 'label' => 'employees.field.code', 'required' => true, 'example' => 'D1-0005'],
            ['key' => 'date', 'label' => 'attendance.field.date', 'required' => true, 'example' => '2026-11-09', 'help' => 'attendance.import.help_date'],
            ['key' => 'time', 'label' => 'attendance.field.time', 'required' => true, 'example' => '08:02', 'help' => 'attendance.import.help_time'],
            ['key' => 'direction', 'label' => 'attendance.field.direction', 'required' => false, 'example' => 'in', 'help' => 'attendance.import.help_direction'],
            ['key' => 'device_code', 'label' => 'attendance.field.device_code', 'required' => false, 'example' => 'GATE-1', 'help' => 'attendance.import.help_device'],
            ['key' => 'note', 'label' => 'attendance.field.note', 'required' => false, 'example' => 'สแกนเสีย ใช้ใบลงเวลา'],
        ];
    }

    public function prepare(Authorizer $az, array $params): array
    {
        $le = (int) ($params['le'] ?? 0);
        if ($le <= 0 || !$az->scope('attendance.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        return ['le' => (string) $le, 'legal_entity_id' => $le];
    }

    public function validateRow(array $row, array $params, array &$seen): array
    {
        $db = $this->app->db();
        $emp = EmployeeService::of($this->app);
        $errors = [];
        $code = strtoupper(trim($row['employee_code'] ?? ''));
        $e = $db->one('SELECT * FROM employees WHERE employee_code = ?', [$code]);
        $date = CalendarDaysImport::parseDate(trim($row['date'] ?? ''));
        if ($date === null) {
            $errors['date'] = 'attendance.error.date';
        }
        $job = $e !== null && $date !== null ? $emp->employment((int) $e['id'], $date) : null;
        if ($e === null || ($date !== null && ($job === null || (int) $job['legal_entity_id'] !== (int) $params['legal_entity_id']))) {
            $errors['employee_code'] = 'attendance.import.no_employee';
        }
        if ($date !== null && $job !== null && PeriodService::of($this->app)->frozen((int) $job['legal_entity_id'], $date)) {
            $errors['date'] = 'attendance.error.period_closed'; // DEC-66: a closed period takes no new punches — reopen it first
        }
        $time = self::parseTime(trim($row['time'] ?? ''));
        if ($time === null) {
            $errors['time'] = 'attendance.error.time';
        }
        $dir = strtolower(trim($row['direction'] ?? ''));
        $dir = match ($dir) {
            '', '-' => null,
            'in', 'i', 'เข้า' => 'in',
            'out', 'o', 'ออก' => 'out',
            default => 'bad',
        };
        if ($dir === 'bad') {
            $errors['direction'] = 'attendance.import.bad_direction';
        }
        $device = trim($row['device_code'] ?? '');
        if ($device !== '' && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $device) !== 1) {
            $errors['device_code'] = 'attendance.import.bad_device';
        }
        $utc = null;
        if ($date !== null && $time !== null && $job !== null) {
            $tz = AttendanceService::of($this->app)->siteTz($job['site_id'] !== null ? (int) $job['site_id'] : null);
            $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time, $tz);
            $utc = $local !== false ? $local->setTimezone(new DateTimeZone('UTC')) : null;
            if ($utc === null || $utc > $this->app->clock->now()) {
                $errors['time'] = 'attendance.import.future';
            }
        }
        $eventId = 'F-' . substr(hash('sha256', $code . '|' . $date . ' ' . $time . '|' . strtoupper($device)), 0, 40);
        if (isset($seen[$eventId])) {
            $errors['time'] = 'import.error.duplicate_in_file';
        }
        $seen[$eventId] = 1;
        $action = 'create';
        if ($errors === [] && $db->scalar("SELECT id FROM att_raw_punches WHERE source = 'file' AND source_event_id = ?", [$eventId]) !== null) {
            $action = 'none'; // imported before — nothing to add
        }
        $note = mb_substr(trim($row['note'] ?? ''), 0, 200);
        return ['data' => ['employee_id' => (int) ($e['id'] ?? 0), 'utc' => $utc?->format('Y-m-d H:i:s'), 'local' => $date . ' ' . $time, 'date' => $date,
            'direction' => $dir === 'bad' ? null : $dir, 'device' => $device === '' ? null : $device, 'note' => $note === '' ? null : $note, 'event' => $eventId], 'errors' => $errors, 'action' => $action];
    }

    public function apply(Authorizer $az, array $params, array $rows, int $batchId): int
    {
        $db = $this->app->db();
        $n = 0;
        $affected = [];
        $now = $this->app->clock->now()->format('Y-m-d H:i:s.u');
        foreach ($rows as $r) {
            if ($r['utc'] === null) {
                continue;
            }
            $ins = $db->run(
                "INSERT IGNORE INTO att_raw_punches (source, source_event_id, device_code, employee_id, occurred_at_utc, device_local_time, verify_method, direction, note, import_batch_id, received_at, created_by)
                 VALUES ('file', ?, ?, ?, ?, ?, 'file', ?, ?, ?, ?, ?)",
                [$r['event'], $r['device'], $r['employee_id'], $r['utc'], $r['local'], $r['direction'], $r['note'], $batchId, $now, $az->userId]
            )->rowCount();
            if ($ins === 1) {
                $n++;
                $affected[(int) $r['employee_id']][] = (string) $r['date'];
            }
        }
        $att = AttendanceService::of($this->app);
        foreach ($affected as $empId => $dates) {
            sort($dates);
            $att->recalc($empId, EmployeeService::ymd($dates[0])->modify('-1 day')->format('Y-m-d'), (string) end($dates));
        }
        return $n;
    }

    /** "8:02", "08:02", "08:02:30", or an Excel time fraction (0.3347…) → H:i:s */
    public static function parseTime(string $v): ?string
    {
        if (preg_match('/^(\d{1,2})[:.](\d{2})(?::(\d{2}))?$/', $v, $m) === 1) {
            [$h, $i, $s] = [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];
            return $h < 24 && $i < 60 && $s < 60 ? sprintf('%02d:%02d:%02d', $h, $i, $s) : null;
        }
        if (is_numeric($v) && (float) $v >= 0 && (float) $v < 1) {
            $sec = (int) round((float) $v * 86400);
            return $sec < 86400 ? sprintf('%02d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60) : null;
        }
        return null;
    }

    public function sensitiveColumns(): array
    {
        return [];
    }

    public function backLink(array $params): string
    {
        return '/attendance/admin?le=' . (int) $params['legal_entity_id'] . '#import';
    }
}
