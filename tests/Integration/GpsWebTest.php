<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\ValidationException;
use Blue\Modules\Gps\GpsService;
use Blue\Tests\Support\TestApp;

/** Phase 5C (DEC-71): M57 GPS check-in — policy + notice, geofence, flags, review, hand-over to attendance, privacy, retention. */
final class GpsWebTest extends TestCase
{
    private TestApp $t;

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

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    /** @param array<string, string> $over */
    private function policy(array $over = [], string $from = '2026-11-01'): void
    {
        $svc = GpsService::of($this->t->app);
        $in = $over + ['outside' => 'flag', 'max_accuracy_m' => '100', 'offline_hours' => '4', 'groups' => '',
            'notice' => 'บริษัทเก็บพิกัดเฉพาะตอนกดลงเวลา เพื่อยืนยันการทำงานนอกสถานที่ ดูได้เฉพาะหัวหน้าและ HR (ทดสอบ)'];
        $cv = $svc->proposePolicy($this->az('hr.demo1'), $this->le(), $in, $from, 'นโยบายความเป็นส่วนตัวพนักงาน (ทดสอบ)', 'เปิดใช้ทดสอบ');
        $inst = (string) $this->t->app->db()->scalar('SELECT i.public_id FROM workflow_instances i JOIN config_versions v ON v.workflow_instance_id = i.id WHERE v.public_id = ?', [$cv]);
        $this->t->app->workflow()->decide($inst, (int) $this->t->user('hr.head1')['id'], 'approve');
    }

    /** @return array<string, string> a reading near the DEMO point (13.7563, 100.5018, 300 m) */
    private function reading(string $dir, string $event, float $lat = 13.7565, float $lng = 100.5019, int $acc = 20, ?int $ms = null, string $offline = '0'): array
    {
        return ['direction' => $dir, 'lat' => (string) $lat, 'lng' => (string) $lng, 'accuracy' => (string) $acc, 'captured' => (string) ($ms ?? $this->t->clock->now()->getTimestamp() * 1000),
            'event' => $event, 'offline' => $offline, 'note' => ''];
    }

    public function testPolicyNoticeAndCheckInInsideTheArea(): void
    {
        $svc = GpsService::of($this->t->app);
        $me = $this->az('employee.demo1');
        $db = $this->t->app->db();
        self::assertFalse($svc->state($me)['eligible'], 'no policy = off');
        try {
            $svc->punch($me, $this->reading('in', 'evt-0000000001'));
            self::fail('off');
        } catch (BusinessException $e) {
            self::assertSame('gps.error.not_enabled', $e->messageKey);
        }
        try {
            $svc->proposePolicy($this->az('hr.demo1'), $this->le(), ['outside' => 'flag', 'max_accuracy_m' => '5', 'offline_hours' => '100', 'groups' => 'a b!', 'notice' => 'สั้น'], '2026-11-01', 'ref', 'x');
            self::fail('invalid policy');
        } catch (ValidationException $e) {
            foreach (['max_accuracy_m', 'offline_hours', 'groups', 'notice', 'source_reference', 'reason'] as $f) {
                self::assertArrayHasKey($f, $e->errors, $f);
            }
        }
        $this->policy();
        $s = $svc->state($me);
        self::assertTrue($s['eligible']);
        self::assertTrue($s['needs_notice']);
        self::assertSame(1, $s['points'], 'DEMO point');
        try {
            $svc->punch($me, $this->reading('in', 'evt-0000000001'));
            self::fail('notice first');
        } catch (BusinessException $e) {
            self::assertSame('gps.error.notice', $e->messageKey);
        }
        $svc->acknowledge($me);
        self::assertFalse($svc->state($me)['needs_notice']);

        $r = $svc->punch($me, $this->reading('in', 'evt-0000000001'));
        self::assertSame(['validated', []], [$r['status'], $r['flags']]);
        $p = $db->one('SELECT * FROM gps_punches WHERE public_id = ?', [$r['pid']]);
        self::assertSame(1, (int) $p['inside']);
        self::assertLessThan(40, (int) $p['distance_m']);
        self::assertSame('2026-11-09 09:00:00', $p['local_time'], 'Bangkok time');
        $raw = $db->one("SELECT * FROM att_raw_punches WHERE source = 'gps' AND source_event_id = ?", ['gps:' . $r['pid']]);
        self::assertNotNull($raw, 'sent to attendance');
        self::assertSame(['gps', 'in', '2026-11-09 02:00:00'], [$raw['verify_method'], $raw['direction'], $raw['occurred_at_utc']]);
        $again = $svc->punch($me, $this->reading('in', 'evt-0000000001'));
        self::assertTrue($again['repeated']);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM gps_punches WHERE client_event_id = 'evt-0000000001'"));
        try {
            $svc->punch($me, $this->reading('in', 'evt-0000000002'));
            self::fail('same direction within a minute');
        } catch (BusinessException $e) {
            self::assertSame('gps.error.too_soon', $e->messageKey);
        }
        $day = $db->one("SELECT * FROM att_days WHERE employee_id = (SELECT id FROM employees WHERE employee_code = 'D1-0004') AND work_date = '2026-11-09'");
        self::assertNotNull($day);
        self::assertNotNull($day['first_in'], 'the GPS punch counts in the daily result');
    }

