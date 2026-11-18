<?php

declare(strict_types=1);

namespace Blue\Core\Install;

use Blue\Core\Database\Connection;
use Blue\Core\Org\Translations;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * 0.40.0 (R4, item 11 / Q-20): one demo company with every department and people to try every function with.
 * Runs once, after the module demos, on installs whose demo profile is 'company' (Bootstrap; the automated tests use
 * their own two-company fixture instead). On top of the DEC-34 basics it adds:
 *   - the full unit tree under DEMO1 on the fixed levels (DEC-68): executive office → 3 divisions → 15 departments →
 *     sections → a sub-section, plus a factory site;
 *   - 28 more fictitious employees with a reporting line, linked to the demo accounts that already exist
 *     (GA, driver, technician, safety, DPO, auditor, finance, payroll, executives …);
 *   - four more demo accounts so every role has someone: hr.officer1 (HR officer, HRT), manager.prod1 (production
 *     manager, MGR), worker.prod1 (production worker, EMP) and wallet.officer1 (e-wallet officer, WAL);
 *   - made-up pay profiles for the new people (only if payroll is installed; no bank accounts, no rule values).
 * Names and numbers are made up; nothing here is real personal data.
 */
final class CompanyDemo
{
    /** code, level, parent code, site code (null = none), Thai name, English name */
    public const UNITS = [
        ['EXEC', 'top_management', null, null, 'คณะผู้บริหาร', 'Executive office'],
        ['IA', 'department', 'EXEC', 'HQ', 'ฝ่ายตรวจสอบภายใน', 'Internal audit'],
        ['DIV-ADM', 'division', 'EXEC', null, 'สายงานบริหารและสนับสนุน', 'Administration and support'],
        ['HR', 'department', 'DIV-ADM', 'HQ', null, null],
        ['HR-REC', 'section', 'HR', 'HQ', 'แผนกสรรหาและพัฒนาบุคลากร', 'Recruitment and development'],
        ['HR-PAY', 'section', 'HR', 'HQ', 'แผนกค่าตอบแทนและเงินเดือน', 'Compensation and payroll'],
        ['ACC', 'department', 'DIV-ADM', 'HQ', null, null],
        ['FIN', 'department', 'DIV-ADM', 'HQ', 'ฝ่ายการเงิน', 'Finance'],
        ['IT', 'department', 'DIV-ADM', 'HQ', null, null],
        ['GA', 'department', 'DIV-ADM', 'HQ', 'ฝ่ายธุรการและอาคารสถานที่', 'General affairs and facilities'],
        ['GA-VEH', 'section', 'GA', 'HQ', 'แผนกยานพาหนะ', 'Vehicles'],
        ['LEGAL', 'department', 'DIV-ADM', 'HQ', 'ฝ่ายกฎหมายและคุ้มครองข้อมูล', 'Legal and data protection'],
        ['DIV-OPS', 'division', 'EXEC', null, 'สายงานปฏิบัติการ', 'Operations'],
        ['PROD', 'department', 'DIV-OPS', 'FAC1', 'ฝ่ายผลิต', 'Production'],
        ['PROD-A', 'section', 'PROD', 'FAC1', 'แผนกผลิต A', 'Production line A'],
        ['PROD-A1', 'sub_section', 'PROD-A', 'FAC1', 'หน่วยผลิต A กะเช้า', 'Line A, morning shift'],
        ['QA', 'department', 'DIV-OPS', 'FAC1', 'ฝ่ายประกันคุณภาพ', 'Quality assurance'],
        ['MAINT', 'department', 'DIV-OPS', 'FAC1', 'ฝ่ายซ่อมบำรุง', 'Maintenance'],
        ['WH', 'department', 'DIV-OPS', 'FAC1', 'ฝ่ายคลังสินค้า', 'Warehouse'],
        ['SHE', 'department', 'DIV-OPS', 'FAC1', 'ฝ่ายความปลอดภัย อาชีวอนามัยและสิ่งแวดล้อม', 'Safety, health and environment'],
        ['DIV-COM', 'division', 'EXEC', null, 'สายงานพาณิชย์', 'Commercial'],
        ['SALES', 'department', 'DIV-COM', 'BR1', null, null],
        ['MKT', 'department', 'DIV-COM', 'HQ', 'ฝ่ายการตลาด', 'Marketing'],
        ['PUR', 'department', 'DIV-COM', 'HQ', 'ฝ่ายจัดซื้อ', 'Procurement'],
    ];

