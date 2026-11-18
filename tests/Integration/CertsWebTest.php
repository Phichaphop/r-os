<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/** Phase 5E-2 (DEC-77): M12 certificate requests — no approval, issue from a template, pickup code, rating before the next request. */
final class CertsWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(certs|employees|common|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

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

    public function testRequestToHandoverAndRating(): void
    {
        $db = $this->t->app->db();
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $lePid = (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        $tpl = (string) $db->scalar("SELECT public_id FROM document_templates WHERE code = 'DEMO_EMPLOYMENT_CERT'");
        self::assertNotSame('', $tpl, 'demo template present');
        $hr = $this->browser('hr.demo1');
        $emp = $this->browser('employee.demo1');

        // no types yet → employees cannot ask
        self::assertStringContainsString('ยังไม่ได้ตั้งประเภท', $emp->get('/certs')->body);
        $types = $hr->get('/certs/admin/types');
        self::assertSame(200, $types->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($types->body));
        self::assertSame(422, $hr->post('/certs/admin/types', ['le' => $lePid, 'code' => 'EMP', 'name_th' => 'หนังสือรับรองการทำงาน', 'template_th' => '', 'template_en' => '', 'max_copies' => '3'])->status);
        $hr->get('/certs/admin/types');
        self::assertSame(303, $hr->post('/certs/admin/types', ['le' => $lePid, 'code' => 'emp', 'name_th' => 'หนังสือรับรองการทำงาน', 'template_th' => $tpl, 'template_en' => '', 'max_copies' => '2',
            'signer_name' => 'นางสาวตัวอย่าง ใจดี', 'signer_title' => 'ผู้จัดการฝ่ายบุคคล'])->status);
        $type = $db->one("SELECT * FROM cert_types WHERE code = 'EMP'");

        // ask — no approval; checks
        $form = $emp->get('/certs');
        self::assertSame(200, $form->status);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($form->body));
        self::assertSame(422, $emp->post('/certs', ['type' => $type['public_id'], 'language' => 'th', 'copies' => '3', 'purpose' => 'ยื่นกู้ธนาคาร'])->status, 'copies over the type limit');
        $emp->get('/certs');
        self::assertSame(422, $emp->post('/certs', ['type' => $type['public_id'], 'language' => 'en', 'copies' => '1', 'purpose' => 'ยื่นกู้ธนาคาร'])->status, 'no English template');
        $emp->get('/certs');
        self::assertSame(303, $emp->post('/certs', ['type' => $type['public_id'], 'language' => 'th', 'copies' => '2', 'purpose' => 'ยื่นกู้ธนาคาร'])->status, $this->errorsLogged());
        $req = $db->one('SELECT * FROM cert_requests ORDER BY id DESC LIMIT 1');
        self::assertSame('submitted', $req['status']);
        self::assertMatchesRegularExpression('/^CR-2026-\d{5}$/', (string) $req['request_no']);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'certs.new'", [(int) $this->t->user('hr.demo1')['id']]));
        $pid = (string) $req['public_id'];
        self::assertSame(404, $this->browser('manager.demo1')->get('/certs/r/' . $pid)->status, 'not someone else\'s');

        // HR: queue, take, ask for info → answer
        $q = $hr->get('/certs/admin');
        self::assertSame(200, $q->status);
        self::assertStringContainsString((string) $req['request_no'], $q->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($q->body));
        $work = $hr->get('/certs/admin/r/' . $pid);
        self::assertSame(200, $work->status, $this->errorsLogged());
        $hr->post('/certs/admin/r/' . $pid . '/take', []);
        $hr->get('/certs/admin/r/' . $pid);
        self::assertSame(422, $hr->post('/certs/admin/r/' . $pid . '/info', ['note' => ''])->status);
        $hr->get('/certs/admin/r/' . $pid);
        $hr->post('/certs/admin/r/' . $pid . '/info', ['note' => 'ต้องการชื่อธนาคารที่ยื่น']);
        self::assertSame('need_info', $db->scalar('SELECT status FROM cert_requests WHERE id = ?', [$req['id']]));
        $mine = $emp->get('/certs/r/' . $pid);
        self::assertStringContainsString('ต้องการชื่อธนาคารที่ยื่น', $mine->body);
        $emp->post('/certs/r/' . $pid . '/answer', ['answer' => 'ธนาคารตัวอย่าง']);
        self::assertSame('submitted', $db->scalar('SELECT status FROM cert_requests WHERE id = ?', [$req['id']]));
        $hr->get('/certs/admin/r/' . $pid);
        $hr->post('/certs/admin/r/' . $pid . '/take', []);

        // issue: fields come from the employee record; frozen under certs.certificate; ready needs an issued document
        $hr->get('/certs/admin/r/' . $pid);
        $hr->post('/certs/admin/r/' . $pid . '/ready', []);
        self::assertSame('preparing', $db->scalar('SELECT status FROM cert_requests WHERE id = ?', [$req['id']]), 'issue first');
        $w = $hr->get('/certs/admin/r/' . $pid)->body;
        self::assertStringContainsString('value="พนักงานตัวอย่าง (DEMO)"', $w, 'name prefilled');
        self::assertStringContainsString('value="2023-03-01"', $w, 'start date prefilled');
        self::assertStringContainsString('value="นางสาวตัวอย่าง ใจดี"', $w, 'signer prefilled');
        $vals = ['employee_name' => 'พนักงานตัวอย่าง (DEMO)', 'position' => 'พนักงานขาย', 'start_date' => '2023-03-01', 'purpose' => 'ยื่นกู้ธนาคาร', 'signer_name' => 'นางสาวตัวอย่าง ใจดี', 'signer_title' => 'ผู้จัดการฝ่ายบุคคล'];
        self::assertSame(422, $hr->post('/certs/admin/r/' . $pid . '/issue', ['f' => ['position' => ''] + $vals])->status, 'required template field');
        $hr->get('/certs/admin/r/' . $pid);
        self::assertSame(303, $hr->post('/certs/admin/r/' . $pid . '/issue', ['f' => $vals])->status, $this->errorsLogged());
        $iss = $db->one('SELECT * FROM cert_issues WHERE request_id = ?', [$req['id']]);
        self::assertMatchesRegularExpression('/^HRC-2026-\d{4}$/', (string) $iss['document_number']);
        self::assertSame('certs.certificate', $db->scalar('SELECT document_class FROM documents WHERE public_id = ?', [$iss['document_pid']]));
        $doc = $hr->get('/certs/r/' . $pid . '/doc');
        self::assertSame(200, $doc->status);
        self::assertStringContainsString('พนักงานตัวอย่าง (DEMO)', $doc->body);
        self::assertSame(404, $emp->get('/certs/r/' . $pid . '/doc')->status, 'requester sees it after receiving');
        $hr->get('/certs/admin/r/' . $pid);
        self::assertSame(422, $hr->post('/certs/admin/r/' . $pid . '/issue', ['f' => $vals])->status, 'issuing again needs a reason');
        $hr->get('/certs/admin/r/' . $pid);
        $hr->post('/certs/admin/r/' . $pid . '/issue', ['f' => ['position' => 'พนักงานขายอาวุโส'] + $vals, 'void_reason' => 'แก้ชื่อตำแหน่ง']);
        self::assertSame(['void', 'issued'], array_column($db->all('SELECT status FROM cert_issues WHERE request_id = ? ORDER BY id', [$req['id']]), 'status'));
        $hr->get('/certs/admin/r/' . $pid);
        $hr->post('/certs/admin/r/' . $pid . '/ready', []);
        $code = (string) $db->scalar('SELECT pickup_code FROM cert_requests WHERE id = ?', [$req['id']]);
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        $ready = $emp->get('/certs/r/' . $pid)->body;
        self::assertStringContainsString($code, $ready, 'the requester sees the pickup code');
        self::assertStringNotContainsString($code, $this->browser('hr.head1')->get('/certs/admin')->body);

        // handover with the code (wrong code refused, proxy needs a note)
        $hr->get('/certs/admin/r/' . $pid);
        self::assertSame(422, $hr->post('/certs/admin/r/' . $pid . '/handover', ['code' => '000000' === $code ? '111111' : '000000', 'receiver' => 'พนักงานตัวอย่าง', 'relation' => 'self'])->status);
        $hr->get('/certs/admin/r/' . $pid);
        self::assertSame(422, $hr->post('/certs/admin/r/' . $pid . '/handover', ['code' => $code, 'receiver' => 'คนรับแทน', 'relation' => 'proxy', 'proxy_note' => ''])->status);
        $hr->get('/certs/admin/r/' . $pid);
        self::assertSame(303, $hr->post('/certs/admin/r/' . $pid . '/handover', ['code' => $code, 'receiver' => 'สมใจ รับแทน', 'relation' => 'proxy', 'proxy_note' => 'หนังสือมอบอำนาจ 9 พ.ย.'])->status);
        $r2 = $db->one('SELECT * FROM cert_requests WHERE id = ?', [$req['id']]);
        self::assertSame(['received', 'proxy', null], [$r2['status'], $r2['receiver_relation'], $r2['pickup_code']]);
        $copy = $emp->get('/certs/r/' . $pid . '/doc');
        self::assertSame(200, $copy->status);
        self::assertStringContainsString('พนักงานขายอาวุโส', $copy->body, 'the current (re-issued) document');

        // a new request waits for the rating; rating 1–5
        $emp->get('/certs');
        $emp->post('/certs', ['type' => $type['public_id'], 'language' => 'th', 'copies' => '1', 'purpose' => 'ทำวีซ่า']);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM cert_requests'), 'rate first');
        $emp->get('/certs/r/' . $pid);
        self::assertSame(422, $emp->post('/certs/r/' . $pid . '/rate', ['rating' => '7'])->status);
        $emp->get('/certs/r/' . $pid);
        $emp->post('/certs/r/' . $pid . '/rate', ['rating' => '5', 'comment' => 'รวดเร็ว']);
        self::assertSame(5, (int) $db->scalar('SELECT rating FROM cert_requests WHERE id = ?', [$req['id']]));
        $emp->get('/certs');
        self::assertSame(303, $emp->post('/certs', ['type' => $type['public_id'], 'language' => 'th', 'copies' => '1', 'purpose' => 'ทำวีซ่า'])->status);
        self::assertStringContainsString('5/5', $hr->get('/certs/admin')->body);

        // the second one: HR cancels with a reason
        $second = (string) $db->scalar('SELECT public_id FROM cert_requests ORDER BY id DESC LIMIT 1');
        $hr->get('/certs/admin/r/' . $second);
        self::assertSame(422, $hr->post('/certs/admin/r/' . $second . '/cancel', ['reason' => ''])->status);
        $hr->get('/certs/admin/r/' . $second);
        $hr->post('/certs/admin/r/' . $second . '/cancel', ['reason' => 'ผู้ขอแจ้งไม่ต้องการแล้ว']);
        self::assertSame('cancelled', $db->scalar('SELECT status FROM cert_requests WHERE public_id = ?', [$second]));
        $emp->get('/certs/r/' . $second);
        self::assertSame(303, $emp->post('/certs/r/' . $second . '/rate', ['rating' => '3'])->status);
        self::assertNull($db->scalar('SELECT rating FROM cert_requests WHERE public_id = ?', [$second]), 'nothing to rate on a cancelled request');
        $page = $emp->get('/certs/r/' . $pid);
        self::assertSame(200, $page->status);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page->body));
        self::assertSame(403, $emp->get('/certs/admin')->status);

        // a third: handed over, the employee cannot rate → HR waives with a reason → a new request is allowed again
        $emp->get('/certs');
        $emp->post('/certs', ['type' => $type['public_id'], 'language' => 'th', 'copies' => '1', 'purpose' => 'สมัครเรียนต่อ']);
        $third = (string) $db->scalar('SELECT public_id FROM cert_requests ORDER BY id DESC LIMIT 1');
        foreach ([['take', []], ['issue', ['f' => $vals]], ['ready', []]] as [$a, $body]) {
            $hr->get('/certs/admin/r/' . $third);
            $hr->post('/certs/admin/r/' . $third . '/' . $a, $body);
        }
        $hr->get('/certs/admin/r/' . $third);
        $hr->post('/certs/admin/r/' . $third . '/handover', ['code' => (string) $db->scalar('SELECT pickup_code FROM cert_requests WHERE public_id = ?', [$third]), 'receiver' => 'พนักงานตัวอย่าง', 'relation' => 'self']);
        self::assertSame('received', $db->scalar('SELECT status FROM cert_requests WHERE public_id = ?', [$third]));
        $hr->get('/certs/admin/r/' . $third);
        self::assertSame(422, $hr->post('/certs/admin/r/' . $third . '/waive', ['waive_reason' => ''])->status);
        $hr->get('/certs/admin/r/' . $third);
        $hr->post('/certs/admin/r/' . $third . '/waive', ['waive_reason' => 'ผู้ขอย้ายไปทำงานต่างประเทศ ให้คะแนนไม่ได้']);
        $emp->get('/certs');
        self::assertSame(303, $emp->post('/certs', ['type' => $type['public_id'], 'language' => 'th', 'copies' => '1', 'purpose' => 'อื่น ๆ'])->status);
        self::assertSame(4, (int) $db->scalar('SELECT COUNT(*) FROM cert_requests'));
    }
}
