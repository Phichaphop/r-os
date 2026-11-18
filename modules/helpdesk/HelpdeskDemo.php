<?php

declare(strict_types=1);

namespace Blue\Modules\Helpdesk;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 9B-1 (installs with demo data, once): two DEMO1 work categories with example SLA hours
 * (the company's own — not defaults), ga.demo1 as their lead, the technician account tech.demo1, and an example
 * policy (reopen within 7 days, close unanswered resolved tickets after 5 days).
 */
final class HelpdeskDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('hd_categories')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if ($le === null || $db->scalar('SELECT id FROM hd_categories WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $ga = $db->scalar("SELECT id FROM users WHERE username = 'ga.demo1'");
        $tech = self::account($db, $clock, (int) $le, $now);
        $cats = [
            ['คอมพิวเตอร์และระบบ (DEMO)', 'เครื่องคอมพิวเตอร์ เครื่องพิมพ์ อินเทอร์เน็ต เข้าระบบไม่ได้', 4, 40, 16, 8, 4],
            ['อาคารและไฟฟ้า (DEMO)', 'ไฟฟ้า แอร์ ประปา ประตู เฟอร์นิเจอร์', 8, null, 24, 8, 4],
        ];
        foreach ($cats as $c) {
            $db->run(
                'INSERT INTO hd_categories (public_id, created_at, updated_at, legal_entity_id, name, description, response_hours, hours_low, hours_normal, hours_high, hours_urgent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [Ulid::generate($clock), $now, $now, $le, ...$c]
            );
            $id = (int) $db->pdo()->lastInsertId();
            if ($ga !== null) {
                $db->run("INSERT INTO hd_members (category_id, user_id, role, created_at) VALUES (?, ?, 'lead', ?)", [$id, $ga, $now]);
            }
            if ($tech !== null) {
                $db->run("INSERT INTO hd_members (category_id, user_id, role, created_at) VALUES (?, ?, 'agent', ?)", [$id, $tech, $now]);
            }
        }
        $db->run('INSERT IGNORE INTO hd_policies (legal_entity_id, reopen_days, auto_close_days, updated_at) VALUES (?, 7, 5, ?)', [$le, $now]);
        return count($cats);
    }

    /** tech.demo1 — a DEMO1 technician (same password as the other demo users) */
    private static function account(Connection $db, Clock $clock, int $le, string $now): ?int
    {
        $hash = $db->scalar("SELECT password_hash FROM users WHERE username = 'hr.demo1' AND is_demo = 1");
        if ($hash === null || $db->scalar("SELECT id FROM roles WHERE code = 'EMP'") === null) {
            return null;
        }
        $existing = $db->scalar("SELECT id FROM users WHERE username = 'tech.demo1'");
        if ($existing !== null) {
            return (int) $existing;
        }
        $admin = $db->scalar("SELECT u.id FROM users u JOIN user_role_assignments a ON a.user_id = u.id JOIN roles r ON r.id = a.role_id WHERE r.code = 'SYS' AND u.is_demo = 0 ORDER BY u.id LIMIT 1");
        $db->run(
            "INSERT INTO users (public_id, created_at, updated_at, row_version, username, display_name, email, password_hash, status, password_changed_at, is_demo)
             VALUES (?, ?, ?, 1, 'tech.demo1', 'ช่างซ่อมบำรุง (DEMO)', 'tech.demo1@demo.invalid', ?, 'active', ?, 1)",
            [Ulid::generate($clock), $now, $now, $hash, $now]
        );
        $uid = (int) $db->pdo()->lastInsertId();
        $db->run(
            "INSERT INTO user_role_assignments (public_id, created_at, updated_at, row_version, user_id, role_id, status, valid_from, requested_by, approved_by, approved_at, reason)
             VALUES (?, ?, ?, 1, ?, (SELECT id FROM roles WHERE code = 'EMP'), 'active', ?, ?, ?, ?, 'DEC-34 demo user (phase 9B-1)')",
            [Ulid::generate($clock), $now, $now, $uid, $clock->now()->format('Y-m-d'), $admin, $admin, $admin !== null ? $now : null]
        );
        $db->run('INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, ?, ?)', [(int) $db->pdo()->lastInsertId(), 'legal_entity', $le]);
        return $uid;
    }
}
