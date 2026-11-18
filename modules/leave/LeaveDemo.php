<?php

declare(strict_types=1);

namespace Blue\Modules\Leave;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 4A: leave TYPES for DEMO1 only. No policy (entitlements, quotas, notice) is created —
 * those are legal/company decisions; HR enters them with a source reference and another HR approver checks (DEC-63).
 * Until then requests are refused with "no policy for this type".
 */
final class LeaveDemo
{
    /** code, Thai, English, statutory, document allowed, sort */
    private const TYPES = [
        ['SICK', 'ลาป่วย', 'Sick leave', 1, 1, 10],
        ['PERSONAL', 'ลากิจ', 'Personal leave', 1, 0, 20],
        ['ANNUAL', 'ลาพักผ่อนประจำปี', 'Annual leave', 1, 0, 30],
        ['MATERNITY', 'ลาคลอด', 'Maternity leave', 1, 1, 40],
        ['UNPAID', 'ลาไม่รับค่าจ้าง', 'Unpaid leave', 0, 0, 90],
    ];

    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('lv_types')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if ($le === null || $db->scalar('SELECT id FROM lv_types WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        foreach (self::TYPES as [$code, $th, $en, $stat, $doc, $sort]) {
            $db->run(
                'INSERT INTO lv_types (public_id, created_at, updated_at, legal_entity_id, code, name_th, name_en, is_statutory, allow_document, sort_order, is_demo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [Ulid::generate($clock), $now, $now, (int) $le, $code, $th, $en, $stat, $doc, $sort]
            );
        }
        return count(self::TYPES);
    }
}
