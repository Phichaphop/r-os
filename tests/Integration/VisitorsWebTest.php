<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use Blue\Core\Support\Ulid;
use Blue\Modules\Visitors\VisitorService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;
use PHPUnit\Framework\TestCase;

/**
 * 0.43.0 (R6-2): M43 visitors — host invitation with a time-limited QR link, gate check-in (QR only opens; notice
 * required; deny list), check-out, walk-in, cancel/deny, overdue notice and retention (both only when the company sets
 * them), expired QR, other companies and plain employees kept out.
 */
final class VisitorsWebTest extends TestCase
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

    private function as(string $user): TestBrowser
    {
        $b = $this->t->browser();
        $b->login($user);
        return $b;
    }

    private function le(string $code): int
    {
        return (int) $this->t->app->db()->scalar('SELECT id FROM legal_entities WHERE code = ?', [$code]);
    }

    private function local(string $modify): string
    {
        return $this->t->app->clock->now()->setTimezone(new \DateTimeZone('Asia/Bangkok'))->modify($modify)->format('Y-m-d\TH:i');
    }

    /** Desk staff without the admin permission: tech.demo1 gets the safety officer role (visitors.gate only). */
    private function gateOnly(): void
    {
        $db = $this->t->app->db();
        $uid = (int) $this->t->user('tech.demo1')['id'];
        $now = $this->t->app->clock->now()->format('Y-m-d H:i:s.u');
        $db->run("INSERT INTO user_role_assignments (public_id, created_at, updated_at, row_version, user_id, role_id, status, valid_from, reason)
            VALUES (?, ?, ?, 1, ?, (SELECT id FROM roles WHERE code = 'SFO'), 'active', ?, 'test')", [Ulid::generate($this->t->app->clock), $now, $now, $uid, $this->t->app->clock->now()->format('Y-m-d')]);
        $db->run('INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, ?, ?)', [(int) $db->pdo()->lastInsertId(), 'legal_entity', $this->le('DEMO1')]);
    }

    public function testInvitationGateCheckInOutAndPublicPage(): void
    {
        $db = $this->t->app->db();
        $host = $this->as('employee.demo1');
        self::assertSame(200, $host->get('/visitors')->status);
        self::assertSame(403, $host->get('/visitors/gate')->status, 'employees are not desk staff');
        self::assertStringContainsString('ยังไม่ได้เผยแพร่ประกาศความเป็นส่วนตัวสำหรับผู้มาติดต่อ', $host->get('/visitors')->body);

        self::assertSame(422, $host->post('/visitors', ['visitor_name' => 'ส', 'purpose' => '', 'expected_from' => $this->local('+1 hour'), 'expected_to' => $this->local('+9 days')])->status);
        $host->get('/visitors');
        $res = $host->post('/visitors', ['visitor_name' => 'นายสมชาย ผู้มาเยือน', 'visitor_company' => 'บริษัทคู่ค้า (ทดสอบ)', 'purpose' => 'ประชุมโครงการ', 'people' => '2',
            'expected_from' => $this->local('+1 hour'), 'expected_to' => $this->local('+5 hours'), 'vehicle_plate' => 'กข 1234']);
        self::assertSame(303, $res->status);
        $v = $db->one("SELECT * FROM vs_visits WHERE visitor_name = 'นายสมชาย ผู้มาเยือน'");
        self::assertMatchesRegularExpression('/^VS\d{4}-0001$/', (string) $v['ref']);
        self::assertSame(32, strlen((string) $v['qr_token']));
        self::assertNull($v['vehicle_plate'], 'vehicles are recorded only when the company turns it on');
        self::assertStringNotContainsString('สมชาย', (string) $db->scalar("SELECT GROUP_CONCAT(IFNULL(diff_json, '')) FROM audit_events WHERE action LIKE 'visitors.%'"), 'no visitor names in the audit');
        $page = $host->get('/visitors/v/' . $v['public_id'])->body;
        self::assertStringContainsString('/visit/' . $v['qr_token'], $page, 'host sees the link to send');
        self::assertStringContainsString('<svg', $page);

        // the visitor's page: no sign-in, shows the visit, QR and (once published) the notice link
        $g = $this->t->browser();
        $pub = $g->get('/visit/' . $v['qr_token']);
        self::assertSame(200, $pub->status);
        self::assertStringContainsString((string) $v['ref'], $pub->body);
        self::assertSame(404, $g->get('/visit/' . str_repeat('0', 32))->status);

        // other people cannot see it
        self::assertSame(404, $this->as('manager.demo1')->get('/visitors/v/' . $v['public_id'])->status);
        self::assertSame(404, $this->as('hr.demo2')->get('/visitors/v/' . $v['public_id'])->status);

        // desk: the QR link opens the record; check-in needs the notice
        $ga = $this->as('ga.demo1');
        $open = $ga->get('/visitors/gate', ['code' => 'https://x.example/visit/' . $v['qr_token']]);
        self::assertSame(303, $open->status);
        self::assertStringEndsWith('/visitors/v/' . $v['public_id'], (string) $open->headers['Location']);
        self::assertSame(303, $ga->get('/visitors/gate', ['code' => (string) $v['ref']])->status);
        $ga->get('/visitors/v/' . $v['public_id']);
        self::assertSame(422, $ga->post('/visitors/v/' . $v['public_id'] . '/checkin', ['badge_no' => 'V-07'])->status);
        $ga->get('/visitors/v/' . $v['public_id']);
        self::assertSame(303, $ga->post('/visitors/v/' . $v['public_id'] . '/checkin', ['notice_given' => '1', 'badge_no' => 'V-07'])->status);
        $in = $db->one('SELECT * FROM vs_visits WHERE id = ?', [$v['id']]);
        self::assertSame(['checked_in', 1, 'V-07'], [$in['status'], (int) $in['notice_given'], $in['badge_no']]);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'visitors.arrived' AND user_id = ?", [(int) $this->t->user('employee.demo1')['id']]));
        $ga->get('/visitors/v/' . $v['public_id']);
        $ga->post('/visitors/v/' . $v['public_id'] . '/checkin', ['notice_given' => '1']);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM vs_events WHERE visit_id = ? AND action LIKE 'checked_in%'", [$v['id']]), 'no double check-in');
        self::assertStringContainsString('นายสมชาย ผู้มาเยือน', $ga->get('/visitors/gate')->body, 'on the day list');
        $ga->get('/visitors/v/' . $v['public_id']);
        self::assertSame(303, $ga->post('/visitors/v/' . $v['public_id'] . '/checkout', [])->status);
        self::assertSame('checked_out_no_badge', $db->scalar('SELECT action FROM vs_events WHERE visit_id = ? ORDER BY id DESC LIMIT 1', [$v['id']]));
        self::assertSame(0, (int) $db->scalar('SELECT badge_returned FROM vs_visits WHERE id = ?', [$v['id']]));
        self::assertSame(404, $g->get('/visit/' . $v['qr_token'])->status, 'link ends when the visit is over');
    }

    public function testWalkInDenyListCancelExpiryOverdueAndRetention(): void
    {
        $db = $this->t->app->db();
        $le = $this->le('DEMO1');
        $this->gateOnly();
        $ga = $this->as('ga.demo1');
        // settings: nothing by default
        self::assertSame(['retention_days' => null, 'overdue_minutes' => null, 'record_vehicle' => false], VisitorService::of($this->t->app)->settings($le));
        $ga->get('/visitors/admin');
        self::assertSame(422, $ga->post('/visitors/admin/blacklist', ['le' => (string) $le, 'name' => 'นายดำ ห้ามเข้า', 'reason' => 'สั้น'])->status);
        $ga->get('/visitors/admin');
        self::assertSame(303, $ga->post('/visitors/admin/blacklist', ['le' => (string) $le, 'name' => 'นายดำ ห้ามเข้า', 'company' => 'X', 'reason' => 'เคยก่อเหตุทะเลาะวิวาทในพื้นที่ (ทดสอบ)'])->status);

        // walk-in with a name that matches (title / spaces ignored)
        $desk = $this->as('tech.demo1');
        $desk->get('/visitors/gate');
        self::assertSame(422, $desk->post('/visitors/gate/walk-in', ['le' => (string) $le, 'visitor_name' => 'ดำ  ห้ามเข้า', 'purpose' => 'ส่งของ', 'host' => 'ไม่มีคนนี้'])->status);
        $desk->get('/visitors/gate');
        $empCode = (string) $db->scalar('SELECT e.employee_code FROM users u JOIN employees e ON e.id = u.employee_id WHERE u.username = ?', ['employee.demo1']);
        self::assertSame(303, $desk->post('/visitors/gate/walk-in', ['le' => (string) $le, 'visitor_name' => 'ดำ  ห้ามเข้า', 'purpose' => 'ส่งของ', 'host' => $empCode . ' — x', 'hours' => '1'])->status);
        $w = $db->one("SELECT * FROM vs_visits WHERE kind = 'walk_in'");
        self::assertNull($w['qr_token']);
        $page = $desk->get('/visitors/v/' . $w['public_id'])->body;
        self::assertStringContainsString('รายชื่อห้ามเข้า', $page);
        $desk->post('/visitors/v/' . $w['public_id'] . '/checkin', ['notice_given' => '1']);
        self::assertSame('invited', $db->scalar('SELECT status FROM vs_visits WHERE id = ?', [$w['id']]), 'desk staff cannot let a listed person in');
        $ga->get('/visitors/v/' . $w['public_id']);
        self::assertSame(422, $ga->post('/visitors/v/' . $w['public_id'] . '/checkin', ['notice_given' => '1', 'override_note' => 'สั้น'])->status);
        $ga->get('/visitors/v/' . $w['public_id']);
        self::assertSame(303, $ga->post('/visitors/v/' . $w['public_id'] . '/checkin', ['notice_given' => '1', 'override_note' => 'ผู้จัดการอนุญาตให้ส่งของที่ป้อมเท่านั้น (ทดสอบ)'])->status);
        self::assertSame('checked_in_override', $db->scalar("SELECT action FROM vs_events WHERE visit_id = ? AND action LIKE 'checked_in%'", [$w['id']]));

        // host cancels; desk denies (reason needed, host told)
        $host = $this->as('employee.demo1');
        $host->get('/visitors');
        $host->post('/visitors', ['visitor_name' => 'คุณหนึ่ง', 'purpose' => 'สัมภาษณ์', 'expected_from' => $this->local('+1 hour'), 'expected_to' => $this->local('+2 hours')]);
        $host->get('/visitors');
        $host->post('/visitors', ['visitor_name' => 'คุณสอง', 'purpose' => 'สัมภาษณ์', 'expected_from' => $this->local('+1 hour'), 'expected_to' => $this->local('+2 hours')]);
        $one = $db->one("SELECT * FROM vs_visits WHERE visitor_name = 'คุณหนึ่ง'");
        $two = $db->one("SELECT * FROM vs_visits WHERE visitor_name = 'คุณสอง'");
        $host->get('/visitors/v/' . $one['public_id']);
        self::assertSame(422, $host->post('/visitors/v/' . $one['public_id'] . '/cancel', ['reason' => ''])->status);
        $host->get('/visitors/v/' . $one['public_id']);
        $host->post('/visitors/v/' . $one['public_id'] . '/cancel', ['reason' => 'เลื่อนนัด']);
        self::assertSame(['cancelled', null], array_values($db->one('SELECT status, qr_token FROM vs_visits WHERE id = ?', [$one['id']])));
        $host->get('/visitors/v/' . $two['public_id']);
        $host->post('/visitors/v/' . $two['public_id'] . '/deny', ['reason' => 'ไม่ใช่คนเดิม']);
        self::assertSame('invited', $db->scalar('SELECT status FROM vs_visits WHERE id = ?', [$two['id']]), 'hosts cannot deny at the desk');
        $desk->get('/visitors/v/' . $two['public_id']);
        $desk->post('/visitors/v/' . $two['public_id'] . '/deny', ['reason' => 'ไม่มีเอกสารยืนยันตัวตน']);
        self::assertSame('denied', $db->scalar('SELECT status FROM vs_visits WHERE id = ?', [$two['id']]));
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'visitors.denied'"));

        // expired QR: invitation whose window has passed cannot be opened at the desk, then the job expires it
        $host->get('/visitors');
        $host->post('/visitors', ['visitor_name' => 'คุณสาม', 'purpose' => 'ส่งเอกสาร', 'expected_from' => $this->local('+1 hour'), 'expected_to' => $this->local('+2 hours')]);
        $three = $db->one("SELECT * FROM vs_visits WHERE visitor_name = 'คุณสาม'");
        $db->run('UPDATE vs_visits SET expected_from = expected_from - INTERVAL 1 DAY, expected_to = expected_to - INTERVAL 1 DAY WHERE id = ?', [$three['id']]);
        $desk->get('/visitors/gate', ['code' => (string) $three['qr_token']]);
        self::assertStringContainsString('หมดเวลานัด', $desk->get('/visitors/gate')->body);
        $svc = VisitorService::of($this->t->app);
        self::assertSame(1, $svc->tick()['expired']);
        self::assertSame('expired', $db->scalar('SELECT status FROM vs_visits WHERE id = ?', [$three['id']]));

        // overdue only when set; then once
        $db->run('UPDATE vs_visits SET expected_to = expected_to - INTERVAL 3 HOUR WHERE id = ?', [$w['id']]);
        self::assertSame(0, $svc->tick()['overdue']);
        $ga->get('/visitors/admin');
        self::assertSame(422, $ga->post('/visitors/admin/settings', ['le' => (string) $le, 'retention_days' => '0', 'overdue_minutes' => '30'])->status);
        $ga->get('/visitors/admin');
        self::assertSame(303, $ga->post('/visitors/admin/settings', ['le' => (string) $le, 'retention_days' => '30', 'overdue_minutes' => '30', 'record_vehicle' => '1'])->status);
        self::assertSame(1, $svc->tick()['overdue']);
        self::assertSame(0, $svc->tick()['overdue']);
        self::assertGreaterThanOrEqual(2, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'visitors.overdue'"), 'host and desk staff');

        // retention: finished visits older than 30 days lose the identity, counts stay
        $db->run('UPDATE vs_visits SET expected_from = expected_from - INTERVAL 40 DAY, expected_to = expected_to - INTERVAL 40 DAY WHERE id IN (?, ?)', [$one['id'], $two['id']]);
        self::assertSame(2, $svc->tick()['purged']);
        $p = $db->one('SELECT visitor_name, purpose, people, purged_at FROM vs_visits WHERE id = ?', [$two['id']]);
        self::assertNull($p['visitor_name']);
        self::assertNull($p['purpose']);
        self::assertSame(1, (int) $p['people']);
        self::assertNotNull($p['purged_at']);
        self::assertNotNull($db->scalar('SELECT visitor_name FROM vs_visits WHERE id = ?', [$w['id']]), 'still on site = kept');
        self::assertSame(200, $ga->get('/visitors/admin')->status);
        self::assertSame(403, $host->get('/visitors/admin')->status);
    }
}
