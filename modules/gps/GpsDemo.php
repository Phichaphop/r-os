<?php

declare(strict_types=1);

namespace Blue\Modules\Gps;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for M57: one example check-in point for DEMO1 marked DEMO (a public place in Bangkok, 300 m).
 * No policy is shipped — check-in stays off until HR writes the notice and another HR approves it (DEC-71).
 */
final class GpsDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('gps_geofences') || $db->scalar('SELECT id FROM gps_geofences WHERE is_demo = 1 LIMIT 1') !== null) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        if ($le === null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $db->run(
            "INSERT INTO gps_geofences (public_id, created_at, updated_at, legal_entity_id, site_id, name, lat, lng, radius_m, status, is_demo)
             VALUES (?, ?, ?, ?, NULL, 'จุดลงเวลาตัวอย่าง (DEMO)', 13.756300, 100.501800, 300, 'active', 1)",
            [Ulid::generate($clock), $now, $now, $le]
        );
        return 1;
    }
}
