<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Modules\Manpower\ManpowerService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/** Phase 5E-3 (DEC-78): M11 manpower budget — plan versions with approval, plan vs actual, requisition check (warn/block), revision, export, history. */
final class ManpowerWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(manpower|recruit|employees|common|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

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

    /** @return list<int> */
    private function approveAll(string $subjectPid): array
    {
        $db = $this->t->app->db();
        $who = [];
        for ($i = 0; $i < 5; $i++) {
            $inst = (string) $db->scalar("SELECT public_id FROM workflow_instances WHERE subject_id = ? AND status IN ('pending','needs_attention') LIMIT 1", [$subjectPid]);
            if ($inst === '') {
                break;
            }
            $u = (int) $db->scalar("SELECT k.assignee_id FROM workflow_tasks k JOIN workflow_instances i ON i.id = k.instance_id WHERE i.public_id = ? AND k.status = 'open' LIMIT 1", [$inst]);
            self::assertGreaterThan(0, $u);
            $who[] = $u;
            $this->t->app->workflow()->decide($inst, $u, 'approve');
        }
        return $who;
    }

    /** @return array<string, mixed> */
    private function requisition(string $title, string $dept = ''): array
    {
        return ['department' => $dept, 'position_title' => $title, 'headcount' => '1', 'employment_type' => 'permanent', 'reason' => 'new', 'replaces' => '', 'salary_min' => '', 'salary_max' => '',
            'needed_by' => '2026-12-15', 'justification' => 'ลูกค้าเพิ่มขึ้นจากสาขาใหม่ (ทดสอบ)'];
    }

    public function testPlanActualCheckAndRevision(): void
    {
        $db = $this->t->app->db();
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $lePid = (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        $sales = (string) $db->scalar("SELECT public_id FROM departments WHERE code = 'SALES' AND legal_entity_id = ?", [$le]);
        $hrDept = (string) $db->scalar("SELECT public_id FROM departments WHERE code = 'HR' AND legal_entity_id = ?", [$le]);
        self::assertSame(403, $this->browser('employee.demo1')->get('/manpower')->status);
        $hr = $this->browser('hr.demo1');
        $idx = $hr->get('/manpower');
        self::assertSame(200, $idx->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($idx->body));

        // no plan: requisitions are not blocked and say so
        $mgr = $this->browser('manager.demo1');
        $mgr->get('/recruit/requisitions');
        self::assertSame(303, $mgr->post('/recruit/requisitions', $this->requisition('พนักงานขาย'))->status);
        $r0 = $db->one('SELECT * FROM rec_requisitions ORDER BY id DESC LIMIT 1');
        self::assertSame('no_plan', $db->scalar('SELECT result FROM mp_checks WHERE requisition_id = ?', [$r0['id']]));
        $db->run("UPDATE rec_requisitions SET status = 'cancelled' WHERE id = ?", [$r0['id']]);

        // plan + lines (validation), plan vs actual
        $hr->get('/manpower');
        self::assertSame(303, $hr->post('/manpower', ['le' => $lePid, 'fiscal_year' => '2026', 'title' => 'แผนอัตรากำลัง 2026', 'assumptions' => 'ขึ้นเงินเดือน 3% (ตัวอย่าง)'])->status);
        $plan = $db->one('SELECT * FROM mp_plans ORDER BY id DESC LIMIT 1');
        $hr->get('/manpower');
        self::assertSame(422, $hr->post('/manpower', ['le' => $lePid, 'fiscal_year' => '2026', 'title' => 'ซ้ำ'])->status, 'one open version per year');
        $hr->get('/manpower/p/' . $plan['public_id']);
        $lines = [
            ['department' => $sales, 'position_title' => 'พนักงานขาย', 'headcount' => '3', 'monthly_cost' => '18,000', 'months' => '12', 'note' => ''],
            ['department' => $sales, 'position_title' => '', 'headcount' => '5', 'monthly_cost' => '', 'months' => '', 'note' => ''],
            ['department' => $hrDept, 'position_title' => '', 'headcount' => '3.5', 'monthly_cost' => '30000', 'months' => '6', 'note' => 'ครึ่งปีหลัง'],
            ['department' => '', 'position_title' => '', 'headcount' => '', 'monthly_cost' => '', 'months' => '', 'note' => ''],
        ];
        self::assertSame(422, $hr->post('/manpower/p/' . $plan['public_id'], ['title' => 'แผนอัตรากำลัง 2026', 'lines' => [['department' => $sales, 'position_title' => 'x', 'headcount' => 'abc']]])->status);
        $hr->get('/manpower/p/' . $plan['public_id']);
        self::assertSame(303, $hr->post('/manpower/p/' . $plan['public_id'], ['title' => 'แผนอัตรากำลัง 2026', 'assumptions' => 'ขึ้นเงินเดือน 3% (ตัวอย่าง)', 'lines' => $lines])->status, $this->errorsLogged());
        self::assertSame(3, (int) $db->scalar('SELECT COUNT(*) FROM mp_lines WHERE plan_id = ?', [$plan['id']]));
        $rep = ManpowerService::of($this->t->app)->report((int) $plan['id']);
        $byLine = [];
        foreach ($rep['lines'] as $x) {
            $byLine[$x['dept'] . '|' . $x['title']] = $x;
        }
        self::assertSame(3, $byLine['SALES|พนักงานขาย']['actual'], 'D1-0004/5/6');
        self::assertSame(5, $byLine['SALES|']['actual'], 'whole sales unit');
        self::assertSame(2, $byLine['HR|']['actual']);
        self::assertSame(648000.0, $byLine['SALES|พนักงานขาย']['cost'], '3 × 18,000 × 12');
        self::assertSame(630000.0, $byLine['HR|']['cost'], '3.5 × 30,000 × 6');
        $page = $hr->get('/manpower/p/' . $plan['public_id']);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page->body));
        self::assertStringNotContainsString('พนักงานตัวอย่าง', $page->body, 'counts only, no names');

        // submit → another person approves → active baseline with a snapshot
        $hr->get('/manpower/p/' . $plan['public_id']);
        $hr->post('/manpower/p/' . $plan['public_id'] . '/submit', []);
        self::assertSame('review', $db->scalar('SELECT status FROM mp_plans WHERE id = ?', [$plan['id']]));
        $hr->get('/manpower/p/' . $plan['public_id']);
        self::assertSame(303, $hr->post('/manpower/p/' . $plan['public_id'], ['title' => 'แก้ระหว่างรอ', 'lines' => $lines])->status);
        self::assertSame('แผนอัตรากำลัง 2026', $db->scalar('SELECT title FROM mp_plans WHERE id = ?', [$plan['id']]), 'not editable in review');
        $who = $this->approveAll((string) $plan['public_id']);
        self::assertNotContains((int) $this->t->user('hr.demo1')['id'], $who);
        $p1 = $db->one('SELECT * FROM mp_plans WHERE id = ?', [$plan['id']]);
        self::assertSame('active', $p1['status']);
        self::assertStringContainsString('"actual":3', (string) $p1['snapshot_json']);

        // a manager asks for one more salesperson: 3 planned, 3 employed → over the plan; warn = saved and flagged
        $mgr->get('/recruit/requisitions');
        self::assertSame(303, $mgr->post('/recruit/requisitions', $this->requisition('พนักงานขาย'))->status);
        $r1 = $db->one('SELECT * FROM rec_requisitions ORDER BY id DESC LIMIT 1');
        $c1 = $db->one('SELECT * FROM mp_checks WHERE requisition_id = ?', [$r1['id']]);
        self::assertSame(['over', 'warn'], [$c1['result'], $c1['mode']]);
        $view = $hr->get('/recruit/r/' . $r1['public_id']);
        self::assertSame(200, $view->status, $this->errorsLogged());
        self::assertStringContainsString("เกินแผน", strip_tags($view->body));
        $rows = \Blue\Modules\Recruit\RequisitionSubject::create($this->t->app)->describe(['subject_id' => $r1['public_id']])['rows'];
        self::assertStringContainsString('เกินแผน', (string) end($rows)[1], 'approvers see it');

        // block: the next one is refused with the reason
        $hr->get('/manpower');
        $hr->post('/manpower/settings', ['le' => $lePid, 'over_budget' => 'block']);
        $mgr->get('/recruit/requisitions');
        $res = $mgr->post('/recruit/requisitions', $this->requisition('พนักงานขาย'));
        self::assertSame(422, $res->status);
        self::assertStringContainsString('เกินแผนอัตรากำลัง', $res->body);
        // a replacement is never checked
        $mgr->get('/recruit/requisitions');
        self::assertSame(303, $mgr->post('/recruit/requisitions', ['reason' => 'replacement', 'replaces' => 'D1-0005 ลาออก'] + $this->requisition('พนักงานขาย'))->status);
        // within the plan: HR line 3.5 with 2 people → one more fits
        $hr->get('/recruit/requisitions');
        self::assertSame(303, $hr->post('/recruit/requisitions', $this->requisition('เจ้าหน้าที่บุคคล', $hrDept))->status, $this->errorsLogged());
        $r3 = $db->one('SELECT * FROM rec_requisitions ORDER BY id DESC LIMIT 1');
        self::assertSame('within', $db->scalar('SELECT result FROM mp_checks WHERE requisition_id = ?', [$r3['id']]));
        self::assertGreaterThanOrEqual(1, (int) ManpowerService::of($this->t->app)->report((int) $plan['id'])['totals']['pending'], 'pending requisitions counted');

        // revise: a new draft copied from the active plan; the active one stays until approved; what-if column
        $hr->get('/manpower/p/' . $plan['public_id']);
        $go = $hr->post('/manpower/p/' . $plan['public_id'] . '/revise', []);
        $p2 = $db->one('SELECT * FROM mp_plans WHERE revision_of = ?', [$plan['id']]);
        self::assertNotNull($p2);
        self::assertStringContainsString((string) $p2['public_id'], (string) $go->headers['Location']);
        self::assertSame([2, 'draft', 3], [(int) $p2['version_no'], $p2['status'], (int) $db->scalar('SELECT COUNT(*) FROM mp_lines WHERE plan_id = ?', [$p2['id']])]);
        $lines[0]['headcount'] = '4';
        $hr->get('/manpower/p/' . $p2['public_id']);
        $hr->post('/manpower/p/' . $p2['public_id'], ['title' => 'แผนอัตรากำลัง 2026 (ปรับ)', 'lines' => $lines]);
        $wi = $hr->get('/manpower/p/' . $p2['public_id'])->body;
        self::assertStringContainsString('(+1)', $wi, 'what-if against the active plan');
        $hr->post('/manpower/p/' . $p2['public_id'] . '/submit', []);
        $this->approveAll((string) $p2['public_id']);
        self::assertSame(['superseded', 'active'], [$db->scalar('SELECT status FROM mp_plans WHERE id = ?', [$plan['id']]), $db->scalar('SELECT status FROM mp_plans WHERE id = ?', [$p2['id']])]);

        // export (counts only) + history + close
        $csv = $hr->get('/manpower/p/' . $p2['public_id'] . '/export', ['format' => 'csv']);
        self::assertSame(200, $csv->status);
        self::assertStringContainsString('planned_cost', $csv->body);
        self::assertStringNotContainsString('พนักงานตัวอย่าง', $csv->body);
        self::assertSame(200, $hr->get('/manpower/p/' . $p2['public_id'] . '/export')->status, 'xlsx');
        self::assertGreaterThan(0, ManpowerService::snapshotJob($this->t->app));
        self::assertSame(0, ManpowerService::snapshotJob($this->t->app), 'once a month');
        self::assertStringContainsString('2026-11', $hr->get('/manpower')->body);
        $hr->get('/manpower/p/' . $p2['public_id']);
        $hr->post('/manpower/p/' . $p2['public_id'] . '/close', []);
        self::assertSame('closed', $db->scalar('SELECT status FROM mp_plans WHERE id = ?', [$p2['id']]));
    }
}
