<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Integrations\HttpTransport;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/**
 * 0.6.1 UI improvements through the HTTP kernel: delegate sub-menu, external links on Home (managed under
 * Administration), "my work calendar" vs calendar administration, org chart diagram, my display settings (dark mode,
 * highlight colour, font) and the organization font (bundled or imported from a link — the network is faked).
 */
final class UiEnhancementsWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(look|font|links|calendar|org|settings|nav|home|workflow)\.[a-z_]+\.?[a-z_.]*\b(?![^<]*>)/';

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

    private function noRawKeys(string $html, string $where): void
    {
        $text = (string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $html);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($text), "untranslated key on {$where}");
    }

    public function testDelegateIsASubMenuWithItsOwnPage(): void
    {
        $emp = $this->as('employee.demo1');
        $home = $emp->get('/')->body;
        self::assertMatchesRegularExpression('#href="/requests".*?class="sidebar__link sidebar__link--sub"[^>]*href="/approvals/delegate"|sidebar__link--sub" href="/approvals/delegate"#s', $home);
        $page = $emp->get('/approvals/delegate');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('action="/approvals/delegations"', $page->body);
        $this->noRawKeys($page->body, '/approvals/delegate');
        $inbox = $emp->get('/approvals')->body;
        self::assertStringNotContainsString('action="/approvals/delegations"', $inbox, 'the form moved off the inbox');
        self::assertStringContainsString('href="/approvals/delegate"', $inbox);
        // only the most specific menu item is "current"
        self::assertSame(1, substr_count($page->body, 'aria-current="page"'));
    }

    public function testExternalLinksAreManagedByAdminsAndShownOnHome(): void
    {
        $db = $this->t->app->db();
        $sys = $this->as(TestApp::ADMIN);
        $home = $sys->get('/')->body;
        self::assertStringContainsString('href="/admin/links"', $home, 'management sits under Administration');
        self::assertDoesNotMatchRegularExpression('#class="sidebar__link[^"]*" href="/links"#', $home, 'no longer a tools menu item');
        $old = $sys->get('/links/manage');
        self::assertSame(301, $old->status);
        self::assertSame('/admin/links', $old->headers['Location']);
        self::assertSame(200, $sys->get('/admin/links')->status);
        $sys->get('/admin/links/new');
        $sys->post('/admin/links/new', ['name_th' => 'ระบบลางานเดิม (DEMO)', 'url' => 'https://leave.example.co.th/', 'category' => 'hr', 'open_new_tab' => '1']);
        $sys->post('/admin/links/new', ['name_th' => 'เฉพาะ HR (DEMO)', 'url' => 'https://hr-only.example.co.th/', 'category' => 'hr', 'audience_roles' => ['HRA']]);
        foreach ($db->all("SELECT public_id FROM link_items WHERE name_th LIKE '%(DEMO)' AND url LIKE '%.example.co.th/'") as $l) {
            $sys->get('/admin/links/' . $l['public_id']);
            $sys->post('/admin/links/' . $l['public_id'] . '/publish', ['purpose' => 'ทดสอบหน้าแรก', 'confirm' => '1']);
        }
        self::assertSame(2, (int) $db->scalar("SELECT COUNT(*) FROM link_items WHERE status = 'published' AND url LIKE '%.example.co.th/'"));

        $emp = $this->as('employee.demo1');
        self::assertSame(403, $emp->get('/admin/links')->status);
        $h = $emp->get('/')->body;
        self::assertStringContainsString('ลิงก์เว็บไซต์ภายนอก', $h);
        self::assertStringContainsString('ระบบลางานเดิม (DEMO)', $h);
        self::assertStringNotContainsString('เฉพาะ HR (DEMO)', $h, 'audience still applies on Home');
        self::assertStringNotContainsString('leave.example.co.th/"', $h, 'cards go through the counted redirect');
        self::assertMatchesRegularExpression('#href="/links/go/[0-9A-Z]{26}"#', $h);
        $this->noRawKeys($h, 'home');
        $hr = $this->as('hr.demo1');
        self::assertStringContainsString('เฉพาะ HR (DEMO)', $hr->get('/')->body);
        // module switched off → no section, no error
        $db->run("UPDATE modules SET enabled = 0 WHERE code = 'links'");
        $this->t->app->modules()->forget();
        self::assertStringNotContainsString('ลิงก์เว็บไซต์ภายนอก', $this->as('employee.demo1')->get('/')->body);
    }

    public function testMyWorkCalendarForEveryoneAndAdministrationForManagers(): void
    {
        $emp = $this->as('employee.demo1');
        $page = $emp->get('/calendars');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('DEMO1-WORK', $page->body, 'the calendar of my company');
        self::assertStringContainsString('cal-grid', $page->body);
        self::assertStringNotContainsString('action="/admin/calendars"', $page->body, 'no management on the employee page');
        $this->noRawKeys($page->body, '/calendars');
        self::assertSame(403, $emp->get('/admin/calendars')->status);
        self::assertStringNotContainsString('href="/admin/calendars"', $emp->get('/')->body);

        $hr = $this->as('hr.demo1');
        $home = $hr->get('/')->body;
        self::assertStringContainsString('href="/calendars"', $home);
        self::assertStringContainsString('href="/admin/calendars"', $home);
        $admin = $hr->get('/admin/calendars');
        self::assertSame(200, $admin->status);
        $this->noRawKeys($admin->body, '/admin/calendars');
        $pid = (string) $this->t->app->db()->scalar("SELECT public_id FROM calendars WHERE code = 'DEMO1-WORK'");
        self::assertSame(200, $hr->get('/admin/calendars/' . $pid)->status);
        $legacy = $hr->get('/calendars/' . $pid);
        self::assertSame(303, $legacy->status);
        self::assertStringEndsWith('/admin/calendars/' . $pid, $legacy->headers['Location']);
        $hr->post('/admin/calendars/' . $pid . '/days', ['date' => '2026-12-31', 'day_type' => 'holiday', 'kind' => 'company', 'name' => 'วันหยุดทดสอบสิ้นปี']);
        $mine = $emp->get('/calendars', ['month' => '2026-12'])->body;
        self::assertStringContainsString('วันหยุดทดสอบสิ้นปี', $mine, 'employees see holidays HR set');
        // a calendar the person may not view
        $other = (string) $this->t->app->db()->scalar("SELECT public_id FROM calendars WHERE code <> 'DEMO1-WORK' AND scope_level = 'legal_entity' AND scope_id <> (SELECT id FROM legal_entities WHERE code = 'DEMO1') LIMIT 1");
        if ($other !== '') {
            self::assertContains($emp->get('/calendars', ['cal' => $other])->status, [403, 404]);
        }
    }

    public function testOrgChartDiagram(): void
    {
        $sys = $this->as(TestApp::ADMIN);
        $tree = $sys->get('/admin/org');
        self::assertSame(200, $tree->status);
        self::assertStringContainsString('class="oc"', $tree->body);
        self::assertStringContainsString('oc-node--company', $tree->body);
        self::assertStringContainsString('class="oc-tag', $tree->body, '0.8.2 (DEC-69): branches are tags on units, not site nodes');
        self::assertStringContainsString('oc-node--dept', $tree->body);
        $this->noRawKeys($tree->body, '/admin/org');
        $list = $sys->get('/admin/org', ['view' => 'list'])->body;
        self::assertStringNotContainsString('class="oc"', $list);
        self::assertStringContainsString('org-tree', $list);
    }

    public function testMyDisplaySettingsDarkHighlightAndFont(): void
    {
        $emp = $this->as('employee.demo1');
        $page = $emp->get('/me/appearance');
        self::assertSame(200, $page->status);
        $this->noRawKeys($page->body, '/me/appearance');
        self::assertStringNotContainsString('id="my-theme-css"', $page->body, 'nothing personal yet');

        $bad = $emp->post('/me/appearance', ['theme' => 'dark', 'accent' => '#FFEE00', 'font' => 'kanit']);
        self::assertSame(422, $bad->status, 'yellow on white fails WCAG AA');
        self::assertStringContainsString('4.5:1', $bad->body);
        self::assertSame(303, $emp->post('/me/appearance', ['theme' => 'dark', 'accent' => '#6D28D9', 'font' => 'kanit'])->status);
        $home = $emp->get('/')->body;
        self::assertStringContainsString("var def = 'dark'", $home, 'saved theme used before the first paint');
        self::assertMatchesRegularExpression('#<link rel="stylesheet" id="my-theme-css" href="/me/theme.css\?v=[0-9a-f]{12}">#', $home);
        $css = $emp->get('/me/theme.css')->body;
        self::assertStringContainsString('--color-action-primary: #6D28D9', $css);
        self::assertStringContainsString("--font-sans: 'Kanit'", $css);
        self::assertStringContainsString(':root[data-theme="dark"]', $css, 'a dark variant of the highlight is derived');
        // only for this person
        $hr = $this->as('hr.demo1');
        self::assertStringNotContainsString('id="my-theme-css"', $hr->get('/')->body);
        self::assertStringNotContainsString('#6D28D9', $hr->get('/me/theme.css')->body);
        // unknown values are refused, reset goes back to the organization
        self::assertSame(422, $emp->post('/me/appearance', ['theme' => 'neon', 'accent' => '', 'font' => 'comic'])->status);
        $emp->get('/me/appearance');
        $emp->post('/me/appearance', ['reset' => '1']);
        self::assertStringNotContainsString('id="my-theme-css"', $emp->get('/')->body);
        // preview endpoints
        self::assertStringContainsString("'Prompt'", $emp->get('/me/theme-preview.css', ['font' => 'prompt'])->body);
        $j = json_decode($emp->get('/me/theme-preview.css', ['accent' => '#FFEE00', 'format' => 'json'])->body, true);
        self::assertFalse($j['ok']);
    }

    public function testOrganizationFontBundledAndImportedFromALink(): void
    {
        $fake = new class () implements HttpTransport {
            /** @var array<string, array{status: int, body: string}> */
            public array $map = [];
            /** @var list<string> */
            public array $calls = [];

            public function get(string $url, array $headers, int $timeoutSeconds, int $maxBytes): array
            {
                $this->calls[] = $url;
                $r = $this->map[$url] ?? ['status' => 404, 'body' => ''];
                if (strlen($r['body']) > $maxBytes) {
                    return ['status' => 0, 'body' => '', 'error' => 'response_too_large', 'ms' => 1];
                }
                return $r + ['error' => null, 'ms' => 1];
            }
        };
        $this->t->app->setHttpTransport($fake);
        $this->t->app->setFontsDir($this->t->tmp . '/fonts');
        $this->t->app->setFontResolver(static fn (string $host): array => $host === 'intranet.example' ? ['10.1.2.3'] : ['142.250.66.10']);
        $woff2 = 'wOF2' . str_repeat("\x01", 300);
        $css = "/* thai */\n@font-face {\n  font-family: 'Sarabun';\n  font-style: normal;\n  font-weight: 400;\n  font-display: swap;\n"
            . "  src: url(https://fonts.gstatic.com/s/sarabun/v1/thai.woff2) format('woff2');\n  unicode-range: U+0E01-0E5B, U+200C-200D;\n}\n"
            . "@font-face {\n  font-family: 'Sarabun';\n  font-weight: 600;\n  src: url(https://fonts.gstatic.com/s/sarabun/v1/latin600.woff2) format('woff2');\n}\n"
            . "body { background: url(https://evil.example/x.png) } @import url(https://evil.example/a.css);\n";
        $g = 'https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600&display=swap';
        $fake->map[$g] = ['status' => 200, 'body' => $css];
        $fake->map['https://fonts.gstatic.com/s/sarabun/v1/thai.woff2'] = ['status' => 200, 'body' => $woff2];
        $fake->map['https://fonts.gstatic.com/s/sarabun/v1/latin600.woff2'] = ['status' => 200, 'body' => $woff2 . 'x'];
        $fake->map['https://cdn.example/notafont.woff2'] = ['status' => 200, 'body' => '<html>nope</html>'];

        $emp = $this->as('employee.demo1');
        self::assertSame(403, $emp->post('/admin/settings/font', ['font' => 'sarabun'])->status);

        $sys = $this->as(TestApp::ADMIN);
        $page = $sys->get('/admin/settings/appearance');
        self::assertStringContainsString('action="/admin/settings/font"', $page->body);
        $this->noRawKeys($page->body, '/admin/settings/appearance');
        $sys->post('/admin/settings/font', ['font' => 'sarabun']);
        $this->t->app->appearance()->reset();
        self::assertSame('sarabun', $this->t->app->appearance()->current()['font']);
        self::assertStringContainsString("--font-sans: 'Sarabun'", $sys->get('/theme.css')->body);
        self::assertStringContainsString('id="theme-css"', $emp->get('/')->body, 'everyone gets the organization font');

        // unsafe links are refused before anything is downloaded
        foreach (
            ['http://fonts.googleapis.com/css2?family=X' => 'https://', 'https://intranet.example/font.woff2' => 'ภายใน', 'https://user:pw@fonts.googleapis.com/x' => 'https://',
            'https://fonts.googleapis.com:8443/x' => 'https://', 'https://cdn.example/notafont.woff2' => 'ไม่ใช่ไฟล์ฟอนต์'] as $bad => $msg
        ) {
            $sys->get('/admin/settings/appearance');
            $res = $sys->post('/admin/settings/font', ['font' => 'custom', 'font_url' => $bad]);
            self::assertSame(422, $res->status, $bad);
            self::assertStringContainsString($msg, $res->body, $bad);
        }
        self::assertNotContains('https://intranet.example/font.woff2', $fake->calls, 'internal address never fetched');

        $clock = $this->t->app->clock;
        self::assertInstanceOf(\Blue\Core\Support\FrozenClock::class, $clock);
        $clock->advance('+1 minute'); // config versions need a later start than the one saved above (frozen test clock)
        $sys->get('/admin/settings/appearance');
        self::assertSame(303, $sys->post('/admin/settings/font', ['font' => 'custom', 'font_url' => $g, 'font_family' => ''])->status);
        $this->t->app->appearance()->reset();
        $look = $this->t->app->appearance()->current();
        self::assertSame('custom', $look['font']);
        self::assertSame('Sarabun', $look['font_family']);
        self::assertSame($g, $look['font_url']);
        $theme = $sys->get('/theme.css')->body;
        self::assertMatchesRegularExpression("#url\\('/brand/font/[a-f0-9]{64}\\.woff2'\\) format\\('woff2'\\)#", $theme, 'served from Blue OS itself');
        self::assertStringNotContainsString('gstatic', $theme);
        self::assertStringNotContainsString('evil.example', $theme, 'downloaded CSS is rebuilt, never passed through');
        self::assertStringContainsString('unicode-range: U+0E01-0E5B,U+200C-200D', $theme);
        self::assertSame(2, substr_count($theme, '@font-face'));
        preg_match('#/brand/font/([a-f0-9]{64}\.woff2)#', $theme, $m);
        $guest = $this->t->browser();
        $file = $guest->get('/brand/font/' . $m[1]);
        self::assertSame(200, $file->status, 'public like the logo (login page uses it too)');
        self::assertSame('font/woff2', $file->headers['Content-Type']);
        self::assertSame(404, $guest->get('/brand/font/' . str_repeat('0', 64) . '.woff2')->status);
        self::assertSame(404, $guest->get('/brand/font/..%2Fenv.php')->status);
        // the imported font is offered to everyone in "my display settings"
        $mine = $emp->get('/me/appearance')->body;
        self::assertStringContainsString('value="custom"', $mine);
        self::assertSame(303, $emp->post('/me/appearance', ['theme' => 'org', 'accent' => '', 'font' => 'custom'])->status);
    }

    public function testCalculatorClearAndPdfDropzone(): void
    {
        $emp = $this->as('employee.demo1');
        $calc = $emp->get('/calc')->body;
        self::assertStringContainsString('data-calc-clear', $calc);
        self::assertStringContainsString('data-calc-history', $calc);
        $pdf = $emp->get('/pdf/tool/merge')->body;
        self::assertStringContainsString('data-dropzone', $pdf);
        self::assertStringContainsString('name="files[]"', $pdf);
    }

    /** 0.6.2: Home order (my apps → module groups → apps without access last), equal tiles, status cards moved, folding sub-menus. */
    public function testHomeLayoutAndFoldingSubMenus(): void
    {
        $db = $this->t->app->db();
        $db->run("UPDATE link_items SET status = 'published' WHERE status = 'draft'");
        $emp = $this->as('employee.demo1');
        $home = $emp->get('/')->body;
        $links = strpos($home, 'hub-group--links');
        $locked = strpos($home, 'hub-group--locked');
        self::assertNotFalse($links);
        self::assertNotFalse($locked);
        self::assertGreaterThan(strpos($home, 'id="hub-g0"'), $links, 'links after the app groups');
        self::assertGreaterThan($links, $locked, 'apps without access come last');
        self::assertMatchesRegularExpression('#hub-group--links.*?<a class="app-tile" href="/links/go/[0-9A-Z]{26}"#s', $home, 'links use the same app tiles');
        self::assertStringContainsString('sparkle-hero', $home);
        self::assertStringNotContainsString('robot-off', $home, 'release / scheduler / AI cards left Home');
        $sys = $this->as(TestApp::ADMIN);
        self::assertStringNotContainsString('robot-off', $sys->get('/')->body);
        $status = $sys->get('/system/status')->body;
        self::assertStringContainsString(explode(' ', \Blue\Core\App::RELEASE)[0], $status);
        self::assertStringContainsString('robot-off', $status);
        // sub-menu folded by default, open on its own page
        self::assertMatchesRegularExpression('#class="sidebar__toggle" aria-expanded="false" aria-controls="(sub-[0-9a-f]{8})"#', $home);
        self::assertMatchesRegularExpression('#class="sidebar__sub" id="sub-[0-9a-f]{8}" hidden>#', $home);
        $page = $emp->get('/approvals/delegate')->body;
        self::assertStringContainsString('aria-expanded="true"', $page);
        // the sub-menu of "my requests" opens on its own page (other modules' sub-menus stay folded)
        $sid = 'sub-' . substr(md5('/requests'), 0, 8);
        self::assertStringContainsString('class="sidebar__sub" id="' . $sid . '" hidden>', $home);
        self::assertStringContainsString('class="sidebar__sub" id="' . $sid . '">', $page);
        $this->noRawKeys($home, 'home');
    }
}
