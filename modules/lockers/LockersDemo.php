<?php

declare(strict_types=1);

namespace Blue\Modules\Lockers;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/** DEC-34 demo data for phase 9A (installs with demo data, once): one DEMO1 locker zone at HQ, 3 rows × 6, hold 3 days (example rule). */
final class LockersDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('lk_zones')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if ($le === null || $db->scalar('SELECT id FROM lk_zones WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $hq = $db->scalar("SELECT id FROM sites WHERE code = 'HQ' AND legal_entity_id = ?", [$le]);
        $db->run(
            "INSERT INTO lk_zones (public_id, created_at, updated_at, legal_entity_id, site_id, code, name, grid_rows, grid_cols, hold_days, note, is_demo) VALUES (?, ?, ?, ?, ?, 'A', 'ตู้ล็อกเกอร์ชั้น 1 (DEMO)', 3, 6, 3, 'ตัวอย่าง (DEMO)', 1)",
            [Ulid::generate($clock), $now, $now, $le, $hq]
        );
        $zone = (int) $db->pdo()->lastInsertId();
        for ($r = 1; $r <= 3; $r++) {
            for ($c = 1; $c <= 6; $c++) {
                $db->run(
                    'INSERT INTO lk_lockers (public_id, created_at, updated_at, zone_id, code, row_no, col_no, size) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [Ulid::generate($clock), $now, $now, $zone, sprintf('A-%02d-%02d', $r, $c), $r, $c, $r === 3 ? 'L' : 'M']
                );
            }
        }
        return 18;
    }
}
