<?php

declare(strict_types=1);

namespace Blue\Modules\Projects;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 9E-2 (installs with demo data, once): one active DEMO1 project led by manager.demo1 with
 * employee.demo1 as a member and three tasks (one depending on another). Only those two people can see it.
 */
final class ProjectsDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('pj_projects')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        $lead = $db->scalar("SELECT id FROM users WHERE username = 'manager.demo1'");
        $member = $db->scalar("SELECT id FROM users WHERE username = 'employee.demo1'");
        if ($le === null || $lead === null || $member === null || $db->scalar('SELECT id FROM pj_projects WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $day = static fn (int $n): string => $clock->now()->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
        $db->run(
            "INSERT INTO pj_projects (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, ref, name, goal, scope, starts_on, ends_on, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')",
            [Ulid::generate($clock), $now, $lead, $now, $lead, $le, 'PJ' . $clock->now()->format('ym') . '-0001', 'จัดพื้นที่ทำงาน 5ส ชั้น 2 (DEMO)',
                'ลดเวลาค้นหาเอกสารและอุปกรณ์ในพื้นที่ทำงานชั้น 2 (DEMO)', 'ชั้นวางเอกสาร โต๊ะทำงาน ห้องเก็บของ (DEMO)', $day(-7), $day(30)]
        );
        $pj = (int) $db->pdo()->lastInsertId();
        foreach ([[$lead, 'lead'], [$member, 'member']] as [$u, $role]) {
            $db->run("INSERT INTO pj_members (project_id, user_id, role, status, invited_by, invited_at, joined_at) VALUES (?, ?, ?, 'active', ?, ?, ?)", [$pj, $u, $role, $lead, $now, $now]);
        }
        $ids = [];
        foreach ([['สำรวจพื้นที่และถ่ายรูปก่อนทำ (DEMO)', $member, -5, 2, 'accepted', 100], ['คัดแยกเอกสารที่ไม่ใช้ (DEMO)', $member, 3, 10, 'in_progress', 40], ['ติดป้ายชั้นวาง (DEMO)', $lead, 11, 20, 'todo', 0]] as $i => [$title, $u, $s, $d, $st, $pr]) {
            $db->run(
                'INSERT INTO pj_tasks (public_id, project_id, title, assignee_id, starts_on, due_on, status, progress, sort, created_by, created_at, updated_at, accepted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [Ulid::generate($clock), $pj, $title, $u, $day($s), $day($d), $st, $pr, $i + 1, $lead, $now, $now, $st === 'accepted' ? $now : null]
            );
            $ids[] = (int) $db->pdo()->lastInsertId();
        }
        $db->run('INSERT INTO pj_deps (task_id, depends_on_id) VALUES (?, ?)', [$ids[2], $ids[1]]);
        $db->run("INSERT INTO pj_events (project_id, event, actor_id, created_at) VALUES (?, 'created', ?, ?)", [$pj, $lead, $now]);
        return 1;
    }
}
