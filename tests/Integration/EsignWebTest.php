<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Pdf\Dict;
use Blue\Core\Pdf\Name;
use Blue\Core\Pdf\Stream;
use Blue\Core\Pdf\Toolkit;
use Blue\Core\Pdf\Writer;
use Blue\Modules\Esign\EsignService;
use Blue\Modules\Forms\FormService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/** Phase 5F (DEC-81): M20 online signing — draft, send, order, typed name + password, hash binding, completion, evidence, decline, cancel, void, expiry, verify. */
final class EsignWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(esign|common|form|file)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function browser(string $user): TestBrowser
    {
        $b = $this->t->browser();
        $b->login($user);
        return $b;
    }

    private function errorsLogged(): string
    {
        return implode("\n", array_map(static fn (string $f): string => mb_substr((string) file_get_contents($f), -1500), glob($this->t->tmp . '/logs/*') ?: []));
    }

    private static function pdf(int $pages, string $label): string
    {
        $w = new Writer();
        for ($i = 0; $i < $pages; $i++) {
            $c = $w->add(new Stream(new Dict(), 'BT /F1 18 Tf 72 760 Td (' . $label . ' page ' . ($i + 1) . ') Tj ET'));
            $w->addPage(new Dict(['Type' => new Name('Page'), 'MediaBox' => [0, 0, 595.28, 841.89],
                'Resources' => new Dict(['Font' => new Dict(['F1' => new Dict(['Type' => new Name('Font'), 'Subtype' => new Name('Type1'), 'BaseFont' => new Name('Helvetica')])])]), 'Contents' => $c]));
        }
        return $w->output();
    }

    public function testSigningFlow(): void
    {
        $db = $this->t->app->db();
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $lePid = (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        $hr = $this->browser('hr.demo1');
        $emp = $this->browser('employee.demo1');
        $mgr = $this->browser('manager.demo1');
        $pw = $this->t->demoPassword;
        $src = self::pdf(2, 'Contract');

        $p = $emp->get('/esign');
        self::assertSame(200, $p->status, $this->errorsLogged());
        self::assertStringContainsString('ไม่มีเอกสารรอคุณลงนาม', $p->body);
        self::assertSame(403, $emp->get('/esign/new')->status, 'only senders create requests');
        $f = $hr->get('/esign/new');
        self::assertSame(200, $f->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($f->body));

        $body = ['le' => $lePid, 'title' => 'สัญญาจ้างทดสอบ', 'category' => 'สัญญา', 'message' => 'โปรดลงนามภายในกำหนด', 'sequential' => '1', 'expires_on' => '2026-11-20',
            'signers' => [['username' => 'employee.demo1', 'role' => 'พนักงาน', 'page' => 'last', 'anchor' => 'bottom-left'], ['username' => 'manager.demo1', 'role' => 'ผู้บังคับบัญชา', 'page' => '2', 'anchor' => 'bottom-right']]];
        // validation: no file, unknown user, page out of range, past date, encrypted PDF
        self::assertSame(422, $hr->post('/esign/new', $body)->status);
        $r = $hr->post('/esign/new', ['signers' => [['username' => 'nobody.x', 'role' => 'พนักงาน', 'page' => '9', 'anchor' => 'bottom-left']], 'expires_on' => '2026-11-01'] + $body, ['file' => ['c.pdf', $src]]);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('ไม่พบผู้ใช้นี้', $r->body);
        self::assertStringContainsString('วันลงนามได้ถึงต้องอยู่หลังวันนี้', $r->body);
        $locked = Toolkit::protect($src, '', 'open-secret-1', 'owner-secret-1', 0)['bytes'];
        self::assertStringContainsString('PDF ล็อกรหัสผ่าน', $hr->post('/esign/new', $body, ['file' => ['l.pdf', $locked]])->body);
        self::assertSame(422, $hr->post('/esign/new', ['signers' => [['username' => 'employee.demo1', 'role' => 'พนักงาน', 'page' => '3', 'anchor' => 'bottom-left']]] + $body, ['file' => ['c.pdf', $src]])->status);
        // a draft, then send
        self::assertSame(303, $hr->post('/esign/new', $body, ['file' => ['contract.pdf', $src]])->status, $this->errorsLogged());
        $req = $db->one('SELECT * FROM sig_requests ORDER BY id DESC LIMIT 1');
        $pid = (string) $req['public_id'];
        self::assertSame('draft', $req['status']);
        self::assertSame(hash('sha256', $src), $req['source_sha256']);
        self::assertSame(2, (int) $db->scalar('SELECT pages FROM sig_requests WHERE id = ?', [$req['id']]));
        self::assertSame(404, $emp->get('/esign/r/' . $pid)->status, 'signers do not see drafts');
        $d = $hr->get('/esign/r/' . $pid);
        self::assertSame(200, $d->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('#<code[^>]*>.*?</code>#s', '', $d->body)));
        self::assertSame(303, $hr->post('/esign/r/' . $pid . '/send', [])->status);
        $req = $db->one('SELECT * FROM sig_requests WHERE public_id = ?', [$pid]);
        self::assertSame('sent', $req['status']);
        self::assertMatchesRegularExpression('/^ES-2026-\d{5}$/', (string) $req['request_no']);
        self::assertSame(['pending', 'waiting'], array_column($db->all('SELECT status FROM sig_signers WHERE request_id = ? ORDER BY seq', [$req['id']]), 'status'));
        $empUid = (int) $this->t->user('employee.demo1')['id'];
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'esign.sign'", [$empUid]));
        $hr->post('/esign/r/' . $pid . '/edit', ['title' => 'แก้หลังส่ง'] + $body); // sent requests cannot change
        self::assertSame('สัญญาจ้างทดสอบ', $db->scalar('SELECT title FROM sig_requests WHERE public_id = ?', [$pid]));

        // order: the manager is second
        $mv = $mgr->get('/esign/r/' . $pid);
        self::assertSame(200, $mv->status);
        self::assertStringContainsString('ยังไม่ถึงลำดับของคุณ', $mv->body);
        $mgr->post('/esign/r/' . $pid . '/sign', ['typed_name' => 'หัวหน้าฝ่ายขาย (DEMO)', 'password' => $pw, 'sha256' => $req['source_sha256'], 'read' => '1']);
        self::assertSame('waiting', $db->scalar('SELECT status FROM sig_signers WHERE request_id = ? AND seq = 2', [$req['id']]));

        // the employee opens and signs: read box, exact name, password, the hash seen
        $ev = $emp->get('/esign/r/' . $pid);
        self::assertSame(200, $ev->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('#<code[^>]*>.*?</code>#s', '', $ev->body)));
        self::assertStringContainsString('พิมพ์ให้ตรงกับ: พนักงานตัวอย่าง (DEMO)', $ev->body);
        $file = $emp->get('/esign/r/' . $pid . '/file/source');
        self::assertSame(200, $file->status);
        self::assertSame($src, $file->body);
        $sign = ['typed_name' => 'พนักงานตัวอย่าง (DEMO)', 'password' => $pw, 'sha256' => $req['source_sha256'], 'read' => '1'];
        $r = $emp->post('/esign/r/' . $pid . '/sign', ['read' => '', 'typed_name' => 'คนอื่น', 'password' => 'wrong'] + $sign);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('ชื่อที่พิมพ์ไม่ตรงกับชื่อของคุณในระบบ', $r->body);
        self::assertStringContainsString('รหัสผ่านไม่ถูกต้อง', $r->body);
        self::assertStringContainsString('ยืนยันว่าได้อ่านเอกสารแล้ว', $r->body);
        $emp->post('/esign/r/' . $pid . '/sign', ['sha256' => str_repeat('0', 64)] + $sign); // a different document seen
        self::assertSame('pending', $db->scalar('SELECT status FROM sig_signers WHERE request_id = ? AND seq = 1', [$req['id']]));
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM sig_events WHERE request_id = ? AND action = 'hash_mismatch'", [$req['id']]));
        self::assertSame(303, $emp->post('/esign/r/' . $pid . '/sign', ['typed_name' => '  พนักงานตัวอย่าง   (DEMO) '] + $sign)->status);
        self::assertSame('partial', $db->scalar('SELECT status FROM sig_requests WHERE id = ?', [$req['id']]));
        self::assertSame('pending', $db->scalar('SELECT status FROM sig_signers WHERE request_id = ? AND seq = 2', [$req['id']]));
        $s1 = $db->one('SELECT * FROM sig_signers WHERE request_id = ? AND seq = 1', [$req['id']]);
        self::assertSame('password', $s1['auth_method']);
        self::assertSame($req['source_sha256'], $s1['signed_sha256']);
        self::assertSame('10.0.0.1', $s1['ip']);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM sig_events WHERE request_id = ? AND action = 'opened'", [$req['id']]));

        // the manager signs → completed: signed copy with boxes + evidence page, its own hash, everyone told
        self::assertSame(303, $mgr->post('/esign/r/' . $pid . '/sign', ['typed_name' => 'หัวหน้าฝ่ายขาย (DEMO)', 'password' => $pw, 'sha256' => $req['source_sha256'], 'read' => '1'])->status, $this->errorsLogged());
        $req = $db->one('SELECT * FROM sig_requests WHERE public_id = ?', [$pid]);
        self::assertSame('completed', $req['status']);
        $signed = $hr->get('/esign/r/' . $pid . '/file/signed');
        self::assertSame(200, $signed->status);
        self::assertSame($req['signed_sha256'], hash('sha256', $signed->body));
        self::assertSame(3, Toolkit::info($signed->body)['pages'], 'two pages + the evidence page');
        self::assertNotSame($req['source_sha256'], $req['signed_sha256']);
        self::assertSame($src, $emp->get('/esign/r/' . $pid . '/file/source')->body, 'the original is unchanged');
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'esign.done'", [$empUid]));
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'esign.completed'"));
        $done = $emp->get('/esign/r/' . $pid);
        self::assertStringContainsString('ลงนามครบ', $done->body);

        // verify: the signed copy and the original match; a changed file does not; strangers see nothing
        $v = $emp->post('/esign/verify', [], ['file' => ['s.pdf', $signed->body]]);
        self::assertSame(200, $v->status, $this->errorsLogged());
        self::assertStringContainsString('ตรงกับฉบับลงนามแล้ว', $v->body);
        self::assertStringContainsString('ตรงกับต้นฉบับ', $emp->post('/esign/verify', [], ['file' => ['o.pdf', $src]])->body);
        self::assertStringContainsString('ไม่พบเอกสารที่ตรงกัน', $emp->post('/esign/verify', [], ['file' => ['x.pdf', $signed->body . ' ']])->body);
        self::assertStringContainsString('ไม่พบเอกสารที่ตรงกัน', $this->browser('hr.demo2')->post('/esign/verify', [], ['file' => ['s.pdf', $signed->body]])->body);
        self::assertSame(404, $this->browser('hr.demo2')->get('/esign/r/' . $pid)->status, 'another company');

        // void (manager, reason) keeps both files
        self::assertSame(422, $hr->post('/esign/r/' . $pid . '/void', ['reason' => ''])->status);
        self::assertSame(303, $hr->post('/esign/r/' . $pid . '/void', ['reason' => 'ทำสัญญาฉบับใหม่แทน'])->status);
        self::assertSame('voided', $db->scalar('SELECT status FROM sig_requests WHERE id = ?', [$req['id']]));
        self::assertSame(200, $hr->get('/esign/r/' . $pid . '/file/signed')->status);
        self::assertStringContainsString('ยกเลิก (void) แล้ว', $emp->post('/esign/verify', [], ['file' => ['s.pdf', $signed->body]])->body);

        // parallel request: decline closes it; signer list keeps the reason (one-page documents from here)
        $body['signers'][1]['page'] = 'last';
        $p2 = $body + [];
        $p2['title'] = 'หนังสือเตือนทดสอบ';
        $p2['sequential'] = '0';
        self::assertSame(303, $hr->post('/esign/new', $p2, ['file' => ['w.pdf', self::pdf(1, 'Warning')]])->status);
        $pid2 = (string) $db->scalar('SELECT public_id FROM sig_requests ORDER BY id DESC LIMIT 1');
        $hr->post('/esign/r/' . $pid2 . '/send', []);
        self::assertSame(2, (int) $db->scalar("SELECT COUNT(*) FROM sig_signers s JOIN sig_requests r ON r.id = s.request_id WHERE r.public_id = ? AND s.status = 'pending'", [$pid2]));
        self::assertSame(422, $mgr->post('/esign/r/' . $pid2 . '/decline', ['reason' => 'ไม่'])->status);
        self::assertSame(303, $mgr->post('/esign/r/' . $pid2 . '/decline', ['reason' => 'ข้อเท็จจริงไม่ถูกต้อง'])->status);
        self::assertSame('declined', $db->scalar('SELECT status FROM sig_requests WHERE public_id = ?', [$pid2]));
        $emp->post('/esign/r/' . $pid2 . '/sign', ['sha256' => (string) $db->scalar('SELECT source_sha256 FROM sig_requests WHERE public_id = ?', [$pid2])] + $sign);
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM sig_signers s JOIN sig_requests r ON r.id = s.request_id WHERE r.public_id = ? AND s.status = 'signed'", [$pid2]), 'closed requests cannot be signed');

        // cancel after one signature keeps it; expiry closes the rest
        $p3 = $body;
        $p3['title'] = 'ใบรับรองทดสอบ';
        self::assertSame(303, $hr->post('/esign/new', $p3, ['file' => ['x.pdf', self::pdf(1, 'Letter')]])->status);
        $pid3 = (string) $db->scalar('SELECT public_id FROM sig_requests ORDER BY id DESC LIMIT 1');
        $hr->post('/esign/r/' . $pid3 . '/send', []);
        $emp->post('/esign/r/' . $pid3 . '/sign', ['sha256' => (string) $db->scalar('SELECT source_sha256 FROM sig_requests WHERE public_id = ?', [$pid3])] + $sign);
        self::assertSame(303, $hr->post('/esign/r/' . $pid3 . '/cancel', ['reason' => 'เปลี่ยนรายละเอียด'])->status);
        self::assertSame('cancelled', $db->scalar('SELECT status FROM sig_requests WHERE public_id = ?', [$pid3]));
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM sig_signers s JOIN sig_requests r ON r.id = s.request_id WHERE r.public_id = ? AND s.status = 'signed'", [$pid3]));
        $p4 = $body;
        $p4['title'] = 'เอกสารหมดอายุทดสอบ';
        $hr->post('/esign/new', $p4, ['file' => ['y.pdf', self::pdf(1, 'Expire')]]);
        $pid4 = (string) $db->scalar('SELECT public_id FROM sig_requests ORDER BY id DESC LIMIT 1');
        $hr->post('/esign/r/' . $pid4 . '/send', []);
        $this->t->clock->advance('+2 days');
        self::assertGreaterThan(0, EsignService::housekeepingJob($this->t->app), 'reminder after a day');
        self::assertGreaterThanOrEqual(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title_key = 'esign.notify.reminder'", [$empUid]));
        $this->t->clock->advance('+12 days');
        EsignService::housekeepingJob($this->t->app);
        self::assertSame('expired', $db->scalar('SELECT status FROM sig_requests WHERE public_id = ?', [$pid4]));

        // lists
        $hr = $this->browser('hr.demo1');
        $a = $hr->get('/esign/admin');
        self::assertSame(200, $a->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($a->body));
        self::assertStringContainsString('หนังสือเตือนทดสอบ', $a->body);
        self::assertSame(403, $this->browser('employee.demo1')->get('/esign/admin')->status);
        $i = $this->browser('employee.demo1')->get('/esign');
        self::assertStringContainsString('สัญญาจ้างทดสอบ', $i->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($i->body));
        self::assertStringNotContainsString('PHP Warning', $this->errorsLogged());
        self::assertStringNotContainsString('PHP Fatal', $this->errorsLogged());
    }

    /** M02 → M20: "send to sign" from a central form uses the form's current PDF; the button says why when it cannot. */
    public function testSendCentralFormForSigning(): void
    {
        $db = $this->t->app->db();
        $app = $this->t->app;
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $hrAz = new Authorizer($db, $this->t->clock, (int) $this->t->user('hr.demo1')['id']);
        $svc = FormService::of($app);
        $pid = $svc->save($hrAz, $le, ['code' => 'HR-09', 'title' => 'หนังสือยินยอมทดสอบ', 'category' => 'บุคคล', 'language' => 'th']);
        $bytes = self::pdf(1, 'Consent');
        $v = $svc->upload($hrAz, $pid, UploadedFile::fromBytes('consent.pdf', $bytes, $this->t->tmp), 'ฉบับแรก');
        $svc->submit($hrAz, $v);
        $inst = (string) $db->scalar('SELECT i.public_id FROM workflow_instances i JOIN frm_versions s ON s.workflow_instance_id = i.id WHERE s.public_id = ?', [$v]);
        $who = (int) $db->scalar("SELECT k.assignee_id FROM workflow_tasks k JOIN workflow_instances i ON i.id = k.instance_id WHERE i.public_id = ? AND k.status = 'open' LIMIT 1", [$inst]);
        $app->workflow()->decide($inst, $who, 'approve');

        $hr = $this->browser('hr.demo1');
        $page = $hr->get('/forms/f/' . $pid);
        self::assertSame(200, $page->status, $this->errorsLogged());
        self::assertStringContainsString('/esign/new?form=' . $pid, $page->body);
        self::assertStringContainsString('ต้องมีสิทธิ์ส่งเอกสารให้เซ็น', $this->browser('employee.demo1')->get('/forms/f/' . $pid)->body);
        $f = $hr->get('/esign/new', ['form' => $pid]);
        self::assertSame(200, $f->status, $this->errorsLogged());
        self::assertStringContainsString('HR-09 · หนังสือยินยอมทดสอบ', $f->body);
        $res = $hr->post('/esign/new', ['form' => $pid, 'le' => (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]), 'title' => 'หนังสือยินยอม', 'sequential' => '1',
            'expires_on' => '2026-11-20', 'signers' => [['username' => 'employee.demo1', 'role' => 'พนักงาน', 'page' => 'last', 'anchor' => 'bottom-right']]]);
        self::assertSame(303, $res->status, $this->errorsLogged());
        self::assertSame(hash('sha256', $bytes), $db->scalar('SELECT source_sha256 FROM sig_requests ORDER BY id DESC LIMIT 1'));
    }
}
