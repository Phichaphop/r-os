<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Privacy\PrivacyService;
use Blue\Core\Support\Ulid;
use Blue\Modules\Eid\CardService;
use Blue\Modules\Gps\GpsService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/**
 * Phase 5 gate — TC-PRV for M57 (GPS) and M13 (e-ID) (DEC-82, P0-06 §8, P0-04 §5–7): own-data export, data-subject
 * request with two-person erasure, masked / restricted values with audit, and no personal data in logs, URLs,
 * notifications or audit details.
 */
final class PrivacyGateTest extends TestCase
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

    private function browser(string $user): TestBrowser
    {
        $b = $this->t->browser();
        $b->login($user);
        return $b;
    }

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    /** A privacy officer (PRV, organization scope) with the demo password. */
    private function officer(string $username): void
    {
        $db = $this->t->app->db();
        $now = $this->t->clock->now()->format('Y-m-d H:i:s.u');
        $hash = $db->scalar("SELECT password_hash FROM users WHERE username = 'hr.demo1'");
        $db->run(
            "INSERT INTO users (public_id, created_at, updated_at, row_version, username, display_name, email, password_hash, status, password_changed_at, is_demo) VALUES (?, ?, ?, 1, ?, ?, ?, ?, 'active', ?, 1)",
            [Ulid::generate($this->t->clock), $now, $now, $username, 'DPO ' . $username, $username . '@demo.invalid', $hash, $now]
        );
        $uid = (int) $db->pdo()->lastInsertId();
        $db->run(
            "INSERT INTO user_role_assignments (public_id, created_at, updated_at, row_version, user_id, role_id, status, valid_from, reason) VALUES (?, ?, ?, 1, ?, (SELECT id FROM roles WHERE code = 'PRV'), 'active', ?, 'test')",
            [Ulid::generate($this->t->clock), $now, $now, $uid, $this->t->clock->now()->format('Y-m-d')]
        );
        $db->run("INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, 'organization', (SELECT id FROM organizations LIMIT 1))", [(int) $db->pdo()->lastInsertId()]);
    }

    private function gpsPolicy(): void
    {
        $svc = GpsService::of($this->t->app);
        $cv = $svc->proposePolicy($this->az('hr.demo1'), $this->le(), ['outside' => 'flag', 'max_accuracy_m' => '100', 'offline_hours' => '4', 'groups' => '',
            'notice' => 'บริษัทเก็บพิกัดเฉพาะตอนกดลงเวลา เพื่อยืนยันการทำงานนอกสถานที่ (ทดสอบ)'], '2026-11-01', 'นโยบายความเป็นส่วนตัว (ทดสอบ)', 'เปิดใช้ทดสอบ');
        $inst = (string) $this->t->app->db()->scalar('SELECT i.public_id FROM workflow_instances i JOIN config_versions v ON v.workflow_instance_id = i.id WHERE v.public_id = ?', [$cv]);
        $this->t->app->workflow()->decide($inst, (int) $this->t->user('hr.head1')['id'], 'approve');
    }

    /** @return array<string, string> */
    private function reading(string $dir, string $event, float $lat, float $lng): array
    {
        return ['direction' => $dir, 'lat' => (string) $lat, 'lng' => (string) $lng, 'accuracy' => '17', 'captured' => (string) ($this->t->clock->now()->getTimestamp() * 1000), 'event' => $event, 'offline' => '0', 'note' => ''];
    }

    public function testTcPrvGpsAndEid(): void
    {
        $db = $this->t->app->db();
        $app = $this->t->app;
        $this->gpsPolicy();
        $this->officer('dpo.one');
        $this->officer('dpo.two');
        $emp = $this->browser('employee.demo1');

        // real flows through the screens: GPS notice + punches (one inside, one far away = flagged), a staff card with a photo
        self::assertSame(303, $emp->post('/gps/notice')->status);
        foreach ([['in', 'evt-prv-000001', 13.756512, 100.501934], ['out', 'evt-prv-000002', 13.812345, 100.567891]] as [$dir, $ev, $lat, $lng]) {
            self::assertSame(200, $emp->post('/gps/punch', $this->reading($dir, $ev, $lat, $lng) + ['_ajax' => '1'])->status);
        }
        $hr = $this->az('hr.demo1');
        $empRow = $db->one("SELECT * FROM employees WHERE employee_code = 'D1-0004'");
        $card = CardService::of($app)->issue($hr, (string) $empRow['public_id']);
        CardService::of($app)->uploadPhoto($hr, $card, UploadedFile::fromBytes('face.jpg', (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/img.jpg'), $this->t->tmp));
        $photoPid = (string) $db->scalar('SELECT photo_document_pid FROM eid_cards WHERE public_id = ?', [$card]);
        self::assertNotSame('', $photoPid);
        $inside = (string) $db->scalar("SELECT public_id FROM gps_punches WHERE client_event_id = 'evt-prv-000001'");
        $far = (string) $db->scalar("SELECT public_id FROM gps_punches WHERE client_event_id = 'evt-prv-000002'");
        self::assertSame('flagged', $db->scalar('SELECT status FROM gps_punches WHERE public_id = ?', [$far]));

        // (1) masked: the manager's report shows in / out only; once decided, reviewers no longer see the position
        $mgr = $this->browser('manager.demo1');
        $rep = $mgr->get('/gps/report');
        self::assertStringNotContainsString('13.812345', $rep->body);
        self::assertStringNotContainsString('13.756512', $rep->body);
        GpsService::of($app)->review($this->az('manager.demo1'), $far, 'approve', 'ไปพบลูกค้าตามนัด');
        self::assertStringNotContainsString('13.812345', $this->browser('hr.demo1')->get('/gps/p/' . $far)->body, 'decided = no coordinates for HR');
        self::assertStringContainsString('13.812345', $emp->get('/gps/p/' . $far)->body, 'the owner sees their own');
        // e-ID check shows photo, name, code, status only — never the identifiers or contacts
        $face = CardService::of($app)->face((array) $db->one('SELECT * FROM eid_cards WHERE public_id = ?', [$card]));
        foreach (['national_id', 'identifier', 'phone', 'email', 'address', 'birth_date', 'salary'] as $k) {
            self::assertArrayNotHasKey($k, $face, 'a card check shows no ' . $k);
        }

        // (2) own-data export: needs a fresh password, has my positions and my card, nothing about others, audited
        $me = $emp->get('/me/data');
        self::assertSame(200, $me->status);
        self::assertStringContainsString('การลงเวลาด้วย GPS', $me->body);
        $x = $emp->get('/me/data/export');
        self::assertContains($x->status, [302, 303], 'password first');
        self::assertStringContainsString('/reauth', (string) ($x->headers['Location'] ?? ''));
        $emp->post('/reauth', ['password' => $this->t->demoPassword, 'next' => '/me/data/export']);
        $x = $emp->get('/me/data/export');
        self::assertSame(200, $x->status);
        $pkg = (array) json_decode($x->body, true);
        $flat = json_encode($pkg, JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('13.812345', (string) $flat);
        self::assertStringContainsString('D1-0004-01', (string) $flat);
        self::assertStringNotContainsString('D1-0005', (string) $flat, 'no one else');
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'privacy.self_export'"));
        self::assertSame(403, $emp->get('/privacy/requests')->status);

        // (3) data-subject request: deletion → verify → dry run → another officer approves → erased; kept items say why
        $emp->post('/me/privacy', ['type' => 'deletion', 'details' => 'ขอลบพิกัด GPS และรูปบนบัตรของฉัน']);
        $req = $db->one('SELECT * FROM privacy_requests ORDER BY id DESC LIMIT 1');
        self::assertSame('received', $req['status']);
        self::assertNull($req['due_on'], 'no deadline until Legal sets privacy.dsr_response_days');
        $pid = (string) $req['public_id'];
        $svc = PrivacyService::of($app);
        $one = $this->az('dpo.one');
        $two = $this->az('dpo.two');
        try {
            $svc->prepareErasure($one, $pid);
            self::fail('verify first');
        } catch (BusinessException $e) {
            self::assertSame('privacy.error.state', $e->messageKey);
        }
        $svc->verify($one, $pid, 'ยื่นจากบัญชีของตนเอง + ตรวจบัตรพนักงานต่อหน้า');
        $svc->prepareErasure($one, $pid);
        $plan = (array) json_decode((string) $db->scalar('SELECT erasure_plan FROM privacy_requests WHERE public_id = ?', [$pid]), true);
        self::assertSame(2, $plan['gps']['done']['gps.privacy.positions'], 'dry run counts the decided punches');
        self::assertSame(1, $plan['eid']['done']['eid.privacy.photos']);
        self::assertSame('employees.privacy.kept_record', $plan['employees']['kept']['employees.privacy.record']);
        self::assertNotNull($db->scalar('SELECT lat FROM gps_punches WHERE public_id = ?', [$inside]), 'a dry run changes nothing');
        try {
            $svc->approveErasure($one, $pid);
            self::fail('two people');
        } catch (BusinessException $e) {
            self::assertSame('privacy.error.same_person', $e->messageKey);
        }
        $svc->approveErasure($two, $pid);
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM gps_punches WHERE employee_id = ? AND (lat IS NOT NULL OR ip IS NOT NULL OR device IS NOT NULL)', [$empRow['id']]));
        self::assertSame(2, (int) $db->scalar('SELECT COUNT(*) FROM gps_punches WHERE employee_id = ? AND inside IS NOT NULL', [$empRow['id']]), 'time + in/out kept');
        self::assertNull($db->scalar('SELECT photo_document_pid FROM eid_cards WHERE public_id = ?', [$card]));
        self::assertSame('disposed', $db->scalar('SELECT lifecycle_status FROM documents WHERE public_id = ?', [$photoPid]));
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'privacy.erased'"));
        $svc->close($two, $pid, 'completed', 'ลบพิกัดและรูปบนบัตรแล้ว เก็บเวลาทำงานและเลขบัตรไว้ตามหน้าที่ของนายจ้าง');
        self::assertSame('completed', $db->scalar('SELECT status FROM privacy_requests WHERE public_id = ?', [$pid]));
        self::assertStringContainsString('ดำเนินการแล้ว', $emp->get('/me/privacy/' . $pid)->body);
        self::assertSame(404, $this->browser('manager.demo1')->get('/me/privacy/' . $pid)->status, 'only the requester');

        // access request: the officer's package is audited and has the person's data only
        $emp->post('/me/privacy', ['type' => 'access', 'details' => 'ขอสำเนาข้อมูลทั้งหมดของฉัน']);
        $pid2 = (string) $db->scalar('SELECT public_id FROM privacy_requests ORDER BY id DESC LIMIT 1');
        $svc->verify($one, $pid2, 'ยื่นจากบัญชีของตนเอง');
        $pack = (array) json_decode($svc->accessPackage($one, $pid2), true);
        self::assertSame('dsr', $pack['purpose']);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'privacy.package_exported'"));
        self::assertGreaterThanOrEqual(3, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'privacy.request_viewed' OR action LIKE 'privacy.request_%'"));

        // (4) no personal data in logs, URLs, notifications or audit details
        $logs = implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), glob($this->t->tmp . '/logs/*') ?: []));
        foreach (['13.812345', '100.567891', '13.756512', 'พนักงานตัวอย่าง', $this->t->demoPassword] as $needle) {
            self::assertStringNotContainsString($needle, $logs, 'log: ' . $needle);
        }
        $audit = implode("\n", array_map(static fn (array $a): string => (string) ($a['reason'] ?? '') . ' ' . ($a['diff_json'] ?? '') . ' ' . ($a['context_json'] ?? ''), $db->all('SELECT * FROM audit_events')));
        foreach (['13.812345', '100.567891', '13.756512'] as $needle) {
            self::assertStringNotContainsString($needle, $audit, 'audit: ' . $needle);
        }
        $notes = implode("\n", array_map(static fn (array $n): string => json_encode($n, JSON_UNESCAPED_UNICODE) ?: '', $db->all('SELECT * FROM notifications')));
        foreach (['13.812345', '100.567891', '13.756512'] as $needle) {
            self::assertStringNotContainsString($needle, $notes, 'notification: ' . $needle);
        }
        foreach ($db->all('SELECT link FROM notifications WHERE link IS NOT NULL') as $n) {
            self::assertMatchesRegularExpression('#^/[a-z0-9/_-]*([0-9A-HJKMNP-TV-Z]{26})?[a-z0-9/_=?&.-]*$#i', (string) $n['link'], 'links carry ids only');
        }
        $qr = CardService::of($app)->qr((array) $db->one('SELECT * FROM eid_cards WHERE public_id = ?', [$card]), 'https://blue.example');
        self::assertStringNotContainsString('D1-0004', (string) ($qr['url'] ?? ''), 'the QR link carries no personal data');
        self::assertStringNotContainsString('พนักงาน', (string) ($qr['url'] ?? ''));

        // the officer's screens render (desk, a request with its erasure result) and the employee's page
        $re = '/(?<![.\\w\\/{:-])(privacy|gps|eid|employees|common|form)\\.[a-z_]+(\\.[a-z_]+)*\\b(?![^<]*>)/';
        $dpo = $this->browser('dpo.one');
        foreach (['/privacy/requests', '/privacy/requests/' . $pid, '/privacy/requests/' . $pid2] as $path) {
            $page = $dpo->get($path);
            self::assertSame(200, $page->status, $path);
            self::assertDoesNotMatchRegularExpression($re, strip_tags($page->body), $path);
        }
        self::assertStringContainsString('ไม่ได้ตั้งจำนวนวันที่ต้องตอบ', $dpo->get('/privacy/requests')->body);
        self::assertDoesNotMatchRegularExpression($re, strip_tags($this->browser('employee.demo1')->get('/me/data')->body));
    }
}
