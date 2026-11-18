<?php

declare(strict_types=1);

namespace Blue\Modules\Dashboard;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 5E-5 (installs with demo data, once): two executive accounts — exec.demo1 (whole DEMO1)
 * and exec.br1 (only site BR1) — and a few live KPI definitions for DEMO1 owned by hr.demo1. No thresholds are set:
 * those are the company's decision. Same demo password as the other demo users.
 */
final class DashboardDemo
{
    /** code, measure, Thai name, English name, better direction */
    private const KPIS = [
        ['HEADCOUNT', 'employees.headcount', 'จำนวนพนักงาน', 'Headcount', 'none'],
        ['TURNOVER', 'employees.turnover', 'อัตราการลาออก', 'Turnover rate', 'down'],
        ['LEAVE-DAYS', 'leave.days', 'วันลาที่ใช้', 'Leave days taken', 'none'],
        ['OT-HOURS', 'overtime.hours', 'ชั่วโมง OT ที่อนุมัติ', 'Approved OT hours', 'down'],
        ['OPEN-POS', 'recruit.open', 'อัตราที่ยังเปิดรับ', 'Open positions', 'none'],
        ['APPROVALS', 'dashboard.approvals_open', 'คำขอที่รออนุมัติ', 'Approvals waiting', 'down'],
    ];

    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('dash_kpis')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        $owner = $db->scalar("SELECT id FROM users WHERE username = 'hr.demo1' AND is_demo = 1");
        if ($le === null || $owner === null || $db->scalar('SELECT id FROM dash_kpis WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        foreach (self::KPIS as $i => [$code, $measure, $th, $en, $dir]) {
            $db->run("INSERT INTO dash_kpis (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, code, measure, name_th, name_en, purpose, direction, refresh_minutes, owner_user_id, status, version, sort_order)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ตัวอย่าง (DEMO)', ?, 60, ?, 'active', 1, ?)", [Ulid::generate($clock), $now, $owner, $now, $owner, $le, $code, $measure, $th, $en, $dir, $owner, ($i + 1) * 10]);
            $db->run(
                'INSERT INTO dash_kpi_versions (kpi_id, version, definition_json, reason, changed_by, changed_at) VALUES (?, 1, ?, ?, ?, ?)',
                [(int) $db->pdo()->lastInsertId(), (string) json_encode(['code' => $code, 'measure' => $measure, 'name_th' => $th, 'name_en' => $en, 'direction' => $dir], JSON_UNESCAPED_UNICODE), 'DEC-34 demo', $owner, $now]
            );
        }
        self::accounts($db, $clock, (int) $le, $now);
        return count(self::KPIS);
    }

    private static function accounts(Connection $db, Clock $clock, int $le, string $now): void
    {
        $hash = $db->scalar("SELECT password_hash FROM users WHERE username = 'hr.demo1' AND is_demo = 1");
        $br1 = $db->scalar("SELECT id FROM sites WHERE code = 'BR1' AND legal_entity_id = ?", [$le]);
        if ($hash === null || $db->scalar("SELECT id FROM roles WHERE code = 'EXE'") === null) {
            return;
        }
        $admin = $db->scalar("SELECT u.id FROM users u JOIN user_role_assignments a ON a.user_id = u.id JOIN roles r ON r.id = a.role_id WHERE r.code = 'SYS' AND u.is_demo = 0 ORDER BY u.id LIMIT 1");
        foreach ([['exec.demo1', 'ผู้บริหารบริษัทตัวอย่าง (DEMO)', 'legal_entity', $le], ['exec.br1', 'ผู้จัดการสาขา 1 (DEMO)', 'site', $br1]] as [$username, $name, $level, $scopeId]) {
            if ($scopeId === null || $db->scalar('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
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
                 VALUES (?, ?, ?, 1, ?, (SELECT id FROM roles WHERE code = 'EXE'), 'active', ?, ?, ?, ?, 'DEC-34 demo user (phase 5E-5)')",
                [Ulid::generate($clock), $now, $now, $uid, $clock->now()->format('Y-m-d'), $admin, $admin, $admin !== null ? $now : null]
            );
            $db->run('INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, ?, ?)', [(int) $db->pdo()->lastInsertId(), $level, (int) $scopeId]);
        }
    }
}
