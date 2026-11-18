<?php

declare(strict_types=1);

namespace Blue\Core\Install;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 3C, added once to installs that have demo data (also when upgrading from 3B):
 * a SAMPLE chart of accounts and fiscal years for DEMO1 (not a real chart — the accountant defines the real one, DEC-12),
 * demo posting rules for paid claims / payables (payroll is left unmapped on purpose, to show "needs attention"),
 * and two demo finance users (maker + checker) with the same demo password as the other demo users.
 */
final class FinanceDemo
{
    /** @var list<array{0: string, 1: string, 2: string, 3: ?string, 4: bool}> code, name, type, parent, postable */
    private const ACCOUNTS = [
        ['1000', 'สินทรัพย์ (DEMO)', 'asset', null, false],
        ['1110', 'เงินฝากธนาคาร — บัญชีจ่าย (DEMO)', 'asset', '1000', true],
        ['1120', 'เงินสดย่อย (DEMO)', 'asset', '1000', true],
        ['2000', 'หนี้สิน (DEMO)', 'liability', null, false],
        ['2110', 'เจ้าหนี้การค้า (DEMO)', 'liability', '2000', true],
        ['2120', 'ค่าสวัสดิการพนักงานค้างจ่าย (DEMO)', 'liability', '2000', true],
        ['2130', 'เงินเดือนค้างจ่าย (DEMO)', 'liability', '2000', true],
        ['3000', 'ส่วนของเจ้าของ (DEMO)', 'equity', null, false],
        ['3100', 'ทุน (DEMO)', 'equity', '3000', true],
        ['4000', 'รายได้ (DEMO)', 'revenue', null, false],
        ['4100', 'รายได้จากการขาย (DEMO)', 'revenue', '4000', true],
        ['5000', 'ค่าใช้จ่าย (DEMO)', 'expense', null, false],
        ['5110', 'เงินเดือนและค่าจ้าง (DEMO)', 'expense', '5000', true],
        ['5120', 'สวัสดิการพนักงาน (DEMO)', 'expense', '5000', true],
        ['5130', 'ค่าใช้จ่ายสำนักงาน (DEMO)', 'expense', '5000', true],
    ];

    public static function run(Connection $db, Clock $clock): int
    {
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if ($le === null) {
            return 0;
        }
        $le = (int) $le;
        $n = 0;
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        if ((int) $db->scalar('SELECT COUNT(*) FROM fin_accounts WHERE legal_entity_id = ?', [$le]) === 0) {
            $ids = [];
            foreach (self::ACCOUNTS as [$code, $name, $type, $parent, $postable]) {
                $db->run(
                    'INSERT INTO fin_accounts (public_id, created_at, updated_at, legal_entity_id, code, name, account_type, parent_id, is_postable, is_demo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                    [Ulid::generate($clock), $now, $now, $le, $code, $name, $type, $parent !== null ? $ids[$parent] : null, $postable ? 1 : 0]
                );
                $ids[$code] = (int) $db->pdo()->lastInsertId();
                $n++;
            }
            foreach (['FY2026' => '2026-01-01', 'FY2027' => '2027-01-01'] as $code => $start) {
                $db->run(
                    'INSERT INTO fin_fiscal_years (public_id, created_at, updated_at, legal_entity_id, code, start_date, end_date, is_demo) VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
                    [Ulid::generate($clock), $now, $now, $le, $code, $start, substr($start, 0, 4) . '-12-31']
                );
                $fy = (int) $db->pdo()->lastInsertId();
                for ($m = 1; $m <= 12; $m++) {
                    $ps = new \DateTimeImmutable(substr($start, 0, 4) . '-' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . '-01');
                    $db->run(
                        'INSERT INTO fin_fiscal_periods (fiscal_year_id, legal_entity_id, period_no, period_code, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?)',
                        [$fy, $le, $m, $code . '-' . str_pad((string) $m, 2, '0', STR_PAD_LEFT), $ps->format('Y-m-d'), $ps->modify('last day of this month')->format('Y-m-d')]
                    );
                }
            }
            foreach ([['payment.claim.paid', '2120', '1110'], ['payment.ap.paid', '2110', '1110']] as $v => [$type, $dr, $cr]) {
                $db->run(
                    "INSERT INTO fin_posting_rules (public_id, created_at, updated_at, legal_entity_id, source_module, line_type, version_no, debit_account_id, credit_account_id,
                        effective_from, status, reason, source_reference, is_demo) VALUES (?, ?, ?, ?, 'core.payment', ?, 1, ?, ?, '2026-01-01', 'approved', ?, ?, 1)",
                    [Ulid::generate($clock), $now, $now, $le, $type, $ids[$dr], $ids[$cr], 'DEMO rule (DEC-34) — not a real accounting decision', 'DEMO — ไม่ใช่การยืนยันของผู้ทำบัญชี']
                );
                unset($v);
            }
        }
        $hash = $db->scalar("SELECT password_hash FROM users WHERE username = 'hr.demo1' AND is_demo = 1");
        $admin = $db->scalar("SELECT u.id FROM users u JOIN user_role_assignments a ON a.user_id = u.id JOIN roles r ON r.id = a.role_id WHERE r.code = 'SYS' AND u.is_demo = 0 ORDER BY u.id LIMIT 1");
        if ($hash !== null) {
            foreach ([['finance.maker', 'Finance Maker (DEMO)', 'FNM'], ['finance.checker', 'Finance Checker (DEMO)', 'FNC']] as [$username, $name, $role]) {
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
                     VALUES (?, ?, ?, 1, ?, (SELECT id FROM roles WHERE code = ?), 'active', ?, ?, ?, ?, 'DEC-34 demo user (phase 3C)')",
                    [Ulid::generate($clock), $now, $now, $uid, $role, $clock->now()->format('Y-m-d'), $admin, $admin, $admin !== null ? $now : null]
                );
                $db->run("INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, 'legal_entity', ?)", [(int) $db->pdo()->lastInsertId(), $le]);
                $n++;
            }
        }
        return $n;
    }
}