    public function testOutOfAreaIsHeldReviewedAndPrivacyIsKept(): void
    {
        $this->policy();
        $svc = GpsService::of($this->t->app);
        $me = $this->az('employee.demo1');
        $db = $this->t->app->db();
        $svc->acknowledge($me);
        $r = $svc->punch($me, $this->reading('out', 'evt-far-000001', 13.8000, 100.5500, 15));
        self::assertSame('flagged', $r['status']);
        self::assertContains('outside', $r['flags']);
        self::assertNull($db->scalar("SELECT id FROM att_raw_punches WHERE source = 'gps'"), 'held, not counted yet');
        self::assertFalse($svc->canReview($me, $db->one('SELECT * FROM gps_punches WHERE public_id = ?', [$r['pid']]) ?? []), 'never one\'s own');

        $mgr = $this->az('manager.demo1');
        self::assertCount(1, $svc->reviewList($mgr), 'line manager sees it');
        $view = $svc->get($mgr, $r['pid']);
        self::assertSame('reviewer', $view['access']);
        self::assertTrue($view['show_location'], 'coordinates while waiting for review');
        try {
            $svc->review($mgr, $r['pid'], 'reject', 'no');
            self::fail('reason');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('review_note', $e->errors);
        }
        $svc->review($mgr, $r['pid'], 'approve', 'ไปพบลูกค้าตามที่แจ้ง');
        self::assertSame('approved', $db->scalar('SELECT status FROM gps_punches WHERE public_id = ?', [$r['pid']]));
        self::assertNotNull($db->scalar("SELECT id FROM att_raw_punches WHERE source = 'gps' AND source_event_id = ?", ['gps:' . $r['pid']]));
        $after = $svc->get($this->az('hr.demo1'), $r['pid']);
        self::assertFalse($after['show_location'], 'decided = no coordinates for reviewers');
        self::assertNull($after['lat']);
        self::assertTrue($svc->get($me, $r['pid'])['show_location'], 'the employee still sees their own');
        try {
            $svc->review($mgr, $r['pid'], 'approve', '');
            self::fail('twice');
        } catch (BusinessException $e) {
            self::assertSame('gps.error.state', $e->messageKey);
        }
        $rows = $svc->report($this->az('hr.demo1'), '2026-11');
        self::assertSame([1, 0, 1], [$rows[0]['punches'], $rows[0]['inside'], $rows[0]['outside']]);
        self::assertArrayNotHasKey('lat', $rows[0], 'no coordinates in the report');
    }

