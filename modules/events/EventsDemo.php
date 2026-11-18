<?php

declare(strict_types=1);

namespace Blue\Modules\Events;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/** DEC-34 demo data for phase 9D-2 (installs with demo data, once): one published DEMO1 event for everyone, two weeks ahead. */
final class EventsDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('ev_events')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if ($le === null || $db->scalar('SELECT id FROM ev_events WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $day = $clock->now()->modify('+14 days')->format('Y-m-d');
        $db->run(
            "INSERT INTO ev_events (public_id, created_at, updated_at, legal_entity_id, title, kind, description, venue, starts_at, ends_at, reg_until, quota, waitlist, walk_in, audience_json, option_label, options, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 50, 1, 1, ?, ?, ?, 'published')",
            [Ulid::generate($clock), $now, $now, $le, 'เดินวิ่งการกุศล 5 กม. (DEMO)', 'งานวิ่ง', 'รวมตัวหน้าอาคาร รับเสื้อที่จุดลงทะเบียน (DEMO)', 'สวนสาธารณะใกล้สำนักงาน (DEMO)',
                $day . ' 06:00:00', $day . ' 09:00:00', $clock->now()->modify('+7 days')->format('Y-m-d') . ' 17:00:00', '{"sites":[],"departments":[],"groups":[]}', 'ไซซ์เสื้อ', "S\nM\nL\nXL"]
        );
        return 1;
    }
}
