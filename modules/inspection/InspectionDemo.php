<?php

declare(strict_types=1);

namespace Blue\Modules\Inspection;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 9B-1 (installs with demo data, once): two DEMO1 example checklists (a fire extinguisher,
 * a server room with a temperature range) and two points checked by tech.demo1 — the server room sends failures to the
 * demo IT helpdesk category. The items and the range are examples, not a standard — the company writes its own.
 */
final class InspectionDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('in_points')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if ($le === null || $db->scalar('SELECT id FROM in_checklists WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $who = $db->scalar("SELECT id FROM users WHERE username = 'tech.demo1'") ?? $db->scalar("SELECT id FROM users WHERE username = 'ga.demo1'");
        if ($who === null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $today = $clock->now()->setTimezone(new \DateTimeZone('Asia/Bangkok'))->format('Y-m-d');
        $hq = $db->scalar("SELECT id FROM sites WHERE code = 'HQ' AND legal_entity_id = ?", [$le]);
        $it = $db->scalar("SELECT public_id FROM hd_categories WHERE legal_entity_id = ? AND name LIKE 'คอมพิวเตอร์%' LIMIT 1", [$le]);
        $lists = [
            ['ตรวจถังดับเพลิง (DEMO)', ['เข็มแรงดันอยู่ในช่องสีเขียว', 'สายและหัวฉีดไม่ชำรุด', 'สลักและซีลนิรภัยอยู่ครบ', 'ป้ายและจุดติดตั้งมองเห็นชัด', '? ทำความสะอาดตัวถัง']],
            ['ตรวจห้องเซิร์ฟเวอร์ (DEMO)', ['อุณหภูมิห้อง (°C) | 18 | 27', 'ไฟสถานะ UPS ปกติ', 'ไม่มีน้ำรั่วซึมหรือกลิ่นไหม้']],
        ];
        $ids = [];
        foreach ($lists as [$name, $lines]) {
            $db->run('INSERT INTO in_checklists (public_id, created_at, updated_at, legal_entity_id, name) VALUES (?, ?, ?, ?, ?)', [Ulid::generate($clock), $now, $now, $le, $name]);
            $id = (int) $db->pdo()->lastInsertId();
            $db->run(
                'INSERT INTO in_checklist_versions (checklist_id, version_no, items_json, effective_from, created_by, created_at) VALUES (?, 1, ?, ?, ?, ?)',
                [$id, json_encode(InspectionService::parseItems(implode("\n", $lines)), JSON_UNESCAPED_UNICODE), $today, $who, $now]
            );
            $ids[] = $id;
        }
        $points = [
            [$hq, 'FE-HQ-01', 'ถังดับเพลิง ทางออกชั้น 1 (DEMO)', 'อาคารสำนักงาน ชั้น 1', $ids[0], 'monthly', null, null],
            [$hq, 'SRV-HQ', 'ห้องเซิร์ฟเวอร์ (DEMO)', 'อาคารสำนักงาน ชั้น 2', $ids[1], 'weekly', $it, $it !== null ? 'high' : null],
        ];
        foreach ($points as [$site, $code, $name, $loc, $cl, $freq, $cat, $prio]) {
            $db->run(
                'INSERT INTO in_points (public_id, created_at, updated_at, legal_entity_id, site_id, code, name, location, checklist_id, frequency, start_date, inspector_id, ticket_category_pid, ticket_priority, qr_token)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [Ulid::generate($clock), $now, $now, $le, $site, $code, $name, $loc, $cl, $freq, $today, $who, $cat, $prio, bin2hex(random_bytes(16))]
            );
        }
        return count($points);
    }
}
