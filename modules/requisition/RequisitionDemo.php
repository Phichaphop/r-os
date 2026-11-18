<?php

declare(strict_types=1);

namespace Blue\Modules\Requisition;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 9B-2 (installs with demo data, once): two DEMO1 supply categories with five items, stock at
 * every DEMO1 site (received into the ledger), a monthly paper quota for the SALES department and example company rules
 * (special approval over quota, 4 requests a month, Mon–Fri 08:00–17:00, pick up within 7 days) — the company's own
 * examples, not defaults.
 */
final class RequisitionDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('rq_items')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if ($le === null || $db->scalar('SELECT id FROM rq_categories WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $local = $clock->now()->setTimezone(new \DateTimeZone('Asia/Bangkok'))->format('Y-m-d H:i:s');
        $cats = [];
        foreach (['เครื่องเขียน (DEMO)', 'กระดาษและแฟ้ม (DEMO)'] as $name) {
            $db->run('INSERT INTO rq_categories (public_id, created_at, updated_at, legal_entity_id, name) VALUES (?, ?, ?, ?, ?)', [Ulid::generate($clock), $now, $now, $le, $name]);
            $cats[] = (int) $db->pdo()->lastInsertId();
        }
        $items = [
            [0, 'PEN-BL', 'ปากกาลูกลื่น สีน้ำเงิน', 'ด้าม', 20, 200],
            [0, 'STAPLER', 'ที่เย็บกระดาษ เบอร์ 10', 'อัน', 2, 15],
            [0, 'TAPE-18', 'เทปใส 18 มม.', 'ม้วน', 5, 40],
            [1, 'PAPER-A4', 'กระดาษ A4 80 แกรม', 'รีม', 10, 60],
            [1, 'FOLDER-A4', 'แฟ้มสันกว้าง A4', 'เล่ม', 5, 30],
        ];
        $sites = array_map('intval', array_column($db->all("SELECT id FROM sites WHERE legal_entity_id = ? AND status = 'active'", [$le]), 'id'));
        $paper = null;
        foreach ($items as [$c, $code, $name, $unit, $reorder, $qty]) {
            $db->run(
                'INSERT INTO rq_items (public_id, created_at, updated_at, legal_entity_id, category_id, code, name, unit, reorder_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [Ulid::generate($clock), $now, $now, $le, $cats[$c], $code, $name . ' (DEMO)', $unit, $reorder]
            );
            $id = (int) $db->pdo()->lastInsertId();
            $paper = $code === 'PAPER-A4' ? $id : $paper;
            foreach ($sites as $s) {
                $db->run('INSERT INTO rq_stock (item_id, site_id, on_hand, reserved, updated_at) VALUES (?, ?, ?, 0, ?)', [$id, $s, $qty, $now]);
                $db->run(
                    "INSERT INTO rq_ledger (legal_entity_id, item_id, site_id, kind, qty, balance_after, note, at_local, created_at) VALUES (?, ?, ?, 'receive', ?, ?, 'DEMO opening stock', ?, ?)",
                    [$le, $id, $s, $qty, $qty, $local, $now]
                );
            }
        }
        $sales = $db->scalar("SELECT id FROM departments WHERE legal_entity_id = ? AND code = 'SALES'", [$le]);
        if ($sales !== null && $paper !== null) {
            $db->run(
                "INSERT INTO rq_quotas (public_id, created_at, updated_at, legal_entity_id, department_id, item_id, qty, period, valid_from) VALUES (?, ?, ?, ?, ?, ?, 10, 'month', ?)",
                [Ulid::generate($clock), $now, $now, $le, $sales, $paper, substr($local, 0, 8) . '01']
            );
        }
        $db->run(
            "INSERT IGNORE INTO rq_policies (legal_entity_id, over_quota, max_requests, max_per, open_days, open_from, open_to, expire_days, updated_at) VALUES (?, 'special', 4, 'month', '1,2,3,4,5', '08:00:00', '17:00:00', 7, ?)",
            [$le, $now]
        );
        return count($items);
    }
}