    public function testOfflineAccuracyBlockModeAndRetention(): void
    {
        $this->policy();
        $svc = GpsService::of($this->t->app);
        $me = $this->az('employee.demo1');
        $db = $this->t->app->db();
        $svc->acknowledge($me);
        $now = $this->t->clock->now()->getTimestamp() * 1000;
        try {
            $svc->punch($me, $this->reading('in', 'evt-old-000001', ms: $now - 5 * 3600 * 1000, offline: '1'));
            self::fail('older than 4 h');
        } catch (BusinessException $e) {
            self::assertSame('gps.error.too_old', $e->messageKey);
        }
        $off = $svc->punch($me, $this->reading('in', 'evt-off-000001', ms: $now - 3600 * 1000, offline: '1'));
        self::assertSame(['flagged', ['offline']], [$off['status'], $off['flags']]);
        self::assertSame('2026-11-09 01:00:00', $db->scalar('SELECT punch_at_utc FROM gps_punches WHERE public_id = ?', [$off['pid']]), 'device time for offline');
        $this->t->clock->advance('+2 hours');
        $imp = $svc->punch($me, $this->reading('out', 'evt-acc-000001', acc: 500));
        self::assertContains('accuracy', $imp['flags']);
        self::assertSame('flagged', $imp['status']);

        // block mode refuses out of area (logged without coordinates)
        $this->policy(['outside' => 'block'], '2026-11-09');
        $svc->acknowledge($me); // a new policy version needs a new acknowledgement
        $this->t->clock->advance('+2 hours');
        try {
            $svc->punch($me, $this->reading('in', 'evt-blk-000001', 13.9, 100.7));
            self::fail('blocked');
        } catch (BusinessException $e) {
            self::assertSame('gps.error.outside', $e->messageKey);
        }
        self::assertNotNull($db->scalar("SELECT id FROM audit_events WHERE action = 'gps.blocked'"));

        // retention: nothing is cleared without an approved rule
        self::assertSame(0, $svc->anonymize());
        $db->run("UPDATE retention_rules SET status = 'approved', duration_months = 1, disposition_action = 'anonymize', effective_from = '2026-01-01' WHERE record_class = 'gps_selfie'");
        $db->run("UPDATE gps_punches SET work_date = '2026-09-01' WHERE public_id = ?", [$imp['pid']]);
        $db->run("UPDATE gps_punches SET status = 'rejected' WHERE public_id = ?", [$imp['pid']]);
        self::assertSame(1, $svc->anonymize());
        $p = $db->one('SELECT lat, lng, ip, anonymized_at, inside FROM gps_punches WHERE public_id = ?', [$imp['pid']]);
        self::assertNull($p['lat']);
        self::assertNotNull($p['anonymized_at']);
    }

    public function testScreensRenderAndOnlyThisPageMayAskForTheLocation(): void
    {
        $this->policy();
        $b = $this->t->browser();
        $b->login('employee.demo1');
        $page = $b->get('/gps');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('ประกาศเรื่องการเก็บตำแหน่ง', $page->body);
        self::assertStringContainsString('geolocation=(self)', (string) ($page->headers['Permissions-Policy'] ?? ''));
        self::assertStringContainsString('geolocation=()', (string) ($b->get('/eid')->headers['Permissions-Policy'] ?? ''), 'other pages keep it off');
        self::assertSame(303, $b->post('/gps/notice')->status);
        $page = $b->get('/gps');
        self::assertStringContainsString('data-gps-form', $page->body);
        $res = $b->post('/gps/punch', $this->reading('in', 'evt-web-000001') + ['_ajax' => '1']);
        self::assertSame(200, $res->status);
        self::assertStringContainsString('"ok":true', $res->body);
        self::assertSame(403, $b->get('/gps/admin')->status);

        $hr = $this->t->browser();
        $hr->login('hr.demo1');
        foreach (['/gps/admin', '/gps/report', '/gps/review'] as $path) {
            $p = $hr->get($path);
            self::assertSame(200, $p->status, $path);
            self::assertDoesNotMatchRegularExpression('/\bgps\.[a-z_]+\.[a-z_.]+\b(?![^<]*>)/', strip_tags($p->body), 'no raw keys on ' . $path);
        }
        $pid = (string) $this->t->app->db()->scalar('SELECT public_id FROM gps_punches LIMIT 1');
        self::assertSame(200, $b->get('/gps/p/' . $pid)->status);
        $res = $hr->post('/gps/admin/point', ['legal_entity' => (string) $this->t->app->db()->scalar("SELECT public_id FROM legal_entities WHERE code = 'DEMO1'"), 'name' => 'คลังสินค้า (ทดสอบ)',
            'site' => '', 'lat' => '13.7', 'lng' => '100.6', 'radius_m' => '150']);
        self::assertSame(303, $res->status);
        self::assertSame(2, (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM gps_geofences'));
    }
}
