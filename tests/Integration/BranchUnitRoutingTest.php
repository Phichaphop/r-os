<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\ValidationException;
use Blue\Modules\Employees\EmployeeService;
use Blue\Tests\Support\TestApp;

/**
 * 0.8.2 (DEC-69): one structure per company with branch-tagged units — e.g. HR of each branch as separate units under
 * the same HR Division. Requests reach the approver of the person's branch/unit; unit rights cover the units below.
 */
final class BranchUnitRoutingTest extends TestCase
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

    private function admin(): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user(TestApp::ADMIN)['id']);
    }

    /** @return array{le: int, lePid: string, hq: int, br1: int, hqPid: string, br1Pid: string, div: string, hrHq: string, hrBr1: string} */
    private function org(): array
    {
        $db = $this->t->app->db();
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $site = fn (string $c): array => $db->one('SELECT id, public_id FROM sites WHERE legal_entity_id = ? AND code = ?', [$le, $c]) ?? [];
        [$hq, $br1] = [$site('HQ'), $site('BR1')];
        $lePid = (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        $org = $this->t->app->org();
        $div = $org->createDepartment($this->admin(), $lePid, ['code' => 'HRDIV', 'name_th' => 'HR Division', 'level' => 'division']);
        $hrHq = $org->createDepartment($this->admin(), $lePid, ['code' => 'HR-HQ', 'name_th' => 'HR สำนักงานใหญ่', 'level' => 'section', 'parent' => $div, 'site' => (string) $hq['public_id']]);
        $hrBr1 = $org->createDepartment($this->admin(), $lePid, ['code' => 'HR-BR1', 'name_th' => 'HR สาขา 1', 'level' => 'section', 'parent' => $div, 'site' => (string) $br1['public_id']]);
        return ['le' => $le, 'lePid' => $lePid, 'hq' => (int) $hq['id'], 'br1' => (int) $br1['id'], 'hqPid' => (string) $hq['public_id'], 'br1Pid' => (string) $br1['public_id'],
            'div' => $div, 'hrHq' => $hrHq, 'hrBr1' => $hrBr1];
    }

    private function deptId(string $pid): int
    {
        return (int) $this->t->app->db()->scalar('SELECT id FROM departments WHERE public_id = ?', [$pid]);
    }

    /** re-scope one user's HRA assignment to a single branch or unit */
    private function scopeTo(string $username, string $level, int $id): void
    {
        $db = $this->t->app->db();
        $aid = (int) $db->scalar("SELECT a.id FROM user_role_assignments a JOIN users u ON u.id = a.user_id JOIN roles r ON r.id = a.role_id WHERE u.username = ? AND r.code = 'HRA'", [$username]);
        self::assertGreaterThan(0, $aid, $username);
        $db->run('DELETE FROM access_scopes WHERE assignment_id = ?', [$aid]);
        $db->run('INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, ?, ?)', [$aid, $level, $id]);
    }

    /** @return list<string> usernames with an open task on the instance */
    private function assignees(string $instPid): array
    {
        $rows = $this->t->app->db()->all("SELECT u.username FROM workflow_tasks k JOIN workflow_instances i ON i.id = k.instance_id JOIN users u ON u.id = k.assignee_id
            WHERE i.public_id = ? AND k.status = 'open' ORDER BY u.username", [$instPid]);
        return array_column($rows, 'username');
    }

    public function testRequestsGoToTheApproverOfTheBranchOrUnit(): void
    {
        $o = $this->org();
        $wf = $this->t->app->workflow();
        $requester = (int) $this->t->user('employee.demo1')['id'];

        // company-level HR only (as installed): everything goes to the company HR admins, as before
        $a = $wf->start('employees.change', 'T-A', 'company level', $o['le'], $requester, $o['br1'], $this->deptId($o['hrBr1']));
        self::assertContains('hr.demo1', $this->assignees($a));
        self::assertSame(['hr.demo1', 'hr.head1'], $this->assignees($a));

        // HR of branch 1: requests about branch-1 people go to them only; head-office people still go to company HR
        $this->scopeTo('hr.head1', 'site', $o['br1']);
        $b = $wf->start('employees.change', 'T-B', 'branch 1', $o['le'], $requester, $o['br1'], $this->deptId($o['hrBr1']));
        self::assertSame(['hr.head1'], $this->assignees($b), 'most specific approver wins');
        $c = $wf->start('employees.change', 'T-C', 'head office', $o['le'], $requester, $o['hq'], $this->deptId($o['hrHq']));
        self::assertSame(['hr.demo1'], $this->assignees($c), 'no branch approver for HQ → company HR');
        $legacy = $wf->start('employees.change', 'T-D', 'no unit known', $o['le'], $requester);
        self::assertSame(['hr.demo1'], $this->assignees($legacy), 'requests without branch/unit route by company as before');

        // a unit-scoped approver (HR Division) beats the branch: the nearest unit up the chart wins
        $this->scopeTo('hr.demo1', 'department', $this->deptId($o['div']));
        $d = $wf->start('employees.change', 'T-E', 'unit', $o['le'], $requester, $o['br1'], $this->deptId($o['hrBr1']));
        self::assertSame(['hr.demo1'], $this->assignees($d));
    }

    public function testUnitRightsCoverTheUnitsBelow(): void
    {
        $o = $this->org();
        $this->scopeTo('hr.demo1', 'department', $this->deptId($o['div']));
        $az = new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user('hr.demo1')['id']);
        $ids = $az->scope('employees.view')->departmentIds;
        sort($ids);
        $want = [$this->deptId($o['div']), $this->deptId($o['hrHq']), $this->deptId($o['hrBr1'])];
        sort($want);
        self::assertSame($want, $ids, 'Division → its sections');
        self::assertTrue(EmployeeService::covers($az->scope('employees.view'), ['legal_entity_id' => $o['le'], 'site_id' => $o['br1'], 'department_id' => $this->deptId($o['hrBr1'])]));
        self::assertFalse(EmployeeService::covers($az->scope('employees.view'), ['legal_entity_id' => $o['le'], 'site_id' => $o['br1'], 'department_id' => null]), 'not the whole branch');
    }

    public function testBranchTagsFlowDownToSubUnitsAndEmployees(): void
    {
        $o = $this->org();
        $org = $this->t->app->org();
        $sub = $org->createDepartment($this->admin(), $o['lePid'], ['code' => 'HR-BR1-PAY', 'name_th' => 'Payroll สาขา 1', 'level' => 'sub_section', 'parent' => $o['hrBr1']]);
        self::assertSame($o['br1'], (int) $this->t->app->db()->scalar('SELECT site_id FROM departments WHERE public_id = ?', [$sub]), 'empty branch = the parent\'s');
        try {
            $org->createDepartment($this->admin(), $o['lePid'], ['code' => 'BAD', 'name_th' => 'x', 'level' => 'sub_section', 'parent' => $o['hrBr1'], 'site' => $o['hqPid']]);
            self::fail('sub-unit in another branch');
        } catch (ValidationException $e) {
            self::assertSame('org.error.site_parent', $e->errors['site'] ?? null);
        }
        $svc = EmployeeService::of($this->t->app);
        [$job, $errors] = $svc->validateJob(['legal_entity_id' => $o['le'], 'department_id' => $this->deptId($o['hrBr1']), 'employee_group' => 'MONTHLY'], null);
        self::assertSame([], $errors);
        self::assertSame($o['br1'], $job['site_id'], 'employee takes the unit\'s branch');
        [, $errors] = $svc->validateJob(['legal_entity_id' => $o['le'], 'site_id' => $o['hq'], 'department_id' => $this->deptId($o['hrBr1']), 'employee_group' => 'MONTHLY'], null);
        self::assertSame('employees.error.site_unit', $errors['site_id'] ?? null);
        [, $errors] = $svc->validateJob(['legal_entity_id' => $o['le'], 'site_id' => $o['hq'], 'department_id' => $this->deptId($o['div']), 'employee_group' => 'MONTHLY'], null);
        self::assertArrayNotHasKey('site_id', $errors, 'an untagged unit serves every branch');
    }

    public function testChartIsOneTreePerCompanyWithBranchTagsAndPagesOpenWithAHero(): void
    {
        $o = $this->org();
        $b = $this->t->browser();
        $b->login(TestApp::ADMIN);
        $page = $b->get('/admin/org', ['le' => $o['lePid']]);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('aria-current="page"><svg', $page->body, 'company switcher');
        self::assertMatchesRegularExpression('#HR-BR1</span>.*?class="oc-tag"[^>]*>.*?<span>BR1</span>#s', $page->body, 'unit carries its branch tag');
        self::assertStringNotContainsString('oc-node--group', $page->body, 'branches are tags, not a second tree');
        $filtered = $b->get('/admin/org', ['le' => $o['lePid'], 'site' => $o['br1Pid']])->body;
        self::assertMatchesRegularExpression('#oc-node--dim[^>]*>.*?HR-HQ#s', $filtered, 'other branch dimmed');

        $leave = $b->get('/admin/users')->body;
        self::assertStringContainsString('page-header page-hero card card--navy', $leave, 'every page opens with the hero banner');
        $home = $b->get('/')->body;
        self::assertStringContainsString('page-hero--home', $home);
        self::assertSame(1, substr_count($home, '<h1'), 'one hero, one heading');
        $guest = $this->t->browser()->get('/')->body;
        self::assertMatchesRegularExpression('#page-hero__aside">.*?hub-search.*?href="/login"#s', $guest, 'search and sign-in sit together on the right');
        self::assertStringNotContainsString('topbar__signin', $guest, 'no second sign-in button on home');
        self::assertStringContainsString('data-pattern="dots"', $guest, 'dots by default');
    }
}
