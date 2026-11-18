<?php

declare(strict_types=1);

namespace Blue\Modules\Employees;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 4A, added once to installs that have demo data (also when upgrading): seven DEMO1
 * employees with a reporting line (HR manager → HR admin; sales manager → three staff), linked to the existing demo
 * accounts, plus two new demo accounts — manager.demo1 (line manager, role MGR) and hr.head1 (a second HR admin so
 * HR settings can be checked by another person). Same demo password as the other demo users. Names are fictitious.
 */
final class EmployeesDemo
{
    /** code, name, site, department, position, manager code, group, type, hire date, account */
    private const PEOPLE = [
        ['D1-0001', 'ผู้จัดการฝ่ายบุคคล (DEMO)', 'HQ', 'HR', 'ผู้จัดการฝ่ายบุคคล', null, 'MONTHLY', 'permanent', '2019-04-01', 'hr.head1'],
        ['D1-0002', 'HR Admin บริษัทตัวอย่าง (DEMO)', 'HQ', 'HR', 'เจ้าหน้าที่บุคคล', 'D1-0001', 'MONTHLY', 'permanent', '2021-06-01', 'hr.demo1'],
        ['D1-0003', 'หัวหน้าฝ่ายขาย (DEMO)', 'BR1', 'SALES', 'หัวหน้าฝ่ายขาย', null, 'MONTHLY', 'permanent', '2020-02-03', 'manager.demo1'],
        ['D1-0004', 'พนักงานตัวอย่าง (DEMO)', 'BR1', 'SALES', 'พนักงานขาย', 'D1-0003', 'MONTHLY', 'permanent', '2023-03-01', 'employee.demo1'],
        ['D1-0005', 'พนักงานขายสอง (DEMO)', 'BR1', 'SALES', 'พนักงานขาย', 'D1-0003', 'MONTHLY', 'permanent', '2024-07-15', null],
        ['D1-0006', 'พนักงานขายสาม (DEMO)', 'BR1', 'SALES', 'พนักงานขาย', 'D1-0003', 'MONTHLY', 'probation', '2026-08-01', null],
        ['D1-0007', 'พนักงานรายวัน (DEMO)', 'BR1', 'SALES', 'ผู้ช่วยขาย', 'D1-0003', 'DAILY', 'daily', '2025-11-01', null],
    ];

    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('employees')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if ($le === null || $db->scalar('SELECT id FROM employees WHERE is_demo = 1 LIMIT 1') !== null) {
            return 0;
        }
        $le = (int) $le;
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        self::accounts($db, $clock, $le, $now);
        $ids = [];
        foreach (self::PEOPLE as [$code, $name, $site, $dept, $pos, $mgr, $group, $type, $hire, $account]) {
            if ($db->scalar('SELECT id FROM employees WHERE employee_code = ?', [$code]) !== null) {
                continue;
            }
            $db->run(
                "INSERT INTO employees (public_id, created_at, updated_at, employee_code, name_th, status, hire_date, is_demo) VALUES (?, ?, ?, ?, ?, 'active', ?, 1)",
                [Ulid::generate($clock), $now, $now, $code, $name, $hire]
            );
            $id = (int) $db->pdo()->lastInsertId();
            $ids[$code] = $id;
            $db->run(
                'INSERT INTO employment_history (public_id, created_at, updated_at, employee_id, legal_entity_id, site_id, department_id, position_title, manager_employee_id,
                    employee_group, employment_type, effective_from, change_reason)
                 VALUES (?, ?, ?, ?, ?, (SELECT id FROM sites WHERE legal_entity_id = ? AND code = ?), (SELECT id FROM departments WHERE legal_entity_id = ? AND code = ?), ?, ?, ?, ?, ?, ?)',
                [Ulid::generate($clock), $now, $now, $id, $le, $le, $site, $le, $dept, $pos, $mgr !== null ? ($ids[$mgr] ?? null) : null, $group, $type, $hire, 'DEC-34 demo']
            );
            if ($db->tableExists('employee_status_history')) { // 0.8.0: status is effective-dated
                $db->run(
                    "INSERT INTO employee_status_history (public_id, created_at, updated_at, employee_id, status, effective_from, reason) VALUES (?, ?, ?, ?, 'active', ?, 'DEC-34 demo')",
                    [Ulid::generate($clock), $now, $now, $id, $hire]
                );
                $db->run('UPDATE employees SET work_email = ? WHERE id = ?', [strtolower($code) . '@demo.invalid', $id]);
            }
            if ($account !== null) {
                $db->run('UPDATE users SET employee_id = ? WHERE username = ? AND is_demo = 1 AND employee_id IS NULL', [$id, $account]);
            }
        }
        return count($ids);
    }

    private static function accounts(Connection $db, Clock $clock, int $le, string $now): void
    {
        $hash = $db->scalar("SELECT password_hash FROM users WHERE username = 'hr.demo1' AND is_demo = 1");
        if ($hash === null) {
            return;
        }
        $admin = $db->scalar("SELECT u.id FROM users u JOIN user_role_assignments a ON a.user_id = u.id JOIN roles r ON r.id = a.role_id WHERE r.code = 'SYS' AND u.is_demo = 0 ORDER BY u.id LIMIT 1");
        foreach ([['manager.demo1', 'หัวหน้าฝ่ายขาย (DEMO)', 'MGR'], ['hr.head1', 'ผู้จัดการฝ่ายบุคคล (DEMO)', 'HRA']] as [$username, $name, $role]) {
            if ($db->scalar('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
                continue;
            }
            $db->run(
                "INSERT INTO users (public_id, created_at, updated_at, row_version, username, display_name, email, password_hash, status, password_changed_at, is_demo)
                 VALUES (?, ?, ?, 1, ?, ?, ?, ?, 'active', ?, 1)",
                [Ulid::generate($clock), $now, $now, $username, $name, $username . '@demo.invalid', $hash, $now]
            );
            $uid = (int) $db->pdo()->lastInsertId();
            $db->run(
                "INSERT INTO user_role_assignments (public_id, created_at, updated_at, row_version, user_id, role_id, status, valid_from, requested_by, approved_by, approved_at, reason)
                 VALUES (?, ?, ?, 1, ?, (SELECT id FROM roles WHERE code = ?), 'active', ?, ?, ?, ?, 'DEC-34 demo user (phase 4A)')",
                [Ulid::generate($clock), $now, $now, $uid, $role, $clock->now()->format('Y-m-d'), $admin, $admin, $admin !== null ? $now : null]
            );
            $db->run("INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, 'legal_entity', ?)", [(int) $db->pdo()->lastInsertId(), $le]);
        }
    }
}
