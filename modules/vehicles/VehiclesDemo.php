<?php

declare(strict_types=1);

namespace Blue\Modules\Vehicles;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 9A-2 (installs with demo data, once): DEMO1 vehicle types, three vehicles (one under
 * repair), two drivers (one with the account driver.demo1 to log trips), three saved places and the company policy
 * "the requester confirms the trip". Plates and people are made up; the places are public landmarks.
 */
final class VehiclesDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('vh_vehicles')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if ($le === null || $db->scalar('SELECT id FROM vh_vehicles WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        $hq = $db->scalar("SELECT id FROM sites WHERE code = 'HQ' AND legal_entity_id = ?", [$le]);
        $br1 = $db->scalar("SELECT id FROM sites WHERE code = 'BR1' AND legal_entity_id = ?", [$le]);
        $types = [];
        foreach (['รถตู้', 'รถเก๋ง', 'รถกระบะ'] as $name) {
            $db->run('INSERT INTO vh_types (public_id, created_at, updated_at, legal_entity_id, name) VALUES (?, ?, ?, ?, ?)', [Ulid::generate($clock), $now, $now, $le, $name]);
            $types[] = (int) $db->pdo()->lastInsertId();
        }
        $vehicles = [
            [$hq, $types[0], 'ฮข 1234 (DEMO)', 'รถตู้ 12 ที่นั่ง (DEMO)', 10, 45210, 'available', null],
            [$hq, $types[1], '1กข 5678 (DEMO)', 'รถเก๋ง (DEMO)', 3, 18800, 'available', null],
            [$br1, $types[2], 'บค 9012 (DEMO)', 'รถกระบะ (DEMO)', 1, 90550, 'maintenance', 'เปลี่ยนยางและเช็กระยะ (DEMO)'],
        ];
        foreach ($vehicles as $v) {
            $db->run(
                'INSERT INTO vh_vehicles (public_id, created_at, updated_at, legal_entity_id, site_id, type_id, plate, name, seats, odometer_km, status, status_note, is_demo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [Ulid::generate($clock), $now, $now, $le, ...$v]
            );
        }
        $driverUser = self::account($db, $clock, (int) $le, $now);
        $drivers = [
            [$hq, 'สมชาย ขับดี (DEMO)', '080-000-0001', $driverUser, 'ใบขับขี่รถยนต์ส่วนบุคคล (DEMO)', '2029-06-30', '08:00:00', '17:00:00', '1,2,3,4,5'],
            [$br1, 'วิชัย ใจเย็น (DEMO)', '080-000-0002', null, 'ใบขับขี่รถยนต์ส่วนบุคคล (DEMO)', '2026-12-15', '07:00:00', '19:00:00', '1,2,3,4,5,6'],
        ];
        foreach ($drivers as $d) {
            $db->run(
                'INSERT INTO vh_drivers (public_id, created_at, updated_at, legal_entity_id, site_id, name, phone, user_id, licence_class, licence_expiry, work_from, work_to, work_days, is_demo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [Ulid::generate($clock), $now, $now, $le, ...$d]
            );
        }
        $places = [
            ['สำนักงานใหญ่ (DEMO)', 'ที่ตั้งสมมติสำหรับข้อมูลตัวอย่าง', 13.7246, 100.5294],
            ['ท่าอากาศยานสุวรรณภูมิ', 'สมุทรปราการ', 13.69, 100.7501],
            ['สถานีกลางกรุงเทพอภิวัฒน์', 'จตุจักร กรุงเทพฯ', 13.8045, 100.54],
        ];
        foreach ($places as $p) {
            $db->run('INSERT INTO vh_places (public_id, created_at, updated_at, legal_entity_id, name, address, lat, lng) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [Ulid::generate($clock), $now, $now, $le, ...$p]);
        }
        $db->run('INSERT IGNORE INTO vh_policies (legal_entity_id, confirm_required, location_keep_days, updated_at) VALUES (?, 1, NULL, ?)', [$le, $now]);
        return count($vehicles);
    }

    /** driver.demo1 — a DEMO1 driver who logs trips (same password as the other demo users) */
    private static function account(Connection $db, Clock $clock, int $le, string $now): ?int
    {
        $hash = $db->scalar("SELECT password_hash FROM users WHERE username = 'hr.demo1' AND is_demo = 1");
        if ($hash === null || $db->scalar("SELECT id FROM roles WHERE code = 'EMP'") === null) {
            return null;
        }
        $existing = $db->scalar("SELECT id FROM users WHERE username = 'driver.demo1'");
        if ($existing !== null) {
            return (int) $existing;
        }
        $admin = $db->scalar("SELECT u.id FROM users u JOIN user_role_assignments a ON a.user_id = u.id JOIN roles r ON r.id = a.role_id WHERE r.code = 'SYS' AND u.is_demo = 0 ORDER BY u.id LIMIT 1");
        $db->run(
            "INSERT INTO users (public_id, created_at, updated_at, row_version, username, display_name, email, password_hash, status, password_changed_at, is_demo)
             VALUES (?, ?, ?, 1, 'driver.demo1', 'พนักงานขับรถ (DEMO)', 'driver.demo1@demo.invalid', ?, 'active', ?, 1)",
            [Ulid::generate($clock), $now, $now, $hash, $now]
        );
        $uid = (int) $db->pdo()->lastInsertId();
        $db->run(
            "INSERT INTO user_role_assignments (public_id, created_at, updated_at, row_version, user_id, role_id, status, valid_from, requested_by, approved_by, approved_at, reason)
             VALUES (?, ?, ?, 1, ?, (SELECT id FROM roles WHERE code = 'EMP'), 'active', ?, ?, ?, ?, 'DEC-34 demo user (phase 9A-2)')",
            [Ulid::generate($clock), $now, $now, $uid, $clock->now()->format('Y-m-d'), $admin, $admin, $admin !== null ? $now : null]
        );
        $db->run('INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, ?, ?)', [(int) $db->pdo()->lastInsertId(), 'legal_entity', $le]);
        return $uid;
    }
}
