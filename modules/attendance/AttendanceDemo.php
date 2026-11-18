<?php

declare(strict_types=1);

namespace Blue\Modules\Attendance;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 4B: the time-clock user codes of the seven DEMO1 employees (D1-0001 → 000101 …,
 * valid on any device from the hire date). No work schedule and no punches — the schedule is a company decision
 * (HR proposes, another HR approves) and punches come from the (simulated) Time Collector or the import file.
 */
final class AttendanceDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('att_device_users') || !$db->tableExists('employees')) {
            return 0;
        }
        if ($db->scalar('SELECT id FROM att_device_users WHERE is_demo = 1 LIMIT 1') !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $n = 0;
        foreach ($db->all("SELECT id, employee_code, hire_date FROM employees WHERE is_demo = 1 AND employee_code LIKE 'D1-____' ORDER BY employee_code") as $e) {
            $code = '0001' . substr((string) $e['employee_code'], -2);
            $db->run(
                "INSERT INTO att_device_users (public_id, created_at, updated_at, device_user_code, employee_id, valid_from, note, is_demo) VALUES (?, ?, ?, ?, ?, ?, 'DEC-34 demo', 1)",
                [Ulid::generate($clock), $now, $now, $code, $e['id'], $e['hire_date']]
            );
            $n++;
        }
        return $n;
    }
}
