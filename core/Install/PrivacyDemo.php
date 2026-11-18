<?php

declare(strict_types=1);

namespace Blue\Core\Install;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for the phase 5 gate (DEC-82), once, on installs with demo data: two privacy officers (role PRV,
 * whole organisation) — two because erasing personal data needs a second person to approve. Same demo password.
 */
final class PrivacyDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        $hash = $db->scalar("SELECT password_hash FROM users WHERE username = 'hr.demo1' AND is_demo = 1");
        $role = $db->scalar("SELECT id FROM roles WHERE code = 'PRV'");
        $org = $db->scalar('SELECT id FROM organizations ORDER BY id LIMIT 1');
        if ($hash === null || $role === null || $org === null || !$db->tableExists('privacy_requests')) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $n = 0;
        foreach ([['dpo.demo1', 'เจ้าหน้าที่คุ้มครองข้อมูล (DEMO)'], ['dpo.demo2', 'เจ้าหน้าที่คุ้มครองข้อมูลคนที่สอง (DEMO)']] as [$username, $name]) {
            if ($db->scalar('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
                continue;
            }
            $db->run(
                "INSERT INTO users (public_id, created_at, updated_at, row_version, username, display_name, email, password_hash, status, password_changed_at, is_demo) VALUES (?, ?, ?, 1, ?, ?, ?, ?, 'active', ?, 1)",
                [Ulid::generate($clock), $now, $now, $username, $name, $username . '@demo.invalid', $hash, $now]
            );
            $uid = (int) $db->pdo()->lastInsertId();
            $db->run(
                "INSERT INTO user_role_assignments (public_id, created_at, updated_at, row_version, user_id, role_id, status, valid_from, reason) VALUES (?, ?, ?, 1, ?, ?, 'active', ?, 'DEC-34 demo user (phase 5 gate)')",
                [Ulid::generate($clock), $now, $now, $uid, $role, $clock->now()->format('Y-m-d')]
            );
            $db->run("INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, 'organization', ?)", [(int) $db->pdo()->lastInsertId(), $org]);
            $n++;
        }
        return $n;
    }
}
