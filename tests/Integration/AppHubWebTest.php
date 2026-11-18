<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\RbacSync;
use Blue\Core\Ui\AppHub;
use Blue\Tests\Support\TestApp;

/** 0.4.3 — Home as an app hub (6.4, REQ-IAM-003): every app shown, locked apps greyed out with Request access. */
final class AppHubWebTest extends TestCase
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

    /** the part of the home page that holds the hub (the sidebar repeats the open links) */
    private static function hub(string $body): string
    {
        $from = strpos($body, 'data-hub>');
        self::assertNotFalse($from, 'hub rendered');
        return (string) substr($body, $from);
    }

    public function testEmployeeSeesOpenAppsAndLockedAppsWithRequestButtons(): void
    {
        $emp = $this->t->browser();
        $emp->login('employee.demo1');
        $res = $emp->get('/');
        self::assertSame(200, $res->status);
        $hub = self::hub($res->body);
        foreach (['/calc', '/pdf', '/draw', '/qr', '/approvals', '/requests'] as $h) {
            self::assertStringContainsString('class="app-tile" href="' . $h . '"', $hub, "open tile {$h}");
        }
        foreach (['core.finance', 'core.payments', 'core.documents'] as $k) {
            self::assertStringContainsString('href="/me/access?app=' . $k . '"', $hub, "locked tile {$k}");
        }
        self::assertStringNotContainsString('href="/finance"', $hub, 'a locked app is not a link');
        self::assertStringNotContainsString('/admin/', $hub, 'administration pages are never offered as locked tiles');
        self::assertStringContainsString('ไม่มีสิทธิ์', $hub);
        self::assertStringNotContainsString('class="grid grid--3"', $res->body, 'system cards only for administrators');
        self::assertStringContainsString('ui/js/home.js', $res->body);

        $sys = $this->t->browser();
        $sys->login(TestApp::ADMIN);
        $body = $sys->get('/')->body;
        self::assertStringNotContainsString('class="grid grid--3"', $body, '0.6.2: system cards moved to System status');
        self::assertStringContainsString('class="app-tile" href="/admin/users"', self::hub($body), 'SYS sees administration as open tiles');
    }

    public function testRequestFromALockedTileOffersOnlyRolesThatOpenItAndShowsWaiting(): void
    {
        $emp = $this->t->browser();
        $emp->login('employee.demo1');
        $form = $emp->get('/me/access', ['app' => 'core.finance']);
        self::assertSame(200, $form->status);
        self::assertStringContainsString('name="app" value="core.finance"', $form->body);
        self::assertStringContainsString('<option value="FNM" selected', $form->body, 'the only ordinary role that opens finance is preselected');
        self::assertStringNotContainsString('<option value="SYS"', $form->body);
        $le = (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");

        $bad = $emp->post('/me/access', ['app' => 'core.finance', 'role' => 'SYS', 'scope' => 'legal_entity:' . $le, 'reason' => 'x']);
        self::assertSame(422, $bad->status, 'a role that is not offered is refused');
        self::assertSame(0, (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM access_requests'));

        $ok = $emp->post('/me/access', ['app' => 'core.finance', 'role' => 'FNM', 'scope' => 'legal_entity:' . $le, 'reason' => 'month-end close']);
        self::assertSame(303, $ok->status);
        self::assertStringEndsWith('/', (string) $ok->headers['Location']);
        self::assertSame('core.finance', $this->t->app->db()->scalar('SELECT module_code FROM access_requests'));
        $hub = self::hub($emp->get('/')->body);
        self::assertStringContainsString('รออนุมัติ', $hub);
        self::assertStringNotContainsString('href="/me/access?app=core.finance"', $hub, 'no second request button while waiting');

        // the approver sees which app it is for; approving opens the app on Home
        $inst = (string) $this->t->app->db()->scalar('SELECT public_id FROM workflow_instances');
        $sys = $this->t->browser();
        $sys->login('sysadmin2.demo');
        $page = $sys->get('/approvals/' . $inst)->body;
        self::assertStringContainsString('แอปที่ขอ', $page);
        $sys->post('/approvals/' . $inst . '/decide', ['decision' => 'approve', '_back' => '/approvals/' . $inst]);
        self::assertSame('approved', $this->t->app->db()->scalar('SELECT status FROM access_requests'));
        $ended = $emp->get('/');
        self::assertStringContainsString('expired=rights', (string) ($ended->headers['Location'] ?? ''), 'the new role ends old sessions — and the login page says why');
        self::assertStringContainsString('สิทธิ์การใช้งานของคุณเปลี่ยนแล้ว', $emp->get('/login', ['expired' => 'rights'])->body);
        $again = $this->t->browser();
        $again->login('employee.demo1');
        self::assertStringContainsString('class="app-tile" href="/finance"', self::hub($again->get('/')->body));
    }

    public function testAppsAlreadyOpenOrUnknownAreNotRequestedThroughTheAppForm(): void
    {
        $emp = $this->t->browser();
        $emp->login('employee.demo1');
        $open = $emp->get('/me/access', ['app' => 'calc']);
        self::assertSame(303, $open->status, 'calc is already open');
        $unknown = $emp->get('/me/access', ['app' => 'nope']);
        self::assertSame(200, $unknown->status, 'unknown key → the general form');
        self::assertStringNotContainsString('name="app"', $unknown->body);
        $le = (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $post = $emp->post('/me/access', ['app' => 'calc', 'role' => 'EMP', 'scope' => 'legal_entity:' . $le, 'reason' => 'x']);
        self::assertSame(303, $post->status);
        self::assertSame(0, (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM access_requests'));
    }

    public function testARequestFromTheGeneralFormAlsoShowsAsWaiting(): void
    {
        $emp = $this->t->browser();
        $emp->login('employee.demo1');
        $le = (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $emp->get('/me/access');
        $emp->post('/me/access', ['role' => 'FNM', 'scope' => 'legal_entity:' . $le, 'reason' => 'old-style request']);
        self::assertNull($this->t->app->db()->scalar('SELECT module_code FROM access_requests'), 'general form (and requests made before 0.4.3) carry no app');
        $hub = self::hub($emp->get('/')->body);
        self::assertStringNotContainsString('href="/me/access?app=core.finance"', $hub);
        self::assertStringNotContainsString('href="/me/access?app=core.payments"', $hub);
        $locked = (string) strstr($hub, 'id="hub-locked"');
        self::assertSame(3, substr_count($locked, '#i-hourglass'), 'finance, payments and the manpower budget (0.14.0) all open with FNM');
    }

    public function testSwitchedOffModuleIsNeitherOpenNorLocked(): void
    {
        $this->t->app->db()->run("UPDATE modules SET enabled = 0 WHERE code = 'draw'");
        $this->t->app->modules()->forget();
        $emp = $this->t->browser();
        $emp->login('employee.demo1');
        $hub = self::hub($emp->get('/')->body);
        self::assertStringNotContainsString('/draw', $hub);
        self::assertStringNotContainsString('app=draw', $hub);
        $sys = $this->t->browser();
        $sys->login(TestApp::ADMIN);
        self::assertStringContainsString('href="/admin/modules"', $sys->get('/')->body);
        self::assertStringContainsString('มี 1 โมดูลที่ปิดอยู่', $sys->get('/')->body);
    }

    public function testLeastPrivilegeRoleOrder(): void
    {
        $roles = RbacSync::roles($this->t->app->modules()->all());
        self::assertSame('EMP', AppHub::rolesFor($roles, 'calc.use')[0], 'every role opens calc — the smallest is offered first');
        self::assertSame(['FNM'], AppHub::rolesFor($roles, 'core.finance.view'), 'high-risk roles are left out when an ordinary one exists');
        self::assertSame('EMP', AppHub::rolesFor($roles, 'draw.use')[0], '0.6.1: everyone may run their own (private) draws');
        $high = AppHub::rolesFor($roles, 'core.audit.view');
        self::assertNotSame([], $high);
        foreach ($high as $r) {
            self::assertTrue($roles[$r][1], "{$r} is high-risk: only high-risk roles have the audit permission");
        }
        self::assertSame([], AppHub::rolesFor($roles, 'no.such.permission'));
    }
}
