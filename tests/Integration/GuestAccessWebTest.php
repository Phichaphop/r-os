<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Ui\GuestAccess;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/**
 * 0.8.1 (DEC-68): public home, tools usable without signing in, the admin page that chooses them, quick draw,
 * fixed organization levels and the organization-wide pattern setting.
 */
final class GuestAccessWebTest extends TestCase
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

    private function admin(): TestBrowser
    {
        $b = $this->t->browser();
        $b->login(TestApp::ADMIN);
        return $b;
    }

    private static function fx(string $n): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/' . $n);
    }

    /** @param list<string> $home @param list<string> $tools */
    private function open(array $home, array $tools): void
    {
        $sys = $this->admin();
        $sys->get('/admin/settings/guest');
        $res = $sys->post('/admin/settings/guest', ['home' => $home, 'tools' => $tools]);
        self::assertSame(303, $res->status);
    }

    public function testHomeIsPublicAndOtherAppsAskToSignInThenContinue(): void
    {
        $g = $this->t->browser();
        $home = $g->get('/');
        self::assertSame(200, $home->status, 'home no longer bounces to the sign-in page');
        self::assertStringContainsString('href="/login"', $home->body, 'a sign-in button');
        self::assertStringContainsString('href="/login?next=%2Fleave"', $home->body, 'nothing chosen yet = every app, through sign-in');
        self::assertStringNotContainsString('id="global-search"', $home->body, 'no search or bell for a visitor');
        self::assertStringNotContainsString('class="btn btn--ghost btn--icon bell"', $home->body);
        self::assertSame([], GuestAccess::tools($this->t->app), 'no tool is open until an admin opens it');

        $calc = $g->get('/calc');
        self::assertSame(302, $calc->status);
        self::assertSame('/login?next=%2Fcalc', $calc->headers['Location']);

        // sign in from that link → straight on to the app
        $g->get('/login', ['next' => '/calc']);
        $in = $g->post('/login', ['identifier' => 'employee.demo1', 'password' => $this->t->demoPassword, 'next' => '/calc']);
        self::assertSame('/calc', parse_url((string) $in->headers['Location'], PHP_URL_PATH));
        self::assertSame(200, $g->get('/calc')->status);
        self::assertStringContainsString('data-hub>', $g->get('/')->body, 'signed in: the normal app hub again');
    }

    public function testAdminChoosesWhatVisitorsSeeAndUse(): void
    {
        $emp = $this->t->browser();
        $emp->login('employee.demo1');
        self::assertSame(403, $emp->get('/admin/settings/guest')->status, 'system admins only');

        $sys = $this->admin();
        $page = $sys->get('/admin/settings/guest');
        self::assertSame(200, $page->status);
        foreach (['calc', 'qr', 'crypto', 'pdf', 'draw'] as $c) {
            self::assertStringContainsString('name="tools[]" value="' . $c . '"', $page->body, "{$c} can be opened");
        }
        self::assertStringNotContainsString('name="tools[]" value="leave"', $page->body, 'leave has no guest block — never usable without sign-in');

        // "leave" in tools is ignored (not guestable); an open tool is always on the home page
        $this->open(['leave'], ['calc', 'draw', 'leave']);
        self::assertSame(['calc', 'draw'], GuestAccess::tools($this->t->app));
        $home = (array) $this->t->app->registry()->resolveOrNull(GuestAccess::HOME_KEY)?->value;
        sort($home);
        self::assertSame(['calc', 'draw', 'leave'], $home);
        self::assertSame(2, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'config.applied'"), 'both settings applied and audited');

        $g = $this->t->browser();
        $body = $g->get('/')->body;
        self::assertStringContainsString('href="/calc"', $body, 'open tool links straight to the tool');
        self::assertStringContainsString('href="/draw/quick"', $body, 'draw opens its quick draw for visitors');
        self::assertStringContainsString('href="/login?next=%2Fleave"', $body);
        self::assertStringNotContainsString('next=%2Fqr', $body, 'apps not chosen are not shown');
        self::assertStringContainsString('ใช้ได้ทันที', $body);
    }

    public function testVisitorUsesOpenToolsButCannotShareSaveOrManage(): void
    {
        $this->open([], ['calc', 'qr', 'crypto']);
        $db = $this->t->app->db();
        $g = $this->t->browser();
        $calc = $g->get('/calc');
        self::assertSame(200, $calc->status);
        self::assertStringContainsString('class="sidebar__link"', $calc->body, 'side bar lists the open tools');
        self::assertStringContainsString('topbar__signin', $calc->body);
        self::assertContains($g->post('/calc/sso', ['at' => '2026-11-01', 'wage' => '20000'])->status, [200, 422], 'calculation runs (result depends on approved rates)');
        self::assertSame(302, $g->post('/calc/sso/share', ['at' => '2026-11-01', 'wage' => '20000'])->status, 'sharing needs an account');
        self::assertSame(302, $g->get('/calc/rates')->status);

        $qr = $g->get('/qr');
        self::assertStringNotContainsString('value="managed"', $qr->body, 'only a plain QR for visitors');
        self::assertStringNotContainsString('/qr/save', $qr->body);
        $form = ['kind' => 'managed', 'content_type' => 'url', 'content' => 'https://example.com/a', 'level' => 'M'];
        $prev = $g->post('/qr/preview', $form);
        self::assertSame(200, $prev->status);
        self::assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg"', $prev->body);
        self::assertStringStartsWith("\x89PNG", $g->post('/qr/download', $form + ['format' => 'png', 'size' => 's'])->body, 'forced to a plain QR');
        self::assertSame(302, $g->post('/qr/save', $form)->status, 'saving needs an account');
        self::assertSame(302, $g->get('/qr/batch')->status);
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM qr_codes'));

        $dig = $g->post('/crypto', ['tool' => 'digest', 'text' => 'abc', 'algo' => 'sha256']);
        self::assertSame(200, $dig->status);
        self::assertStringContainsString('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', $dig->body);
        self::assertSame('no-store, max-age=0', $dig->headers['Cache-Control']);

        self::assertSame(302, $g->get('/pdf')->status, 'a tool not opened still needs sign-in');
        self::assertSame(302, $g->get('/leave')->status);
    }

    public function testVisitorPdfJobsBelongToThatBrowserOnly(): void
    {
        $this->open([], ['pdf']);
        $db = $this->t->app->db();
        $a = $this->t->browser();
        $a->get('/pdf/tool/extract');
        $res = $a->post('/pdf/tool/extract', [], ['files' => ['a.pdf', self::fx('a.pdf')]]);
        self::assertSame(303, $res->status);
        $job = $db->one('SELECT public_id, owner_user_id, guest_key, created_by, legal_entity_id FROM pdf_jobs ORDER BY id DESC LIMIT 1');
        self::assertNotNull($job);
        self::assertSame(0, (int) $job['owner_user_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $job['guest_key']);
        self::assertNull($job['created_by']);
        self::assertNull($job['legal_entity_id']);
        $pid = (string) $job['public_id'];
        self::assertSame(200, $a->get('/pdf/job/' . $pid)->status);
        self::assertStringNotContainsString('/save', $a->get('/pdf/job/' . $pid)->body, 'no save to records');
        $a->post('/pdf/job/' . $pid . '/run', ['pages' => '1']);
        self::assertStringStartsWith('%PDF', $a->get('/pdf/job/' . $pid . '/file/0')->body);
        self::assertStringNotContainsString($pid, $a->get('/pdf')->body, '0.39.0 (8.2): unsaved results are not listed — visitors cannot save');
        self::assertStringContainsString('data-sign', $a->get('/pdf/sign')->body, '0.39.0: the browser-only signature tool is open to visitors too');

        $b = $this->t->browser();
        self::assertSame(404, $b->get('/pdf/job/' . $pid)->status, 'another visitor cannot reach it');
        self::assertSame(404, $b->get('/pdf/job/' . $pid . '/file/0')->status);
        self::assertStringNotContainsString($pid, $b->get('/pdf')->body);
        $emp = $this->t->browser();
        $emp->login('employee.demo1');
        self::assertSame(404, $emp->get('/pdf/job/' . $pid)->status, 'nor a signed-in user');
        self::assertSame(302, $a->post('/pdf/job/' . $pid . '/file/0/save', ['class' => 'core.general', 'title' => 'x'])->status, 'saving needs an account');
        self::assertSame(302, $a->get('/pdf/admin')->status);
    }

    public function testQuickDrawStoresNothing(): void
    {
        $db = $this->t->app->db();
        $before = (int) $db->scalar('SELECT COUNT(*) FROM audit_events');
        $g = $this->t->browser();
        self::assertSame(302, $g->get('/draw/quick')->status, 'closed to visitors until opened');
        $this->open([], ['draw']);
        $before = (int) $db->scalar('SELECT COUNT(*) FROM audit_events');
        self::assertSame(200, $g->get('/draw/quick')->status);
        self::assertSame(302, $g->get('/draw')->status, 'the full draw still needs an account');
        $names = "สมชาย\nสมหญิง\n\nสมชาย\nมานี\nมานะ";
        $res = $g->post('/draw/quick', ['names' => $names, 'count' => '2']);
        self::assertSame(200, $res->status);
        self::assertStringContainsString('ผู้โชคดี 2 คน จากทั้งหมด 4 ชื่อ', $res->body, 'blank lines and repeats dropped');
        preg_match_all('#<li>([^<]+)</li>#u', $res->body, $m);
        self::assertCount(2, $m[1]);
        self::assertCount(2, array_unique($m[1]), 'no one wins twice');
        foreach ($m[1] as $w) {
            self::assertContains($w, ['สมชาย', 'สมหญิง', 'มานี', 'มานะ']);
        }
        self::assertSame(422, $g->post('/draw/quick', ['names' => "a\nb", 'count' => '3'])->status, 'more winners than names');
        self::assertSame(422, $g->post('/draw/quick', ['names' => '', 'count' => '1'])->status);
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM draw_events'));
        self::assertSame($before, (int) $db->scalar('SELECT COUNT(*) FROM audit_events'), 'nothing logged — not the names, not the result');

        $emp = $this->t->browser();
        $emp->login('employee.demo1');
        self::assertStringContainsString('href="/draw/quick"', $emp->get('/draw')->body, 'signed-in users get it from the draw page too');
        self::assertSame(200, $emp->post('/draw/quick', ['names' => "x\ny", 'count' => '1'])->status);
    }

    public function testOrganizationLevelsAreFixedAndOrdered(): void
    {
        $db = $this->t->app->db();
        $admin = $this->t->user(TestApp::ADMIN);
        $az = new \Blue\Core\Authz\Authorizer($db, $this->t->clock, (int) $admin['id']);
        $org = $this->t->app->org();
        $lePid = (string) $db->scalar("SELECT public_id FROM legal_entities WHERE code = 'DEMO1'");
        $mk = fn (string $code, string $level, string $parent = '', string $site = ''): string => $org->createDepartment(
            $az,
            $lePid,
            ['code' => $code, 'name_th' => $code, 'level' => $level, 'parent' => $parent, 'site' => $site]
        );
        $top = $mk('TOP', 'top_management');
        $div = $mk('DIV', 'division', $top);
        $sec = $mk('SEC', 'section', $div); // skipping "department" is allowed
        $sub = $mk('SUB', 'sub_section', $sec);
        self::assertSame('sub_section', $db->scalar('SELECT unit_level FROM departments WHERE public_id = ?', [$sub]));
        $fail = function (callable $f, string $field, string $key): void {
            try {
                $f();
                self::fail('expected ' . $key);
            } catch (\Blue\Core\Error\ValidationException $e) {
                self::assertSame($key, $e->errors[$field] ?? null);
            }
        };
        $fail(fn () => $mk('BAD1', 'division', $sec), 'parent', 'org.error.parent_level');
        $fail(fn () => $mk('BAD2', 'division', $div), 'parent', 'org.error.parent_level');
        $fail(fn () => $mk('BAD3', 'chairman'), 'level', 'form.invalid_choice');
        $other = (string) $db->scalar("SELECT public_id FROM legal_entities WHERE code <> 'DEMO1' ORDER BY id LIMIT 1");
        if ($other !== '') {
            $fail(fn () => $org->createDepartment($az, $other, ['code' => 'X', 'name_th' => 'x', 'level' => 'division', 'parent' => $top]), 'parent', 'org.error.parent_other_company');
        }
        $row = $db->one('SELECT row_version FROM departments WHERE public_id = ?', [$sec]);
        $fail(fn () => $org->updateDepartment($az, $sec, (int) $row['row_version'], ['name_th' => 'SEC', 'level' => 'sub_section', 'parent' => $div]), 'level', 'org.error.children_level');
        $row = $db->one('SELECT row_version FROM departments WHERE public_id = ?', [$top]);
        $fail(fn () => $org->updateDepartment($az, $top, (int) $row['row_version'], ['name_th' => 'TOP', 'level' => 'top_management', 'parent' => $sub]), 'parent', 'org.error.parent_loop');

        $sys = $this->admin();
        $page = $sys->get('/admin/org/department/' . $lePid . '/new');
        self::assertSame(200, $page->status);
        foreach (['top_management', 'division', 'department', 'section', 'sub_section'] as $lv) {
            self::assertStringContainsString('value="' . $lv . '"', $page->body);
        }
        $chart = $sys->get('/admin/org')->body;
        self::assertMatchesRegularExpression('#oc-node--top-management.*oc-node--division.*oc-node--section.*oc-node--sub-section#s', $chart, 'chart nests by level');
        self::assertStringContainsString('org-node--d3', $sys->get('/admin/org', ['view' => 'list'])->body, 'list: four levels deep, indented');
    }

    public function testPatternSettingSwitchesSparklesAndDots(): void
    {
        $sys = $this->admin();
        $page = $sys->get('/admin/settings/appearance');
        self::assertStringContainsString('name="pattern" value="sparkles"', $page->body);
        self::assertStringContainsString('data-pattern="dots"', $page->body, '0.8.2: dots by default');
        $look = $this->t->app->appearance()->current();
        $sys->post('/admin/settings/appearance', ['app_name' => $look['app_name'], 'tagline' => $look['tagline'], 'preset' => $look['preset'], 'mode' => $look['mode'], 'pattern' => 'sparkles']);
        $this->t->app->appearance()->reset();
        self::assertSame('sparkles', $this->t->app->appearance()->current()['pattern']);
        self::assertStringContainsString('data-pattern="sparkles"', $this->t->browser()->get('/')->body, 'for everyone, visitors included');
    }
}
