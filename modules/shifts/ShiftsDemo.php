<?php

declare(strict_types=1);

namespace Blue\Modules\Shifts;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for M35: three example shift patterns for DEMO1 (morning, afternoon, overnight) marked DEMO —
 * the times are invented examples, not a company rule. No roster: planning is done on screen.
 */
final class ShiftsDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('sh_patterns') || $db->scalar('SELECT id FROM sh_patterns WHERE is_demo = 1 LIMIT 1') !== null) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        if ($le === null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $n = 0;
        foreach ([['M', 'กะเช้า (DEMO)', '06:00', '15:00', 60, 'morning'], ['A', 'กะบ่าย (DEMO)', '14:00', '23:00', 60, 'afternoon'], ['N', 'กะดึก (DEMO)', '22:00', '07:00', 60, 'night']] as [$c, $name, $s, $e, $br, $tone]) {
            $db->run(
                "INSERT INTO sh_patterns (public_id, created_at, updated_at, legal_entity_id, code, name, start_time, end_time, break_minutes, late_grace_minutes, early_grace_minutes, tone, status, is_demo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, 'active', 1)",
                [Ulid::generate($clock), $now, $now, $le, $c, $name, $s, $e, $br, $tone]
            );
            $n++;
        }
        return $n;
    }
}
