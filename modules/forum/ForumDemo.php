<?php

declare(strict_types=1);

namespace Blue\Modules\Forum;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/** DEC-34 demo data for phase 9D-2 (installs with demo data, once): two DEMO1 categories and one thread with a reply. */
final class ForumDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('fm_categories')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        $emp = $db->scalar("SELECT id FROM users WHERE username = 'employee.demo1'");
        $mgr = $db->scalar("SELECT id FROM users WHERE username = 'manager.demo1'");
        if ($le === null || $emp === null || $mgr === null || $db->scalar('SELECT id FROM fm_categories WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $cats = [];
        foreach ([['ทั่วไป (DEMO)', 1], ['ถาม-ตอบการทำงาน (DEMO)', 2]] as [$name, $sort]) {
            $db->run('INSERT INTO fm_categories (public_id, created_at, updated_at, legal_entity_id, name, sort) VALUES (?, ?, ?, ?, ?, ?)', [Ulid::generate($clock), $now, $now, $le, $name, $sort]);
            $cats[] = (int) $db->pdo()->lastInsertId();
        }
        $db->run(
            'INSERT INTO fm_threads (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, category_id, author_id, title, body, last_post_at, reply_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [Ulid::generate($clock), $now, $emp, $now, $emp, $le, $cats[0], $emp, 'แนะนำร้านอาหารกลางวันแถวออฟฟิศ (DEMO)', 'มีร้านไหนอร่อยและเร็วบ้างครับ (DEMO)', $now]
        );
        $tid = (int) $db->pdo()->lastInsertId();
        $db->run('INSERT INTO fm_posts (public_id, thread_id, author_id, body, created_at) VALUES (?, ?, ?, ?, ?)', [Ulid::generate($clock), $tid, $mgr, 'ร้านข้าวมันไก่หน้าซอยเร็วมาก (DEMO)', $now]);
        return 1;
    }
}
