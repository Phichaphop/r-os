<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Files\Xlsx;
use Blue\Tests\Support\TestApp;

/** Phase 3B screens through the HTTP kernel: rendering, rights, uploads, print view, search, brand files, exports. */
final class RecordsWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{])(document|template|records|calendar|import|search|file|nav|settings|common|form)\.[a-z_]+\.?[a-z_.]*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function le(string $code): int
    {
        return (int) $this->t->app->db()->scalar('SELECT id FROM legal_entities WHERE code = ?', [$code]);
    }

    public function testPagesRenderTranslatedForHrAndAdmin(): void
    {
        $hr = $this->t->browser();
        $hr->login('hr.demo1');
        $tpl = (string) $this->t->app->db()->scalar("SELECT public_id FROM document_templates WHERE code = 'DEMO_EMPLOYMENT_CERT'");
        $cal = (string) $this->t->app->db()->scalar("SELECT public_id FROM calendars WHERE code = 'DEMO1-WORK'");
        foreach (
            ['/documents', '/documents/new', '/templates', '/templates/' . $tpl, '/calendars', '/calendars?month=2026-12', '/admin/calendars', '/admin/calendars/' . $cal, '/admin/calendars/' . $cal . '?month=2026-12',
            '/import/calendar.days?calendar=' . $cal, '/search?q=DEMO'] as $p
        ) {
            [$path, $qs] = array_pad(explode('?', $p, 2), 2, '');
            parse_str($qs, $q);
            $res = $hr->get($path, $q);
            self::assertSame(200, $res->status, $p);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body), "untranslated key on {$p}");
        }
        $sys = $this->t->browser();
        $sys->login(TestApp::ADMIN);
        foreach (['/admin/records', '/admin/records/rules/employee_file', '/admin/records/holds', '/admin/records/disposition', '/templates/' . $tpl, '/templates/new', '/admin/settings/appearance'] as $p) {
            $res = $sys->get($p);
            self::assertSame(200, $res->status, $p);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body), "untranslated key on {$p}");
        }
        self::assertStringContainsString('href="/documents"', $hr->get('/')->body, 'menu shows Documents');
        self::assertStringNotContainsString('href="/admin/records"', $hr->get('/')->body, 'HR admin has no records-retention menu');
    }

    public function testRightsOnScreens(): void
    {
        $emp = $this->t->browser();
        $emp->login('employee.demo1');
        foreach (['/documents/new', '/templates', '/admin/records', '/templates/new'] as $p) {
            self::assertSame(403, $emp->get($p)->status, $p);
        }
        self::assertSame(200, $emp->get('/calendars')->status, 'employees may see calendars');
        $sys = $this->t->browser();
        $sys->login(TestApp::ADMIN);
        $tpl = (string) $this->t->app->db()->scalar("SELECT public_id FROM document_templates WHERE code = 'DEMO_EMPLOYMENT_CERT'");
        self::assertStringNotContainsString('name="f_employee_name"', $sys->get('/templates/' . $tpl)->body, 'system admin designs templates but does not issue letters');
    }

    public function testUploadIssuePrintAndSearchThroughTheWeb(): void
    {
        $hr = $this->t->browser();
        $hr->login('hr.demo1');
        $hr->get('/documents/new');
        $res = $hr->post('/documents/new', ['class' => 'core.general', 'legal_entity' => (string) $this->le('DEMO1'), 'title' => 'ระเบียบการแต่งกาย (DEMO)'], ['file' => ['dress.pdf', DocumentsTest::PDF]]);
        self::assertSame(303, $res->status, $res->body);
        $doc = (string) $this->t->app->db()->scalar('SELECT public_id FROM documents');
        $show = $hr->get('/documents/' . $doc);
        self::assertStringContainsString('ระเบียบการแต่งกาย', $show->body);
        $dl = $hr->get('/documents/' . $doc . '/download');
        self::assertSame(DocumentsTest::PDF, $dl->body);
        self::assertStringContainsString('attachment', $dl->headers['Content-Disposition']);
        self::assertStringContainsString('sandbox', $dl->headers['Content-Security-Policy']);
        $bad = $hr->post('/documents/new', ['class' => 'core.general', 'legal_entity' => (string) $this->le('DEMO1'), 'title' => 'x'], ['file' => ['x.pdf', 'MZ not a pdf']]);
        self::assertSame(422, $bad->status);

        $tpl = (string) $this->t->app->db()->scalar("SELECT public_id FROM document_templates WHERE code = 'DEMO_EMPLOYMENT_CERT'");
        $hr->get('/templates/' . $tpl);
        $issued = $hr->post('/templates/' . $tpl . '/issue', ['legal_entity' => (string) $this->le('DEMO1'), 'doc_title' => 'หนังสือรับรอง นายเว็บ',
            'f_employee_name' => 'นายเว็บ ทดสอบ', 'f_position' => 'QA', 'f_start_date' => '2025-03-01', 'f_purpose' => 'วีซ่า', 'f_signer_name' => 'HR', 'f_signer_title' => 'ผู้จัดการ']);
        self::assertSame(303, $issued->status, $issued->body);
        $letter = basename((string) $issued->headers['Location']);
        $view = $hr->get('/documents/' . $letter . '/view');
        self::assertSame(200, $view->status);
        self::assertStringContainsString('นายเว็บ ทดสอบ', $view->body);
        self::assertStringContainsString('id="blue-print"', $view->body);
        self::assertMatchesRegularExpression("/script-src 'nonce-[A-Za-z0-9_-]+'/", $view->headers['Content-Security-Policy']);

        $found = $hr->get('/search', ['q' => 'นายเว็บ']);
        self::assertStringContainsString('/documents/' . $letter, $found->body);
        $other = $this->t->browser();
        $other->login('hr.demo2');
        self::assertStringNotContainsString('/documents/' . $letter, $other->get('/search', ['q' => 'นายเว็บ'])->body, 'search never shows another company\'s document');
        self::assertSame(404, $other->get('/documents/' . $letter)->status);
        self::assertSame(404, $other->get('/documents/' . $letter . '/view')->status);
    }

    public function testBrandLogoUploadIsPublicAndUsedInTheShell(): void
    {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $sys = $this->t->browser();
        $sys->login(TestApp::ADMIN);
        $sys->get('/admin/settings/appearance');
        $sys->post('/admin/settings/appearance/brand', ['kind' => 'logo'], ['file' => ['logo.png', $png]]);
        $this->t->app->appearance()->reset();
        $page = $sys->get('/admin/users');
        self::assertMatchesRegularExpression('#class="brand-logo" src="/brand/logo\?v=[0-9a-f]{12}"#', $page->body);
        self::assertStringContainsString('href="/brand/favicon?v=', $page->body, 'favicon falls back to the logo');
        $guest = $this->t->browser();
        $logo = $guest->get('/brand/logo');
        self::assertSame(200, $logo->status, 'public — shown on the sign-in page');
        self::assertSame($png, $logo->body);
        self::assertSame('image/png', $logo->headers['Content-Type']);
        if (\Blue\Core\Files\ImageResizer::available()) {
            // a large picture is shrunk automatically instead of being refused
            $img = imagecreatetruecolor(2400, 1200);
            imagefilledrectangle($img, 0, 0, 2399, 1199, (int) imagecolorallocate($img, 14, 98, 101));
            ob_start();
            imagejpeg($img, null, 98);
            $jpg = (string) ob_get_clean();
            $this->t->clock->advance('+1 minute');
            $res = $sys->post('/admin/settings/appearance/brand', ['kind' => 'logo'], ['file' => ['big-photo.jpeg', $jpg]]);
            self::assertSame(303, $res->status);
            self::assertStringContainsString('512×256', (string) $sys->get('/admin/settings/appearance')->body);
            $this->t->app->appearance()->reset();
            $shrunk = $guest->get('/brand/logo');
            self::assertSame('image/jpeg', $shrunk->headers['Content-Type']);
            self::assertSame([512, 256], array_slice((array) getimagesizefromstring($shrunk->body), 0, 2));
            self::assertSame(2, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM documents WHERE document_class = 'core.brand_asset'"));
        }
        $svg = $sys->post('/admin/settings/appearance/brand', ['kind' => 'logo'], ['file' => ['x.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>']]);
        self::assertSame(303, $svg->status);
        self::assertSame(\Blue\Core\Files\ImageResizer::available() ? 2 : 1, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM documents WHERE document_class = 'core.brand_asset'"), 'SVG refused');
        $this->t->clock->advance('+1 minute'); // a new config version needs a later start time
        $sys->post('/admin/settings/appearance/brand', ['kind' => 'logo', 'reset' => '1']);
        $this->t->app->appearance()->reset();
        self::assertSame((string) file_get_contents(dirname(__DIR__, 2) . '/ui/icons/logo-blue.png'), $guest->get('/brand/logo')->body, 'back to the logo shipped with the system (0.37.0)');
    }

    public function testExportsRespectRightsAndProduceExcel(): void
    {
        $sys = $this->t->browser();
        $sys->login(TestApp::ADMIN);
        $cal = (string) $this->t->app->db()->scalar("SELECT public_id FROM calendars WHERE code = 'DEMO1-WORK'");
        $x = $sys->get('/calendars/' . $cal . '/export', ['year' => '2026']);
        $rows = Xlsx::read($x->body);
        self::assertSame(['date', 'type', 'kind', 'name', 'note'], $rows[0]);
        self::assertSame('2026-12-30', $rows[1][0]);
        $users = $sys->get('/admin/users/export');
        self::assertStringContainsString('/reauth', (string) ($users->headers['Location'] ?? ''), 'user list export needs the password again');
        $sys->get('/reauth');
        $sys->post('/reauth', ['password' => TestApp::ADMIN_PASSWORD, 'next' => '/admin/users/export']);
        $users = $sys->get('/admin/users/export', ['format' => 'csv']);
        self::assertStringStartsWith("\xEF\xBB\xBF", $users->body);
        self::assertStringContainsString('hr.demo1', $users->body);
        self::assertSame(200, $sys->get('/admin/config/export')->status);
        $aud = $this->t->browser();
        $aud->login('auditor.demo');
        $aud->get('/reauth');
        $aud->post('/reauth', ['password' => $this->t->demoPassword, 'next' => '/admin/audit']);
        $ax = $aud->get('/admin/audit/export', ['format' => 'xlsx']);
        self::assertSame(200, $ax->status);
        self::assertSame('id', Xlsx::read($ax->body)[0][0]);
        $emp = $this->t->browser();
        $emp->login('employee.demo1');
        self::assertSame(403, $emp->get('/admin/users/export')->status);
        $api = $emp->get('/api/calendar/working-day', ['legal_entity' => (string) $this->le('DEMO1'), 'date' => '2026-12-30']);
        self::assertSame(['date' => '2026-12-30', 'working' => false, 'calendar' => 'DEMO1-WORK', 'type' => 'holiday', 'name' => 'วันหยุดบริษัทตัวอย่าง (DEMO)'], json_decode($api->body, true));
    }
}
