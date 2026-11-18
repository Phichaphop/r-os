<?php

declare(strict_types=1);

namespace Blue\Modules\Contractors;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 9C-3 (installs with demo data, once): three DEMO1 required document types (examples — the
 * safety officer decides the real ones), alert 30 days, one contractor company with two workers and no documents yet
 * (so it shows as "documents pending"), and safety.demo1 — a second workplace admin who approves permits (an approver
 * may not approve their own request).
 */
final class ContractorsDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('ct_doc_types')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if ($le === null || $db->scalar('SELECT id FROM ct_doc_types WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        foreach ([['สัญญาจ้างเหมา (DEMO)', 'company', 0], ['กรมธรรม์ประกันความรับผิด (DEMO)', 'company', 1], ['อบรมความปลอดภัยก่อนเข้างาน (DEMO)', 'worker', 1]] as [$name, $applies, $expiry]) {
            $db->run('INSERT INTO ct_doc_types (public_id, created_at, updated_at, legal_entity_id, name, applies_to, required, needs_expiry) VALUES (?, ?, ?, ?, ?, ?, 1, ?)', [Ulid::generate($clock), $now, $now, $le, $name, $applies, $expiry]);
        }
        $db->run('INSERT IGNORE INTO ct_settings (legal_entity_id, alert_days, updated_at) VALUES (?, 30, ?)', [$le, $now]);
        $owner = $db->scalar("SELECT id FROM users WHERE username = 'ga.demo1'");
        $db->run(
            'INSERT INTO ct_companies (public_id, created_at, updated_at, legal_entity_id, name, contact_name, contact_phone, contact_email, owner_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [Ulid::generate($clock), $now, $now, $le, 'บริษัท ช่างดีเซอร์วิส (DEMO)', 'คุณสมชาย (DEMO)', '000-000-0000', 'contact@demo.invalid', $owner]
        );
        $cid = (int) $db->pdo()->lastInsertId();
        foreach ([['ช่างไฟฟ้า ก (DEMO)', 'ช่างไฟฟ้า'], ['ผู้ช่วยช่าง ข (DEMO)', 'ผู้ช่วยช่าง']] as [$name, $pos]) {
            $db->run('INSERT INTO ct_workers (public_id, created_at, updated_at, company_id, name, position) VALUES (?, ?, ?, ?, ?, ?)', [Ulid::generate($clock), $now, $now, $cid, $name, $pos]);
        }
        self::account($db, $clock, (int) $le, $now);
        return 1;
    }

    /** safety.demo1 — a DEMO1 workplace admin (WPA) who approves contractor permits */
    private static function account(Connection $db, Clock $clock, int $le, string $now): void
    {
        $hash = $db->scalar("SELECT password_hash FROM users WHERE username = 'hr.demo1' AND is_demo = 1");
        $role = $db->scalar("SELECT id FROM roles WHERE code = 'WPA'");
        if ($hash === null || $role === null || $db->scalar("SELECT id FROM users WHERE username = 'safety.demo1'") !== null) {
            return;
        }
        $admin = $db->scalar("SELECT u.id FROM users u JOIN user_role_assignments a ON a.user_id = u.id JOIN roles r ON r.id = a.role_id WHERE r.code = 'SYS' AND u.is_demo = 0 ORDER BY u.id LIMIT 1");
        $db->run(
            "INSERT INTO users (public_id, created_at, updated_at, row_version, username, display_name, email, password_hash, status, password_changed_at, is_demo)
             VALUES (?, ?, ?, 1, 'safety.demo1', 'เจ้าหน้าที่ความปลอดภัย (DEMO)', 'safety.demo1@demo.invalid', ?, 'active', ?, 1)",
            [Ulid::generate($clock), $now, $now, $hash, $now]
        );
        $uid = (int) $db->pdo()->lastInsertId();
        $db->run(
            "INSERT INTO user_role_assignments (public_id, created_at, updated_at, row_version, user_id, role_id, status, valid_from, requested_by, approved_by, approved_at, reason)
             VALUES (?, ?, ?, 1, ?, ?, 'active', ?, ?, ?, ?, 'DEC-34 demo user (phase 9C-3)')",
            [Ulid::generate($clock), $now, $now, $uid, $role, $clock->now()->format('Y-m-d'), $admin, $admin, $admin !== null ? $now : null]
        );
        $db->run('INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, ?, ?)', [(int) $db->pdo()->lastInsertId(), 'legal_entity', $le]);
    }
}
