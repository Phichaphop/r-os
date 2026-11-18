<?php

declare(strict_types=1);

namespace Blue\Modules\Assets;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 9C-1 (installs with demo data, once): three DEMO1 asset types and five assets at HQ and BR1;
 * notebook NB-0001 is held by employee.demo1 (not yet acknowledged). Costs and dates are examples.
 */
final class AssetsDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('as_assets')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if ($le === null || $db->scalar('SELECT id FROM as_types WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $local = $clock->now()->setTimezone(new \DateTimeZone('Asia/Bangkok'))->format('Y-m-d H:i:s');
        $types = [];
        foreach ([['คอมพิวเตอร์โน้ตบุ๊ก (DEMO)', 'it', 'NB'], ['เครื่องมือช่าง (DEMO)', 'tool', 'TL'], ['เฟอร์นิเจอร์สำนักงาน (DEMO)', 'fixed', 'FN']] as [$name, $class, $prefix]) {
            $db->run('INSERT INTO as_types (public_id, created_at, updated_at, legal_entity_id, name, asset_class, code_prefix) VALUES (?, ?, ?, ?, ?, ?, ?)', [Ulid::generate($clock), $now, $now, $le, $name, $class, $prefix]);
            $types[$prefix] = (int) $db->pdo()->lastInsertId();
        }
        $site = static fn (string $code): ?int => ($id = $db->scalar('SELECT id FROM sites WHERE legal_entity_id = ? AND code = ?', [$le, $code])) === null ? null : (int) $id;
        $hq = $site('HQ');
        $br = $site('BR1') ?? $hq;
        if ($hq === null || $br === null) {
            return 0;
        }
        $assets = [
            ['NB', 'NB-0001', 'โน้ตบุ๊กพนักงานขาย (DEMO)', 'DEMO-SN-1001', 'DemoBrand', 'X14', '2025-06-10', '28500.00', $br, 'ฝ่ายขาย ชั้น 1'],
            ['NB', 'NB-0002', 'โน้ตบุ๊กสำรอง (DEMO)', 'DEMO-SN-1002', 'DemoBrand', 'X14', '2025-06-10', '28500.00', $br, 'ตู้เก็บของ ฝ่ายขาย'],
            ['TL', 'TL-0001', 'สว่านไฟฟ้า (DEMO)', 'DEMO-DR-77', 'DemoTools', 'D500', '2024-02-01', '3200.00', $hq, 'ห้องช่าง'],
            ['FN', 'FN-0001', 'โต๊ะประชุม 8 ที่นั่ง (DEMO)', null, null, null, '2023-01-15', '15000.00', $hq, 'ห้องประชุมใหญ่'],
            ['FN', 'FN-0002', 'ตู้เอกสารเหล็ก (DEMO)', null, null, null, null, null, $br, 'ฝ่ายขาย'],
        ];
        $emp = $db->scalar("SELECT id FROM users WHERE username = 'employee.demo1'");
        // ga.demo1 may be created later by another module's demo: the first administrator records the hand-over then
        $ga = $db->scalar("SELECT id FROM users WHERE username = 'ga.demo1'") ?? $db->scalar('SELECT id FROM users WHERE is_demo = 0 ORDER BY id LIMIT 1') ?? $emp;
        foreach ($assets as [$prefix, $code, $name, $serial, $maker, $model, $acq, $cost, $s, $loc]) {
            $held = $code === 'NB-0001' && $emp !== null && $ga !== null;
            $db->run(
                'INSERT INTO as_assets (public_id, created_at, updated_at, legal_entity_id, type_id, code, name, serial_no, manufacturer, model, acquired_on, cost, site_id, location, status, custodian_id, qr_token)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [Ulid::generate($clock), $now, $now, $le, $types[$prefix], $code, $name, $serial, $maker, $model, $acq, $cost, $s, $loc, $held ? 'assigned' : 'available', $held ? $emp : null, bin2hex(random_bytes(16))]
            );
            $id = (int) $db->pdo()->lastInsertId();
            $db->run("INSERT INTO as_moves (asset_id, kind, after_json, reason, at_local, created_at) VALUES (?, 'registered', ?, 'DEMO', ?, ?)", [$id, json_encode(['code' => $code], JSON_UNESCAPED_UNICODE), $local, $now]);
            if ($held) {
                $db->run("INSERT INTO as_custody (asset_id, user_id, assigned_at, assigned_by, condition_out) VALUES (?, ?, ?, ?, 'good')", [$id, $emp, $local, $ga]);
                $db->run("INSERT INTO as_moves (asset_id, kind, after_json, actor_id, at_local, created_at) VALUES (?, 'assigned', ?, ?, ?, ?)", [$id, json_encode(['custodian' => 'employee.demo1'], JSON_UNESCAPED_UNICODE), $ga, $local, $now]);
            }
        }
        return count($assets);
    }
}
