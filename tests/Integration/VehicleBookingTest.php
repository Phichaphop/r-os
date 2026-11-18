<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Integrations\HttpTransport;
use Blue\Modules\Vehicles\Geo;
use Blue\Modules\Vehicles\VehicleService;
use Blue\Modules\Vehicles\VehiclesPersonalData;
use Blue\Tests\Support\TestApp;

/**
 * Phase 9A-2 (DEC-90): M05 vehicle booking. Clock: Mon 2026-11-09 09:00 (Asia/Bangkok).
 * Demo (DEMO1): van "ฮข 1234 (DEMO)" HQ 10 seats odometer 45210, car "1กข 5678 (DEMO)" HQ 3 seats, pickup BR1 under repair;
 * drivers สมชาย (driver.demo1, Mon–Fri 08:00–17:00, licence to 2029-06-30) and วิชัย (Mon–Sat 07:00–19:00, licence to
 * 2026-12-15); places HQ / Suvarnabhumi / Krung Thep Aphiwat; policy: the requester confirms the trip.
 * employee.demo1 (D1-0004) reports to manager.demo1; ga.demo1 is the workplace admin (WPA).
 */
final class VehicleBookingTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(vehicles|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    private const SUVARNABHUMI_LINK = 'https://www.google.com/maps/place/Suvarnabhumi+Airport/@13.6900,100.7400,14z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d13.6900000!4d100.7501000';

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

    private function svc(): VehicleService
    {
        return VehicleService::of($this->t->app);
    }

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    private function pid(string $table, string $col, string $val): string
    {
        return (string) $this->t->app->db()->scalar("SELECT public_id FROM {$table} WHERE {$col} = ?", [$val]);
    }

    /** @param array<string, mixed> $in */
    private function ask(string $user, array $in, bool $submit = true): string
    {
        $base = ['site' => $this->pid('sites', 'code', 'HQ'), 'start' => '2026-11-10T09:00', 'end' => '2026-11-10T12:00', 'purpose' => 'พบลูกค้า (ทดสอบ)', 'passenger_count' => '3',
            'from_place' => $this->pid('vh_places', 'name', 'สำนักงานใหญ่ (DEMO)'), 'to_name' => 'ท่าอากาศยาน', 'to_link' => self::SUVARNABHUMI_LINK];
        return $this->svc()->save($this->az($user), $this->le(), $in + $base, $submit)['pid'];
    }

    /** @param array<string, mixed> $in */
    private function askErrors(string $user, array $in): array
    {
        try {
            $this->ask($user, $in);
        } catch (ValidationException $e) {
            return $e->errors;
        }
        self::fail('expected a refusal');
    }

    /**
     * @param callable(): mixed $fn
     * @return array<string, string>
     */
    private function errors(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors;
        }
        self::fail('expected a refusal');
    }

    /** @return array<string, mixed> */
    private function row(string $pid): array
    {
        return $this->t->app->db()->one('SELECT * FROM vh_bookings WHERE public_id = ?', [$pid]) ?? [];
    }

    private function decide(string $pid, string $user, string $decision, string $comment = ''): void
    {
        $this->t->app->workflow()->decide((string) $this->row($pid)['workflow_id'], (int) $this->t->user($user)['id'], $decision, $comment);
    }

    private function notes(string $user, string $type): int
    {
        return (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = ?', [(int) $this->t->user($user)['id'], $type]);
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
        if ($status === 200 && str_contains($r->headers['Content-Type'] ?? '', 'text/html')) {
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($r->body), $path);
        }
        return $r->body;
    }

    public function testGeoHelpers(): void
    {
        // straight line HQ (DEMO) → Suvarnabhumi ≈ 24 km; symmetric; same point 0
        $km = Geo::straightKm(13.7246, 100.5294, 13.69, 100.7501);
        self::assertEqualsWithDelta(24.1, $km, 0.3);
        self::assertEqualsWithDelta($km, Geo::straightKm(13.69, 100.7501, 13.7246, 100.5294), 1e-9);
        self::assertSame(0.0, Geo::straightKm(13.7, 100.5, 13.7, 100.5));
        self::assertSame([13.7563, 100.5018], Geo::point(' 13.7563, 100.5018 '));
        self::assertNull(Geo::point('100.5, 13.7')); // latitude out of range
        self::assertNull(Geo::point('0,0'));
        self::assertNull(Geo::point('abc'));
        // links: the place marker wins over the view centre; queries; OpenStreetMap; short links carry nothing; other hosts refused
        self::assertSame([13.69, 100.7501], Geo::fromLink(self::SUVARNABHUMI_LINK));
        self::assertSame([13.7563, 100.5018], Geo::fromLink('https://maps.google.com/?q=13.7563,100.5018'));
        self::assertSame([13.7563, 100.5018], Geo::fromLink('https://www.google.com/maps/search/?api=1&query=13.7563%2C100.5018'));
        self::assertSame([13.75, 100.49], Geo::fromLink('https://www.openstreetmap.org/?mlat=13.75&mlon=100.49#map=16/13.75/100.49'));
        self::assertSame([13.751, 100.492], Geo::fromLink('https://www.openstreetmap.org/#map=17/13.751/100.492'));
        self::assertSame([13.7, 100.6], Geo::fromLink('https://maps.apple.com/?ll=13.70,100.60&q=Office'));
        self::assertNull(Geo::fromLink('https://maps.app.goo.gl/AbCdEf123'));
        self::assertTrue(Geo::linkAllowed('https://maps.app.goo.gl/AbCdEf123'));
        self::assertFalse(Geo::linkAllowed('http://maps.google.com/?q=13.7,100.5'));
        self::assertFalse(Geo::linkAllowed('https://evil.example/maps?q=13.7,100.5'));
        self::assertFalse(Geo::linkAllowed('https://maps.google.com.evil.example/?q=13.7,100.5'));
        self::assertFalse(Geo::linkAllowed('https://user@maps.google.com/?q=1,2'));
        self::assertSame('https://tile.openstreetmap.org', VehicleService::origin('https://tile.openstreetmap.org/{z}/{x}/{y}.png'));
        self::assertSame('', VehicleService::origin('http://tile.example/{z}/{x}/{y}.png'));
    }

    public function testRequestApprovalAssignmentTripAndReport(): void
    {
        $app = $this->t->app;
        $db = $app->db();
        $svc = $this->svc();

        // ---- request: validation ----------------------------------------------------------------------------------------
        $e = $this->askErrors('employee.demo1', ['start' => '2026-11-08T09:00']);
        self::assertSame('vehicles.error.past', $e['start']);
        self::assertSame('vehicles.error.end', $this->askErrors('employee.demo1', ['end' => '2026-11-10T08:00'])['end']);
        self::assertSame('vehicles.error.no_vehicle_big', $this->askErrors('employee.demo1', ['passenger_count' => '11'])['passenger_count']);
        self::assertSame('vehicles.error.passengers_count', $this->askErrors('employee.demo1', ['passenger_count' => '1', 'passengers' => "D1-0005\nคุณลูกค้า"])['passengers']);
        self::assertSame('vehicles.error.link', $this->askErrors('employee.demo1', ['to_link' => 'https://evil.example/?q=13.7,100.5'])['to_link']);
        self::assertSame('vehicles.error.point', $this->askErrors('employee.demo1', ['to_point' => '200, 13'])['to_point']);
        self::assertSame('vehicles.error.place_name', $this->askErrors('employee.demo1', ['to_name' => ''])['to_name']);

        // ---- request: the saved place + a pasted Google link; straight-line estimate; manager gets the task --------------------
        $b1 = $this->ask('employee.demo1', ['passengers' => "D1-0005\nคุณลูกค้า (ภายนอก)"]);
        $r = $this->row($b1);
        self::assertSame('pending', $r['status']);
        self::assertMatchesRegularExpression('/^VB2611-\d{4}$/', (string) $r['ref']);
        self::assertSame('13.690000', (string) $r['to_lat']);
        self::assertSame('straight', $r['est_source']);
        self::assertSame('24.2', (string) $r['est_km']); // 24.150 km by hand (haversine, mean radius 6371.0088 km)
        self::assertSame(2, (int) $db->scalar('SELECT COUNT(*) FROM vh_passengers WHERE booking_id = ?', [$r['id']]));
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM vh_passengers WHERE booking_id = ? AND employee_id IS NOT NULL', [$r['id']]));
        $task = $db->scalar("SELECT t.assignee_id FROM workflow_tasks t JOIN workflow_instances i ON i.id = t.instance_id WHERE i.public_id = ? AND t.status = 'open'", [$r['workflow_id']]);
        self::assertSame((int) $this->t->user('manager.demo1')['id'], (int) $task, 'routed to the line manager');
        // the same person cannot ask twice for the same time
        self::assertSame('vehicles.error.own_overlap', $this->askErrors('employee.demo1', ['start' => '2026-11-10T11:00', 'end' => '2026-11-10T13:00'])['start']);
        // round trip doubles the estimate; a distance the requester knows replaces it
        $rt = $this->ask('employee.demo1', ['start' => '2026-11-16T09:00', 'end' => '2026-11-16T10:00', 'round_trip' => '1'], false);
        self::assertSame((string) round(2 * Geo::straightKm(13.7246, 100.5294, 13.69, 100.7501), 1), (string) $this->row($rt)['est_km'], 'doubled before rounding (48.3, not 2 × 24.2)');
        self::assertSame('48.3', (string) $this->row($rt)['est_km']);
        $mn = $this->ask('employee.demo1', ['start' => '2026-11-17T09:00', 'end' => '2026-11-17T10:00', 'est_km_manual' => '31.5'], false);
        self::assertSame(['31.5', 'manual'], [(string) $this->row($mn)['est_km'], $this->row($mn)['est_source']]);
        self::assertSame('draft', $this->row($mn)['status']);
        // a short link carries no point → no estimate, the link is kept
        $sl = $this->ask('employee.demo1', ['start' => '2026-11-18T09:00', 'end' => '2026-11-18T10:00', 'to_link' => 'https://maps.app.goo.gl/AbCdEf123'], false);
        self::assertSame([null, null, 'https://maps.app.goo.gl/AbCdEf123'], [$this->row($sl)['to_lat'], $this->row($sl)['est_km'], $this->row($sl)['to_link']]);

        // privacy: audit and notifications carry no places, pins, links or names
        $audit = (string) $db->scalar("SELECT GROUP_CONCAT(COALESCE(diff_json, '') SEPARATOR ' ') FROM audit_events WHERE action LIKE 'vehicles.%'");
        foreach (['13.69', '100.75', 'goo.gl', 'ท่าอากาศยาน', 'คุณลูกค้า'] as $secret) {
            self::assertStringNotContainsString($secret, $audit);
        }
        // only the requester, approvers, admin and driver may open a booking
        self::assertTrue($svc->booking($this->az('manager.demo1'), $b1)['is_approver']);
        foreach (['hr.demo1', 'hr.demo2', 'driver.demo1'] as $u) {
            try {
                $svc->booking($this->az($u), $b1);
                self::fail($u . ' must not see the booking');
            } catch (HttpException $x) {
                self::assertSame(404, $x->httpStatus);
            }
        }
        self::assertTrue($svc->booking($this->az('ga.demo1'), $b1)['can_manage']);
        self::assertFalse($svc->booking($this->az('ga.demo1'), $b1)['can_assign'], 'not before approval');

        // ---- approval ------------------------------------------------------------------------------------------------------
        $this->decide($b1, 'manager.demo1', 'approve');
        self::assertSame('approved', $this->row($b1)['status']);
        self::assertSame(1, $this->notes('employee.demo1', 'vehicles.approved'));
        self::assertSame(1, $this->notes('ga.demo1', 'vehicles.dispatch'));

        // ---- assignment: repair, seats, then the van with สมชาย ------------------------------------------------------------
        $van = $this->pid('vh_vehicles', 'plate', 'ฮข 1234 (DEMO)');
        $car = $this->pid('vh_vehicles', 'plate', '1กข 5678 (DEMO)');
        $pickup = $this->pid('vh_vehicles', 'plate', 'บค 9012 (DEMO)');
        $somchai = $this->pid('vh_drivers', 'name', 'สมชาย ขับดี (DEMO)');
        $wichai = $this->pid('vh_drivers', 'name', 'วิชัย ใจเย็น (DEMO)');
        $ga = $this->az('ga.demo1');
        self::assertSame('vehicles.error.vehicle_maintenance', $this->errors(fn () => $svc->assign($ga, $b1, ['vehicle' => $pickup, 'driver' => $somchai]))['vehicle']);
        self::assertSame('vehicles.error.driver', $this->errors(fn () => $svc->assign($ga, $b1, ['vehicle' => $van]))['driver']);
        $opts = $svc->options($svc->booking($ga, $b1));
        $flags = array_column($opts['vehicles'], 'flag', 'plate');
        self::assertSame(['ok', 'ok', 'maintenance'], [$flags['ฮข 1234 (DEMO)'], $flags['1กข 5678 (DEMO)'], $flags['บค 9012 (DEMO)']]);
        $svc->assign($ga, $b1, ['vehicle' => $van, 'driver' => $somchai]);
        self::assertSame('assigned', $this->row($b1)['status']);
        self::assertSame(1, $this->notes('employee.demo1', 'vehicles.assigned'));
        self::assertSame(1, $this->notes('driver.demo1', 'vehicles.driver'));
        self::assertTrue($svc->booking($this->az('driver.demo1'), $b1)['is_driver'], 'the driver now sees it');
        self::assertCount(1, $svc->driverJobs($this->az('driver.demo1')));

        // a second request at the same time (manager.demo1 has no manager → vehicle approver ga.demo1)
        $b2 = $this->ask('manager.demo1', ['passenger_count' => '2']);
        $this->decide($b2, 'ga.demo1', 'approve');
        self::assertSame('vehicles.error.vehicle_busy', $this->errors(fn () => $svc->assign($ga, $b2, ['vehicle' => $van, 'driver' => $wichai]))['vehicle']);
        self::assertSame('vehicles.error.driver_busy', $this->errors(fn () => $svc->assign($ga, $b2, ['vehicle' => $car, 'driver' => $somchai]))['driver']);
        $svc->assign($ga, $b2, ['vehicle' => $car, 'driver' => $wichai]);
        // the admin cancels it with a reason; the requester is told
        self::assertSame('vehicles.error.reason', $this->errors(fn () => $svc->cancel($ga, $b2, ''))['reason']);
        $svc->cancel($ga, $b2, 'รถติดภารกิจผู้บริหาร (ทดสอบ)');
        self::assertSame('cancelled', $this->row($b2)['status']);
        self::assertSame(1, $this->notes('manager.demo1', 'vehicles.change'));

        // ---- the trip: start, finish once, odometer moves on, the requester confirms ---------------------------------------
        $drv = $this->az('driver.demo1');
        $this->t->clock->advance('+1 day');
        $this->t->clock->advance('+5 minutes'); // Tue 09:05
        self::assertSame('vehicles.error.odo_back', $this->errors(fn () => $svc->startTrip($drv, $b1, ['odo_start' => '45000']))['odo_start']);
        self::assertSame('vehicles.error.future', $this->errors(fn () => $svc->startTrip($drv, $b1, ['odo_start' => '45210', 'started_at' => '2026-11-10T10:00']))['started_at']);
        $svc->startTrip($drv, $b1, ['odo_start' => '45210', 'started_at' => '2026-11-10T09:00']);
        self::assertSame('in_progress', $this->row($b1)['status']);
        try {
            $svc->assign($ga, $b1, ['vehicle' => $car, 'driver' => $somchai]);
            self::fail('cannot reassign a trip under way');
        } catch (BusinessException $x) {
            self::assertSame('vehicles.error.cannot_change', $x->messageKey);
        }
        $this->t->clock->advance('+3 hours'); // 12:05
        self::assertSame('vehicles.error.odo_end', $this->errors(fn () => $svc->finishTrip($drv, $b1, ['odo_end' => '45210']))['odo_end']);
        self::assertSame('vehicles.error.amount', $this->errors(fn () => $svc->finishTrip($drv, $b1, ['odo_end' => '45262', 'fuel_cost' => '12.345']))['fuel_cost']);
        $svc->finishTrip($drv, $b1, ['odo_end' => '45262', 'ended_at' => '2026-11-10T12:00', 'fuel_litres' => '20.5', 'fuel_cost' => '700', 'other_cost' => '70.00', 'incident' => '']);
        $r = $this->row($b1);
        self::assertSame(['completed', '45262', '20.50', '700.00', '70.00'], [$r['status'], (string) $r['odo_end'], (string) $r['fuel_litres'], (string) $r['fuel_cost'], (string) $r['other_cost']]);
        self::assertSame(45262, (int) $db->scalar('SELECT odometer_km FROM vh_vehicles WHERE public_id = ?', [$van]));
        try {
            $svc->finishTrip($drv, $b1, ['odo_end' => '45300']);
            self::fail('a trip is logged once');
        } catch (BusinessException $x) {
            self::assertSame('vehicles.error.trip_logged', $x->messageKey);
        }
        self::assertSame(1, $this->notes('employee.demo1', 'vehicles.confirm'));
        $svc->confirm($this->az('employee.demo1'), $b1);
        self::assertNotNull($this->row($b1)['confirmed_at']);
        try {
            $svc->confirm($this->az('employee.demo1'), $b1);
            self::fail('confirmed once');
        } catch (BusinessException $x) {
            self::assertSame('vehicles.error.cannot_change', $x->messageKey);
        }

        // ---- outside the driver's hours needs a reason; a no-show frees vehicle and driver -----------------------------------
        $b4 = $this->ask('employee.demo1', ['start' => '2026-11-13T17:30', 'end' => '2026-11-13T18:30', 'passenger_count' => '1', 'to_name' => 'สถานีกลาง', 'to_link' => '',
            'to_place' => $this->pid('vh_places', 'name', 'สถานีกลางกรุงเทพอภิวัฒน์')]);
        self::assertSame('สถานีกลางกรุงเทพอภิวัฒน์', $this->row($b4)['to_name']);
        $this->decide($b4, 'manager.demo1', 'approve');
        $flags = array_column($svc->options($svc->booking($ga, $b4))['drivers'], 'flag', 'name');
        self::assertSame(['hours', 'ok'], [$flags['สมชาย ขับดี (DEMO)'], $flags['วิชัย ใจเย็น (DEMO)']]);
        self::assertSame('vehicles.error.driver_hours', $this->errors(fn () => $svc->assign($ga, $b4, ['vehicle' => $car, 'driver' => $somchai]))['driver']);
        self::assertSame('vehicles.error.reason', $this->errors(fn () => $svc->assign($ga, $b4, ['vehicle' => $car, 'driver' => $somchai, 'outside_hours' => '1']))['reason']);
        $svc->assign($ga, $b4, ['vehicle' => $car, 'driver' => $somchai, 'outside_hours' => '1', 'reason' => 'ส่งผู้บริหารขึ้นรถไฟ (ทดสอบ)']);
        self::assertStringContainsString('ส่งผู้บริหาร', (string) $db->scalar("SELECT note FROM vh_events WHERE booking_id = ? AND event = 'assigned'", [$this->row($b4)['id']]));
        self::assertFalse($svc->booking($ga, $b4)['can_no_show'], 'not before the start');
        $this->t->clock->advance('+3 days');
        $this->t->clock->advance('+6 hours'); // Fri 18:05
        $svc->noShow($ga, $b4, '');
        self::assertSame('no_show', $this->row($b4)['status']);
        self::assertSame(3, $this->notes('driver.demo1', 'vehicles.driver'), 'two jobs + one removed');

        // ---- returned for changes, edited, sent again; rejected; withdrawn while pending ----------------------------------
        $b5 = $this->ask('employee.demo1', ['start' => '2026-11-20T10:00', 'end' => '2026-11-20T11:00']);
        $this->decide($b5, 'manager.demo1', 'return', 'ระบุผู้เดินทางด้วย');
        self::assertSame('draft', $this->row($b5)['status']);
        self::assertTrue($svc->booking($this->az('employee.demo1'), $b5)['can_edit']);
        $res = $svc->save($this->az('employee.demo1'), 0, ['site' => $this->pid('sites', 'code', 'HQ'), 'start' => '2026-11-20T10:00', 'end' => '2026-11-20T11:00', 'purpose' => 'พบลูกค้า (แก้ไข)',
            'passenger_count' => '2', 'passengers' => 'D1-0005', 'from_place' => $this->pid('vh_places', 'name', 'สำนักงานใหญ่ (DEMO)'), 'to_name' => 'ท่าอากาศยาน', 'to_point' => '13.690000, 100.750100'], true, $b5);
        self::assertSame(['pending', 'straight'], [$this->row($res['pid'])['status'], $this->row($b5)['est_source']]);
        self::assertSame('pending', (string) $db->scalar('SELECT status FROM workflow_instances WHERE public_id = ?', [$this->row($b5)['workflow_id']]));
        $this->decide($b5, 'manager.demo1', 'reject', 'ใช้รถตู้ไม่ได้ (ทดสอบ)');
        self::assertSame('rejected', $this->row($b5)['status']);
        $b6 = $this->ask('employee.demo1', ['start' => '2026-11-23T10:00', 'end' => '2026-11-23T11:00']);
        $svc->cancel($this->az('employee.demo1'), $b6, '');
        self::assertSame('cancelled', $this->row($b6)['status']);
        self::assertSame('cancelled', (string) $db->scalar('SELECT status FROM workflow_instances WHERE public_id = ?', [$this->row($b6)['workflow_id']]));
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM vh_events WHERE booking_id = ? AND event = 'cancelled'", [$this->row($b6)['id']]), 'logged once');

        // ---- report and export (numbers only) ------------------------------------------------------------------------------
        $rep = $svc->report($ga, $this->le(), '2026-11');
        $vanRow = array_values(array_filter($rep['vehicles'], static fn (array $x): bool => $x['plate'] === 'ฮข 1234 (DEMO)'))[0];
        self::assertSame([1, 52, '700.00'], [(int) $vanRow['trips'], (int) $vanRow['km'], (string) $vanRow['fuel']]);
        $somRow = array_values(array_filter($rep['drivers'], static fn (array $x): bool => $x['name'] === 'สมชาย ขับดี (DEMO)'))[0];
        self::assertSame([1, 180], [(int) $somRow['trips'], (int) $somRow['minutes']]);
        self::assertSame(1, $rep['statuses']['completed'] ?? 0);
        $x = $svc->export($ga, $this->le(), '2026-11');
        self::assertStringStartsWith('PK', $x['bytes']);
        try {
            $svc->report($this->az('employee.demo1'), $this->le(), '2026-11');
            self::fail('report is for the admin');
        } catch (HttpException $x) {
            self::assertSame(403, $x->httpStatus);
        }

        // ---- location retention: older finished bookings lose places, pins, links and names ------------------------------
        $svc->savePolicy($ga, $this->le(), ['confirm_required' => '1', 'location_keep_days' => '30']);
        self::assertSame('vehicles.error.keep_days', $this->errors(fn () => $svc->savePolicy($ga, $this->le(), ['location_keep_days' => '0']))['location_keep_days']);
        self::assertSame(0, $svc->purge(), 'nothing old enough yet');
        $this->t->clock->advance('+40 days');
        self::assertGreaterThanOrEqual(4, $svc->purge());
        $r = $this->row($b1);
        self::assertSame(['-', null, null, '-'], [$r['to_name'], $r['to_lat'], $r['to_link'], $r['from_name']]);
        self::assertSame(['-'], array_values(array_unique(array_column($db->all('SELECT name FROM vh_passengers WHERE booking_id = ?', [$r['id']]), 'name'))));
        self::assertSame('45262', (string) $r['odo_end'], 'the numbers stay');
        self::assertSame(0, $svc->purge(), 'once');
    }

    public function testAdminSettingsRoadEstimatePagesAndPrivacy(): void
    {
        $app = $this->t->app;
        $db = $app->db();
        $svc = $this->svc();
        $ga = $this->az('ga.demo1');
        $le = $this->le();

        // ---- vehicles, drivers, places, types, policy ----------------------------------------------------------------------
        self::assertSame('vehicles.error.plate_taken', $this->errors(fn () => $svc->saveVehicle($ga, $le, ['plate' => 'ฮข 1234 (DEMO)', 'name' => 'ซ้ำ', 'seats' => '4', 'odometer_km' => '0']))['plate']);
        $nv = $svc->saveVehicle($ga, $le, ['plate' => 'ทด 1111', 'name' => 'รถทดสอบ', 'seats' => '4', 'odometer_km' => '100', 'type' => $this->pid('vh_types', 'name', 'รถเก๋ง')]);
        self::assertSame('vehicles.error.reason', $this->errors(fn () => $svc->setVehicleStatus($ga, $nv, 'maintenance', ''))['status_note']);
        self::assertSame(0, $svc->setVehicleStatus($ga, $nv, 'maintenance', 'เข้าศูนย์ (ทดสอบ)'));
        self::assertSame('maintenance', $db->scalar('SELECT status FROM vh_vehicles WHERE public_id = ?', [$nv]));
        $base = ['name' => 'คนขับทดสอบ', 'licence_expiry' => '2027-12-31', 'work_from' => '08:00', 'work_to' => '17:00', 'work_days' => ['1', '2', '3', '4', '5']];
        self::assertSame('vehicles.error.account', $this->errors(fn () => $svc->saveDriver($ga, $le, $base + ['account' => 'nobody.here']))['account']);
        self::assertSame('vehicles.error.hours', $this->errors(fn () => $svc->saveDriver($ga, $le, ['work_to' => '07:00'] + $base))['work_to']);
        self::assertSame('vehicles.error.days', $this->errors(fn () => $svc->saveDriver($ga, $le, ['work_days' => []] + $base))['work_days']);
        $nd = $svc->saveDriver($ga, $le, $base + ['account' => 'employee.demo1', 'phone' => '081-111-2222']);
        self::assertSame((int) $this->t->user('employee.demo1')['id'], (int) $db->scalar('SELECT user_id FROM vh_drivers WHERE public_id = ?', [$nd]));
        self::assertSame((int) $this->t->user('employee.demo1')['id'], (int) $db->scalar('SELECT user_id FROM vh_drivers WHERE public_id = ?', [$svc->saveDriver($ga, $le, $base + ['account' => 'D1-0004'])]), 'by employee code');
        self::assertSame('vehicles.error.point', $this->errors(fn () => $svc->savePlace($ga, $le, ['name' => 'ลูกค้า', 'point' => '13.7']))['place_point']);
        $np = $svc->savePlace($ga, $le, ['name' => 'ลูกค้า A (ทดสอบ)', 'point' => '13.80, 100.60']);
        $svc->setPlaceActive($ga, $np, false);
        self::assertCount(3, $svc->places($le, true));
        $svc->addType($ga, $le, 'รถบัส');
        self::assertSame('vehicles.error.type_taken', $this->errors(fn () => $svc->addType($ga, $le, 'รถบัส'))['type_name']);
        try {
            $svc->saveVehicle($this->az('employee.demo1'), $le, ['plate' => 'x', 'name' => 'x', 'seats' => '1']);
            self::fail('only the admin');
        } catch (HttpException $x) {
            self::assertSame(403, $x->httpStatus);
        }

        // ---- map settings (system admin) and the road estimate through OpenRouteService (fake network) --------------------
        $sys = $this->az(TestApp::ADMIN);
        try {
            $svc->saveSettings($ga, ['map_enabled' => '1']);
            self::fail('system admin only');
        } catch (HttpException $x) {
            self::assertSame(403, $x->httpStatus);
        }
        $ok = ['map_enabled' => '1', 'tile_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', 'tile_attribution' => '© OpenStreetMap contributors', 'routing' => 'none'];
        self::assertSame('vehicles.error.tile_url', $this->errors(fn () => $svc->saveSettings($sys, ['tile_url' => 'http://tile.example/{z}/{x}/{y}.png'] + $ok))['tile_url']);
        self::assertSame('vehicles.error.tile_url', $this->errors(fn () => $svc->saveSettings($sys, ['tile_url' => 'https://tile.example/x.png'] + $ok))['tile_url']);
        self::assertSame('vehicles.error.ors_key_needed', $this->errors(fn () => $svc->saveSettings($sys, ['routing' => 'ors'] + $ok))['ors_key']);
        $key = '5b3ce3597851110001cf6248aaaabbbbccccddddeeee';
        $svc->saveSettings($sys, ['routing' => 'ors', 'ors_key' => $key] + $ok);
        self::assertTrue($svc->settings()['has_key']);
        self::assertStringNotContainsString($key, (string) $db->scalar('SELECT ors_key_enc FROM vh_settings WHERE id = 1'), 'stored encrypted');
        self::assertStringNotContainsString($key, (string) $db->scalar("SELECT GROUP_CONCAT(COALESCE(diff_json, '')) FROM audit_events WHERE action = 'vehicles.settings_changed'"));
        $seen = [];
        $app->setHttpTransport(new class ($seen) implements HttpTransport {
            /** @param list<array{0: string, 1: array<string, string>}> $seen */
            public function __construct(public array &$seen)
            {
            }

            public function get(string $url, array $headers, int $timeoutSeconds, int $maxBytes): array
            {
                $this->seen[] = [$url, $headers];
                return ['status' => 200, 'body' => '{"type":"FeatureCollection","features":[{"properties":{"summary":{"distance":31234.5,"duration":2460.0}}}]}', 'error' => null, 'ms' => 5];
            }
        });
        $est = $svc->estimate(13.7246, 100.5294, 13.69, 100.7501);
        self::assertSame(['road', 31.2, 41], [$est['source'], round($est['km'], 1), $est['minutes']]);
        self::assertStringContainsString('start=100.529400%2C13.724600&end=100.750100%2C13.690000', $seen[0][0]);
        self::assertSame($key, $seen[0][1]['Authorization']);
        $app->setHttpTransport(new class implements HttpTransport {
            public function get(string $url, array $headers, int $timeoutSeconds, int $maxBytes): array
            {
                return ['status' => 0, 'body' => '', 'error' => 'network:7', 'ms' => 1];
            }
        });
        $est = $svc->estimate(13.7246, 100.5294, 13.69, 100.7501);
        self::assertSame(['straight', true], [$est['source'], $est['road_failed']], 'falls back to a straight line');
        $svc->saveSettings($sys, ['routing' => 'none', 'clear_key' => '1'] + $ok);
        self::assertFalse($svc->settings()['has_key']);

        // ---- pages: CSP allows the tile host only where the map is drawn; everything translated ---------------------------
        $b = $this->t->browser();
        $b->login('employee.demo1');
        $res = $b->get('/vehicles/new');
        self::assertSame(200, $res->status);
        self::assertStringContainsString("img-src 'self' data: blob: https://tile.openstreetmap.org", $res->headers['Content-Security-Policy'] ?? '');
        self::assertStringContainsString('data-vh-map="pick"', $res->body);
        self::assertStringNotContainsString('tile.openstreetmap.org', $b->get('/vehicles')->headers['Content-Security-Policy'] ?? '');
        $res = $b->post('/vehicles/new', ['le' => $this->pid('legal_entities', 'code', 'DEMO1'), 'site' => $this->pid('sites', 'code', 'HQ'), 'start' => '2026-11-11T13:00', 'end' => '2026-11-11T15:00',
            'purpose' => 'ประชุมนอกสถานที่ (ทดสอบ)', 'passenger_count' => '2', 'from_place' => $this->pid('vh_places', 'name', 'สำนักงานใหญ่ (DEMO)'), 'to_name' => 'ลูกค้า',
            'to_point' => '13.80, 100.60', 'go' => 'submit']);
        self::assertSame(303, $res->status);
        $pid = (string) $db->scalar("SELECT public_id FROM vh_bookings WHERE purpose = 'ประชุมนอกสถานที่ (ทดสอบ)'");
        self::assertSame('pending', $this->row($pid)['status']);
        $res = $b->post('/vehicles/new', ['le' => $this->pid('legal_entities', 'code', 'DEMO1'), 'site' => $this->pid('sites', 'code', 'HQ'), 'start' => 'x', 'go' => 'submit']);
        self::assertSame(422, $res->status);
        $body = $this->page('employee.demo1', '/vehicles/b/' . $pid);
        self::assertStringContainsString('data-vh-map="view"', $body);
        $this->page('employee.demo1', '/vehicles');
        $this->page('manager.demo1', '/vehicles/b/' . $pid);
        $this->decide($pid, 'manager.demo1', 'approve');
        $body = $this->page('ga.demo1', '/vehicles/b/' . $pid);
        self::assertStringContainsString('/assign', $body);
        $b2 = $this->t->browser();
        $b2->login('ga.demo1');
        $res = $b2->post('/vehicles/b/' . $pid . '/assign', ['vehicle' => $this->pid('vh_vehicles', 'plate', 'ฮข 1234 (DEMO)'), 'driver' => $this->pid('vh_drivers', 'name', 'สมชาย ขับดี (DEMO)')]);
        self::assertSame(303, $res->status);
        self::assertSame('assigned', $this->row($pid)['status']);
        $body = $this->page('driver.demo1', '/vehicles');
        self::assertStringContainsString($this->row($pid)['ref'], $body);
        $this->page('driver.demo1', '/vehicles/b/' . $pid);
        $this->page('ga.demo1', '/vehicles/board');
        $this->page('ga.demo1', '/vehicles/admin');
        $this->page('ga.demo1', '/vehicles/v/' . $nv);
        $this->page('ga.demo1', '/vehicles/d/' . $nd);
        $this->page('ga.demo1', '/vehicles/report?month=2026-11');
        $this->page('ga.demo1', '/vehicles/report/export?month=2026-11');
        $this->page(TestApp::ADMIN, '/vehicles/settings');
        $this->page('employee.demo1', '/vehicles/settings', 403);
        $this->page('employee.demo1', '/vehicles/board', 403);
        $this->page('hr.demo1', '/vehicles/b/' . $pid, 404);
        $this->page('employee.demo1', '/vehicles/v/' . $nv, 404);
        self::assertStringNotContainsString($key, $this->page(TestApp::ADMIN, '/vehicles/settings'));

        // ---- personal data ----------------------------------------------------------------------------------------------------
        $uid = (int) $this->t->user('employee.demo1')['id'];
        $exp = VehiclesPersonalData::export($app, $uid, 4);
        self::assertSame('requests', $exp[0]['key']);
        self::assertCount(1, $exp[0]['rows']);
        $dry = VehiclesPersonalData::erase($app, $uid, 4, (int) $this->t->user(TestApp::ADMIN)['id'], true);
        self::assertSame(1, $dry['done']['vehicles.privacy.cancelled'] ?? 0);
        VehiclesPersonalData::erase($app, $uid, 4, (int) $this->t->user(TestApp::ADMIN)['id'], false);
        $r = $this->row($pid);
        self::assertSame(['cancelled', '-', '-', null], [$r['status'], $r['purpose'], $r['to_name'], $r['to_lat']]);
    }
}
