<?php

declare(strict_types=1);

namespace Blue\Modules\Safety;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 9E-1 (installs with demo data, once): DEMO1 level descriptions marked DEMO (the company writes
 * its own — there are no defaults), anonymous reports off, and one hazard reported by employee.demo1 waiting for triage.
 */
final class SafetyDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('sf_incidents')) {
            return 0;
        }
        self::officer($db, $clock);
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        $emp = $db->scalar("SELECT id FROM users WHERE username = 'employee.demo1'");
        if ($le === null || $emp === null || $db->scalar('SELECT legal_entity_id FROM sf_settings WHERE legal_entity_id = ?', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $notes = ['1' => 'ไม่มีผู้บาดเจ็บ แก้ได้ทันที (DEMO)', '2' => 'ปฐมพยาบาล / เสียหายเล็กน้อย (DEMO)', '3' => 'ต้องพบแพทย์ / หยุดงาน (DEMO)', '4' => 'บาดเจ็บสาหัส / หยุดสายการผลิต (DEMO)'];
        $db->run('INSERT INTO sf_settings (legal_entity_id, level_notes, escalate_json, anonymous_on, updated_at) VALUES (?, ?, ?, 0, ?)', [$le, json_encode($notes, JSON_UNESCAPED_UNICODE), '{"3":[],"4":[]}', $now]);
        $when = $clock->now()->modify('-1 day')->format('Y-m-d') . ' 10:30:00';
        $pid = Ulid::generate($clock);
        $db->run(
            "INSERT INTO sf_incidents (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, ref, kind, severity, occurred_at, location, description, reporter_id, digested)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'hazard', 2, ?, ?, ?, ?, 1)",
            [$pid, $now, $emp, $now, $emp, $le, 'SF' . $clock->now()->format('ym') . '-0001', $when, 'ทางเดินหน้าคลังสินค้า (DEMO)', 'พื้นมีน้ำมันหกเป็นทางยาว เสี่ยงลื่นล้ม (DEMO)', $emp]
        );
        $db->run("INSERT INTO sf_events (incident_id, event, actor_id, created_at) VALUES (?, 'reported', ?, ?)", [(int) $db->pdo()->lastInsertId(), $emp, $now]);
        return 1;
    }

    /**
     * 0.37.0 (DEC-102): safety moved from WPA to the safety officer role (SFO). The demo workplace admin ga.demo1 also
     * gets SFO so demo installs keep a safety team — every sync, once (a real company assigns SFO to its จป.).
     */
    private static function officer(Connection $db, Clock $clock): void
    {
        $uid = $db->scalar("SELECT id FROM users WHERE username = 'ga.demo1' AND is_demo = 1");
        $role = $db->scalar("SELECT id FROM roles WHERE code = 'SFO'");
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
             VALUES (?, ?, ?, 1, ?, ?, 'active', ?, ?, ?, ?, 'DEC-34 demo user (0.37.0 safety officer)')",
            [Ulid::generate($clock), $now, $now, $uid, $role, $clock->now()->format('Y-m-d'), $admin, $admin, $admin !== null ? $now : null]
        );
        $db->run('INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, ?, ?)', [(int) $db->pdo()->lastInsertId(), 'legal_entity', $le]);
    }
}
