<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Modules\Lockers\LockerService;
use Blue\Modules\Rooms\BookingProblem;
use Blue\Modules\Rooms\RoomService;
use Blue\Modules\Rooms\RoomsPersonalData;
use Blue\Tests\Support\TestApp;

/**
 * Phase 9A (DEC-89): M04 meeting rooms and M07 lockers. Clock: Mon 2026-11-09 09:00 (Asia/Bangkok).
 * Demo rooms (example rules): R-101 HQ 12 seats, Mon–Fri 08:00–18:00, gap 10 min, ≤ 240 min, ≤ 60 days ahead, check-in
 * (release 15 min); R-201 BR1 6 seats, Mon–Sat 08:30–17:30, no gap, ≤ 120 min, ≤ 30 days. Locker zone A: 3 × 6, hold 3 days.
 */
final class WorkplaceRoomsLockersTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(rooms|lockers|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    private function room(string $code): string
    {
        return (string) $this->t->app->db()->scalar('SELECT public_id FROM rm_rooms WHERE code = ?', [$code]);
    }

    /** @param array<string, mixed> $in */
    private function book(string $user, string $room, array $in): array
    {
        return RoomService::of($this->t->app)->book($this->az($user), $this->room($room), $in + ['attendee_count' => '4', 'title' => 'ประชุมทดสอบ']);
    }

    /** @param array<string, mixed> $in */
    private function refused(string $user, string $room, array $in): array
    {
        try {
            $this->book($user, $room, $in);
        } catch (ValidationException $e) {
            return $e->errors;
        }
        self::fail('expected a refusal');
    }

    private function page(string $user, string $path, int $status = 200): string
    {
        $q = [];
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $q);
        }
        $b = $this->t->browser();
        $b->login($user);
        $r = $b->get($path, $q);
        self::assertSame($status, $r->status, $path . ' as ' . $user);
        if ($status === 200) {
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($r->body), $path);
        }
        return $r->body;
    }

    private function notes(string $user, string $type): int
    {
        return (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = ?', [(int) $this->t->user($user)['id'], $type]);
    }

    public function testMeetingRooms(): void
    {
        $app = $this->t->app;
        $db = $app->db();
        $svc = RoomService::of($app);
        self::assertSame('2026-11-09 09:00:00', $svc->nowLocal()->format('Y-m-d H:i:s'));
        self::assertNotNull($db->scalar("SELECT id FROM users WHERE username = 'ga.demo1'"));

        // ---- the rules of the room ----------------------------------------------------------------------------------------
        $tue = '2026-11-10';
        self::assertSame(['start' => 'rooms.error.hours'], $this->refused('employee.demo1', 'R-101', ['date' => $tue, 'start' => '07:00', 'end' => '08:00']));
        self::assertSame(['end' => 'rooms.error.too_long'], $this->refused('employee.demo1', 'R-101', ['date' => $tue, 'start' => '08:00', 'end' => '12:15']));
        self::assertSame(['date' => 'rooms.error.too_far'], $this->refused('employee.demo1', 'R-101', ['date' => '2027-02-01', 'start' => '10:00', 'end' => '11:00']));
        self::assertSame(['attendee_count' => 'rooms.error.capacity'], $this->refused('employee.demo1', 'R-101', ['date' => $tue, 'start' => '10:00', 'end' => '11:00', 'attendee_count' => '13']));
        self::assertSame(['start' => 'rooms.error.past'], $this->refused('employee.demo1', 'R-101', ['date' => '2026-11-09', 'start' => '08:00', 'end' => '08:30']));
        self::assertSame(['start' => 'rooms.error.step'], $this->refused('employee.demo1', 'R-101', ['date' => $tue, 'start' => '10:10', 'end' => '11:00']));
        self::assertSame(['start' => 'rooms.error.hours'], $this->refused('employee.demo1', 'R-101', ['date' => '2026-11-14', 'start' => '10:00', 'end' => '11:00']), 'Saturday');

        // ---- booking without approval, invited people, unknown people listed back ----------------------------------------
        try {
            $this->book('employee.demo1', 'R-101', ['date' => $tue, 'start' => '10:00', 'end' => '11:00', 'attendees' => 'manager.demo1, nosuch.person']);
            self::fail('unknown');
        } catch (BookingProblem $e) {
            self::assertSame(['attendees', ['nosuch.person']], [$e->field, $e->items]);
        }
        $mine = $this->book('employee.demo1', 'R-101', ['date' => $tue, 'start' => '10:00', 'end' => '11:00', 'title' => 'วางแผนงาน Q1 (ทดสอบ)', 'attendees' => "manager.demo1\nD1-0002"]);
        self::assertSame([1, []], [$mine['booked'], $mine['skipped']]);
        self::assertSame(['confirmed', '2026-11-10 10:00:00'], array_values($db->one('SELECT status, starts_at FROM rm_bookings WHERE public_id = ?', [$mine['first']]) ?? []));
        self::assertSame(1, $this->notes('manager.demo1', 'rooms.invite'));
        self::assertSame(1, $this->notes('hr.demo1', 'rooms.invite'), 'D1-0002 is hr.demo1');
        // G9A-01 first come first served, with the room's 10-minute gap
        self::assertSame(['start' => 'rooms.error.taken'], $this->refused('hr.head1', 'R-101', ['date' => $tue, 'start' => '11:00', 'end' => '12:00']));
        self::assertSame(['start' => 'rooms.error.taken'], $this->refused('hr.head1', 'R-101', ['date' => $tue, 'start' => '09:00', 'end' => '10:00']));
        $other = $this->book('hr.head1', 'R-101', ['date' => $tue, 'start' => '11:15', 'end' => '12:00', 'title' => 'ลับ: สัมภาษณ์ (ทดสอบ)']);

        // ---- weekly series (G9A-02) -----------------------------------------------------------------------------------------
        $series = $this->book('hr.head1', 'R-201', ['date' => $tue, 'start' => '09:00', 'end' => '10:00', 'repeat' => 'weekly', 'every_weeks' => '1', 'until' => '2026-12-08']);
        self::assertSame(5, $series['booked']);
        self::assertSame(5, (int) $db->scalar('SELECT COUNT(*) FROM rm_bookings WHERE series_id IS NOT NULL'));
        $this->book('employee.demo1', 'R-201', ['date' => '2026-11-24', 'start' => '08:30', 'end' => '09:00']); // touches the series (no gap here) — fine
        $one = $this->book('employee.demo1', 'R-201', ['date' => '2026-12-01', 'start' => '10:00', 'end' => '11:00', 'title' => 'ขอใช้หนึ่งครั้ง']);
        try {
            $this->book('manager.demo1', 'R-201', ['date' => '2026-11-17', 'start' => '10:00', 'end' => '11:00', 'repeat' => 'weekly', 'every_weeks' => '2', 'until' => '2026-12-01']);
            self::fail('dec 1 is taken');
        } catch (BookingProblem $e) {
            self::assertSame(['repeat', 'rooms.error.series_clash', ['2026-12-01']], [$e->field, $e->messageKey, $e->items]);
        }
        $skip = $this->book('manager.demo1', 'R-201', ['date' => '2026-11-17', 'start' => '10:00', 'end' => '11:00', 'repeat' => 'weekly', 'every_weeks' => '2', 'until' => '2026-12-01', 'skip_conflicts' => '1']);
        self::assertSame([1, ['2026-12-01']], [$skip['booked'], $skip['skipped']]);

        // ---- who sees what (G9A-03) -----------------------------------------------------------------------------------------
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $cells = static fn (array $grid, string $code) => array_values(array_filter($grid['rows'][(int) array_values(array_filter($grid['rooms'], static fn (array $r): bool => $r['code'] === $code))[0]['id']], static fn (array $c): bool => $c['kind'] === 'booked'));
        $g = $svc->day($this->az('employee.demo1'), $le, null, $tue);
        $booked = $cells($g, 'R-101');
        self::assertSame([[$mine['first'], 'วางแผนงาน Q1 (ทดสอบ)', 4], [null, null, 3]], array_map(static fn (array $c): array => [$c['pid'], $c['title'], $c['span']], $booked), 'own booking 10:00–11:00 = 4 slots; someone else\'s = busy only');
        $gm = $svc->day($this->az('manager.demo1'), $le, null, $tue);
        self::assertSame('วางแผนงาน Q1 (ทดสอบ)', $cells($gm, 'R-101')[0]['title'], 'invited');
        $ga = $svc->day($this->az('ga.demo1'), $le, null, $tue);
        self::assertSame('ลับ: สัมภาษณ์ (ทดสอบ)', $cells($ga, 'R-101')[1]['title'], 'room admin');
        $this->page('payroll.maker', '/rooms/b/' . $mine['first'], 404);
        $this->page('manager.demo1', '/rooms/b/' . $mine['first']);
        $this->page('hr.demo2', '/rooms/b/' . $mine['first'], 404);

        // ---- move, cancel (self / admin / rest of a series) ------------------------------------------------------------------
        try {
            $svc->reschedule($this->az('employee.demo1'), $mine['first'], ['date' => $tue, 'start' => '11:00', 'end' => '12:00']);
            self::fail('taken');
        } catch (ValidationException $e) {
            self::assertSame(['start' => 'rooms.error.taken'], $e->errors);
        }
        $svc->reschedule($this->az('employee.demo1'), $mine['first'], ['date' => $tue, 'start' => '13:00', 'end' => '14:00']);
        self::assertSame(1, $this->notes('manager.demo1', 'rooms.change'));
        try {
            $svc->cancel($this->az('ga.demo1'), $one['first'], '', false);
            self::fail('admin needs a reason');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('reason', $e->errors);
        }
        $svc->cancel($this->az('ga.demo1'), $one['first'], 'ห้องใช้จัดอบรม (ทดสอบ)', false);
        self::assertSame(1, $this->notes('employee.demo1', 'rooms.change'), 'the booker is told');
        $third = (string) $db->scalar('SELECT public_id FROM rm_bookings WHERE series_id IS NOT NULL ORDER BY starts_at LIMIT 1 OFFSET 2');
        self::assertSame(3, $svc->cancel($this->az('hr.head1'), $third, '', true), 'this and the two later dates');
        try {
            $svc->cancel($this->az('manager.demo1'), $other['first'], 'x', false);
            self::fail('not theirs');
        } catch (HttpException $e) {
            self::assertSame(404, $e->httpStatus);
        }

        // ---- check-in, no-show release, completion (G9A-04) ----------------------------------------------------------------------
        self::assertFalse($svc->booking($this->az('employee.demo1'), $mine['first'])['can_checkin']);
        $this->t->clock->advance('+1 day +3 hours +50 minutes'); // Tue 12:50
        self::assertSame('no_show', $db->scalar('SELECT status FROM rm_bookings WHERE public_id = ?', [$svc->booking($this->az('hr.head1'), $other['first'])['public_id']]), '11:15 + 15 min passed without check-in');
        self::assertSame(1, $this->notes('hr.head1', 'rooms.no_show'));
        $svc->checkIn($this->az('employee.demo1'), $mine['first']);
        $this->t->clock->advance('+2 hours'); // 14:50
        $svc->tick();
        self::assertSame('completed', $db->scalar('SELECT status FROM rm_bookings WHERE public_id = ?', [$mine['first']]));
        try {
            $svc->checkIn($this->az('employee.demo1'), $mine['first']);
            self::fail('over');
        } catch (BusinessException $e) {
            self::assertSame('rooms.error.checkin_window', $e->messageKey);
        }

        // ---- rooms admin: new room, closures, closing a room ---------------------------------------------------------------------
        $ga = $this->az('ga.demo1');
        try {
            $svc->saveRoom($ga, $le, ['code' => 'r-101', 'name' => '', 'capacity' => '0', 'open_from' => '18:00', 'open_to' => '08:00', 'buffer_minutes' => '0', 'max_minutes' => '50', 'advance_days' => '10']);
            self::fail('validation');
        } catch (ValidationException $e) {
            self::assertSame(['code', 'name', 'capacity', 'open_to', 'open_days', 'max_minutes'], array_keys($e->errors));
        }
        $new = $svc->saveRoom($ga, $le, ['code' => 'R-102', 'name' => 'ห้องกลาง (ทดสอบ)', 'capacity' => '8', 'open_from' => '09:00', 'open_to' => '17:00', 'open_days' => ['1', '2', '3', '4', '5'],
            'buffer_minutes' => '0', 'max_minutes' => '60', 'advance_days' => '14']);
        try {
            $svc->saveRoom($this->az('employee.demo1'), $le, ['code' => 'X']);
            self::fail('not admin');
        } catch (HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        self::assertSame(0, $svc->addClosure($ga, $new, '2026-11-12T09:00', '2026-11-12T12:00', 'ทาสีห้อง (ทดสอบ)'));
        self::assertSame(['start' => 'rooms.error.room_closed_then'], $this->refused('employee.demo1', 'R-102', ['date' => '2026-11-12', 'start' => '10:00', 'end' => '11:00']));
        $this->book('employee.demo1', 'R-102', ['date' => '2026-11-12', 'start' => '13:00', 'end' => '14:00']);
        self::assertSame(1, $svc->addClosure($ga, $new, '2026-11-12T13:30', '2026-11-12T15:00', 'ซ่อมแอร์ (ทดสอบ)'), 'the admin is told one booking falls in it');
        $svc->setStatus($ga, $new, false, 'ปิดปรับปรุง (ทดสอบ)');
        self::assertSame(['room' => 'rooms.error.closed'], $this->refused('employee.demo1', 'R-102', ['date' => '2026-11-13', 'start' => '10:00', 'end' => '11:00']));

        // ---- report (numbers only) + export, privacy, screens ----------------------------------------------------------------------
        $use = array_column($svc->usage($ga, $le, '2026-11'), null, 'code');
        self::assertSame([1, 1, 0], [$use['R-101']['used'], $use['R-101']['no_show'], $use['R-101']['cancelled']]);
        self::assertSame([4, 1], [$use['R-201']['used'], $use['R-201']['cancelled']], 'November: series 10 + 17 Nov, 24 Nov 08:30, manager 17 Nov = 4 used; series 24 Nov cancelled = 1 (the rest is December)');
        $x = $svc->export($ga, $le, '2026-11');
        self::assertStringStartsWith('PK', $x['bytes']);
        $data = RoomsPersonalData::export($app, (int) $this->t->user('employee.demo1')['id'], null);
        self::assertGreaterThanOrEqual(3, count($data[0]['rows']));
        $this->page('employee.demo1', '/rooms');
        $this->page('employee.demo1', '/rooms?date=2026-11-17');
        $body = $this->page('employee.demo1', '/rooms/r/' . $this->room('R-101') . '?date=2026-11-17&start=10:00');
        self::assertStringContainsString('จองเลย', $body);
        $this->page('employee.demo1', '/rooms/b/' . $mine['first']);
        $this->page('employee.demo1', '/rooms/mine');
        $this->page('employee.demo1', '/rooms/admin', 403);
        $this->page('ga.demo1', '/rooms/admin');
        $this->page('ga.demo1', '/rooms/report');
        $this->page('ga.demo1', '/rooms/r/' . $new);
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action LIKE 'rooms.%' AND diff_json LIKE '%ทดสอบ%'"), 'no titles in the audit details');
    }

    public function testLockers(): void
    {
        $app = $this->t->app;
        $db = $app->db();
        $svc = LockerService::of($app);
        $id = static fn (string $code): string => (string) $db->scalar('SELECT public_id FROM lk_lockers WHERE code = ?', [$code]);
        $emp = $this->az('employee.demo1');
        $mgr = $this->az('manager.demo1');
        $ga = $this->az('ga.demo1');
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        self::assertSame(18, (int) $db->scalar('SELECT COUNT(*) FROM lk_lockers'));

        // ---- reserve at once, one per zone, the row is taken (G9A-05) ---------------------------------------------------------
        $svc->reserve($emp, $id('A-01-01'));
        $a = $db->one("SELECT a.status, a.hold_until, l.status AS locker FROM lk_assignments a JOIN lk_lockers l ON l.id = a.locker_id WHERE l.code = 'A-01-01'") ?? [];
        self::assertSame(['reserved', '2026-11-12', 'reserved'], array_values($a));
        foreach ([[$emp, 'A-01-02', 'lockers.error.one_per_zone'], [$mgr, 'A-01-01', 'lockers.error.not_free'], [$this->az('payroll.maker'), 'A-01-03', 'lockers.error.no_employee']] as [$who, $code, $key]) {
            try {
                $svc->reserve($who, $id($code));
                self::fail($key);
            } catch (BusinessException $e) {
                self::assertSame($key, $e->messageKey);
            }
        }

        // ---- key handed over, confirmed, cannot be put out of use while held ------------------------------------------------
        try {
            $svc->issueKey($emp, $id('A-01-01'), 'K-001');
            self::fail('not admin');
        } catch (HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        $svc->issueKey($ga, $id('A-01-01'), 'K-001');
        self::assertSame(1, $this->notes('employee.demo1', 'lockers.key'));
        $svc->acknowledge($emp, $id('A-01-01'));
        try {
            $svc->setStatus($ga, $id('A-01-01'), 'maintenance', 'ซ่อมบานพับ');
            self::fail('occupied');
        } catch (BusinessException $e) {
            self::assertSame('lockers.error.occupied', $e->messageKey);
        }
        // the grid: others see the status only, the admin sees who
        $zone = (string) $db->scalar("SELECT public_id FROM lk_zones WHERE code = 'A'");
        self::assertNull($svc->zone($mgr, $zone)['grid'][1][1]['employee_code']);
        self::assertSame('D1-0004', $svc->zone($ga, $zone)['grid'][1][1]['employee_code']);
        self::assertTrue($svc->zone($emp, $zone)['grid'][1][1]['mine']);

        // ---- return: asked by the holder, recorded by the admin (G9A-06) ----------------------------------------------------
        $svc->askReturn($emp, $id('A-01-01'));
        try {
            $svc->recordReturn($ga, $id('A-01-01'), ['key_returned' => '1', 'condition' => 'damaged', 'note' => '']);
            self::fail('note');
        } catch (ValidationException $e) {
            self::assertSame(['note' => 'lockers.error.note_needed'], $e->errors);
        }
        $svc->recordReturn($ga, $id('A-01-01'), ['key_returned' => '1', 'condition' => 'damaged', 'note' => 'บานพับหลวม (ทดสอบ)']);
        self::assertSame(['maintenance', 'returned'], [(string) $db->scalar("SELECT status FROM lk_lockers WHERE code = 'A-01-01'"), (string) $db->scalar("SELECT a.status FROM lk_assignments a JOIN lk_lockers l ON l.id = a.locker_id WHERE l.code = 'A-01-01'")]);
        $svc->setStatus($ga, $id('A-01-01'), 'available', '');

        // ---- a reservation lapses when the key is not picked up -------------------------------------------------------------
        $svc->reserve($mgr, $id('A-01-02'));
        $this->t->clock->advance('+4 days');
        $svc->tick();
        self::assertSame(['expired', 'available'], [(string) $db->scalar("SELECT a.status FROM lk_assignments a JOIN lk_lockers l ON l.id = a.locker_id WHERE l.code = 'A-01-02'"), (string) $db->scalar("SELECT status FROM lk_lockers WHERE code = 'A-01-02'")]);
        self::assertSame(1, $this->notes('manager.demo1', 'lockers.expired'));

        // ---- the holder leaves: return pending + the admins are told once (G9A-07) -------------------------------------------
        $svc->reserve($emp, $id('A-02-01'));
        $svc->issueKey($ga, $id('A-02-01'), 'K-007');
        $db->run("UPDATE employees SET termination_date = ? WHERE employee_code = 'D1-0004'", [$svc->today()]);
        $svc->tick();
        $svc->tick();
        self::assertSame('return_pending', $db->scalar("SELECT status FROM lk_lockers WHERE code = 'A-02-01'"));
        self::assertSame(1, $this->notes('ga.demo1', 'lockers.left'));
        $svc->recordReturn($ga, $id('A-02-01'), ['key_returned' => '0', 'condition' => 'good', 'note' => 'ยังติดตามกุญแจ (ทดสอบ)']);
        self::assertSame('blocked', $db->scalar("SELECT status FROM lk_lockers WHERE code = 'A-02-01'"), 'key not back → blocked');

        // ---- zones, report, screens -----------------------------------------------------------------------------------------
        try {
            $svc->createZone($ga, $le, ['code' => 'a', 'name' => 'ซ้ำ', 'grid_rows' => '0', 'grid_cols' => '5', 'hold_days' => '']);
            self::fail('validation');
        } catch (ValidationException $e) {
            self::assertSame(['code', 'grid_rows', 'hold_days'], array_keys($e->errors));
        }
        $b = $svc->createZone($ga, $le, ['code' => 'B', 'name' => 'ตู้ชั้น 2 (ทดสอบ)', 'grid_rows' => '2', 'grid_cols' => '3', 'hold_days' => '2']);
        self::assertSame(['B-01-01', 'B-02-03'], [(string) $db->scalar("SELECT l.code FROM lk_lockers l JOIN lk_zones z ON z.id = l.zone_id WHERE z.public_id = ? ORDER BY l.id LIMIT 1", [$b]),
            (string) $db->scalar("SELECT l.code FROM lk_lockers l JOIN lk_zones z ON z.id = l.zone_id WHERE z.public_id = ? ORDER BY l.id DESC LIMIT 1", [$b])]);
        self::assertStringStartsWith('PK', $svc->export($ga, $le)['bytes']);
        $this->page('manager.demo1', '/lockers');
        $this->page('manager.demo1', '/lockers/z/' . $zone);
        $this->page('manager.demo1', '/lockers/l/' . $id('A-01-03'));
        $this->page('manager.demo1', '/lockers/admin', 403);
        $this->page('ga.demo1', '/lockers/admin');
        $body = $this->page('ga.demo1', '/lockers/l/' . $id('A-02-01'));
        self::assertStringContainsString('ยังติดตามกุญแจ', $body);
        $this->page('hr.demo2', '/lockers/l/' . $id('A-01-03'), 404);
    }
}
