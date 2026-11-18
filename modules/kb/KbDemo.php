<?php

declare(strict_types=1);

namespace Blue\Modules\Kb;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 9D-1 (installs with demo data, once): two DEMO1 categories, two published answers about using
 * this system (written by hr.demo1, reviewed by hr.head1 — never policy or legal content) and one synonym group.
 */
final class KbDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('kb_categories')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        $author = $db->scalar("SELECT id FROM users WHERE username = 'hr.demo1'");
        $reviewer = $db->scalar("SELECT id FROM users WHERE username = 'hr.head1'");
        if ($le === null || $author === null || $reviewer === null || $db->scalar('SELECT id FROM kb_categories WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $due = $clock->now()->modify('+1 year')->format('Y-m-d');
        $cats = [];
        foreach ([['การใช้ระบบ (DEMO)', 'Using the system (DEMO)', 1], ['สำนักงาน (DEMO)', 'Office (DEMO)', 2]] as [$th, $en, $sort]) {
            $db->run('INSERT INTO kb_categories (public_id, created_at, updated_at, legal_entity_id, name_th, name_en, sort) VALUES (?, ?, ?, ?, ?, ?, ?)', [Ulid::generate($clock), $now, $now, $le, $th, $en, $sort]);
            $cats[] = (int) $db->pdo()->lastInsertId();
        }
        $items = [
            [$cats[0], 'ลืมรหัสผ่านต้องทำอย่างไร (DEMO)', "ที่หน้าเข้าสู่ระบบ กด \"ลืมรหัสผ่าน\" แล้วใส่อีเมลที่ใช้กับระบบ\n\n- ระบบส่งลิงก์ตั้งรหัสใหม่ไปที่อีเมล\n- ลิงก์ใช้ได้ครั้งเดียว\n\nถ้าไม่ได้รับอีเมล ให้ติดต่อผู้ดูแลระบบ (DEMO)", 'รหัสผ่าน, เข้าสู่ระบบ'],
            [$cats[1], 'จองห้องประชุมได้ที่ไหน (DEMO)', "เมนู \"จองห้องประชุม\" เลือกวันและสาขา แล้วกดช่องเวลาที่ว่าง (DEMO)", 'ห้องประชุม, จอง'],
        ];
        foreach ($items as [$cat, $q, $a, $tags]) {
            $db->run('INSERT INTO kb_articles (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, category_id, owner_id, status, review_due) VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'published\', ?)', [Ulid::generate($clock), $now, $author, $now, $author, $le, $cat, $author, $due]);
            $aid = (int) $db->pdo()->lastInsertId();
            $db->run("INSERT INTO kb_revisions (article_id, version, question_th, answer_th, tags, status, author_id, submitted_at, reviewer_id, decided_at, review_due, created_at) VALUES (?, 1, ?, ?, ?, 'published', ?, ?, ?, ?, ?, ?)", [$aid, $q, $a, $tags, $author, $now, $reviewer, $now, $due, $now]);
            $db->run('UPDATE kb_articles SET published_rev_id = ? WHERE id = ?', [(int) $db->pdo()->lastInsertId(), $aid]);
        }
        $db->run('INSERT INTO kb_synonyms (legal_entity_id, groups_text, updated_at) VALUES (?, ?, ?)', [$le, 'รหัสผ่าน, password, พาสเวิร์ด', $now]);
        return 1;
    }
}
