<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/**
 * Phase 4A (DEC-63) — employees-lite + M32 leave through the HTTP kernel. Test clock: Mon 2026-11-09 09:00 Bangkok;
 * DEMO1 work calendar = Mon–Fri. Policy values here are TEST values only (nothing is shipped).
 */
final class LeaveWebTest extends TestCase
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

    private const UNTRANSLATED = '/(?<![.\w])(leave|employees|workflow|config|document)\.[a-z_]+\.?[a-z_.]*\b(?![^<]*>)/';

    private function as(string $user): TestBrowser
    {
        $b = $this->t->browser();
        $b->login($user);
        return $b;
    }

    private function db(): \Blue\Core\Database\Connection
    {
        return $this->t->app->db();
    }

    private function type(string $code): string
    {
        return (string) $this->db()->scalar("SELECT t.public_id FROM lv_types t JOIN legal_entities l ON l.id = t.legal_entity_id WHERE l.code = 'DEMO1' AND t.code = ?", [$code]);
    }

    private function le(): int
    {
        return (int) $this->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    /** Approves the current open task of an instance as $b (re-auth when asked). */
    private function decide(TestBrowser $b, string $inst, string $decision, string $comment = ''): void
    {
        $b->get('/approvals/' . $inst);
        $res = $b->post('/approvals/' . $inst . '/decide', ['decision' => $decision, 'comment' => $comment, '_back' => '/approvals/' . $inst]);
        if (str_contains((string) ($res->headers['Location'] ?? ''), '/reauth')) {
            $b->get('/reauth');
            $b->post('/reauth', ['password' => $this->t->demoPassword, 'next' => '/approvals/' . $inst]);
            $b->get('/approvals/' . $inst);
            $b->post('/approvals/' . $inst . '/decide', ['decision' => $decision, 'comment' => $comment, '_back' => '/approvals/' . $inst]);
        }
    }

    /** HR proposes, the second HR approves — the TEST policy used by the other tests. */
    private function policy(): void
    {
        $hr = $this->as('hr.demo1');
        $hr->get('/leave/admin');
        $res = $hr->post('/leave/admin/policy', ['le' => (string) $this->le(), 'effective_from' => '2026-01-01', 'source_reference' => 'TEST ONLY — not a legal value', 'reason' => 'test setup',
            'types' => [
                'ANNUAL' => ['legal_minimum_days' => '6', 'entitlement_days' => '10', 'group_text' => 'DAILY=7', 'half_day' => '1', 'carry_forward_max_days' => '3', 'max_backdate_days' => '30'],
                'SICK' => ['legal_minimum_days' => '', 'entitlement_days' => '5', 'half_day' => '1', 'document_from_days' => '2', 'max_backdate_days' => '30'],
                'UNPAID' => ['entitlement_days' => '', 'min_notice_days' => '0', 'max_backdate_days' => '0'],
            ]]);
        self::assertSame(303, $res->status, $res->body);
        $inst = (string) $this->db()->scalar("SELECT i.public_id FROM workflow_instances i JOIN config_versions v ON v.public_id = i.subject_id ORDER BY i.id DESC LIMIT 1");
        $this->decide($this->as('hr.head1'), $inst, 'approve');
        self::assertSame('approved', $this->db()->scalar("SELECT v.status FROM config_versions v JOIN config_definitions d ON d.id = v.definition_id WHERE d.config_key = 'leave.policy'"));
    }

    public function testDemoEmployeesManagersAndPagesRenderTranslated(): void
    {
        self::assertSame(7, (int) $this->db()->scalar('SELECT COUNT(*) FROM employees WHERE is_demo = 1'));
        $emp = (int) $this->db()->scalar("SELECT employee_id FROM users WHERE username = 'employee.demo1'");
        $mgr = (int) $this->db()->scalar("SELECT id FROM users WHERE username = 'manager.demo1'");
        self::assertSame([$mgr], \Blue\Modules\Employees\EmployeeService::of($this->t->app)->managerUserIds($emp, '2026-11-09'));
        self::assertSame(5, (int) $this->db()->scalar('SELECT COUNT(*) FROM lv_types'));
        self::assertSame(0, (int) $this->db()->scalar("SELECT COUNT(*) FROM config_versions v JOIN config_definitions d ON d.id = v.definition_id WHERE d.config_key = 'leave.policy'"), 'no policy is shipped');

        $hr = $this->as('hr.demo1');
        $empPid = (string) $this->db()->scalar("SELECT public_id FROM employees WHERE employee_code = 'D1-0004'");
        foreach (['/employees', '/employees/new', '/employees/' . $empPid, '/leave', '/leave/admin', '/leave/team', '/leave/admin/employee/' . $empPid, '/import/leave.opening?le=' . $this->le()] as $p) {
            [$path, $qs] = array_pad(explode('?', $p, 2), 2, '');
            parse_str($qs, $q);
            $res = $hr->get($path, $q);
            self::assertSame(200, $res->status, $p);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body), "untranslated key on {$p}");
        }
        $e = $this->as('employee.demo1');
        foreach (['/leave', '/leave/new'] as $p) {
            $res = $e->get($p);
            self::assertSame(200, $res->status, $p);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body), "untranslated key on {$p}");
        }
        self::assertStringContainsString('ยังไม่มีนโยบาย', $e->get('/leave')->body);
        self::assertSame(403, $e->get('/employees')->status, 'employees.view is HR');
        self::assertSame(403, $e->get('/leave/admin')->status);
        self::assertSame(303, $e->get('/leave/team')->status, 'no direct reports → back with a message');
        $res = $e->post('/leave', ['type' => $this->type('ANNUAL'), 'start_date' => '2026-11-10', 'end_date' => '2026-11-10', 'part' => 'full']);
        self::assertSame(422, $res->status);
        self::assertStringContainsString('ยังไม่ได้ตั้งนโยบาย', $res->body, 'no policy → refused, nothing guessed');
    }

    public function testPolicyIsMakerCheckerAndRefusesBelowStatutory(): void
    {
        $hr = $this->as('hr.demo1');
        $hr->get('/leave/admin');
        $bad = $hr->post('/leave/admin/policy', ['le' => (string) $this->le(), 'effective_from' => '2026-01-01', 'source_reference' => 'TEST ONLY', 'reason' => 'x x',
            'types' => ['ANNUAL' => ['legal_minimum_days' => '6', 'entitlement_days' => '4']]]);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('น้อยกว่าขั้นต่ำตามกฎหมายไม่ได้', $bad->body);
        $noSource = $hr->post('/leave/admin/policy', ['le' => (string) $this->le(), 'effective_from' => '2026-01-01', 'source_reference' => '', 'reason' => 'x x',
            'types' => ['ANNUAL' => ['entitlement_days' => '10']]]);
        self::assertSame(422, $noSource->status, 'a source reference is required');

        $hr->post('/leave/admin/policy', ['le' => (string) $this->le(), 'effective_from' => '2026-01-01', 'source_reference' => 'TEST ONLY', 'reason' => 'x x', 'types' => ['ANNUAL' => ['entitlement_days' => '10']]]);
        $inst = (string) $this->db()->scalar('SELECT public_id FROM workflow_instances ORDER BY id DESC LIMIT 1');
        $assignees = array_column($this->db()->all('SELECT u.username FROM workflow_tasks k JOIN workflow_instances i ON i.id = k.instance_id JOIN users u ON u.id = k.assignee_id WHERE i.public_id = ?', [$inst]), 'username');
        self::assertSame(['hr.head1'], $assignees, 'only the other HR approver — never the proposer, never the system admin');
        $this->decide($hr, $inst, 'approve');
        self::assertSame('in_review', $this->db()->scalar("SELECT v.status FROM config_versions v JOIN config_definitions d ON d.id = v.definition_id WHERE d.config_key = 'leave.policy'"));
        $sys = $this->as(TestApp::ADMIN);
        self::assertSame(403, $sys->post('/leave/admin/policy', ['le' => (string) $this->le(), 'types' => []])->status, 'SYS does not own leave policy');
        $head = $this->as('hr.head1');
        $page = $head->get('/approvals/' . $inst)->body;
        self::assertStringContainsString('ลาพักผ่อนประจำปี (ANNUAL)', $page, 'the approver reads the policy per type');
        self::assertStringNotContainsString('{&quot;types&quot;', $page, 'no raw JSON');
        self::assertStringStartsWith('นโยบายการลา · 2026-01-01', (string) $this->db()->scalar('SELECT title FROM workflow_instances WHERE public_id = ?', [$inst]));
        $this->decide($head, $inst, 'approve');
        self::assertSame('approved', $this->db()->scalar("SELECT v.status FROM config_versions v JOIN config_definitions d ON d.id = v.definition_id WHERE d.config_key = 'leave.policy'"));
    }

    public function testRequestCountsWorkingDaysGoesToTheManagerAndPostsTheLedger(): void
    {
        $this->policy();
        $e = $this->as('employee.demo1');
        $e->get('/leave/new');
        $pre = json_decode($e->post('/leave/preview', ['type' => $this->type('ANNUAL'), 'start_date' => '2026-11-12', 'end_date' => '2026-11-16', 'part' => 'full'])->body, true);
        self::assertTrue($pre['ok']);
        self::assertSame('3 วัน', $pre['units'], 'Thu, Fri, Mon — the weekend is not counted');
        $res = $e->post('/leave', ['type' => $this->type('ANNUAL'), 'start_date' => '2026-11-12', 'end_date' => '2026-11-16', 'part' => 'full', 'reason' => 'พักผ่อน']);
        self::assertSame(303, $res->status, $res->body);
        $req = $this->db()->one('SELECT * FROM lv_requests');
        self::assertSame('pending', $req['status']);
        self::assertSame('3.00', (string) $req['units']);
        self::assertSame(['2026-11-12', '2026-11-13', '2026-11-16'], json_decode((string) $req['days_json'], true));
        $inst = (string) $this->db()->scalar('SELECT public_id FROM workflow_instances WHERE subject_type = ?', ['leave_request']);
        self::assertSame(['manager.demo1'], array_column($this->db()->all('SELECT u.username FROM workflow_tasks k JOIN users u ON u.id = k.assignee_id JOIN workflow_instances i ON i.id = k.instance_id WHERE i.public_id = ?', [$inst]), 'username'));

        $m = $this->as('manager.demo1');
        $page = $m->get('/approvals/' . $inst)->body;
        self::assertStringContainsString('10 → 7 วันหลังอนุมัติ', $page, 'the approver sees the balance before and after');
        $this->decide($m, $inst, 'approve');
        self::assertSame('approved', $this->db()->scalar('SELECT status FROM lv_requests'));
        self::assertSame('-3.00', (string) $this->db()->scalar("SELECT units FROM lv_ledger WHERE entry_type = 'taken'"));
        self::assertSame(1, (int) $this->db()->scalar("SELECT COUNT(*) FROM outbox_events WHERE event_name = 'leave.request.approved'"));
        $grant = $this->db()->one("SELECT l.units, l.statutory_units FROM lv_ledger l JOIN lv_types t ON t.id = l.lv_type_id WHERE l.entry_type = 'grant' AND t.code = 'ANNUAL'");
        self::assertSame(['10.00', '6.00'], [(string) $grant['units'], (string) $grant['statutory_units']], 'statutory share recorded apart from the company benefit');
        $again = $this->as('employee.demo1')->get('/leave')->body;
        self::assertMatchesRegularExpression('#leave-card__value">7 <#', $again);
        // the same approval cannot post twice
        \Blue\Modules\Leave\LeaveService::of($this->t->app)->onApproved((string) $req['public_id'], 1);
        self::assertSame(1, (int) $this->db()->scalar("SELECT COUNT(*) FROM lv_ledger WHERE entry_type = 'taken'"));
        self::assertSame(1, (int) $this->db()->scalar("SELECT COUNT(*) FROM outbox_events WHERE event_name = 'leave.request.approved'"));
    }

    public function testValidationOverlapBalanceHalfDaysAndWeekends(): void
    {
        $this->policy();
        $e = $this->as('employee.demo1');
        $e->get('/leave/new');
        $a = $this->type('ANNUAL');
        $err = static fn (string $body): array => (array) (json_decode($body, true)['errors'] ?? []);
        self::assertStringContainsString('ไม่มีวันทำงาน', implode(' ', $err($e->post('/leave/preview', ['type' => $a, 'start_date' => '2026-11-14', 'end_date' => '2026-11-15'])->body)));
        self::assertStringContainsString('ไม่พอ', implode(' ', $err($e->post('/leave/preview', ['type' => $a, 'start_date' => '2026-11-10', 'end_date' => '2026-11-24'])->body)), '11 working days > 10');
        self::assertStringContainsString('ข้ามปี', implode(' ', $err($e->post('/leave/preview', ['type' => $a, 'start_date' => '2026-12-31', 'end_date' => '2027-01-04'])->body)));
        self::assertStringContainsString('วันเดียว', implode(' ', $err($e->post('/leave/preview', ['type' => $a, 'start_date' => '2026-11-10', 'end_date' => '2026-11-11', 'part' => 'am'])->body)));
        self::assertStringContainsString('ย้อนหลังเกิน', implode(' ', $err($e->post('/leave/preview', ['type' => $a, 'start_date' => '2026-09-01'])->body)));
        $e->post('/leave', ['type' => $a, 'start_date' => '2026-11-10', 'part' => 'am']);
        $pm = $e->post('/leave', ['type' => $a, 'start_date' => '2026-11-10', 'part' => 'pm']);
        self::assertSame(303, $pm->status, 'morning + afternoon of the same day may coexist');
        $dup = $e->post('/leave', ['type' => $a, 'start_date' => '2026-11-10', 'end_date' => '2026-11-11']);
        self::assertSame(422, $dup->status);
        self::assertStringContainsString('ซ้อนกับคำขอลาอื่น', $dup->body);
        self::assertSame('0.50', (string) $this->db()->scalar('SELECT units FROM lv_requests ORDER BY id LIMIT 1'));
        $pre = json_decode($e->post('/leave/preview', ['type' => $a, 'start_date' => '2026-11-17', 'end_date' => '2026-11-27'])->body, true);
        self::assertTrue($pre['ok'], 'pending half days count against the balance: 9 working days fit into 10 - 1');
        self::assertSame('9 วัน', $pre['units']);
        $pre2 = json_decode($e->post('/leave/preview', ['type' => $a, 'start_date' => '2026-11-17', 'end_date' => '2026-11-30'])->body, true);
        self::assertFalse($pre2['ok'], '10 days no longer fit');
        // unpaid has no quota: any length within the rules
        self::assertTrue(json_decode($e->post('/leave/preview', ['type' => $this->type('UNPAID'), 'start_date' => '2026-12-01', 'end_date' => '2026-12-31'])->body, true)['ok']);
        self::assertSame('22 วัน', json_decode($e->post('/leave/preview', ['type' => $this->type('UNPAID'), 'start_date' => '2026-12-01', 'end_date' => '2026-12-31'])->body, true)['units'], 'company holiday 30 Dec not counted');
        // a type without policy
        self::assertStringContainsString('ยังไม่ได้ตั้งนโยบาย', implode(' ', $err($e->post('/leave/preview', ['type' => $this->type('PERSONAL'), 'start_date' => '2026-11-20'])->body)));
    }

    public function testMedicalDocumentRequiredAndVisibleOnlyToThoseInvolved(): void
    {
        $this->policy();
        $e = $this->as('employee.demo1');
        $e->get('/leave/new');
        $sick = $this->type('SICK');
        $no = $e->post('/leave', ['type' => $sick, 'start_date' => '2026-11-05', 'end_date' => '2026-11-06']);
        self::assertSame(422, $no->status);
        self::assertStringContainsString('ต้องแนบเอกสาร', $no->body);
        $png = (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/alpha.png');
        $ok = $e->post('/leave', ['type' => $sick, 'start_date' => '2026-11-05', 'end_date' => '2026-11-06'], ['document' => ['cert.png', $png]]);
        self::assertSame(303, $ok->status, $ok->body);
        $pid = (string) $this->db()->scalar('SELECT public_id FROM lv_requests');
        self::assertSame('R', $this->db()->scalar('SELECT classification FROM documents WHERE public_id = (SELECT document_pid FROM lv_requests)'), 'medical certificate = restricted');
        self::assertSame(200, $e->get('/leave/r/' . $pid . '/document')->status, 'owner');
        self::assertSame(200, $this->as('manager.demo1')->get('/leave/r/' . $pid . '/document')->status, 'deciding manager');
        self::assertSame(200, $this->as('hr.demo1')->get('/leave/r/' . $pid . '/document')->status, 'HR with leave.medical');
        self::assertSame(404, $this->as('finance.maker')->get('/leave/r/' . $pid . '/document')->status, 'anyone else: not found');
        self::assertSame(404, $this->as('finance.maker')->get('/leave/r/' . $pid)->status);
        self::assertSame(1, (int) $this->db()->scalar("SELECT COUNT(*) FROM documents WHERE document_class = 'leave.medical'"));
        self::assertStringNotContainsString('cert.png', $this->as('hr.demo1')->get('/documents')->body, 'module documents stay out of the central list');
    }

    public function testReturnEditResubmitRejectAndCancellations(): void
    {
        $this->policy();
        $e = $this->as('employee.demo1');
        $e->get('/leave/new');
        $a = $this->type('ANNUAL');
        $e->post('/leave', ['type' => $a, 'start_date' => '2026-11-18', 'end_date' => '2026-11-19']);
        $pid = (string) $this->db()->scalar('SELECT public_id FROM lv_requests');
        $inst = (string) $this->db()->scalar("SELECT public_id FROM workflow_instances WHERE subject_type = 'leave_request'");
        $m = $this->as('manager.demo1');
        $this->decide($m, $inst, 'return', 'ขอเลื่อนเป็นวันศุกร์');
        self::assertSame('returned', $this->db()->scalar('SELECT status FROM lv_requests'));
        self::assertSame(200, $e->get('/leave/r/' . $pid . '/edit')->status);
        $re = $e->post('/leave/r/' . $pid, ['type' => $a, 'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'part' => 'full']);
        self::assertSame(303, $re->status, $re->body);
        self::assertSame(['pending', '2026-11-20', '1.00'], array_values(array_map('strval', $this->db()->one('SELECT status, start_date, units FROM lv_requests') ?? [])));
        $this->decide($m, $inst, 'reject', 'งานด่วน');
        self::assertSame('rejected', $this->db()->scalar('SELECT status FROM lv_requests'));
        self::assertSame(0, (int) $this->db()->scalar("SELECT COUNT(*) FROM lv_ledger WHERE entry_type = 'taken'"));

        // withdraw a pending one
        $e->post('/leave', ['type' => $a, 'start_date' => '2026-11-24']);
        $p2 = (string) $this->db()->scalar('SELECT public_id FROM lv_requests ORDER BY id DESC LIMIT 1');
        $e->post('/leave/r/' . $p2 . '/cancel', ['reason' => 'ไม่ไปแล้ว']);
        self::assertSame('cancelled', $this->db()->scalar('SELECT status FROM lv_requests WHERE public_id = ?', [$p2]));

        // approved, not started → cancellation request → manager approves → days back + event
        $e->post('/leave', ['type' => $a, 'start_date' => '2026-11-25', 'end_date' => '2026-11-26']);
        $p3 = (string) $this->db()->scalar('SELECT public_id FROM lv_requests ORDER BY id DESC LIMIT 1');
        $this->decide($m, (string) $this->db()->scalar('SELECT i.public_id FROM workflow_instances i JOIN lv_requests r ON r.workflow_instance_id = i.id WHERE r.public_id = ?', [$p3]), 'approve');
        $e->post('/leave/r/' . $p3 . '/cancel', ['reason' => 'เปลี่ยนแผน']);
        self::assertSame('cancel_pending', $this->db()->scalar('SELECT status FROM lv_requests WHERE public_id = ?', [$p3]));
        $cinst = (string) $this->db()->scalar("SELECT public_id FROM workflow_instances WHERE subject_type = 'leave_cancel'");
        self::assertSame(['manager.demo1'], array_column($this->db()->all('SELECT u.username FROM workflow_tasks k JOIN users u ON u.id = k.assignee_id JOIN workflow_instances i ON i.id = k.instance_id WHERE i.public_id = ?', [$cinst]), 'username'));
        $this->decide($m, $cinst, 'approve');
        self::assertSame('cancelled', $this->db()->scalar('SELECT status FROM lv_requests WHERE public_id = ?', [$p3]));
        self::assertSame('0.00', (string) $this->db()->scalar('SELECT SUM(units) FROM lv_ledger WHERE source_id = ?', [$p3]), 'taken -2 then reversal +2');
        self::assertSame(1, (int) $this->db()->scalar("SELECT COUNT(*) FROM outbox_events WHERE event_name = 'leave.request.cancelled'"));

        // HR cancels someone's taken leave (past) — balance back; HR cannot fast-track their own
        $e->post('/leave', ['type' => $a, 'start_date' => '2026-11-03']);
        $p4 = (string) $this->db()->scalar('SELECT public_id FROM lv_requests ORDER BY id DESC LIMIT 1');
        $this->decide($m, (string) $this->db()->scalar('SELECT i.public_id FROM workflow_instances i JOIN lv_requests r ON r.workflow_instance_id = i.id WHERE r.public_id = ?', [$p4]), 'approve');
        \Blue\Modules\Leave\LeaveService::completeJob($this->t->app);
        self::assertSame('taken', $this->db()->scalar('SELECT status FROM lv_requests WHERE public_id = ?', [$p4]));
        $self = $e->post('/leave/r/' . $p4 . '/cancel', ['reason' => 'ลองเอง']);
        self::assertSame(303, $self->status);
        self::assertSame('taken', $this->db()->scalar('SELECT status FROM lv_requests WHERE public_id = ?', [$p4]), 'the employee cannot cancel leave already taken');
        $hr = $this->as('hr.demo1');
        $hr->get('/leave/r/' . $p4);
        $hr->post('/leave/r/' . $p4 . '/cancel', ['reason' => 'บันทึกผิดวัน']);
        self::assertSame('cancelled', $this->db()->scalar('SELECT status FROM lv_requests WHERE public_id = ?', [$p4]));
        self::assertSame('0.00', (string) $this->db()->scalar('SELECT SUM(units) FROM lv_ledger WHERE source_id = ?', [$p4]));
    }

    public function testNoManagerGoesToCompanyHrNeverToTheSystemAdmin(): void
    {
        $this->policy();
        $m = $this->as('manager.demo1'); // D1-0003 has no manager
        $m->get('/leave/new');
        $res = $m->post('/leave', ['type' => $this->type('ANNUAL'), 'start_date' => '2026-11-20']);
        self::assertSame(303, $res->status, $res->body);
        $inst = (string) $this->db()->scalar("SELECT public_id FROM workflow_instances WHERE subject_type = 'leave_request'");
        $who = array_column($this->db()->all('SELECT u.username FROM workflow_tasks k JOIN users u ON u.id = k.assignee_id JOIN workflow_instances i ON i.id = k.instance_id WHERE i.public_id = ? ORDER BY u.username', [$inst]), 'username');
        self::assertSame(['hr.demo1', 'hr.head1'], $who);
    }

    public function testHrAdjustmentsOpeningImportAndTeamCalendar(): void
    {
        $this->policy();
        $hr = $this->as('hr.demo1');
        $empPid = (string) $this->db()->scalar("SELECT public_id FROM employees WHERE employee_code = 'D1-0004'");
        $hr->get('/leave/admin/employee/' . $empPid);
        $bad = $hr->post('/leave/admin/employee/' . $empPid . '/adjust', ['type' => $this->type('ANNUAL'), 'year' => '2026', 'units' => '0.3', 'reason' => 'x']);
        self::assertSame(422, $bad->status);
        $hr->post('/leave/admin/employee/' . $empPid . '/adjust', ['type' => $this->type('ANNUAL'), 'year' => '2026', 'units' => '1.5', 'reason' => 'ชดเชยวันหยุด']);
        self::assertSame('1.50', (string) $this->db()->scalar("SELECT units FROM lv_ledger WHERE entry_type = 'adjustment'"));
        $own = (string) $this->db()->scalar("SELECT public_id FROM employees WHERE employee_code = 'D1-0002'");
        $hr->get('/leave/admin/employee/' . $own);
        self::assertSame(403, $hr->post('/leave/admin/employee/' . $own . '/adjust', ['type' => $this->type('ANNUAL'), 'year' => '2026', 'units' => '5', 'reason' => 'ให้ตัวเอง'])->status, 'no self-adjustment');

        // opening balance import (once per person/type/year)
        $csv = "employee_code,type_code,year,units\nD1-0005,ANNUAL,2569,4.5\nD1-0005,ANNUAL,2026,1\nNOPE,ANNUAL,2026,1\n";
        $hr->get('/import/leave.opening', ['le' => (string) $this->le()]);
        $up = $hr->post('/import/leave.opening', ['le' => (string) $this->le()], ['file' => ['opening.csv', $csv]]);
        self::assertSame(303, $up->status, $up->body);
        $batch = basename((string) $up->headers['Location']);
        $hr->get('/import/batch/' . $batch);
        $hr->post('/import/batch/' . $batch . '/confirm', ['skip_errors' => '1']);
        self::assertSame('4.50', (string) $this->db()->scalar("SELECT units FROM lv_ledger WHERE entry_type = 'opening_balance'"), 'Buddhist year 2569 = 2026; duplicate + unknown rows skipped');

        // team calendar: the manager sees the team, the employee is refused
        $e = $this->as('employee.demo1');
        $e->get('/leave/new');
        $e->post('/leave', ['type' => $this->type('ANNUAL'), 'start_date' => '2026-11-19']);
        $cal = $this->as('manager.demo1')->get('/leave/team', ['month' => '2026-11']);
        self::assertSame(200, $cal->status);
        self::assertStringContainsString('D1-0004', $cal->body);
        self::assertStringContainsString('leave-chip is-pending', $cal->body);
    }

    public function testEmployeeMasterEffectiveDatedChangesAndRights(): void
    {
        $hr = $this->as('hr.demo1');
        $hr->get('/employees/new');
        $le = $this->le();
        $site = (int) $this->db()->scalar("SELECT id FROM sites WHERE legal_entity_id = ? AND code = 'HQ'", [$le]);
        $mgr = (int) $this->db()->scalar("SELECT id FROM employees WHERE employee_code = 'D1-0003'");
        $res = $hr->post('/employees', ['employee_code' => 'd1-0100', 'name_th' => 'ทดสอบ ใหม่', 'status' => 'active', 'hire_date' => '2026-11-01',
            'legal_entity_id' => (string) $le, 'site_id' => (string) $site, 'manager_employee_id' => (string) $mgr, 'employee_group' => 'monthly', 'employment_type' => 'probation']);
        self::assertSame(303, $res->status, $res->body);
        $pid = (string) $this->db()->scalar("SELECT public_id FROM employees WHERE employee_code = 'D1-0100'");
        self::assertNotSame('', $pid, 'code upper-cased');
        $dup = $hr->post('/employees', ['employee_code' => 'D1-0100', 'name_th' => 'ซ้ำ', 'hire_date' => '2026-11-01', 'legal_entity_id' => (string) $le, 'employee_group' => 'MONTHLY']);
        self::assertSame(422, $dup->status);
        $hr->get('/employees/' . $pid);
        $back = $hr->post('/employees/' . $pid . '/employment', ['legal_entity_id' => (string) $le, 'employee_group' => 'MONTHLY', 'employment_type' => 'permanent', 'effective_from' => '2026-10-01', 'change_reason' => 'x']);
        self::assertSame(422, $back->status, 'no rewriting of history');
        $ok = $hr->post('/employees/' . $pid . '/employment', ['legal_entity_id' => (string) $le, 'site_id' => (string) $site, 'manager_employee_id' => (string) $mgr, 'employee_group' => 'MONTHLY',
            'employment_type' => 'permanent', 'effective_from' => '2027-03-01', 'change_reason' => 'ผ่านทดลองงาน']);
        self::assertSame(303, $ok->status, $ok->body);
        // 0.8.0 (DEC-67): a change is a request another HR person approves; a future date applies on that date
        $req = $this->db()->one("SELECT r.*, i.public_id AS inst FROM emp_change_requests r JOIN workflow_instances i ON i.id = r.workflow_instance_id WHERE r.employee_id = (SELECT id FROM employees WHERE public_id = ?)", [$pid]);
        self::assertSame('pending', $req['status']);
        self::assertSame([null], array_column($this->db()->all('SELECT effective_to FROM employment_history WHERE employee_id = (SELECT id FROM employees WHERE public_id = ?)', [$pid]), 'effective_to'), 'nothing changes before approval');
        $hr->post('/employees/' . $pid . '/employment', ['legal_entity_id' => (string) $le, 'employee_group' => 'DAILY', 'employment_type' => 'daily', 'effective_from' => '2027-04-01', 'change_reason' => 'ซ้อน']);
        self::assertSame(1, (int) $this->db()->scalar('SELECT COUNT(*) FROM emp_change_requests WHERE employee_id = ?', [$req['employee_id']]), 'one open request per kind');
        $this->decide($this->as('hr.head1'), (string) $req['inst'], 'approve');
        self::assertSame('approved', $this->db()->scalar('SELECT status FROM emp_change_requests WHERE id = ?', [$req['id']]), 'future date: approved, not yet applied');
        $this->t->clock->advance('+113 days'); // 2027-03-02
        \Blue\Modules\Employees\ChangeService::dueJob($this->t->app);
        self::assertSame('applied', $this->db()->scalar('SELECT status FROM emp_change_requests WHERE id = ?', [$req['id']]));
        self::assertSame(['2027-02-28', null], array_column($this->db()->all('SELECT effective_to FROM employment_history WHERE employee_id = (SELECT id FROM employees WHERE public_id = ?) ORDER BY effective_from', [$pid]), 'effective_to'));
        $this->t->clock->advance('-113 days');
        // a loop in the reporting line is refused
        $mgrPid = (string) $this->db()->scalar("SELECT public_id FROM employees WHERE employee_code = 'D1-0003'");
        $newId = (int) $this->db()->scalar('SELECT id FROM employees WHERE public_id = ?', [$pid]);
        $hr->get('/employees/' . $mgrPid);
        $loop = $hr->post('/employees/' . $mgrPid . '/employment', ['legal_entity_id' => (string) $le, 'manager_employee_id' => (string) $newId, 'employee_group' => 'MONTHLY', 'employee_type' => 'permanent',
            'effective_from' => '2026-12-01', 'change_reason' => 'ลอง']);
        self::assertSame(422, $loop->status);
        // link an account; one account per employee
        $link = $hr->post('/employees/' . $pid . '/user', ['username' => 'employee.demo1']);
        self::assertSame(422, $link->status, 'already linked to D1-0004');
        // HR of another company cannot see DEMO1 employees
        self::assertSame(404, $this->as('hr.demo2')->get('/employees/' . $pid)->status);
        self::assertSame(1, (int) $this->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'employee.employment_changed'"));
    }

    /** 0.7.0 (DEC-66): leave cannot be asked for, approved or cancelled over days of a closed attendance period. */
    public function testLeaveRespectsClosedAttendancePeriods(): void
    {
        $this->policy();
        $e = $this->as('employee.demo1');
        $e->get('/leave/new');
        $res = $e->post('/leave', ['type' => $this->type('ANNUAL'), 'start_date' => '2026-11-12', 'end_date' => '2026-11-13', 'part' => 'full', 'reason' => 'พักผ่อน']);
        self::assertSame(303, $res->status, $res->body);
        $inst = (string) $this->db()->scalar('SELECT public_id FROM workflow_instances WHERE subject_type = ?', ['leave_request']);
        $hr = (int) $this->db()->scalar("SELECT id FROM users WHERE username = 'hr.demo1'");
        $lock = $this->t->app->locks()->lock('attendance', 'legal_entity', $this->le(), '2026-11-09..2026-11-13', 'hard', $hr, 'test: period sent for close');
        $e->get('/leave/new');
        $blocked = $e->post('/leave', ['type' => $this->type('ANNUAL'), 'start_date' => '2026-11-11', 'end_date' => '2026-11-11', 'part' => 'full', 'reason' => 'ธุระ']);
        self::assertSame(422, $blocked->status);
        self::assertStringContainsString('งวดเวลาทำงานที่ปิดแล้ว', $blocked->body);
        // the pending request cannot be approved into the closed period (approval execution refused, nothing posted)
        $this->decide($this->as('manager.demo1'), $inst, 'approve');
        self::assertSame('pending', $this->db()->scalar('SELECT status FROM lv_requests'));
        self::assertSame(0, (int) $this->db()->scalar("SELECT COUNT(*) FROM lv_ledger WHERE entry_type = 'taken'"));
        self::assertSame('lock.error.locked', $this->db()->scalar('SELECT execution_error FROM workflow_instances WHERE public_id = ?', [$inst]));
        self::assertGreaterThan(0, (int) $this->db()->scalar("SELECT COUNT(*) FROM lock_events WHERE event = 'blocked_write'"));
        // released → the next days are free again
        $this->t->app->locks()->release($lock, $hr, 'test');
        $e->get('/leave/new');
        self::assertSame(303, $e->post('/leave', ['type' => $this->type('ANNUAL'), 'start_date' => '2026-11-16', 'end_date' => '2026-11-16', 'part' => 'full', 'reason' => 'ลา'])->status);
    }
}