    /**
     * code, name, unit, position, manager code, group, type, hire date, account, made-up pay (read through people())
     */
    public const PEOPLE = [
        ['D1-0101', 'กรรมการผู้จัดการ (DEMO)', 'EXEC', 'กรรมการผู้จัดการ', null, 'MONTHLY', 'permanent', '2015-01-05', 'exec.demo1', '150000.00'],
        ['D1-0102', 'ผู้อำนวยการสายงานบริหาร (DEMO)', 'DIV-ADM', 'ผู้อำนวยการสายงาน', 'D1-0101', 'MONTHLY', 'permanent', '2016-03-01', null, '95000.00'],
        ['D1-0103', 'ผู้อำนวยการสายงานปฏิบัติการ (DEMO)', 'DIV-OPS', 'ผู้อำนวยการสายงาน', 'D1-0101', 'MONTHLY', 'permanent', '2016-05-02', null, '95000.00'],
        ['D1-0104', 'ผู้อำนวยการสายงานพาณิชย์ (DEMO)', 'DIV-COM', 'ผู้อำนวยการสายงาน', 'D1-0101', 'MONTHLY', 'permanent', '2017-01-09', null, '95000.00'],
        ['D1-0105', 'เจ้าหน้าที่สรรหาและฝึกอบรม (DEMO)', 'HR-REC', 'เจ้าหน้าที่สรรหาและฝึกอบรม', 'D1-0001', 'MONTHLY', 'permanent', '2022-02-01', 'hr.officer1', '26000.00'],
        ['D1-0106', 'เจ้าหน้าที่เงินเดือน (DEMO)', 'HR-PAY', 'เจ้าหน้าที่เงินเดือน', 'D1-0107', 'MONTHLY', 'permanent', '2021-09-01', 'payroll.maker', '28000.00'],
        ['D1-0107', 'หัวหน้าแผนกค่าตอบแทน (DEMO)', 'HR-PAY', 'หัวหน้าแผนกค่าตอบแทนและสวัสดิการ', 'D1-0001', 'MONTHLY', 'permanent', '2019-07-01', 'payroll.checker', '42000.00'],
        ['D1-0108', 'ผู้จัดการฝ่ายบัญชี (DEMO)', 'ACC', 'ผู้จัดการฝ่ายบัญชี', 'D1-0102', 'MONTHLY', 'permanent', '2018-04-02', 'finance.checker', '55000.00'],
        ['D1-0109', 'เจ้าหน้าที่บัญชี (DEMO)', 'ACC', 'เจ้าหน้าที่บัญชี', 'D1-0108', 'MONTHLY', 'permanent', '2023-05-15', null, '22000.00'],
        ['D1-0110', 'เจ้าหน้าที่การเงิน (DEMO)', 'FIN', 'เจ้าหน้าที่การเงิน', 'D1-0108', 'MONTHLY', 'permanent', '2022-08-01', 'finance.maker', '25000.00'],
        ['D1-0111', 'เจ้าหน้าที่กระเป๋าเงินพนักงาน (DEMO)', 'FIN', 'เจ้าหน้าที่การเงิน', 'D1-0108', 'MONTHLY', 'permanent', '2024-01-08', 'wallet.officer1', '23000.00'],
        ['D1-0112', 'ผู้ดูแลระบบไอที (DEMO)', 'IT', 'ผู้ดูแลระบบ', 'D1-0102', 'MONTHLY', 'permanent', '2020-10-01', 'sysadmin2.demo', '45000.00'],
        ['D1-0113', 'เจ้าหน้าที่ธุรการและอาคาร (DEMO)', 'GA', 'เจ้าหน้าที่ธุรการและอาคารสถานที่', 'D1-0102', 'MONTHLY', 'permanent', '2020-06-01', 'ga.demo1', '27000.00'],
        ['D1-0114', 'พนักงานขับรถ (DEMO)', 'GA-VEH', 'พนักงานขับรถ', 'D1-0113', 'MONTHLY', 'permanent', '2021-03-15', 'driver.demo1', '17000.00'],
        ['D1-0115', 'เจ้าหน้าที่คุ้มครองข้อมูลส่วนบุคคล (DEMO)', 'LEGAL', 'เจ้าหน้าที่คุ้มครองข้อมูลส่วนบุคคล', 'D1-0102', 'MONTHLY', 'permanent', '2022-11-01', 'dpo.demo1', '40000.00'],
        ['D1-0116', 'ผู้ตรวจสอบภายใน (DEMO)', 'IA', 'ผู้ตรวจสอบภายใน', 'D1-0101', 'MONTHLY', 'permanent', '2021-01-04', 'auditor.demo', '48000.00'],
        ['D1-0117', 'ผู้จัดการฝ่ายผลิต (DEMO)', 'PROD', 'ผู้จัดการฝ่ายผลิต', 'D1-0103', 'MONTHLY', 'permanent', '2018-09-03', 'manager.prod1', '60000.00'],
        ['D1-0118', 'หัวหน้าแผนกผลิต A (DEMO)', 'PROD-A', 'หัวหน้าแผนกผลิต', 'D1-0117', 'MONTHLY', 'permanent', '2019-11-01', null, '32000.00'],
        ['D1-0119', 'พนักงานฝ่ายผลิต (DEMO)', 'PROD-A1', 'พนักงานควบคุมเครื่องจักร', 'D1-0117', 'DAILY', 'daily', '2024-04-01', 'worker.prod1', '480.00'],
        ['D1-0120', 'พนักงานฝ่ายผลิตสอง (DEMO)', 'PROD-A1', 'พนักงานควบคุมเครื่องจักร', 'D1-0117', 'DAILY', 'daily', '2025-02-03', null, '450.00'],
        ['D1-0121', 'เจ้าหน้าที่ประกันคุณภาพ (DEMO)', 'QA', 'เจ้าหน้าที่ประกันคุณภาพ', 'D1-0103', 'MONTHLY', 'permanent', '2022-06-01', null, '26000.00'],
        ['D1-0122', 'ช่างซ่อมบำรุง (DEMO)', 'MAINT', 'ช่างซ่อมบำรุง', 'D1-0103', 'MONTHLY', 'permanent', '2020-02-17', 'tech.demo1', '24000.00'],
        ['D1-0123', 'พนักงานคลังสินค้า (DEMO)', 'WH', 'พนักงานคลังสินค้า', 'D1-0103', 'MONTHLY', 'permanent', '2023-08-01', null, '17500.00'],
        ['D1-0124', 'เจ้าหน้าที่ความปลอดภัย (DEMO)', 'SHE', 'เจ้าหน้าที่ความปลอดภัยในการทำงาน', 'D1-0103', 'MONTHLY', 'permanent', '2021-05-03', 'safety.demo1', '30000.00'],
        ['D1-0125', 'เจ้าหน้าที่การตลาด (DEMO)', 'MKT', 'เจ้าหน้าที่การตลาด', 'D1-0104', 'MONTHLY', 'permanent', '2023-01-09', null, '24000.00'],
        ['D1-0126', 'เจ้าหน้าที่จัดซื้อ (DEMO)', 'PUR', 'เจ้าหน้าที่จัดซื้อ', 'D1-0104', 'MONTHLY', 'permanent', '2022-04-18', null, '25000.00'],
        ['D1-0127', 'ผู้จัดการสาขา 1 (DEMO)', 'SALES', 'ผู้จัดการสาขา', 'D1-0104', 'MONTHLY', 'permanent', '2019-02-01', 'exec.br1', '50000.00'],
        ['D1-0128', 'แคชเชียร์สาขา 1 (DEMO)', 'SALES', 'แคชเชียร์', 'D1-0127', 'MONTHLY', 'permanent', '2024-09-02', 'cashier.br1', '16500.00'],
    ];

