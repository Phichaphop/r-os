<?php

declare(strict_types=1);

namespace Blue\Core\Import;

use Blue\Core\Authz\Authorizer;
use Blue\Core\Calendar\WorkCalendar;
use Blue\Core\Database\Connection;
use Blue\Core\Files\Xlsx;

/**
 * Holidays / special working days for one calendar (03 §6.14 "เพิ่ม/แก้/นำเข้า Excel ได้").
 * Accepts dates as 2026-12-05, 05/12/2026, 05/12/2569 (Buddhist Era) or an Excel date cell; type and kind in
 * English codes or Thai words. Existing dates are updated (bulk update with preview), new dates are added.
 */
final class CalendarDaysImport implements ImportDefinition
{
    private const TYPES = ['holiday' => 'holiday', 'วันหยุด' => 'holiday', 'working' => 'working', 'วันทำงาน' => 'working', 'weekend' => 'weekend', 'วันหยุดประจำสัปดาห์' => 'weekend'];
    private const KINDS = ['public' => 'public', 'นักขัตฤกษ์' => 'public', 'วันหยุดตามประเพณี' => 'public', 'company' => 'company', 'บริษัท' => 'company', 'special' => 'special', 'พิเศษ' => 'special'];

    public function __construct(private readonly Connection $db, private readonly WorkCalendar $calendars)
    {
    }

    public function code(): string
    {
        return 'calendar.days';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function titleKey(): string
    {
        return 'calendar.import.title';
    }

    public function columns(): array
    {
        return [
            ['key' => 'date', 'label' => 'calendar.field.date', 'required' => true, 'example' => '2026-12-31', 'help' => 'calendar.import.help_date'],
            ['key' => 'type', 'label' => 'calendar.field.day_type', 'required' => true, 'example' => 'holiday', 'help' => 'calendar.import.help_type'],
            ['key' => 'kind', 'label' => 'calendar.field.kind', 'required' => false, 'example' => 'company', 'help' => 'calendar.import.help_kind'],
            ['key' => 'name', 'label' => 'calendar.field.holiday_name', 'required' => false, 'example' => 'วันหยุดตัวอย่าง (DEMO)'],
            ['key' => 'note', 'label' => 'calendar.field.note', 'required' => false, 'example' => ''],
        ];
    }

    public function prepare(Authorizer $az, array $params): array
    {
        $c = $this->calendars->get($az, (string) ($params['calendar'] ?? ''), true);
        $le = match ((string) $c['scope_level']) {
            'legal_entity' => (int) $c['scope_id'],
            'site' => (int) ($this->db->scalar('SELECT legal_entity_id FROM sites WHERE id = ?', [$c['scope_id']]) ?? 0) ?: null,
            default => null,
        };
        return ['calendar' => (string) $c['public_id'], 'calendar_id' => (int) $c['id'], 'legal_entity_id' => $le];
    }

    public function validateRow(array $row, array $params, array &$seen): array
    {
        $errors = [];
        $date = self::parseDate($row['date'] ?? '');
        if ($date === null) {
            $errors['date'] = 'calendar.error.date';
        } elseif (isset($seen[$date])) {
            $errors['date'] = 'import.error.duplicate_in_file';
        } else {
            $seen[$date] = 1;
        }
        $type = self::TYPES[mb_strtolower(trim($row['type'] ?? ''))] ?? null;
        $kindRaw = mb_strtolower(trim($row['kind'] ?? ''));
        $kind = $kindRaw === '' ? null : (self::KINDS[$kindRaw] ?? 'invalid');
        $name = trim($row['name'] ?? '');
        if ($type === null) {
            $errors['type'] = 'calendar.error.type';
        } elseif ($type === 'holiday') {
            $kind ??= 'company';
            foreach (WorkCalendar::dayErrors($date ?? '2000-01-01', $type, $kind === 'invalid' ? null : $kind, $name) as $f => $k) {
                if ($f !== 'date') {
                    $errors[$f] = $k;
                }
            }
        }
        if (mb_strlen($name) > 120) {
            $errors['name'] = 'calendar.error.name_long';
        }
        $action = 'create';
        if ($date !== null && $this->db->scalar('SELECT id FROM calendar_days WHERE calendar_id = ? AND day_date = ?', [$params['calendar_id'], $date]) !== null) {
            $action = 'update';
        }
        return ['data' => ['date' => $date, 'type' => $type, 'kind' => $type === 'holiday' ? $kind : null, 'name' => $name, 'note' => mb_substr(trim($row['note'] ?? ''), 0, 255)], 'errors' => $errors, 'action' => $action];
    }

    public function apply(Authorizer $az, array $params, array $rows, int $batchId): int
    {
        $n = 0;
        foreach ($rows as $r) {
            $this->calendars->putDay((int) $params['calendar_id'], (string) $r['date'], (string) $r['type'], $r['kind'] !== null ? (string) $r['kind'] : null, (string) $r['name'], (string) $r['note'], $az->userId, $batchId);
            $n++;
        }
        return $n;
    }

    public function sensitiveColumns(): array
    {
        return [];
    }

    public function backLink(array $params): string
    {
        return '/calendars/' . $params['calendar'];
    }

    /** Y-m-d | d/m/yyyy (BE years > 2400 converted) | Excel serial → Y-m-d */
    public static function parseDate(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{4})$#', $v, $m) === 1) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return Xlsx::serialToDate($v);
        }
        if ($y > 2400) {
            $y -= 543;
        }
        return $y >= 1900 && $y <= 2200 && checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }
}
