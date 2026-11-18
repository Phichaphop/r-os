<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Tests\Support\TestApp;

/** Signed-in page checks: safe error pages (phase-1 gate kept), production switches, every admin page renders. */
final class WebPagesTest extends TestCase
{
    /** @var list<TestApp> */
    private array $apps = [];

    protected function tearDown(): void
    {
        foreach ($this->apps as $a) {
            $a->cleanup();
        }
    }

    private function app(string $env): TestApp
    {
        return $this->apps[] = TestApp::create($this, $env);
    }

    public function testTechnicalErrorPageHasReferenceButNoStackTraceOrSecrets(): void
    {
        foreach (['prototype', 'staging'] as $env) {
            $b = $this->app($env)->browser();
            $b->login(TestApp::ADMIN);
            $res = $b->get('/system/ui-showcase/error-demo');
            self::assertSame(500, $res->status, $env);
            self::assertMatchesRegularExpression('/[0-9A-HJKMNP-TV-Z]{26}/', $res->body);
            foreach (['DB_PASS', 'should-never-be-shown', '#0 ', '.php', 'RuntimeException'] as $leak) {
                self::assertStringNotContainsString($leak, $res->body, "{$env}: {$leak}");
            }
        }
    }

    public function testDeveloperDetailOnlyOnCi(): void
    {
        $b = $this->app('ci')->browser();
        $b->login(TestApp::ADMIN);
        self::assertStringContainsString('RuntimeException', $b->get('/system/ui-showcase/error-demo')->body);
    }

    public function testProductionHidesShowcaseStatusAndMailbox(): void
    {
        $b = $this->app('production')->browser();
        $b->login(TestApp::ADMIN);
        foreach (['/system/ui-showcase', '/system/status', '/system/mailbox', '/system/ui-showcase/error-demo'] as $p) {
            self::assertSame(404, $b->get($p)->status, $p);
        }
        self::assertStringNotContainsString('UI Showcase', $b->get('/')->body, 'not in the menu either');
    }

    public function testEveryAdminPageRendersForTheSystemAdmin(): void
    {
        $t = $this->app('prototype');
        $b = $t->browser();
        $b->login(TestApp::ADMIN);
        $user = $t->user('hr.demo1')['public_id'];
        $le = $t->id('legal_entities', 'DEMO1');
        foreach (
            ['/', '/me', '/me/password', '/admin/users', '/admin/users/new', '/admin/users/' . $user, '/admin/roles', '/admin/org',
                     '/admin/org/legal-entity/new', '/admin/org/legal-entity/' . $le, '/admin/org/site/' . $le . '/new', '/admin/org/department/' . $le . '/new',
                     '/admin/audit', '/system/status', '/system/ui-showcase', '/system/mailbox', '/reauth', '/admin/users?lang=en'] as $p
        ) {
            [$path, $q] = array_pad(explode('?', $p, 2), 2, '');
            parse_str($q, $query);
            $res = $b->get($path, $query);
            self::assertSame(200, $res->status, $p);
            self::assertDoesNotMatchRegularExpression('/\b(auth|iam|org|audit|profile|nav|common|form|role|perm)\.[a-z_]+\.[a-z_.]+\b(?![^<]*>)/', strip_tags($res->body), "untranslated key on {$p}");
        }
    }

    public function testCreateCompanySiteDepartmentThroughForms(): void
    {
        $t = $this->app('prototype');
        $b = $t->browser();
        $b->login(TestApp::ADMIN);
        $b->get('/admin/org/legal-entity/new');
        self::assertSame(422, $b->post('/admin/org/legal-entity/new', ['code' => 'bad code!', 'name_th' => ''])->status);
        self::assertSame(303, $b->post('/admin/org/legal-entity/new', ['code' => 'DEMO3', 'name_th' => 'บริษัทที่สาม (DEMO)', 'registration_no' => 'DEMO-3', 'fiscal_year_start_month' => '1'])->status);
        $le = $t->id('legal_entities', 'DEMO3');
        self::assertSame(303, $b->post("/admin/org/site/{$le}/new", ['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'site_type' => 'hq', 'branch_no' => '0'])->status);
        self::assertSame(422, $b->post("/admin/org/site/{$le}/new", ['code' => 'HQ', 'name_th' => 'ซ้ำ', 'site_type' => 'hq'])->status, 'duplicate code');
        self::assertSame(303, $b->post("/admin/org/department/{$le}/new", ['code' => 'FIN', 'name_th' => 'การเงิน'])->status);
        self::assertSame('00000', $t->app->db()->scalar("SELECT s.branch_no FROM sites s JOIN legal_entities l ON l.id = s.legal_entity_id WHERE l.code = 'DEMO3'"));
        self::assertSame(3, (int) $t->app->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action IN ('org.legal_entity.create','org.site.create','org.department.create')"));
    }

    public function testStaleEditIsRejectedAsConcurrencyConflict(): void
    {
        $t = $this->app('prototype');
        $b = $t->browser();
        $b->login(TestApp::ADMIN);
        $le = $t->id('legal_entities', 'DEMO1');
        $b->get('/admin/org/legal-entity/' . $le);
        self::assertSame(303, $b->post('/admin/org/legal-entity/' . $le, ['row_version' => 1, 'code' => 'DEMO1', 'name_th' => 'ชื่อใหม่', 'fiscal_year_start_month' => '1'])->status);
        self::assertSame(409, $b->post('/admin/org/legal-entity/' . $le, ['row_version' => 1, 'code' => 'DEMO1', 'name_th' => 'ชื่อทับ', 'fiscal_year_start_month' => '1'])->status);
    }

    public function testInviteActivateAndSignInEndToEnd(): void
    {
        $t = $this->app('prototype');
        $admin = $t->browser();
        $admin->login(TestApp::ADMIN);
        $admin->get('/admin/users/new');
        $res = $admin->post('/admin/users/new', ['username' => 'Somchai.K', 'display_name' => 'สมชาย ก.', 'email' => 'somchai@demo.invalid', 'locale' => 'th']);
        self::assertSame(303, $res->status);
        $page = $admin->get((string) parse_url((string) $res->headers['Location'], PHP_URL_PATH));
        preg_match('#/activate/([A-Za-z0-9_-]{20,})#', $page->body, $m);
        self::assertNotEmpty($m, 'activation link shown once');
        self::assertDoesNotMatchRegularExpression('#/activate/[A-Za-z0-9_-]{20,}#', $admin->get((string) parse_url((string) $res->headers['Location'], PHP_URL_PATH))->body, 'not shown again');

        $user = $t->browser();
        $user->get('/activate/' . $m[1]);
        self::assertSame(303, $user->post('/activate/' . $m[1], ['password' => 'ช้างเผือกกินกล้วย 3 ลูก', 'password_confirm' => 'ช้างเผือกกินกล้วย 3 ลูก'])->status);
        self::assertSame(303, $user->login('somchai.k', 'ช้างเผือกกินกล้วย 3 ลูก')->status);
        self::assertSame(200, $user->get('/me')->status);
        self::assertSame(410, $t->browser()->get('/activate/' . $m[1])->status, 'link is single-use');
    }
}
