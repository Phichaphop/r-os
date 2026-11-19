<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use Blue\Core\Support\Ulid;
use Blue\Modules\Complaints\ComplaintService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;
use PHPUnit\Framework\TestCase;

/**
 * 0.44.0 (R6-3): M16 anonymous complaints — intake gate (notice + legal confirmation + two handlers), a submission that keeps
 * no sender (no user id, day-only times and public id, no audit, metadata-free pictures, hashed code), tracking with the
 * code signed in and on the public page, the anonymous message box, conflict-of-interest acceptance, restricted notes,
 * outcome + two-way rating, reopen, the alternate route, daily limit, wrong-code limit, next-day digest, SLA notices,
 * retention and small-group suppression in the figures.
 */
final class ComplaintsWebTest extends TestCase
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

    /** The privacy centre's notice for complainants, published (R6-1 is tested on its own). */
    private function publishNotice(): void
    {
        $now = $this->t->app->clock->now()->format('Y-m-d H:i:s.u');
        $this->t->app->db()->run(
            "INSERT INTO pv_notices (public_id, created_at, updated_at, legal_entity_id, audience, version, status, title, body, effective_from) VALUES (?, ?, ?, ?, 'complainant', 1, 'published', 'ประกาศผู้ร้องเรียน (ทดสอบ)', 'ข้อความทดสอบ', ?)",
            [Ulid::generate($this->t->app->clock), $now, $now, $this->le('DEMO1'), $this->t->app->clock->now()->format('Y-m-d')]
        );
    }

    private function openIntake(TestBrowser $mgr): void
    {
        $this->publishNotice();
        self::assertSame(303, $mgr->post('/complaints/settings/open', ['le' => $this->le('DEMO1'), 'legal_checked' => '1', 'legal_reviewer' => 'ที่ปรึกษากฎหมาย (ทดสอบ)', 'legal_reviewed_on' => '2026-11-01'])->status);
    }

    /** A JPEG carrying a comment segment that stands in for camera / GPS metadata. */
    private function jpegWithMetadata(): string
    {
        $img = imagecreatetruecolor(40, 30);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagejpeg($img);
        $jpg = (string) ob_get_clean();
        $com = 'SECRET-CAMERA-GPS 13.7563,100.5018';
        return substr($jpg, 0, 2) . "\xFF\xFE" . pack('n', strlen($com) + 2) . $com . substr($jpg, 2);
    }

    private function code(string $body): string
    {
        self::assertSame(1, preg_match('/id="cp-code-value">([0-9A-Z-]{24})</', $body, $m), 'the code is shown once');
        return $m[1];
    }

    public function testIntakeSubmitTrackHandleAndClose(): void
    {
        $db = $this->t->app->db();
        $emp = $this->as('employee.demo1');
        self::assertStringContainsString('ยังไม่เปิดรับเรื่องร้องเรียน', $emp->get('/complaints')->body);
        self::assertSame(422, $emp->post('/complaints', ['category' => 'harassment', 'title' => 'หัวข้อทดสอบ', 'body' => str_repeat('ก', 30), 'ack' => '1'])->status, 'intake closed');

        // the intake opens only with the legal confirmation and the complainant notice
        $mgr = $this->as('hr.head1');
        self::assertSame(200, $mgr->get('/complaints/settings')->status);
        self::assertSame(422, $mgr->post('/complaints/settings/open', ['le' => $this->le('DEMO1'), 'legal_reviewer' => 'ท', 'legal_reviewed_on' => '2099-01-01'])->status);
        self::assertSame(303, $mgr->post('/complaints/settings/open', ['le' => $this->le('DEMO1'), 'legal_checked' => '1', 'legal_reviewer' => 'ที่ปรึกษากฎหมาย', 'legal_reviewed_on' => '2026-11-01'])->status);
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM cp_settings WHERE intake_open = 1'), 'no complainant notice yet');
        $this->openIntake($mgr);
        self::assertSame(1, (int) $db->scalar('SELECT intake_open FROM cp_settings WHERE legal_entity_id = ?', [$this->le('DEMO1')]));
        self::assertSame(403, $emp->get('/complaints/inbox')->status, 'employees are not handlers');

        // submit: validation, then a case that keeps no sender
        // 0.44.1: "keep in My complaints" is ticked on a fresh form; a re-shown form keeps the person's choice (here: unticked)
        self::assertStringContainsString('name="remember" value="1" aria-describedby="cp-rem-help" checked', $emp->get('/complaints')->body);
        $bad = $emp->post('/complaints', ['category' => 'x', 'title' => 'ส', 'body' => 'สั้น']);
        self::assertSame(422, $bad->status);
        self::assertStringNotContainsString('name="remember" value="1" aria-describedby="cp-rem-help" checked', $bad->body);
        self::assertSame(422, $emp->post('/complaints', ['category' => 'harassment', 'title' => 'หัวข้อทดสอบ', 'body' => str_repeat('ก', 30), 'ack' => '1'], ['files' => [['notes.pdf', "%PDF-1.4\n%%EOF"]]])->status, 'documents are refused');
        $auditBefore = (int) $db->scalar('SELECT COUNT(*) FROM audit_events');
        $res = $emp->post(
            '/complaints',
            ['category' => 'harassment', 'title' => 'หัวหน้าแผนกพูดจาคุกคาม', 'body' => 'เหตุเกิดในห้องประชุมชั้น 3 หลายครั้งในเดือนนี้ (ทดสอบ)', 'ack' => '1', 'remember' => '1'],
            ['files' => [['IMG_20261109_LINE_somchai.jpg', $this->jpegWithMetadata()]]]
        );
        self::assertSame(200, $res->status, substr(strip_tags($res->body), 0, 1500));
        $code = $this->code($res->body);
        // 0.44.2: a follow-up ticket — ref, code, day and a QR for the public page; no subject or details on the page
        self::assertStringContainsString('data-cp-ticket', $res->body);
        self::assertStringContainsString('ใบติดตาม', $res->body);
        self::assertStringContainsString('<svg', $res->body);
        self::assertMatchesRegularExpression('/data-modules="[01|]{400,}"/', $res->body);
        self::assertStringContainsString('/speak-up/track', $res->body);
        self::assertStringNotContainsString('หัวหน้าแผนกพูดจาคุกคาม', $res->body, 'the ticket has no subject');
        self::assertStringNotContainsString('ห้องประชุมชั้น 3', $res->body, 'the ticket has no details');
        self::assertSame('no-store', $res->headers['Cache-Control'] ?? null);
        self::assertSame($auditBefore, (int) $db->scalar('SELECT COUNT(*) FROM audit_events'), 'no audit event for a submission');
        $c = $db->one('SELECT * FROM cp_cases');
        self::assertNotNull($c);
        self::assertMatchesRegularExpression('/^CP\d{4}-0001$/', (string) $c['ref']);
        self::assertNull($c['created_by']);
        $svc = ComplaintService::of($this->t->app);
        self::assertSame($svc->dayStamp(), (string) $c['created_at'], 'stamped with the start of the local day');
        self::assertSame('2026-11-08 17:00:00.000000', (string) $c['created_at']);
        self::assertSame(strtotime('2026-11-08 17:00:00 UTC') * 1000, Ulid::timestampMs((string) $c['public_id']), 'the public id carries the day only');
        self::assertSame(hash('sha256', 'cp|' . $code), (string) $c['track_hash']);
        $f = $db->one('SELECT filename, mime, content FROM cp_files');
        self::assertSame(['evidence-1.jpg', 'image/jpeg'], [$f['filename'], $f['mime']], 'no original file name');
        self::assertStringNotContainsString('SECRET-CAMERA', (string) $f['content'], 'picture metadata removed');
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type LIKE 'complaints.%'"), 'handlers hear the next day');
        $uid = (int) $this->t->user('employee.demo1')['id'];
        foreach (['cp_cases', 'cp_events', 'cp_files'] as $tb) {
            self::assertStringNotContainsString('employee.demo1', json_encode($db->all("SELECT * FROM {$tb}"), JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
        }
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM cp_events WHERE actor_id = ?', [$uid]));
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM cp_vault'), 'kept in my list (encrypted)');
        self::assertStringNotContainsString($code, (string) $db->scalar('SELECT blob_enc FROM cp_vault'));
        self::assertStringContainsString((string) $c['ref'], $emp->get('/complaints')->body, 'my list shows it');

        // daily limit
        self::assertSame(303, $mgr->post('/complaints/settings/save', ['le' => $this->le('DEMO1'), 'min_group' => '5', 'daily_limit' => '1'])->status);
        self::assertSame(429, $emp->post('/complaints', ['category' => 'other', 'title' => 'เรื่องที่สอง', 'body' => str_repeat('ข', 30), 'ack' => '1'])->status);

        // the public page: no sign-in, POST only, wrong codes refused
        $pub = $this->t->browser();
        $form = $pub->get('/speak-up/track');
        self::assertSame(200, $form->status);
        self::assertStringContainsString('data-cp-track', $form->body, 'the code form can read a ticket QR / a "#" code');
        self::assertStringContainsString('camera=(self)', (string) ($form->headers['Permissions-Policy'] ?? ''));
        self::assertSame(404, $pub->post('/speak-up/track', ['code' => 'AAAA-BBBB-CCCC-DDDD-EEEE'])->status);
        $tr = $pub->post('/speak-up/track', ['code' => strtolower(str_replace('-', '', $code))]);
        self::assertSame(200, $tr->status);
        self::assertStringContainsString('หัวหน้าแผนกพูดจาคุกคาม', $tr->body);
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM auth_attempts WHERE point = 'cp.track' AND success = 1"), 'right codes are not recorded');

        // handlers: no sender anywhere; others kept out
        $inbox = $mgr->get('/complaints/inbox')->body;
        self::assertStringContainsString((string) $c['ref'], $inbox);
        $page = $mgr->get('/complaints/c/' . $c['public_id']);
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString('employee.demo1', $page->body);
        self::assertSame(404, $this->as('manager.demo1')->get('/complaints/c/' . $c['public_id'])->status);
        self::assertSame(403, $this->as('manager.demo1')->get('/complaints/inbox')->status);

        // triage → assign (an investigator who is not a handler of the route) → summary only until declaring no conflict
        self::assertSame(303, $mgr->post('/complaints/c/' . $c['public_id'] . '/triage', ['urgency' => 'high', 'category' => 'harassment'])->status);
        $inv = (int) $this->t->user('exec.demo1')['id'];
        self::assertSame(422, $mgr->post('/complaints/c/' . $c['public_id'] . '/assign', ['investigator' => (string) $uid])->status, 'only handlers can investigate');
        self::assertSame(303, $mgr->post('/complaints/c/' . $c['public_id'] . '/assign', ['investigator' => (string) $inv])->status);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'complaints.assigned' AND user_id = ?", [$inv]));
        $off = $this->as('exec.demo1');
        $sum = $off->get('/complaints/c/' . $c['public_id'])->body;
        self::assertStringNotContainsString('ห้องประชุมชั้น 3', $sum, 'details hidden before the declaration');
        self::assertSame(422, $off->post('/complaints/c/' . $c['public_id'] . '/accept', [])->status);
        self::assertSame(303, $off->post('/complaints/c/' . $c['public_id'] . '/accept', ['no_conflict' => '1', 'coi_note' => 'ไม่รู้จักผู้เกี่ยวข้อง'])->status);
        self::assertStringContainsString('ห้องประชุมชั้น 3', $off->get('/complaints/c/' . $c['public_id'])->body);

        // the anonymous message box both ways; notes stay with the handlers
        self::assertSame(303, $off->post('/complaints/c/' . $c['public_id'] . '/message', ['body' => 'ขอทราบช่วงเวลาที่เกิดเหตุโดยประมาณ', 'asks_reply' => '1'])->status);
        self::assertSame('action_required', $db->scalar('SELECT status FROM cp_cases'));
        self::assertSame(303, $off->post('/complaints/c/' . $c['public_id'] . '/note', ['body' => 'บันทึกลับ: สัมภาษณ์พยานสองคนแล้ว'])->status);
        $view = $pub->post('/speak-up/track', ['code' => $code])->body;
        self::assertStringContainsString('ขอทราบช่วงเวลา', $view);
        self::assertStringNotContainsString('บันทึกลับ', $view, 'notes never reach the sender');
        self::assertStringNotContainsString('exec.demo1', $view, 'no handler names for the sender');
        self::assertSame(200, $pub->post('/speak-up/track/reply', ['code' => $code, 'body' => 'ช่วงบ่ายวันจันทร์และวันพุธ'])->status);
        $pub->post('/speak-up/track/reply', ['code' => $code, 'body' => 'ช่วงบ่ายวันจันทร์และวันพุธ']);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM cp_messages WHERE side = 'reporter'"), 'a reloaded page does not send twice');
        self::assertSame(['investigating', $svc->dayStamp()], [$db->scalar('SELECT status FROM cp_cases'), $db->scalar("SELECT created_at FROM cp_messages WHERE side = 'reporter'")]);

        // outcome, close rules, the sender's rating
        self::assertSame(422, $off->post('/complaints/c/' . $c['public_id'] . '/resolve', ['outcome' => 'สั้น', 'finding' => 'x', 'score' => '9'])->status);
        self::assertSame(303, $off->post('/complaints/c/' . $c['public_id'] . '/resolve', ['outcome' => 'ตรวจสอบแล้วพบว่ามีมูลบางส่วน ได้ตักเตือนและจัดอบรมแล้ว', 'finding' => 'partly', 'score' => '4'])->status);
        self::assertSame(403, $off->post('/complaints/c/' . $c['public_id'] . '/close', [])->status, 'closing is for the route\'s handlers');
        self::assertSame('resolved', $db->scalar('SELECT status FROM cp_cases'));
        $view = $pub->post('/speak-up/track', ['code' => $code])->body;
        self::assertStringContainsString('ได้ตักเตือนและจัดอบรมแล้ว', $view);
        self::assertStringNotContainsString('มีมูลบางส่วน</', $view, 'the finding label is for handlers');
        self::assertSame(422, $pub->post('/speak-up/track/reopen', ['code' => $code, 'reason' => 'สั้น'])->status);
        self::assertSame(422, $pub->post('/speak-up/track/accept', ['code' => $code, 'rating' => '9'])->status);
        self::assertSame(200, $pub->post('/speak-up/track/accept', ['code' => $code, 'rating' => '5', 'comment' => 'ขอบคุณ'])->status);
        $done = $db->one('SELECT status, reporter_rating, closed_at, staff_score, finding FROM cp_cases');
        self::assertSame(['closed', 5, $svc->dayStamp(), 4, 'partly'], [$done['status'], (int) $done['reporter_rating'], (string) $done['closed_at'], (int) $done['staff_score'], $done['finding']]);
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action LIKE 'complaints.%' AND actor_id = ?", [$uid]));

        // next day: the digest tells the handlers about the case once (sent "yesterday")
        $this->t->clock->advance('+1 day');
        $svc->tick();
        self::assertSame(2, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'complaints.new'"), 'both CMP handlers');
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'complaints.reply' AND user_id = ?", [$inv]));
        $svc->tick();
        self::assertSame(2, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'complaints.new'"), 'once');
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM cp_quota'), 'yesterday\'s counters are deleted');
    }

    public function testAlternateRouteLimitsFiguresSlaAndRetention(): void
    {
        $db = $this->t->app->db();
        $mgr = $this->as('hr.head1');
        $this->openIntake($mgr);
        $emp = $this->as('employee.demo1');
        $emp->get('/complaints');
        $alt = $this->code($emp->post('/complaints', ['category' => 'misconduct', 'title' => 'เรื่องเกี่ยวกับผู้รับเรื่อง', 'body' => 'ผู้รับเรื่องคนหนึ่งเกี่ยวข้องกับเหตุการณ์ (ทดสอบ)', 'ack' => '1', 'route' => 'alternate'])->body);
        $a = $db->one('SELECT * FROM cp_cases WHERE route = ?', ['alternate']);
        self::assertNotNull($a);
        self::assertStringNotContainsString((string) $a['ref'], $mgr->get('/complaints/inbox')->body, 'usual handlers do not see the alternate route');
        self::assertSame(404, $mgr->get('/complaints/c/' . $a['public_id'])->status);
        $x = $this->as('exec.demo1');
        self::assertStringContainsString((string) $a['ref'], $x->get('/complaints/inbox')->body);
        self::assertSame(200, $x->get('/complaints/c/' . $a['public_id'])->status);
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM cp_vault"), 'not kept unless asked');

        // a handler who investigated a case cannot close it themselves
        $x->post('/complaints/c/' . $a['public_id'] . '/triage', ['urgency' => 'normal']);
        $exec = (int) $this->t->user('exec.demo1')['id'];
        self::assertSame(303, $x->post('/complaints/c/' . $a['public_id'] . '/assign', ['investigator' => (string) $exec])->status);
        $x->post('/complaints/c/' . $a['public_id'] . '/accept', ['no_conflict' => '1']);
        $x->post('/complaints/c/' . $a['public_id'] . '/resolve', ['outcome' => 'ส่งเรื่องให้คณะกรรมการอิสระพิจารณาแล้ว (ทดสอบ)', 'finding' => 'inconclusive', 'score' => '3']);
        self::assertSame(303, $x->post('/complaints/c/' . $a['public_id'] . '/close', [])->status);
        self::assertSame('resolved', $db->scalar('SELECT status FROM cp_cases WHERE id = ?', [$a['id']]), 'own case');
        // a second alternate-route complaint nobody has answered yet (for the SLA below)
        $this->code($emp->post('/complaints', ['category' => 'other', 'title' => 'เรื่องที่ยังไม่มีใครตอบ', 'body' => 'รายละเอียดเรื่องที่สองสำหรับทดสอบกำหนดเวลา', 'ack' => '1', 'route' => 'alternate'])->body);

        // figures: fewer than the minimum group are hidden
        $stats = $x->get('/complaints/stats')->body;
        self::assertStringContainsString('น้อยกว่า 5', $stats);

        // wrong codes: the 11th try within 15 minutes is refused
        $pub = $this->t->browser();
        for ($i = 0; $i < 10; $i++) {
            $pub->post('/speak-up/track', ['code' => sprintf('ZZZZ-ZZZZ-ZZZZ-ZZZZ-%04d', $i)]);
        }
        self::assertSame(429, $pub->post('/speak-up/track', ['code' => $alt])->status);

        // SLA: first response within 1 day (company setting) → one notice to the route's handlers
        self::assertSame(303, $mgr->post('/complaints/settings/save', ['le' => $this->le('DEMO1'), 'first_response_days' => '1', 'retention_days' => '30', 'min_group' => '5', 'daily_limit' => '3'])->status);
        $svc = ComplaintService::of($this->t->app);
        $this->t->clock->advance('+2 days');
        $svc->tick();
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'complaints.overdue' AND user_id = ?", [$exec]));
        $svc->tick();
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'complaints.overdue' AND user_id = ?", [$exec]), 'once');

        // retention: 30 days after closing the texts, files and code go; the counts stay
        self::assertSame(200, $this->t->browser()->post('/speak-up/track/accept', ['code' => $alt, 'rating' => '4'])->status);
        self::assertSame('closed', $db->scalar('SELECT status FROM cp_cases WHERE id = ?', [$a['id']]));
        $this->t->clock->advance('+31 days');
        $svc->tick();
        $p = $db->one('SELECT title, body, track_hash, purged_at, category FROM cp_cases WHERE id = ?', [$a['id']]);
        self::assertSame([null, null, null, 'misconduct'], [$p['title'], $p['body'], $p['track_hash'], $p['category']]);
        self::assertNotNull($p['purged_at']);
        self::assertNull($svc->byCode($alt), 'the code no longer opens anything');
    }
}
