<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use Blue\Modules\Pdf\PdfFeatures;
use Blue\Modules\Pdf\PdfService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;
use PHPUnit\Framework\TestCase;

/**
 * 0.39.0 (R3): PDF tools in ILovePDF style — grouped tool cards, every page shown as a picture (drawn by PDF.js from
 * the job's own preview URL), drag to reorder / rotate / drop pages; nothing kept until Save (8.2): the originals
 * are deleted as soon as the result exists, unsaved results live pdf.result_minutes and are never listed; the
 * signature tool (3.1) runs only in the browser — the page cannot send a signature anywhere.
 */
final class PdfR3WebTest extends TestCase
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

    private function browser(string $user): TestBrowser
    {
        $b = $this->t->browser();
        $b->login($user);
        return $b;
    }

    private static function fx(string $n): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/' . $n);
    }

    /** @return list<string> */
    private function tempFiles(string $pid): array
    {
        return array_map('basename', glob($this->t->app->path($this->t->app->config->string('PDF_TEMP_PATH', 'storage/pdf-temp')) . '/' . $pid . '-*') ?: []);
    }

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar('SELECT MIN(id) FROM legal_entities');
    }

    public function testToolPageThumbnailsShortLivedResultsAndSavedList(): void
    {
        $db = $this->t->app->db();
        $emp = $this->browser('hr.demo1'); // may save to the document register
        $home = $emp->get('/pdf')->body;
        foreach (array_keys(PdfFeatures::GROUPS) as $g) {
            self::assertStringContainsString('pdf-group--' . $g, $home, 'tool group ' . $g);
        }
        self::assertStringContainsString('href="/pdf/sign"', $home, 'the signature tool is a card');
        self::assertStringContainsString('ทำในเบราว์เซอร์ ไม่อัปโหลด', $home);
        self::assertStringContainsString('ผลลัพธ์ที่ไม่ได้บันทึกถูกลบใน 60 นาที', $home);
        self::assertStringContainsString('class="pdf-hero__title"', $emp->get('/pdf/tool/organize')->body, 'ILovePDF-style centred step 1');

        // step 2: every page is a picture of the staged original; cards carry the same form fields as before
        $emp->post('/pdf/tool/organize', [], ['files' => ['a.pdf', self::fx('a.pdf')]]);
        $pid = (string) $db->scalar('SELECT public_id FROM pdf_jobs ORDER BY id DESC LIMIT 1');
        $job = $db->one('SELECT created_at, expires_at FROM pdf_jobs WHERE public_id = ?', [$pid]);
        self::assertSame('+60 minutes', $this->minutes((string) $job['created_at'], (string) $job['expires_at']), 'staged originals live pdf.result_minutes');
        $page = $emp->get('/pdf/job/' . $pid)->body;
        self::assertStringContainsString('data-pdf-pages="organize"', $page);
        self::assertSame(3, substr_count($page, 'data-src="/pdf/job/' . $pid . '/preview/in/0"'), 'one picture per page');
        self::assertMatchesRegularExpression('#data-lib="[^"]*vendor/pdfjs/pdf\.min\.js#', $page);
        self::assertStringContainsString('ui/js/pdf-pages.js', $page);
        foreach (['keep[2]', 'pos[2]', 'rot[2]'] as $f) {
            self::assertStringContainsString('name="' . $f . '"', $page, 'works without script too: ' . $f);
        }
        $pv = $emp->get('/pdf/job/' . $pid . '/preview/in/0');
        self::assertSame(200, $pv->status);
        self::assertSame('application/pdf', $pv->headers['Content-Type']);
        self::assertStringStartsWith('%PDF', $pv->body);
        self::assertStringContainsString('sandbox', $pv->headers['Content-Security-Policy']);
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'pdf.downloaded' AND target_id = ?", [$pid]), 'a preview is not a download');
        self::assertSame(404, $emp->get('/pdf/job/' . $pid . '/preview/in/5')->status);
        self::assertSame(404, $emp->get('/pdf/job/' . $pid . '/preview/out/0')->status, 'no result yet');
        $other = $this->browser('employee.demo1');
        self::assertSame(404, $other->get('/pdf/job/' . $pid . '/preview/in/0')->status, 'previews are private like the job');

        // run: drop page 2, move page 3 first, turn it → the originals are deleted at once; the result lives 60 minutes
        self::assertSame(['in0'], array_map(static fn (string $f): string => substr($f, 27, -4), $this->tempFiles($pid)));
        $this->t->clock->advance('+5 minutes');
        $emp->post('/pdf/job/' . $pid . '/run', ['keep' => ['1', '', '1'], 'pos' => ['2', '3', '1'], 'rot' => ['0', '0', '90']]);
        $row = $db->one('SELECT status, params_json, finished_at, expires_at, saved_at FROM pdf_jobs WHERE public_id = ?', [$pid]);
        self::assertSame('done', $row['status']);
        self::assertSame(['order' => ['3@90', '1']], json_decode((string) $row['params_json'], true));
        self::assertSame(['out0'], array_map(static fn (string $f): string => substr($f, 27, -4), $this->tempFiles($pid)), '8.2: originals gone, only the result');
        self::assertSame('+60 minutes', $this->minutes((string) $row['finished_at'], (string) $row['expires_at']));
        self::assertNull($row['saved_at']);
        self::assertSame(404, $emp->get('/pdf/job/' . $pid . '/preview/in/0')->status, 'the originals are not kept');
        self::assertStringStartsWith('%PDF', $emp->get('/pdf/job/' . $pid . '/preview/out/0')->body);
        $res = $emp->get('/pdf/job/' . $pid)->body;
        self::assertStringContainsString('ยังไม่ได้บันทึก — ไฟล์นี้จะถูกลบ', $res);
        self::assertStringContainsString('data-src="/pdf/job/' . $pid . '/preview/out/0"', $res, 'the result is shown as a picture');
        self::assertStringContainsString('/pdf/job/' . $pid . '/file/0/save', $res);
        self::assertStringNotContainsString('/pdf/job/' . $pid, $emp->get('/pdf')->body, 'unsaved: not listed');

        // save → listed under saved results (with the document); a second job left unsaved never shows up
        $emp->post('/pdf/job/' . $pid . '/file/0/save', ['class' => 'core.general', 'legal_entity_id' => (string) $this->le(), 'title' => 'จัดหน้าแล้ว']);
        self::assertNotNull($db->scalar('SELECT saved_at FROM pdf_jobs WHERE public_id = ?', [$pid]));
        $doc = (string) $db->scalar("SELECT public_id FROM documents WHERE related_type = 'pdf_job' AND related_id = ?", [$pid]);
        $list = $emp->get('/pdf')->body;
        self::assertStringContainsString('/pdf/job/' . $pid, $list);
        self::assertStringContainsString('/documents/' . $doc, $list);
        self::assertStringContainsString('บันทึกเข้าทะเบียนเอกสารแล้ว', $emp->get('/pdf/job/' . $pid)->body);
        $emp->get('/pdf/tool/compress');
        $emp->post('/pdf/tool/compress', [], ['files' => ['b.pdf', self::fx('b.pdf')]]);
        $p2 = (string) $db->scalar('SELECT public_id FROM pdf_jobs ORDER BY id DESC LIMIT 1');
        $emp->post('/pdf/job/' . $p2 . '/run', []);
        self::assertStringNotContainsString('/pdf/job/' . $p2, $emp->get('/pdf')->body);

        // after pdf.result_minutes the unsaved result is gone; the saved document stays in the register
        $this->t->clock->advance('+61 minutes');
        PdfService::of($this->t->app)->cleanup();
        $emp = $this->browser('hr.demo1'); // the session idled out meanwhile
        self::assertSame([], $this->tempFiles($p2));
        self::assertSame(410, $emp->get('/pdf/job/' . $p2 . '/preview/out/0')->status);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM documents WHERE public_id = ?', [$doc]));
        self::assertStringContainsString('/pdf/job/' . $pid, $emp->get('/pdf')->body, 'still listed: it was saved');
    }

    public function testPageTilesForMergePickingAndViewOnlyTools(): void
    {
        $db = $this->t->app->db();
        $emp = $this->browser('employee.demo1');
        $emp->get('/pdf/tool/merge');
        $emp->post('/pdf/tool/merge', [], ['files' => [['a.pdf', self::fx('a.pdf')], ['b.pdf', self::fx('b.pdf')]]]);
        $pid = (string) $db->scalar('SELECT public_id FROM pdf_jobs ORDER BY id DESC LIMIT 1');
        $page = $emp->get('/pdf/job/' . $pid)->body;
        self::assertStringContainsString('data-pdf-pages="order"', $page);
        self::assertStringContainsString('data-src="/pdf/job/' . $pid . '/preview/in/1"', $page, 'first page of each file');
        self::assertStringContainsString('name="order[1]"', $page);
        self::assertSame(200, $emp->get('/pdf/job/' . $pid . '/preview/in/1')->status);

        $emp->get('/pdf/tool/extract');
        $emp->post('/pdf/tool/extract', [], ['files' => ['a.pdf', self::fx('a.pdf')]]);
        $ex = (string) $db->scalar('SELECT public_id FROM pdf_jobs ORDER BY id DESC LIMIT 1');
        $page = $emp->get('/pdf/job/' . $ex)->body;
        self::assertStringContainsString('data-pdf-pages="pick"', $page);
        self::assertSame(3, substr_count($page, 'data-pick='), 'tick pages to fill the pages field');
        self::assertStringContainsString('name="pages"', $page);

        $emp->get('/pdf/tool/images');
        $emp->post('/pdf/tool/images', [], ['files' => [['p.png', self::fx('alpha.png')], ['j.jpg', self::fx('img.jpg')]]]);
        $im = (string) $db->scalar('SELECT public_id FROM pdf_jobs ORDER BY id DESC LIMIT 1');
        self::assertStringContainsString('<img class="pdf-thumb__img" src="/pdf/job/' . $im . '/preview/in/1"', $emp->get('/pdf/job/' . $im)->body);
        self::assertSame('image/jpeg', $emp->get('/pdf/job/' . $im . '/preview/in/1')->headers['Content-Type']);
    }

    public function testSignatureToolNeverSendsTheSignature(): void
    {
        $emp = $this->browser('employee.demo1');
        $res = $emp->get('/pdf/sign');
        self::assertSame(200, $res->status);
        $body = $res->body;
        $sign = substr($body, (int) strpos($body, 'data-sign'));
        self::assertStringNotContainsString('<form', substr($sign, 0, (int) strpos($sign, 'ui/js/pdf-sign.js')), 'no form: nothing can be posted');
        self::assertMatchesRegularExpression('#<input class="input" type="file" id="sg-pdf" accept="\.pdf,application/pdf" data-pdf#', $body, 'the PDF input has no name');
        self::assertStringContainsString('<canvas class="sign__pad" data-pad', $body);
        self::assertMatchesRegularExpression('#data-pdflib="[^"]*vendor/pdflib/pdf-lib\.min\.js#', $body);
        self::assertStringContainsString('ไม่ถูกส่งไปที่เซิร์ฟเวอร์', $body);
        self::assertStringContainsString('ภาพลายเซ็นไม่ใช่การยืนยันตัวตน', $body);
        self::assertContains($emp->post('/pdf/sign', ['sig' => 'data:image/png;base64,AAAA'])->status, [404, 405], 'there is no endpoint that accepts a signature');

        $root = dirname(__DIR__, 2);
        $lib = (string) file_get_contents($root . '/ui/js/vendor/pdflib/pdf-lib.min.js');
        self::assertDoesNotMatchRegularExpression('/\beval\s*\(|new Function\s*\(/', $lib, 'runs under the strict CSP');
        $js = (string) file_get_contents($root . '/ui/js/pdf-sign.js');
        self::assertDoesNotMatchRegularExpression('/\bfetch\s*\(|XMLHttpRequest|sendBeacon|localStorage|sessionStorage|indexedDB/', $js, 'never sent or stored');

        // an administrator can switch the browser-only tool off like any other
        $admin = $this->browser(TestApp::ADMIN);
        $admin->get('/pdf/admin');
        $on = array_fill_keys(array_diff(PdfFeatures::switchable(), ['sign']), '1');
        $admin->post('/pdf/admin', ['enabled' => $on, 'reason' => 'ทดสอบปิดเครื่องมือเซ็น', 'result_minutes' => '30']);
        self::assertSame(['sign'], $this->t->app->registry()->resolveOrNull('pdf.disabled_features')?->value);
        self::assertSame(30, $this->t->app->registry()->resolveOrNull('pdf.result_minutes')?->value);
        self::assertStringNotContainsString('data-sign', $emp->get('/pdf/sign')->body);
        self::assertStringContainsString('ผลลัพธ์ที่ไม่ได้บันทึกถูกลบใน 30 นาที', $emp->get('/pdf')->body);
    }

    private function minutes(string $from, string $to): string
    {
        $m = (int) round(((new \DateTimeImmutable($to))->getTimestamp() - (new \DateTimeImmutable($from))->getTimestamp()) / 60);
        return '+' . $m . ' minutes';
    }
}
