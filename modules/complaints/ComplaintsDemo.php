<?php

declare(strict_types=1);

namespace Blue\Modules\Complaints;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for M16 (0.44.0): two complaint handlers (role CMP: hr.head1, safety.demo1) and one person for the
 * alternate route (role CMX: exec.demo1) in DEMO1, once each. No demo complaints — a complaint has no sender, so there is
 * nothing meaningful to fake, and the intake stays closed until a handler opens it (notice + legal confirmation).
 */
final class ComplaintsDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('cp_cases')) {
            return 0;
        }
        $n = 0;
        foreach ([['hr.head1', 'CMP'], ['safety.demo1', 'CMP'], ['exec.demo1', 'CMX']] as [$user, $role]) {
            $n += self::grant($db, $clock, $user, $role);
        }
        return $n;
    }

    private static function grant(Connection $db, Clock $clock, string $user, string $code): int
    {
        $uid = $db->scalar('SELECT id FROM users WHERE username = ? AND is_demo = 1', [$user]);
        $role = $db->scalar('SELECT id FROM roles WHERE code = ?', [$code]);
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if (
            $uid === null || $role === null || $le === null
            || $db->scalar("SELECT id FROM user_role_assignments WHERE user_id = ? AND role_id = ? AND status = 'active'", [$uid, $role]) !== null
        ) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $admin = $db->scalar("SELECT u.id FROM users u JOIN user_role_assignments a ON a.user_id = u.id JOIN roles r ON r.id = a.role_id WHERE r.code = 'SYS' AND u.is_demo = 0 ORDER BY u.id LIMIT 1");
        $db->run(
            "INSERT INTO user_role_assignments (public_id, created_at, updated_at, row_version, user_id, role_id, status, valid_from, requested_by, approved_by, approved_at, reason)
             VALUES (?, ?, ?, 1, ?, ?, 'active', ?, ?, ?, ?, 'DEC-34 demo user (0.44.0 complaints)')",
            [Ulid::generate($clock), $now, $now, $uid, $role, $clock->now()->format('Y-m-d'), $admin, $admin, $admin !== null ? $now : null]
        );
        $db->run('INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, ?, ?)', [(int) $db->pdo()->lastInsertId(), 'legal_entity', $le]);
        return 1;
    }
}
