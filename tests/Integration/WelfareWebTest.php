<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Pdf\Dict;
use Blue\Core\Pdf\Name;
use Blue\Core\Pdf\Stream;
use Blue\Core\Pdf\Writer;
use Blue\Core\Support\Money;
use Blue\Core\Support\Ulid;
use Blue\Modules\Payroll\PayrollService;
use Blue\Modules\Welfare\ClaimService;
use Blue\Modules\Welfare\PayoutService;
use Blue\Modules\Welfare\TypeService;
use Blue\Modules\Welfare\WelfarePersonalData;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/**
 * Phase 6D M37 welfare claims (DEC-86). Clock: Mon 2026-11-09. Demo types (made-up DEMO limits): MED medical 20 000 a
 * year, paid with payroll on WELFARE; FUNERAL assistance 5 000 a claim, at most 2 a year, 119 days of service, paid by
 * transfer. Payroll rule values are TEST values (as in the other payroll tests).
 */
final class WelfareWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(welfare|payroll|workflow|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    private function as(string $user): TestBrowser
    {
        $b = $this->t->browser();
        $b->login($user);
        return $b;
    }

    private static function pdf(string $label): string
    {
        $w = new Writer();
        $c = $w->add(new Stream(new Dict(), 'BT /F1 18 Tf 72 760 Td (' . $label . ') Tj ET'));
        $w->addPage(new Dict(['Type' => new Name('Page'), 'MediaBox' => [0, 0, 595.28, 841.89],
            'Resources' => new Dict(['Font' => new Dict(['F1' => new Dict(['Type' => new Name('Font'), 'Subtype' => new Name('Type1'), 'BaseFont' => new Name('Helvetica')])])]), 'Contents' => $c]));
        return $w->output();
    }

    /** @return array<string, mixed> */
    private function logs(): string
    {
        return implode("\n", array_map(static fn (string $f): string => mb_substr((string) file_get_contents($f), -3000), glob($this->t->tmp . '/logs/*') ?: []));
    }

    /** @return array<string, mixed> */
    private function claim(string $pid): array
    {
        return $this->t->app->db()->one('SELECT * FROM wf_claims WHERE public_id = ?', [$pid]) ?? [];
    }

    private function newClaim(TestBrowser $b, array $in, array $files): string
    {
        $r = $b->post('/welfare/claims', $in, $files === [] ? [] : ['files' => $files]);
        self::assertSame(303, $r->status, strip_tags($r->body));
        return (string) $this->t->app->db()->scalar('SELECT public_id FROM wf_claims ORDER BY id DESC LIMIT 1');
    }

    public function testTypesClaimsReviewApprovalAndPayout(): void
    {
        $app = $this->t->app;
        $db = $app->db();
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $types = TypeService::of($app);
        $claims = ClaimService::of($app);
        $hr = $this->az('hr.demo1');
        $head = $this->az('hr.head1');

        // ---- types: nothing legal, written by one HR person, activated by another; a change is a new version ----------
        self::assertSame(['FUNERAL', 'MED'], array_column($types->inForce($le, '2026-11-09'), 'code'), 'demo types (DEMO limits)');
        try {
            $types->save($hr, $le, ['code' => '1x', 'name_th' => 'ทันตกรรม', 'category' => 'medical', 'payout' => 'payroll', 'component_code' => 'NOPE', 'policy_ref' => 'x', 'effective_from' => '2026-13-01']);
            self::fail('validation');
        } catch (ValidationException $e) {
            foreach (['code', 'limit_year', 'component_code', 'policy_ref', 'effective_from'] as $k) {
                self::assertArrayHasKey($k, $e->errors, $k);
            }
        }
        $in = ['code' => 'DENTAL', 'name_th' => 'ค่าทันตกรรม (ทดสอบ)', 'category' => 'medical', 'limit_year' => '3,000', 'window_days' => '30', 'receipt_required' => true,
            'payout' => 'payroll', 'component_code' => 'WELFARE', 'policy_ref' => 'ระเบียบสวัสดิการ (ทดสอบ) ข้อ 9', 'effective_from' => '2026-01-01'];
        $v1 = $types->save($hr, $le, $in);
        try {
            $types->activate($hr, $v1);
            self::fail('own draft');
        } catch (BusinessException $e) {
            self::assertSame('welfare.error.own_draft', $e->messageKey);
        }
        $types->activate($head, $v1);
        $v2 = $types->save($head, $le, ['limit_year' => '6000', 'effective_from' => '2026-12-01', 'change_note' => 'ปรับวงเงิน (ทดสอบ)'] + $in, $v1);
        $types->activate($hr, $v2);
        self::assertSame('2026-11-30', $db->scalar('SELECT effective_to FROM wf_types WHERE public_id = ?', [$v1]));
        self::assertSame([1, 2], [(int) ($types->inForceOn($le, 'DENTAL', '2026-11-09')['version_no'] ?? 0), (int) ($types->inForceOn($le, 'DENTAL', '2026-12-05')['version_no'] ?? 0)]);
        $page = $this->as('hr.demo1')->get('/welfare/types');
        self::assertSame(200, $page->status, $this->logs());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page->body));
        self::assertSame(403, $this->as('employee.demo1')->get('/welfare/types')->status);

        // ---- the employee writes a claim ----------------------------------------------------------------------------------
        $emp = $this->as('employee.demo1');
        $page = $emp->get('/welfare');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('20,000.00', $page->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page->body));
        self::assertSame(200, $emp->get('/welfare/new', ['type' => 'MED'])->status);
        self::assertSame(422, $emp->post('/welfare/claims', ['type' => 'MED', 'expense_date' => '2026-12-01', 'description' => 'x', 'amount' => '-1'])->status);
        $emp->get('/welfare/new');
        $receipt1 = self::pdf('Receipt RC-001 hospital 12000');
        $c1 = $this->newClaim($emp, ['type' => 'MED', 'expense_date' => '2026-11-01', 'description' => 'ค่ารักษาผู้ป่วยนอก (ทดสอบ)', 'amount' => '12,000', 'receipt_no' => 'RC-001', 'receipt_from' => 'โรงพยาบาลทดสอบ'], ['r1.pdf', $receipt1]);
        self::assertSame('draft', $this->claim($c1)['status']);
        self::assertSame('welfare.receipt_health', $db->scalar('SELECT d.document_class FROM wf_claim_files f JOIN documents d ON d.public_id = f.document_pid JOIN wf_claims c ON c.id = f.claim_id WHERE c.public_id = ?', [$c1]), 'medical receipts are Restricted');
        $page = $emp->get('/welfare/claims/' . $c1);
        self::assertSame(200, $page->status);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page->body));
        self::assertSame(303, $emp->post('/welfare/claims/' . $c1 . '/submit')->status);
        $c = $this->claim($c1);
        self::assertSame(['submitted', 'WF-2026-00001'], [$c['status'], $c['claim_no']]);

        // duplicates: the same file, the same receipt number (written differently) on another claim
        $emp->get('/welfare/new');
        $d1 = $this->newClaim($emp, ['type' => 'MED', 'expense_date' => '2026-11-01', 'description' => 'ซ้ำ (ทดสอบ)', 'amount' => '500'], ['again.pdf', $receipt1]);
        self::assertContains('duplicate_file', $claims->check($this->claim($d1))['blocking']);
        self::assertSame(303, $emp->post('/welfare/claims/' . $d1 . '/submit')->status);
        self::assertSame('draft', $this->claim($d1)['status'], 'a claim that fails a check is not sent');
        $emp->get('/welfare/new');
        $d2 = $this->newClaim($emp, ['type' => 'MED', 'expense_date' => '2026-11-03', 'description' => 'ซ้ำเลขใบเสร็จ (ทดสอบ)', 'amount' => '700', 'receipt_no' => 'rc 001', 'receipt_from' => 'โรงพยาบาล ทดสอบ'], ['other.pdf', self::pdf('other')]);
        self::assertContains('duplicate_receipt', $claims->check($this->claim($d2))['blocking']);
        $claims->cancel($this->az('employee.demo1'), $d1, '');
        $claims->cancel($this->az('employee.demo1'), $d2, 'ยื่นผิด');

        // G6D-01 limit order: a later claim waits behind the earlier one — 20 000 − 12 000 waiting = 8 000 payable
        $emp->get('/welfare/new');
        $c2 = $this->newClaim($emp, ['type' => 'MED', 'expense_date' => '2026-11-02', 'description' => 'ค่ายา (ทดสอบ)', 'amount' => '10000', 'receipt_no' => 'RC-002'], ['r2.pdf', self::pdf('Receipt RC-002')]);
        $chk = $claims->check($this->claim($c2));
        self::assertSame([true, 80000000, ['over_limit']], [$chk['ok'], $chk['eligible'], $chk['warnings']]);
        $claims->submit($this->az('employee.demo1'), $c2);

        // ---- review (step 1) → approval (step 2) by another person ----------------------------------------------------------
        try {
            $claims->decide($this->az('employee.demo1'), $c1, 'pass', '', '');
            self::fail('the employee has no task');
        } catch (BusinessException $e) {
            self::assertSame('welfare.error.no_task', $e->messageKey);
        }
        $q = $this->as('hr.demo1')->get('/welfare/admin');
        self::assertSame(200, $q->status);
        self::assertStringContainsString('WF-2026-00001', $q->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($q->body));
        $hrb = $this->as('hr.demo1');
        $hrb->get('/welfare/claims/' . $c1);
        self::assertSame(303, $hrb->post('/welfare/claims/' . $c1 . '/decide', ['decision' => 'pass', 'note' => 'เอกสารครบ'])->status);
        $c = $this->claim($c1);
        self::assertSame(['reviewing', (int) $this->t->user('hr.demo1')['id']], [$c['status'], (int) $c['reviewed_by']]);
        $inst = (int) $db->scalar('SELECT id FROM workflow_instances WHERE public_id = ?', [$c['workflow_pid']]);
        self::assertSame([(int) $this->t->user('hr.head1')['id']], array_map('intval', array_column($db->all("SELECT assignee_id FROM workflow_tasks WHERE instance_id = ? AND step_no = 2 AND status = 'open'", [$inst]), 'assignee_id')), 'distinct steps: the reviewer is not an approver');
        try {
            $claims->decide($hr, $c1, 'approve', '12000', '');
            self::fail('reviewer approves');
        } catch (BusinessException $e) {
            self::assertSame('welfare.error.no_task', $e->messageKey);
        }
        try {
            $claims->decide($head, $c1, 'approve', '15000', '');
            self::fail('more than payable');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('amount', $e->errors);
        }
        $claims->decide($head, $c1, 'approve', '12000', 'อนุมัติ');
        self::assertSame(['approved', '12000.0000'], [$this->claim($c1)['status'], $this->claim($c1)['approved_amount']]);
        // claim 2: reviewed by hr.head1, approved by hr.demo1 — G6D-02 partial: 20 000 − 12 000 approved = 8 000
        $claims->decide($head, $c2, 'pass', '', '');
        $claims->decide($hr, $c2, 'approve', '8000', 'ตามวงเงินที่เหลือ');
        self::assertSame(['partially_approved', '8000.0000'], [$this->claim($c2)['status'], $this->claim($c2)['approved_amount']]);
        // G6D-03 the limit is used up: a third claim cannot be sent
        $emp->get('/welfare/new');
        $c3 = $this->newClaim($emp, ['type' => 'MED', 'expense_date' => '2026-11-05', 'description' => 'ค่ารักษาเพิ่ม (ทดสอบ)', 'amount' => '100'], ['r3.pdf', self::pdf('Receipt 3')]);
        self::assertContains('no_balance', $claims->check($this->claim($c3))['blocking']);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'welfare.approved' AND user_id = ?", [$this->t->user('employee.demo1')['id']]) >= 1 ? 1 : 0);

        // ---- need more information → edit → send again; reject ----------------------------------------------------------------
        $emp->get('/welfare/new');
        $f1 = $this->newClaim($emp, ['type' => 'FUNERAL', 'expense_date' => '2026-11-05', 'description' => 'งานศพบิดา (ทดสอบ)', 'amount' => '6000', 'receipt_no' => 'F-1'], ['f1.pdf', self::pdf('Funeral F-1')]);
        $chk = $claims->check($this->claim($f1));
        self::assertSame([50000000, ['over_limit']], [$chk['eligible'], $chk['warnings']], 'G6D-04 per-claim cap 5 000');
        $claims->submit($this->az('employee.demo1'), $f1);
        try {
            $claims->decide($hr, $f1, 'return', '', '');
            self::fail('a note is required');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('note', $e->errors);
        }
        $claims->decide($hr, $f1, 'return', '', 'ขอสำเนาใบมรณบัตร');
        self::assertSame(['need_info', 'ขอสำเนาใบมรณบัตร'], [$this->claim($f1)['status'], $this->claim($f1)['decision_note']]);
        $emp->get('/welfare/claims/' . $f1 . '/edit');
        self::assertSame(303, $emp->post(
            '/welfare/claims/' . $f1,
            ['type' => 'FUNERAL', 'expense_date' => '2026-11-05', 'description' => 'งานศพบิดา (ทดสอบ) + ใบมรณบัตร', 'amount' => '5000', 'receipt_no' => 'F-1'],
            ['files' => ['death.pdf', self::pdf('Death certificate')]]
        )->status);
        $claims->submit($this->az('employee.demo1'), $f1);
        self::assertSame('submitted', $this->claim($f1)['status']);
        // decided from the central approvals page instead of the claim page: the claim follows its request
        $app->workflow()->decide((string) $this->claim($f1)['workflow_pid'], (int) $this->t->user('hr.demo1')['id'], 'approve', 'ครบ');
        self::assertSame('reviewing', $claims->view($head, $f1)['status']);
        try {
            $app->workflow()->decide((string) $this->claim($f1)['workflow_pid'], (int) $this->t->user('hr.demo1')['id'], 'approve', '');
            self::fail('the reviewer approves from the approvals page');
        } catch (HttpException | BusinessException $e) {
            self::assertContains($e instanceof HttpException ? 'forbidden' : $e->messageKey, ['forbidden', 'workflow.error.same_person']);
        }
        // even with a task (e.g. given by an escalation) the reviewer may not decide the next step
        $instId = (int) $db->scalar('SELECT id FROM workflow_instances WHERE public_id = ?', [$this->claim($f1)['workflow_pid']]);
        $db->run(
            "INSERT INTO workflow_tasks (instance_id, step_no, assignee_id, via_delegation_from, is_escalation, status, due_at, created_at) VALUES (?, 2, ?, NULL, 1, 'open', NULL, ?)",
            [$instId, $this->t->user('hr.demo1')['id'], $app->clock->now()->format('Y-m-d H:i:s.u')]
        );
        try {
            $app->workflow()->decide((string) $this->claim($f1)['workflow_pid'], (int) $this->t->user('hr.demo1')['id'], 'approve', '');
            self::fail('same person');
        } catch (BusinessException $e) {
            self::assertSame('workflow.error.same_person', $e->messageKey);
        }
        $db->run('DELETE FROM workflow_tasks WHERE instance_id = ? AND step_no = 2 AND assignee_id = ?', [$instId, $this->t->user('hr.demo1')['id']]);
        $claims->decide($head, $f1, 'approve', '5000', '');
        self::assertSame('approved', $this->claim($f1)['status']);
        $emp->get('/welfare/new');
        $f2 = $this->newClaim($emp, ['type' => 'FUNERAL', 'expense_date' => '2026-11-06', 'description' => 'งานศพญาติ (ทดสอบ)', 'amount' => '3000', 'receipt_no' => 'F-2'], ['f2.pdf', self::pdf('Funeral F-2')]);
        $claims->submit($this->az('employee.demo1'), $f2);
        $claims->decide($head, $f2, 'reject', '', 'ไม่อยู่ในเงื่อนไขญาติที่มีสิทธิ์');
        self::assertSame('rejected', $this->claim($f2)['status']);

        // ---- who may see what ------------------------------------------------------------------------------------------------
        self::assertSame(404, $this->as('manager.demo1')->get('/welfare/claims/' . $c1)->status);
        self::assertSame(404, $this->as('hr.demo2')->get('/welfare/claims/' . $c1)->status, 'another company');
        self::assertSame(403, $emp->get('/welfare/admin')->status);
        $fid = (int) $db->scalar('SELECT f.id FROM wf_claim_files f JOIN wf_claims c ON c.id = f.claim_id WHERE c.public_id = ? LIMIT 1', [$c1]);
        self::assertStringStartsWith('%PDF', $emp->get('/welfare/claims/' . $c1 . '/files/' . $fid)->body);
        self::assertSame(404, $this->as('manager.demo1')->get('/welfare/claims/' . $c1 . '/files/' . $fid)->status);

        // ---- payout: payroll batch → payroll run; transfer batch → payment batch ----------------------------------------------
        $pay = PayoutService::of($app);
        $maker = $this->az('payroll.maker');
        $ready = $pay->ready($maker, $le);
        self::assertSame([['WF-2026-00001', 'WF-2026-00002'], ['WF-2026-00003']], [array_column($ready['payroll'], 'claim_no'), array_column($ready['payment'], 'claim_no')]);
        try {
            $pay->ready($head, $le);
            self::fail('HR does not pay');
        } catch (HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        $db->run('UPDATE wf_claims SET decided_by = ? WHERE public_id = ?', [$this->t->user('payroll.maker')['id'], $c2]);
        try {
            $pay->create($maker, $le, 'payroll', [$c1, $c2], '', '');
            self::fail('payer = approver');
        } catch (BusinessException $e) {
            self::assertSame('welfare.error.payer_sod', $e->messageKey);
        }
        $db->run('UPDATE wf_claims SET decided_by = ? WHERE public_id = ?', [$this->t->user('hr.demo1')['id'], $c2]);
        $wb = $pay->create($maker, $le, 'payroll', [$c1, $c2], '', 'รอบ พ.ย. (ทดสอบ)');
        $b = $db->one('SELECT * FROM wf_batches WHERE public_id = ?', [$wb]) ?? [];
        self::assertSame(['WFB-2026-0001', 'ready', 2, '20000.0000', hash('sha256', (string) $b['lines_json'])], [$b['batch_no'], $b['status'], (int) $b['claim_count'], (string) $b['total_amount'], $b['lines_hash']]);
        // the payroll run (TEST rule values; November time fixture)
        $svc = PayrollService::of($app);
        $pm = $this->az('payroll.maker');
        foreach (
            [
                ['payroll.policy', ['monthly_divisor' => '30', 'hours_per_day' => '8', 'proration' => 'calendar_days', 'daily_paid' => ['worked_days', 'holiday_days']]],
                ['payroll.sso', ['rate_employee' => '3.5', 'rate_employer' => '4', 'wage_floor' => '1000', 'wage_cap' => '20000', 'rounding' => 'half_up_baht']],
            ] as [$key, $val]
        ) {
            $id = $svc->proposeRules($pm, $le, $key, $val, '2026-01-01', 'ค่าทดสอบเท่านั้น', 'ทดสอบ');
            $i = (string) $db->scalar('SELECT public_id FROM workflow_instances WHERE subject_id = ? ORDER BY id DESC LIMIT 1', [$id]);
            $app->workflow()->decide($i, (int) $this->t->user('payroll.checker')['id'], 'approve', 'ตรวจแล้ว');
        }
        $lines = [];
        foreach (['D1-0001', 'D1-0002', 'D1-0003', 'D1-0004', 'D1-0005', 'D1-0006', 'D1-0007'] as $code) {
            $lines[] = ['employee_code' => $code, 'name' => $code, 'scheduled_days' => 21, 'worked_days' => 21, 'absent_days' => 0, 'missing_punch_days' => 0, 'late_count' => 0, 'late_minutes' => 0,
                'early_count' => 0, 'early_minutes' => 0, 'worked_minutes' => 21 * 480, 'leave_days' => 0, 'leave' => [], 'holiday_days' => 0, 'off_days' => 9, 'no_data_days' => 0, 'corrected_days' => 0];
        }
        $calc = $app->ledger()->record('attendance.period', 'TEST', 'TEST', $app->clock->now(), [], [], [], ['lines' => $lines, 'leave_types' => []], ['legal_entity_id' => $le]);
        $now = $app->clock->now()->format('Y-m-d H:i:s.u');
        $db->run(
            "INSERT INTO att_periods (public_id, created_at, updated_at, legal_entity_id, period_start, period_end, status, revision, snapshot_run_pid, output_hash) VALUES (?, ?, ?, ?, '2026-11-01', '2026-11-30', 'finalized', 1, ?, 'x')",
            [Ulid::generate($app->clock), $now, $now, $le, $calc]
        );
        $run = $svc->createRun($pm, $le, ['label' => 'เงินเดือน พ.ย. 2569 (ทดสอบ)', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'pay_date' => '2026-11-30']);
        $runRow = $svc->run($pm, $run);
        self::assertSame(['WFB-2026-0001'], array_column($svc->sourceChoices($runRow)['welfare'], 'batch_no'));
        $period = (string) $db->scalar('SELECT public_id FROM att_periods WHERE id = ?', [$runRow['att_period_id']]);
        $svc->setSources($pm, $run, $period, [], [$wb]);
        $svc->calculate($pm, $run);
        $wl = $db->all(
            "SELECT l.amount, l.source_ref, l.component_code FROM pay_result_lines l JOIN pay_run_employees re ON re.id = l.run_employee_id JOIN pay_runs r ON r.id = re.run_id JOIN employees e ON e.id = re.employee_id
              WHERE r.public_id = ? AND e.employee_code = 'D1-0004' AND l.source_type = 'welfare' ORDER BY l.source_ref",
            [$run]
        );
        // G6D-05 welfare in the payroll run: 12 000 + 8 000 on WELFARE, by claim number
        self::assertSame([['12000.0000', 'WF-2026-00001', 'WELFARE'], ['8000.0000', 'WF-2026-00002', 'WELFARE']], array_map(static fn (array $l): array => [(string) $l['amount'], (string) $l['source_ref'], (string) $l['component_code']], $wl));
        self::assertTrue($svc->replay($pm, $run), 'replays with the welfare lines');
        try {
            $pay->cancel($maker, $wb, 'ทดสอบยกเลิก');
            self::fail('in use');
        } catch (BusinessException $e) {
            self::assertSame('welfare.error.batch_in_use', $e->messageKey);
        }
        $svc->submit($pm, $run, '');
        $i = (string) $db->scalar('SELECT public_id FROM workflow_instances WHERE subject_id = ? ORDER BY id DESC LIMIT 1', [$run]);
        $app->workflow()->decide($i, (int) $this->t->user('payroll.checker')['id'], 'approve', 'ตรวจแล้ว');
        self::assertSame(2, $pay->sync($le));
        self::assertSame(['paid', 'paid', 'paid'], [$this->claim($c1)['status'], $this->claim($c2)['status'], (string) $db->scalar('SELECT status FROM wf_batches WHERE public_id = ?', [$wb])]);
        self::assertSame('เงินเดือน พ.ย. 2569 (ทดสอบ)', $this->claim($c1)['paid_ref']);
        // transfer: the person needs a bank account on the pay profile
        try {
            $pay->create($maker, $le, 'payment', [$f1], '2026-11-20', '');
            self::fail('no bank');
        } catch (BusinessException $e) {
            self::assertSame('welfare.error.no_bank', $e->messageKey);
        }
        $e4 = (int) $db->scalar("SELECT id FROM employees WHERE employee_code = 'D1-0004'");
        $db->run("UPDATE pay_profiles SET bank_code = 'TESTBANK', account_enc = ?, account_last4 = '7890' WHERE employee_id = ? AND status = 'approved'", [$app->crypto()->encrypt('1234567890'), $e4]);
        $tb = $pay->create($maker, $le, 'payment', [$f1], '2026-11-20', '');
        $pmt = (string) $db->scalar('SELECT payment_batch_pid FROM wf_batches WHERE public_id = ?', [$tb]);
        self::assertSame(['claim', 'welfare_batch', '5000.0000', 'draft'], array_values(array_map('strval', $db->one('SELECT payment_type, source_type, total_amount, status FROM pmt_batches WHERE public_id = ?', [$pmt]) ?? [])));
        // the bank results come back (simulated) → the claim is paid
        $pbid = (int) $db->scalar('SELECT id FROM pmt_batches WHERE public_id = ?', [$pmt]);
        $db->run("UPDATE pmt_batch_items SET status = 'paid' WHERE batch_id = ?", [$pbid]);
        $db->run("UPDATE pmt_batches SET status = 'reconciled' WHERE id = ?", [$pbid]);
        self::assertSame(1, $pay->sync($le));
        self::assertSame('paid', $this->claim($f1)['status']);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'welfare.paid' AND link = ?", ['/welfare/claims/' . $f1]));
        $page = $this->as('payroll.maker')->get('/welfare/payout');
        self::assertSame(200, $page->status);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page->body));

        // ---- report, personal data, no amounts in audit or notifications --------------------------------------------------------
        $rows = $pay->usage($maker, $le, 2026);
        $med = array_values(array_filter($rows, static fn (array $r): bool => $r['employee_code'] === 'D1-0004' && $r['code'] === 'MED'))[0];
        // G6D-06 use of MED by D1-0004 in 2026 (cancelled drafts left out; the draft c3 left out)
        self::assertSame([2, 220000000, 200000000, 200000000, 200000000, 0], [(int) $med['claims'], $med['claimed'], $med['approved'], $med['paid'], $med['limit'], $med['remaining']]);
        $rep = $this->as('payroll.maker');
        $page = $rep->get('/welfare/report');
        self::assertSame(200, $page->status);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page->body));
        self::assertSame(302, $rep->get('/welfare/report/export')->status, 'export needs the password first');
        $export = WelfarePersonalData::export($app, (int) $this->t->user('employee.demo1')['id'], $e4);
        self::assertSame(['claims', 'receipts'], array_column($export, 'key'));
        self::assertGreaterThanOrEqual(5, count($export[0]['rows']));
        $audit = implode("\n", array_map(static fn (array $a): string => (string) ($a['diff_json'] ?? ''), $db->all("SELECT diff_json FROM audit_events WHERE action LIKE 'welfare.claim%' OR action LIKE 'welfare.batch%'")));
        $notes = implode("\n", array_column($db->all("SELECT params_json FROM notifications WHERE event_type LIKE 'welfare.%'"), 'params_json'));
        self::assertStringNotContainsString('12000', $audit . $notes);
        self::assertStringNotContainsString('8000', $audit . $notes);
        self::assertSame(Money::fromDb('20000'), 200000000);
    }
}
