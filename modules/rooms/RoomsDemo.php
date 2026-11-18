<?php

declare(strict_types=1);

namespace Blue\Modules\Rooms;

use Blue\Core\Support\Clock;
use Blue\Core\Database\Connection;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 9A (installs with demo data, once): two DEMO1 rooms with example rules (the company's own
 * rules — not defaults) and the workplace admin account ga.demo1 (role WPA, whole DEMO1).
 */
final class RoomsDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('rm_rooms')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if ($le === null || $db->scalar('SELECT id FROM rm_rooms WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $hq = $db->scalar("SELECT id FROM sites WHERE code = 'HQ' AND legal_entity_id = ?", [$le]);
        $br1 = $db->scalar("SELECT id FROM sites WHERE code = 'BR1' AND legal_entity_id = ?", [$le]);
        $rooms = [
            [$hq, 'R-101', 'ห้องประชุมใหญ่ (DEMO)', 12, 'จอ 75 นิ้ว · กล้องประชุม · ไวท์บอร์ด', 'ชั้น 1 อาคารสำนักงาน', '08:00:00', '18:00:00', '1,2,3,4,5', 10, 240, 60, 1, 15],
            [$br1, 'R-201', 'ห้องประชุมเล็ก สาขา 1 (DEMO)', 6, 'จอ 43 นิ้ว', 'สาขา 1 ชั้น 2', '08:30:00', '17:30:00', '1,2,3,4,5,6', 0, 120, 30, 0, null],
        ];
        foreach ($rooms as $r) {
            $db->run(
                'INSERT INTO rm_rooms (public_id, created_at, updated_at, legal_entity_id, site_id, code, name, capacity, equipment, location, open_from, open_to, open_days, buffer_minutes, max_minutes,
                    advance_days, checkin_required, release_minutes, is_demo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [Ulid::generate($clock), $now, $now, $le, ...$r]
            );
        }
        self::account($db, $clock, (int) $le, $now);
        return count($rooms);
    }

    /** ga.demo1 — workplace admin of DEMO1 (same password as the other demo users) */
    public static function account(Connection $db, Clock $clock, int $le, string $now): void
    {
        $hash = $db->scalar("SELECT password_hash FROM users WHERE username = 'hr.demo1' AND is_demo = 1");
        if ($hash === null || $db->scalar("SELECT id FROM roles WHERE code = 'WPA'") === null || $db->scalar("SELECT id FROM users WHERE username = 'ga.demo1'") !== null) {
            return;
        }
        $admin = $db->scalar("SELECT u.id FROM users u JOIN user_role_assignments a ON a.user_id = u.id JOIN roles r ON r.id = a.role_id WHERE r.code = 'SYS' AND u.is_demo = 0 ORDER BY u.id LIMIT 1");
        $db->run(
            "INSERT INTO users (public_id, created_at, updated_at, row_version, username, display_name, email, password_hash, status, password_changed_at, is_demo)
             VALUES (?, ?, ?, 1, 'ga.demo1', 'ผู้ดูแลงานสำนักงาน (DEMO)', 'ga.demo1@demo.invalid', ?, 'active', ?, 1)",
            [Ulid::generate($clock), $now, $now, $hash, $now]
        );
        $uid = (int) $db->pdo()->lastInsertId();
        foreach (['WPA'] as $role) {
            $db->run(
                "INSERT INTO user_role_assignments (public_id, created_at, updated_at, row_version, user_id, role_id, status, valid_from, requested_by, approved_by, approved_at, reason)
                 VALUES (?, ?, ?, 1, ?, (SELECT id FROM roles WHERE code = ?), 'active', ?, ?, ?, ?, 'DEC-34 demo user (phase 9A)')",
                [Ulid::generate($clock), $now, $now, $uid, $role, $clock->now()->format('Y-m-d'), $admin, $admin, $admin !== null ? $now : null]
            );
            $db->run('INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, ?, ?)', [(int) $db->pdo()->lastInsertId(), 'legal_entity', $le]);
        }
    }
}