    /** new demo accounts: username, display name, role, scope level ('legal_entity') */
    public const ACCOUNTS = [
        ['hr.officer1', 'เจ้าหน้าที่สรรหาและฝึกอบรม (DEMO)', 'HRT'],
        ['manager.prod1', 'ผู้จัดการฝ่ายผลิต (DEMO)', 'MGR'],
        ['worker.prod1', 'พนักงานฝ่ายผลิต (DEMO)', 'EMP'],
        ['wallet.officer1', 'เจ้าหน้าที่กระเป๋าเงินพนักงาน (DEMO)', 'WAL'],
    ];

    /** Every demo account of a demo install and its main role (shown once on the installer's done page). */
    public const SIGN_INS = [
        'hr.head1' => 'HRA', 'hr.demo1' => 'HRA', 'hr.officer1' => 'HRT', 'payroll.maker' => 'PYO', 'payroll.checker' => 'PYA',
        'finance.maker' => 'FNM', 'finance.checker' => 'FNC', 'wallet.officer1' => 'WAL', 'cashier.br1' => 'CSH', 'exec.demo1' => 'EXE', 'exec.br1' => 'EXE',
        'manager.demo1' => 'MGR', 'manager.prod1' => 'MGR', 'employee.demo1' => 'EMP', 'worker.prod1' => 'EMP', 'driver.demo1' => 'EMP', 'tech.demo1' => 'EMP',
        'ga.demo1' => 'WPA', 'safety.demo1' => 'WPA', 'dpo.demo1' => 'PRV', 'dpo.demo2' => 'PRV', 'auditor.demo' => 'AUD', 'sysadmin2.demo' => 'SYS',
    ];

