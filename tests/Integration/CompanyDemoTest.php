<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use Blue\Core\Install\CompanyDemo;
use Blue\Core\Org\OrgService;
use Blue\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * 0.40.0 (R4, item 11 / Q-20): the one demo company with every department and someone for every role. The tests'
 * own fixture has two demo companies (cross-company checks), so CompanyDemo is run here explicitly; a real install
 * runs it by itself (demo profile 'company' — checked on a fresh install, see the R4 test report).
 */
final class CompanyDemoTest extends TestCase
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

    public function testFullDemoCompanyStructurePeopleAndAccounts(): void
    {
        $db = $this->t->app->db();
        self::assertSame('test', $db->scalar("SELECT info_value FROM system_info WHERE info_key = 'demo_profile'"));
        self::assertSame(0, CompanyDemo::run($db, $this->t->clock), 'not on the two-company test fixture unless asked');
        self::assertSame(count(CompanyDemo::PEOPLE), CompanyDemo::run($db, $this->t->clock, true));
        self::assertSame(0, CompanyDemo::run($db, $this->t->clock, true), 'runs once');

        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $units = $db->all('SELECT d.code, d.unit_level, d.site_id, p.code AS parent, p.unit_level AS parent_level, p.site_id AS parent_site FROM departments d
            LEFT JOIN departments p ON p.id = d.parent_id WHERE d.legal_entity_id = ?', [$le]);
        self::assertCount(count(CompanyDemo::UNITS), $units, 'every unit, the installer\'s four placed into the tree');
        $rank = array_flip(OrgService::UNIT_LEVELS);
        $levels = [];
        foreach ($units as $u) {
            $levels[$u['unit_level']] = true;
            if ($u['parent'] !== null) {
                self::assertGreaterThan($rank[$u['parent_level']], $rank[$u['unit_level']], $u['code'] . ': a child is on a lower level than its parent (DEC-68)');
                if ($u['parent_site'] !== null) {
                    self::assertSame($u['parent_site'], $u['site_id'], $u['code'] . ': units under a branch unit stay in that branch (DEC-69)');
                }
            } else {
                self::assertSame('top_management', $u['unit_level']);
            }
        }
        self::assertSame(OrgService::UNIT_LEVELS, array_values(array_intersect(OrgService::UNIT_LEVELS, array_keys($levels))), 'all five levels are used');
        self::assertSame('ฝ่ายซ่อมบำรุง', $db->scalar("SELECT t.value FROM translations t JOIN departments d ON d.id = t.entity_id AND t.entity_type = 'department' WHERE d.code = 'MAINT' AND t.locale = 'th'"));
        self::assertSame('factory', $db->scalar("SELECT site_type FROM sites WHERE code = 'FAC1'"));

        // people: 7 + 28, every unit below top management has someone, a reporting line up to the managing director
        self::assertSame(35, (int) $db->scalar('SELECT COUNT(*) FROM employees e JOIN employment_history h ON h.employee_id = e.id WHERE h.legal_entity_id = ?', [$le]));
        $empty = $db->all("SELECT d.code FROM departments d WHERE d.legal_entity_id = ? AND d.unit_level IN ('department','section','sub_section')
            AND NOT EXISTS (SELECT 1 FROM employment_history h WHERE h.department_id = d.id)", [$le]);
        self::assertSame([], $empty, 'every department and section has people');
        $chain = [];
        $code = 'D1-0119';
        while ($code !== null && count($chain) < 10) {
            $chain[] = $code;
            $code = $db->scalar('SELECT m.employee_code FROM employment_history h JOIN employees e ON e.id = h.employee_id LEFT JOIN employees m ON m.id = h.manager_employee_id WHERE e.employee_code = ?', [$code]);
        }
        self::assertSame(['D1-0119', 'D1-0117', 'D1-0103', 'D1-0101'], $chain, 'production worker → production manager → operations director → managing director');
        self::assertSame('D1-0104', $db->scalar("SELECT m.employee_code FROM employment_history h JOIN employees e ON e.id = h.employee_id JOIN employees m ON m.id = h.manager_employee_id WHERE e.employee_code = 'D1-0003'"));

        // accounts: the existing demo users are linked to their person; four new ones cover HRT, MGR, EMP and WAL
        foreach (
            ['ga.demo1' => 'D1-0113', 'driver.demo1' => 'D1-0114', 'tech.demo1' => 'D1-0122', 'safety.demo1' => 'D1-0124', 'dpo.demo1' => 'D1-0115',
            'auditor.demo' => 'D1-0116', 'payroll.maker' => 'D1-0106', 'payroll.checker' => 'D1-0107', 'exec.demo1' => 'D1-0101', 'hr.officer1' => 'D1-0105',
            'manager.prod1' => 'D1-0117', 'worker.prod1' => 'D1-0119', 'wallet.officer1' => 'D1-0111'] as $user => $emp
        ) {
            self::assertSame($emp, $db->scalar('SELECT e.employee_code FROM users u JOIN employees e ON e.id = u.employee_id WHERE u.username = ?', [$user]), $user);
        }
        foreach (CompanyDemo::ACCOUNTS as [$user, , $role]) {
            self::assertSame($role, $db->scalar("SELECT r.code FROM users u JOIN user_role_assignments a ON a.user_id = u.id AND a.status = 'active' JOIN roles r ON r.id = a.role_id WHERE u.username = ?", [$user]));
            $b = $this->t->browser();
            $b->login($user); // same demo password as the others
            self::assertSame(200, $b->get('/')->status, $user . ' signs in');
        }
        $roles = $db->all('SELECT DISTINCT r.code FROM users u JOIN user_role_assignments a ON a.user_id = u.id JOIN roles r ON r.id = a.role_id WHERE u.is_demo = 1');
        $have = array_column($roles, 'code');
        foreach (['EMP', 'MGR', 'HRA', 'HRT', 'PYO', 'PYA', 'FNM', 'FNC', 'CSH', 'WAL', 'SYS', 'AUD', 'PRV', 'EXE', 'WPA', 'SFO'] as $r) {
            self::assertContains($r, $have, 'a demo account holds ' . $r);
        }
        // made-up pay for everyone (no bank accounts), daily workers paid by the day
        self::assertSame(35, (int) $db->scalar('SELECT COUNT(*) FROM pay_profiles WHERE legal_entity_id = ?', [$le]));
        self::assertSame('daily', $db->scalar("SELECT p.pay_basis FROM pay_profiles p JOIN employees e ON e.id = p.employee_id WHERE e.employee_code = 'D1-0119'"));

        // approvals of the production worker (leave, OT …) go to the production manager; the HR officer's to the HR manager
        $uid = static fn (string $u): int => (int) $db->scalar('SELECT id FROM users WHERE username = ?', [$u]);
        $eid = static fn (string $u): int => (int) $db->scalar('SELECT employee_id FROM users WHERE username = ?', [$u]);
        $svc = \Blue\Modules\Employees\EmployeeService::of($this->t->app);
        self::assertSame([$uid('manager.prod1')], $svc->managerUserIds($eid('worker.prod1'), '2026-11-20'));
        self::assertSame([$uid('hr.head1')], $svc->managerUserIds($eid('hr.officer1'), '2026-11-20'));
        self::assertSame([$uid('ga.demo1')], $svc->managerUserIds($eid('driver.demo1'), '2026-11-20'));

        // the production manager sees the production worker in the team leave view, and only the team
        $mgr = $this->t->browser();
        $mgr->login('manager.prod1');
        $team = $mgr->get('/leave/team');
        self::assertSame(200, $team->status);
        self::assertStringContainsString('พนักงานฝ่ายผลิต (DEMO)', $team->body);
        self::assertStringNotContainsString('พนักงานขายสอง (DEMO)', $team->body, 'not the sales staff');
    }
}
