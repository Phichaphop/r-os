<?php

declare(strict_types=1);

namespace Blue\Modules\Library;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 9D-3 (installs with demo data, once): two DEMO1 categories, one published book with two copies
 * and one digital item left as a draft (demo data cannot store files — a librarian uploads a PDF to try reading online).
 */
final class LibraryDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('lb_categories')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if ($le === null || $db->scalar('SELECT id FROM lb_categories WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $cats = [];
        foreach ([['คู่มือและระเบียบ (DEMO)', 1], ['หนังสือพัฒนาตนเอง (DEMO)', 2]] as [$name, $sort]) {
            $db->run('INSERT INTO lb_categories (public_id, legal_entity_id, name, sort, active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)', [Ulid::generate($clock), $le, $name, $sort, $now, $now]);
            $cats[] = (int) $db->pdo()->lastInsertId();
        }
        $everyone = '{"sites":[],"departments":[],"groups":[]}';
        $db->run(
            "INSERT INTO lb_items (public_id, created_at, updated_at, legal_entity_id, category_id, kind, title, author, publisher, pub_year, language, description, tags, rights_holder, license, audience_json,
                loan_days, pickup_days, status, published_at) VALUES (?, ?, ?, ?, ?, 'physical', ?, ?, ?, ?, 'th', ?, ?, ?, ?, ?, 14, 3, 'published', ?)",
            [Ulid::generate($clock), $now, $now, $le, $cats[1], 'การสื่อสารในที่ทำงาน (DEMO)', 'ผู้เขียนตัวอย่าง (DEMO)', 'สำนักพิมพ์ตัวอย่าง (DEMO)', 2024,
                'หนังสือตัวอย่างสำหรับทดลองยืม-คืน (DEMO)', 'สื่อสาร, ทำงานเป็นทีม', 'สำนักพิมพ์ตัวอย่าง (DEMO)', 'ซื้อเล่มจริงเข้าห้องสมุด (DEMO)', $everyone, $now]
        );
        $book = (int) $db->pdo()->lastInsertId();
        foreach (['DEMO-001', 'DEMO-002'] as $code) {
            $db->run("INSERT INTO lb_copies (public_id, item_id, code, location, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'available', ?, ?)", [Ulid::generate($clock), $book, $code, 'ชั้น A (DEMO)', $now, $now]);
        }
        $db->run(
            "INSERT INTO lb_items (public_id, created_at, updated_at, legal_entity_id, category_id, kind, title, language, description, tags, rights_holder, license, audience_json, status)
             VALUES (?, ?, ?, ?, ?, 'digital', ?, 'th', ?, ?, ?, ?, ?, 'draft')",
            [Ulid::generate($clock), $now, $now, $le, $cats[0], 'คู่มือพนักงาน (DEMO)', 'อัปโหลดไฟล์ PDF แล้วเผยแพร่เพื่อทดลองอ่านออนไลน์ (DEMO)', 'คู่มือ, ระเบียบ', 'บริษัทตัวอย่าง (DEMO)', 'เอกสารภายในบริษัท (DEMO)', $everyone]
        );
        return 1;
    }
}