    /** @return int employees added (0 = nothing to do) */
    public static function run(Connection $db, Clock $clock, bool $force = false): int
    {
        if (!$db->tableExists('employees') || !$db->tableExists('employment_history')) {
            return 0;
        }
        if (!$force && (string) $db->scalar("SELECT info_value FROM system_info WHERE info_key = 'demo_profile'") !== 'company') {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if ($le === null || $db->scalar("SELECT id FROM departments WHERE legal_entity_id = ? AND code = 'EXEC'", [$le]) !== null) {
            return 0;
        }
        $le = (int) $le;
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $tr = new Translations($db);
        return $db->transactional(static function () use ($db, $clock, $tr, $le, $now): int {
            // a factory site for the operations units
            $fac = $db->scalar("SELECT id FROM sites WHERE legal_entity_id = ? AND code = 'FAC1'", [$le]);
            if ($fac === null) {
                $db->run(
                    "INSERT INTO sites (public_id, created_at, updated_at, row_version, legal_entity_id, code, site_type, branch_no, is_demo) VALUES (?, ?, ?, 1, ?, 'FAC1', 'factory', '00002', 1)",
                    [Ulid::generate($clock), $now, $now, $le]
                );
                $fac = (int) $db->pdo()->lastInsertId();
                $tr->put('site', $fac, 'name', ['th' => 'โรงงานตัวอย่าง (DEMO)', 'en' => 'Demo factory (DEMO)']);
            }
            $site = static fn (?string $code): ?int => $code === null ? null : (int) $db->scalar('SELECT id FROM sites WHERE legal_entity_id = ? AND code = ?', [$le, $code]);
            $unit = [];
            foreach (self::UNITS as [$code, $level, $parent, $siteCode, $th, $en]) {
                $parentId = $parent !== null ? $unit[$parent] : null;
                $id = $db->scalar('SELECT id FROM departments WHERE legal_entity_id = ? AND code = ?', [$le, $code]);
                if ($id !== null) { // HR, ACC, IT, SALES from the installer: placed into the tree, names kept
                    $db->run('UPDATE departments SET parent_id = ?, unit_level = ?, updated_at = ? WHERE id = ?', [$parentId, $level, $now, $id]);
                    $unit[$code] = (int) $id;
                    continue;
                }
                $db->run(
                    'INSERT INTO departments (public_id, created_at, updated_at, row_version, legal_entity_id, site_id, parent_id, unit_level, code, is_demo) VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, 1)',
                    [Ulid::generate($clock), $now, $now, $le, $site($siteCode), $parentId, $level, $code]
                );
                $unit[$code] = (int) $db->pdo()->lastInsertId();
                $tr->put('department', $unit[$code], 'name', ['th' => (string) $th, 'en' => (string) $en]);
            }
            self::accounts($db, $clock, $le, $now);
            $emp = static fn (string $code): ?int => ($v = $db->scalar('SELECT id FROM employees WHERE employee_code = ?', [$code])) === null ? null : (int) $v;
            $added = 0;
            foreach (self::people() as [$code, $name, $u, $pos, $mgr, $group, $type, $hire, $account]) {
                if ($emp($code) !== null) {
                    continue;
                }
                $db->run(
                    "INSERT INTO employees (public_id, created_at, updated_at, employee_code, name_th, status, hire_date, work_email, is_demo) VALUES (?, ?, ?, ?, ?, 'active', ?, ?, 1)",
                    [Ulid::generate($clock), $now, $now, $code, $name, $hire, strtolower($code) . '@demo.invalid']
                );
                $id = (int) $db->pdo()->lastInsertId();
                $unitSite = $db->scalar('SELECT site_id FROM departments WHERE id = ?', [$unit[$u]]);
                $db->run(
                    'INSERT INTO employment_history (public_id, created_at, updated_at, employee_id, legal_entity_id, site_id, department_id, position_title, manager_employee_id,
                        employee_group, employment_type, effective_from, change_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [Ulid::generate($clock), $now, $now, $id, $le, $unitSite ?? $site('HQ'), $unit[$u], $pos, $mgr !== null ? $emp($mgr) : null, $group, $type, $hire, 'R4 demo company (Q-20)']
                );
                if ($db->tableExists('employee_status_history')) {
                    $db->run(
                        "INSERT INTO employee_status_history (public_id, created_at, updated_at, employee_id, status, effective_from, reason) VALUES (?, ?, ?, ?, 'active', ?, 'R4 demo company (Q-20)')",
                        [Ulid::generate($clock), $now, $now, $id, $hire]
                    );
                }
                if ($account !== null) {
                    $db->run('UPDATE users SET employee_id = ? WHERE username = ? AND is_demo = 1 AND employee_id IS NULL', [$id, $account]);
                }
                $added++;
            }
            // the first managers now report to their division heads
            foreach (['D1-0001' => 'D1-0102', 'D1-0003' => 'D1-0104'] as $code => $head) {
                $db->run(
                    'UPDATE employment_history h JOIN employees e ON e.id = h.employee_id SET h.manager_employee_id = ? WHERE e.employee_code = ? AND h.manager_employee_id IS NULL',
                    [$emp($head), $code]
                );
            }
            self::pay($db, $clock, $le, $now);
            return $added;
        });
    }

    /** @return list<array{string, string, string, string, ?string, string, string, string, ?string, string}> */
    private static function people(): array
    {
        return self::PEOPLE;
    }

    private static function accounts(Connection $db, Clock $clock, int $le, string $now): void
    {
        $hash = $db->scalar("SELECT password_hash FROM users WHERE username = 'hr.demo1' AND is_demo = 1");
        if ($hash === null) {
            return;
        }
        $admin = $db->scalar("SELECT u.id FROM users u JOIN user_role_assignments a ON a.user_id = u.id JOIN roles r ON r.id = a.role_id WHERE r.code = 'SYS' AND u.is_demo = 0 ORDER BY u.id LIMIT 1");
        foreach (self::ACCOUNTS as [$username, $name, $role]) {
            if ($db->scalar('SELECT id FROM users WHERE username = ?', [$username]) !== null || $db->scalar('SELECT id FROM roles WHERE code = ?', [$role]) === null) {
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
                 VALUES (?, ?, ?, 1, ?, (SELECT id FROM roles WHERE code = ?), 'active', ?, ?, ?, ?, 'R4 demo company (Q-20)')",
                [Ulid::generate($clock), $now, $now, $uid, $role, $clock->now()->format('Y-m-d'), $admin, $admin, $admin !== null ? $now : null]
            );
            $db->run("INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, 'legal_entity', ?)", [(int) $db->pdo()->lastInsertId(), $le]);
        }
    }

    /** Made-up pay for the new people (payroll installed only) — same shape as the payroll module's demo. */
    private static function pay(Connection $db, Clock $clock, int $le, string $now): void
    {
        if (!$db->tableExists('pay_profiles')) {
            return;
        }
        $maker = $db->scalar("SELECT id FROM users WHERE username = 'payroll.maker' AND is_demo = 1");
        $checker = $db->scalar("SELECT id FROM users WHERE username = 'payroll.checker' AND is_demo = 1");
        if ($maker === null || $checker === null) {
            return;
        }
        foreach (self::people() as $p) {
            $e = $db->one('SELECT id, hire_date FROM employees WHERE employee_code = ?', [$p[0]]);
            if ($e === null || $db->scalar('SELECT id FROM pay_profiles WHERE employee_id = ? LIMIT 1', [$e['id']]) !== null) {
                continue;
            }
            $db->run(
                "INSERT INTO pay_profiles (public_id, created_at, created_by, updated_at, updated_by, employee_id, legal_entity_id, effective_from, pay_basis, base_amount, items_json, status, reason, proposed_by, decided_by, decided_at, is_demo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 'approved', 'R4 demo company (ตัวอย่าง)', ?, ?, ?, 1)",
                [Ulid::generate($clock), $now, $maker, $now, $checker, $e['id'], $le, $e['hire_date'], $p[6] === 'daily' ? 'daily' : 'monthly', $p[9], $maker, $checker, $now]
            );
        }
    }
}
