<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Tests\Support\TestApp;

/** Phase 3A screens through the HTTP kernel: rendering, permissions, approval flow end-to-end, theme, update guard. */
final class SharedServicesWebTest extends TestCase
{
    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false); // MFA off (default install) — these tests are about the 3A screens
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private const UNTRANSLATED = '/(?<![.\w])(config|workflow|access|amend|lock|notify|settings|update|page|nav|common|iam|form)\.[a-z_]+\.?[a-z_.]*\b(?![^<]*>)/';

    public function testEveryNewPageRendersTranslatedForTheSystemAdmin(): void
    {
        $b = $this->t->browser();
        $b->login(TestApp::ADMIN);
        foreach (
            ['/approvals', '/requests', '/notifications', '/me/access', '/admin/config', '/admin/config/key/demo.meal_allowance_per_day',
            '/admin/config/key/theme.preset', '/admin/settings/appearance', '/admin/workflows', '/system/update', '/system/ui-showcase'] as $p
        ) {
            $res = $b->get($p);
            self::assertSame(200, $res->status, $p);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body), "untranslated key on {$p}");
        }
    }

    public function testAdminPagesNeedTheRightPermission(): void
    {
        $b = $this->t->browser();
        $b->login('hr.demo1');
        foreach (['/admin/config', '/admin/settings/appearance', '/admin/workflows', '/system/update', '/admin/settings/appearance/check'] as $p) {
            self::assertSame(403, $b->get($p)->status, $p);
        }
        foreach (['/approvals', '/requests', '/notifications', '/me/access'] as $p) {
            self::assertSame(200, $b->get($p)->status, $p);
        }
        $auditor = $this->t->browser();
        $auditor->login('auditor.demo');
        self::assertSame(200, $auditor->get('/admin/config')->status, 'auditors may read the registry');
        self::assertStringNotContainsString('id="propose"', $auditor->get('/admin/config/key/theme.preset')->body, '… but not propose');
    }

    public function testAccessRequestFromTheWebToApprovalAndGrant(): void
    {
        $emp = $this->t->browser();
        $emp->login('employee.demo1');
        $emp->get('/me/access');
        $le = (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $res = $emp->post('/me/access', ['role' => 'HRT', 'scope' => 'legal_entity:' . $le, 'reason' => 'covering time attendance']);
        self::assertSame(303, $res->status);
        self::assertStringContainsString('covering time attendance', $this->t->app->db()->scalar('SELECT reason FROM access_requests') ?? '');
        $inst = (string) $this->t->app->db()->scalar('SELECT public_id FROM workflow_instances');

        // the requester sees it in My requests but cannot decide
        self::assertStringContainsString($inst, $emp->get('/requests')->body);
        $emp->post('/approvals/' . $inst . '/decide', ['decision' => 'approve']);
        self::assertSame('pending', $this->t->app->db()->scalar('SELECT status FROM workflow_instances'));

        // a system admin sees it in the inbox (badge) and approves
        $sys = $this->t->browser();
        $sys->login('sysadmin2.demo');
        $home = $sys->get('/');
        self::assertMatchesRegularExpression('#href="/approvals"[^>]*>.*?sidebar__count[^>]*>1<#s', $home->body);
        self::assertStringContainsString('covering time attendance', $sys->get('/approvals/' . $inst)->body);
        $sys->post('/approvals/' . $inst . '/decide', ['decision' => 'approve', '_back' => '/approvals/' . $inst]);
        self::assertSame(['approved', 'succeeded'], array_values($this->t->app->db()->one('SELECT status, execution_status FROM workflow_instances') ?? []));
        self::assertSame(1, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM user_role_assignments a JOIN roles r ON r.id = a.role_id JOIN users u ON u.id = a.user_id WHERE u.username = 'employee.demo1' AND r.code = 'HRT' AND a.status = 'active'"));

        // the employee was notified; opening the notification marks it read and goes to the request
        $again = $this->t->browser();
        $again->login('employee.demo1');
        $note = (string) $this->t->app->db()->scalar("SELECT n.public_id FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.username = 'employee.demo1' AND n.event_type = 'workflow.approved'");
        $sys->get('/notifications/' . $note);
        self::assertSame('queued', $this->t->app->db()->scalar('SELECT status FROM notifications WHERE public_id = ?', [$note]), 'someone else cannot mark it');
        $open = $again->get('/notifications/' . $note);
        self::assertStringEndsWith('/requests/' . $inst, (string) $open->headers['Location']);
        self::assertSame('read', $this->t->app->db()->scalar('SELECT status FROM notifications WHERE public_id = ?', [$note]));
    }

    public function testHighRiskConfigApprovalNeedsReauthAndAnotherPerson(): void
    {
        $admin = $this->t->browser();
        $admin->login(TestApp::ADMIN);
        $org = (int) $this->t->app->db()->scalar('SELECT id FROM organizations LIMIT 1');
        $admin->get('/admin/config/key/demo.meal_allowance_per_day');
        $res = $admin->post('/admin/config/key/demo.meal_allowance_per_day/propose', ['value' => '85.5', 'scope' => 'organization:' . $org, 'from_date' => '2026-12-01', 'from_time' => '00:00', 'reason' => 'pilot']);
        $ver = (string) $this->t->app->db()->scalar('SELECT public_id FROM config_versions');
        self::assertStringEndsWith('/admin/config/version/' . $ver, (string) $res->headers['Location']);
        self::assertSame('"85.50"', $this->t->app->db()->scalar('SELECT value_json FROM config_versions'));
        self::assertSame('2026-11-30 17:00:00.000000', $this->t->app->db()->scalar('SELECT effective_from FROM config_versions'), 'midnight Bangkok = 17:00 UTC the day before');
        $admin->get('/admin/config/version/' . $ver);
        $admin->post('/admin/config/version/' . $ver . '/submit');
        $inst = (string) $this->t->app->db()->scalar('SELECT public_id FROM workflow_instances');
        self::assertStringNotContainsString('name="decision"', $admin->get('/approvals/' . $inst)->body, 'the proposer gets no decision buttons');

        $checker = $this->t->browser();
        $checker->login('sysadmin2.demo');
        $checker->get('/approvals/' . $inst);
        $first = $checker->post('/approvals/' . $inst . '/decide', ['decision' => 'approve', '_back' => '/approvals/' . $inst]);
        self::assertStringContainsString('/reauth', (string) $first->headers['Location'], 'high risk → confirm password first');
        $checker->get('/reauth');
        $checker->post('/reauth', ['password' => $this->t->demoPassword, 'next' => '/approvals/' . $inst]);
        $checker->get('/approvals/' . $inst);
        $checker->post('/approvals/' . $inst . '/decide', ['decision' => 'approve', '_back' => '/approvals/' . $inst]);
        self::assertSame('approved', $this->t->app->db()->scalar('SELECT status FROM config_versions'));
        self::assertStringContainsString('85.50', $admin->get('/admin/config/key/demo.meal_allowance_per_day', ['at' => '2026-12-02'])->body);
    }

    public function testAppearanceSavesAsConfigVersionsAndRefusesUnreadableColours(): void
    {
        $b = $this->t->browser();
        $b->login(TestApp::ADMIN);
        $b->get('/admin/settings/appearance');
        $bad = $b->post('/admin/settings/appearance', ['app_name' => 'Blue OS', 'tagline' => '', 'preset' => 'steel', 'primary' => '#FFE600', 'mode' => 'light']);
        self::assertSame(422, $bad->status);
        self::assertSame(0, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM config_versions WHERE status = 'approved'"));

        $ok = $b->post('/admin/settings/appearance', ['app_name' => 'ระบบกลุ่ม DEMO', 'tagline' => '', 'preset' => 'teal', 'primary' => '', 'mode' => 'light']);
        self::assertSame(303, $ok->status);
        self::assertSame(2, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM config_versions WHERE status = 'approved'"), 'name + preset changed');
        $page = $b->get('/admin/users');
        self::assertStringContainsString('ระบบกลุ่ม DEMO', $page->body);
        self::assertMatchesRegularExpression('#<link rel="stylesheet" id="theme-css" href="/theme\.css\?v=[0-9a-f]{12}">#', $page->body);
        $css = (new \Blue\Core\Http\Kernel($this->t->app))->handle(new \Blue\Core\Http\Request('GET', '/theme.css', [], [], [], [], '127.0.0.1'));
        self::assertSame(200, $css->status);
        self::assertStringContainsString('--color-action-primary: #0E6265', $css->body, 'public, used on the sign-in page too');
        $check = $b->get('/admin/settings/appearance/check', ['preset' => 'steel', 'primary' => '#FFE600']);
        $json = json_decode($check->body, true);
        self::assertFalse($json['ok']);
        self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', (string) $json['suggestion']);
    }

    public function testOwnMenuHighlightAndBackgroundColoursAndTheTaglineInTheMenu(): void
    {
        $b = $this->t->browser();
        $b->login(TestApp::ADMIN);
        $b->get('/admin/settings/appearance');
        $bad = $b->post('/admin/settings/appearance', ['app_name' => 'Blue OS', 'tagline' => '', 'preset' => 'steel', 'primary' => '', 'menu' => '#C8D8EA', 'accent' => '', 'background' => '', 'mode' => 'light']);
        self::assertSame(422, $bad->status, 'white text on a pale menu is refused');
        self::assertStringContainsString('id="f-menu-err"', $bad->body);
        $ok = $b->post('/admin/settings/appearance', ['app_name' => 'Blue OS', 'tagline' => 'ระบบงานกลางของกลุ่มบริษัท', 'preset' => 'steel', 'primary' => '',
            'menu' => '#1E3A2F', 'accent' => '#B8E2C8', 'background' => '#F1F5F2', 'mode' => 'light']);
        self::assertSame(303, $ok->status, $ok->body);
        $this->t->app->appearance()->reset();
        foreach (['theme.menu_color' => '#1E3A2F', 'theme.accent_color' => '#B8E2C8', 'theme.background_color' => '#F1F5F2'] as $k => $v) {
            self::assertSame($v, $this->t->app->registry()->resolveOrNull($k)?->value, $k);
        }
        $css = (new \Blue\Core\Http\Kernel($this->t->app))->handle(new \Blue\Core\Http\Request('GET', '/theme.css', [], [], [], [], '127.0.0.1'));
        self::assertStringContainsString('--color-navy: #1E3A2F', $css->body);
        self::assertStringContainsString('--color-bg: #F1F5F2', $css->body);
        $page = $b->get('/admin/users');
        self::assertStringContainsString('<span class="sidebar__tagline">ระบบงานกลางของกลุ่มบริษัท</span>', $page->body);
        $form = $b->get('/admin/settings/appearance')->body;
        self::assertStringContainsString('name="menu" value="#1E3A2F"', $form, 'form shows the saved own colour');
        $check = json_decode($b->get('/admin/settings/appearance/check', ['preset' => 'steel', 'menu' => '#C8D8EA'])->body, true);
        self::assertFalse($check['ok']);
        self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', (string) ($check['suggestions']['menu'] ?? ''));
        // reset clears every own colour
        $this->t->clock->advance('+1 minute');
        $b->post('/admin/settings/appearance', ['reset' => '1']);
        $this->t->app->appearance()->reset();
        self::assertNull($this->t->app->appearance()->current()['menu']);
    }

    public function testAnOutOfDateDatabaseSendsAdminsToTheUpdatePage(): void
    {
        // simulate "new release copied over an older install": one migration is not recorded as applied
        $this->t->app->db()->run("DELETE FROM schema_migrations WHERE version LIKE '202611160002%'");
        $sys = $this->t->browser();
        $sys->login(TestApp::ADMIN);
        $res = $sys->get('/admin/users');
        self::assertSame(303, $res->status);
        self::assertStringEndsWith('/system/update', (string) $res->headers['Location']);
        self::assertStringContainsString('202611160002', $sys->get('/system/update')->body);

        $emp = $this->t->browser();
        $emp->login('employee.demo1');
        self::assertSame(503, $emp->get('/')->status);

        $sys->get('/system/update');
        $run = $sys->post('/system/update', ['backup_confirmed' => '1', '_back' => '/system/update']);
        self::assertStringContainsString('/reauth', (string) $run->headers['Location']);
        $sys->get('/reauth');
        $sys->post('/reauth', ['password' => TestApp::ADMIN_PASSWORD, 'next' => '/system/update']);
        $sys->get('/system/update');
        $sys->post('/system/update', ['_back' => '/system/update']);
        self::assertSame(0, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM schema_migrations WHERE version LIKE '202611160002%'"), 'no backup confirmation → not run');
        $sys->post('/system/update', ['backup_confirmed' => '1', '_back' => '/system/update']);
        self::assertSame(1, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM schema_migrations WHERE version LIKE '202611160002%'"));
        self::assertSame(200, $emp->get('/')->status);
        self::assertSame(1, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'system.update' AND outcome = 'success'"));
    }
}
