<?php

declare(strict_types=1);

namespace Blue\Modules\Kaizen;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 9D-1 (installs with demo data, once): three DEMO1 scoring criteria (examples — the company
 * publishes its own), one named idea from employee.demo1 waiting for screening, and one draft pulse survey for everyone.
 */
final class KaizenDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('kz_criteria')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if ($le === null || $db->scalar('SELECT id FROM kz_criteria WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        foreach ([['ผลกระทบต่องาน (DEMO)', 40], ['ความคุ้มค่า (DEMO)', 30], ['ทำได้จริง (DEMO)', 30]] as [$name, $w]) {
            $db->run('INSERT INTO kz_criteria (public_id, created_at, updated_at, legal_entity_id, name, weight) VALUES (?, ?, ?, ?, ?, ?)', [Ulid::generate($clock), $now, $now, $le, $name, $w]);
        }
        $emp = $db->scalar("SELECT id FROM users WHERE username = 'employee.demo1'");
        if ($emp !== null) {
            $db->run(
                'INSERT INTO kz_ideas (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, ref, title, problem, proposal, benefit, submitter_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [Ulid::generate($clock), $now, $emp, $now, $emp, $le, 'KZ' . $clock->now()->format('ym') . '-0001', 'ติดป้ายบอกทางไปห้องประชุม (DEMO)',
                    'ผู้มาติดต่อหาห้องประชุมชั้น 2 ไม่เจอ ต้องถามพนักงานบ่อย (DEMO)', 'ติดป้ายบอกทางที่ลิฟต์และบันได พร้อมเลขห้อง (DEMO)', 'ลดเวลาที่พนักงานต้องพาผู้มาติดต่อไปส่ง (DEMO)', $emp]
            );
            $db->run("INSERT INTO kz_events (idea_id, event, actor_id, created_at) VALUES (?, 'submitted', ?, ?)", [(int) $db->pdo()->lastInsertId(), $emp, $now]);
        }
        $today = $clock->now()->format('Y-m-d');
        $close = $clock->now()->modify('+14 days')->format('Y-m-d');
        $db->run(
            "INSERT INTO kz_surveys (public_id, created_at, updated_at, legal_entity_id, title, intro, audience_json, opens_on, closes_on, anonymous, min_results) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 3)",
            [Ulid::generate($clock), $now, $now, $le, 'Pulse ความพึงพอใจในการทำงาน (DEMO)', 'แบบสำรวจสั้น 3 ข้อ ไม่ระบุตัวตน (DEMO)', '{"sites":[],"departments":[],"groups":[]}', $today, $close]
        );
        $sid = (int) $db->pdo()->lastInsertId();
        foreach ([['scale', 'พอใจกับงานที่ทำอยู่แค่ไหน (DEMO)', null], ['enps', 'จะแนะนำบริษัทให้เพื่อนมาทำงานไหม (0–10) (DEMO)', null], ['text', 'อยากให้ปรับปรุงเรื่องใด (DEMO)', null]] as $k => [$kind, $text, $opts]) {
            $db->run('INSERT INTO kz_questions (survey_id, sort, kind, text, options, required) VALUES (?, ?, ?, ?, ?, ?)', [$sid, $k + 1, $kind, $text, $opts, $kind === 'text' ? 0 : 1]);
        }
        return 1;
    }
}
