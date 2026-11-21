<?php

declare(strict_types=1);

namespace Blue\Modules\Payroll;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 6A (installs with demo data, once): DEMO1 pay components made by payroll.maker and
 * activated by payroll.checker, and an approved pay profile for each DEMO1 employee (monthly; D1-0007 daily) (made-up amounts, no bank
 * accounts). NO rule values are seeded — the company pay rules and the social security values stay empty until
 * someone enters and approves them (the run then lists them as missing).
 */
final class PayrollDemo
{
    /** code, Thai name, English name, kind, method, taxable, sso_base, statutory, order */
    private const COMPONENTS = [
        ['SALARY', 'เงินเดือน', 'Salary', 'earning', 'base', 1, 1, 'none', 10],
        ['POS_ALLOW', 'ค่าตำแหน่ง', 'Position allowance', 'earning', 'fixed', 1, 1, 'none', 20],
        ['OT', 'ค่าล่วงเวลา', 'Overtime', 'earning', 'ot', 1, 1, 'none', 30],
        ['BONUS', 'เงินได้อื่น (กรอกเอง)', 'Other earning (manual)', 'earning', 'manual', 1, 0, 'none', 40],
        ['ABSENT', 'หักขาดงาน', 'Absence', 'deduction', 'absence', 1, 1, 'none', 50],
        ['SSO_EE', 'ประกันสังคม (ลูกจ้าง)', 'Social security (employee)', 'deduction', 'sso_employee', 0, 0, 'sso_employee', 60],
        ['WHT', 'ภาษีหัก ณ ที่จ่าย (กรอกเอง)', 'Withholding tax (manual)', 'deduction', 'manual', 0, 0, 'wht', 70],
        ['SSO_ER', 'ประกันสังคม (นายจ้าง)', 'Social security (employer)', 'employer', 'sso_employer', 0, 0, 'sso_employer', 80],
    ];

    /** made-up pay of the DEMO1 people, by employee code (D1-0007 is paid daily) */
    private const SALARIES = ['D1-0001' => '52000.00', 'D1-0002' => '38000.00', 'D1-0003' => '30000.00', 'D1-0004' => '18500.00', 'D1-0005' => '16000.00', 'D1-0006' => '21000.00', 'D1-0007' => '520.00'];

    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('pay_components')) {
            return 0;
        }
        self::checker($db, $clock);
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        $maker = $db->scalar("SELECT id FROM users WHERE username = 'payroll.maker' AND is_demo = 1");
        $checker = $db->scalar("SELECT id FROM users WHERE username = 'payroll.checker' AND is_demo = 1");
        if ($le === null || $maker === null || $checker === null || $db->scalar('SELECT id FROM pay_components WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $ids = [];
        foreach (self::COMPONENTS as [$code, $th, $en, $kind, $method, $tax, $sso, $stat, $order]) {
            $db->run(
                "INSERT INTO pay_components (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, code, name_th, name_en, kind, method, taxable, sso_base, statutory, sort_order, status, activated_by, activated_at, is_demo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, 1)",
                [Ulid::generate($clock), $now, $maker, $now, $checker, $le, $code, $th, $en, $kind, $method, $tax, $sso, $stat, $order, $checker, $now]
            );
            $ids[$code] = (int) $db->pdo()->lastInsertId();
        }
        $n = 0;
        foreach ($db->all("SELECT DISTINCT e.id, e.employee_code, e.hire_date FROM employees e JOIN employment_history h ON h.employee_id = e.id WHERE h.legal_entity_id = ? AND e.is_demo = 1", [$le]) as $e) {
            $salary = self::SALARIES[(string) $e['employee_code']] ?? null;
            if ($salary === null) {
                continue;
            }
            $items = in_array($e['employee_code'], ['D1-0001', 'D1-0002', 'D1-0003'], true) ? json_encode([['component_id' => $ids['POS_ALLOW'], 'code' => 'POS_ALLOW', 'amount' => '3000.0000']]) : null;
            $db->run(
                "INSERT INTO pay_profiles (public_id, created_at, created_by, updated_at, updated_by, employee_id, legal_entity_id, effective_from, pay_basis, base_amount, items_json, status, reason, proposed_by, decided_by, decided_at, is_demo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', 'DEC-34 demo (ตัวอย่าง)', ?, ?, ?, 1)",
                [Ulid::generate($clock), $now, $maker, $now, $checker, $e['id'], $le, $e['hire_date'], $e['employee_code'] === 'D1-0007' ? 'daily' : 'monthly', $salary, $items, $maker, $checker, $now]
            );
            $n++;
        }
        return $n;
    }
    /**
     * 0.49.0 (DEC-118 · Q-11): the demo internal auditor (auditor.demo) is the demo Payroll Checker (PYC) of DEMO1, so the
     * review list can be tried — every sync, once. A real company appoints its own checker (not a payroll maker/approver).
     */
    private static function checker(Connection $db, Clock $clock): void
    {
        $uid = $db->scalar("SELECT id FROM users WHERE username = 'auditor.demo' AND is_demo = 1");
        $role = $db->scalar("SELECT id FROM roles WHERE code = 'PYC'");
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if (
            $uid === null || $role === null || $le === null
            || $db->scalar("SELECT id FROM user_role_assignments WHERE user_id = ? AND role_id = ? AND status = 'active'", [$uid, $role]) !== null
        ) {
            return;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $admin = $db->scalar("SELECT u.id FROM users u JOIN user_role_assignments a ON a.user_id = u.id JOIN roles r ON r.id = a.role_id WHERE r.code = 'SYS' AND u.is_demo = 0 ORDER BY u.id LIMIT 1");
        $db->run(
            "INSERT INTO user_role_assignments (public_id, created_at, updated_at, row_version, user_id, role_id, status, valid_from, requested_by, approved_by, approved_at, reason)
             VALUES (?, ?, ?, 1, ?, ?, 'active', ?, ?, ?, ?, 'DEC-34 demo user (0.49.0 Payroll Checker)')",
            [Ulid::generate($clock), $now, $now, $uid, $role, $clock->now()->format('Y-m-d'), $admin, $admin, $admin !== null ? $now : null]
        );
        $db->run('INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, ?, ?)', [(int) $db->pdo()->lastInsertId(), 'legal_entity', $le]);
    }
}
