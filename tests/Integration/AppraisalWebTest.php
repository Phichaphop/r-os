<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Modules\Appraisal\AppraisalService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/** Phase 5E-4 (DEC-79): M38 appraisal — forms, cycles, self → manager → final review → finalized → acknowledge / appeal, visibility, HR actions, report, export. */
final class AppraisalWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(appraisal|employees|common|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

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

    /** @return array<string, mixed> */
    private function apr(string $code): array
    {
        return (array) $this->t->app->db()->one('SELECT a.* FROM apr_appraisals a JOIN employees e ON e.id = a.employee_id WHERE e.employee_code = ? ORDER BY a.id DESC LIMIT 1', [$code]);
    }

    private function formBody(string $lePid, array $weights): array
    {
        $items = [];
        $titles = ['ยอดขายตามเป้า', 'ลูกค้าใหม่', 'การทำงานเป็นทีม'];
        foreach ($weights as $i => $w) {
            $items[$i] = ['section' => $i === 2 ? 'competency' : 'kpi', 'title' => $titles[$i], 'weight' => $w, 'description' => 'เกณฑ์ข้อ ' . ($i + 1)];
        }
        return ['le' => $lePid, 'code' => 'STAFF-26', 'name' => 'แบบประเมินพนักงาน 2569', 'scale_max' => '5', 'bands' => "4.5 | ดีเยี่ยม\n3.5 | ดี\n2.5 | พอใช้\n1 | ต้องปรับปรุง", 'items' => $items];
    }

    public function testAppraisalFlow(): void
    {
        $db = $this->t->app->db();
        $app = $this->t->app;
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $lePid = (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        $sales = (string) $db->scalar("SELECT public_id FROM departments WHERE code = 'SALES' AND legal_entity_id = ?", [$le]);
        $hr = $this->browser('hr.demo1');
        $emp = $this->browser('employee.demo1');
        $mgr = $this->browser('manager.demo1');
        $mgrId = (int) $this->t->user('manager.demo1')['id'];
        $empId = (int) $this->t->user('employee.demo1')['id'];

        self::assertSame(403, $emp->get('/appraisal/admin')->status);
        $page = $emp->get('/appraisal');
        self::assertSame(200, $page->status, $this->errorsLogged());
        self::assertStringContainsString('ยังไม่มีรอบประเมินของคุณ', $page->body);

        // forms: weights must total 100, bands checked
        $f = $hr->get('/appraisal/admin/forms');
        self::assertSame(200, $f->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($f->body));
        $bad = $hr->post('/appraisal/admin/forms', $this->formBody($lePid, ['40', '30', '20']));
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('น้ำหนักรวมต้องเท่ากับ 100', $bad->body);
        $bad2 = $hr->post('/appraisal/admin/forms', ['bands' => "9 | เกิน"] + $this->formBody($lePid, ['40', '30', '30']));
        self::assertSame(422, $bad2->status);
        self::assertStringContainsString('รูปแบบระดับไม่ถูกต้อง', $bad2->body);
        self::assertSame(303, $hr->post('/appraisal/admin/forms', $this->formBody($lePid, ['40', '30', '30']))->status, $this->errorsLogged());
        $formPid = (string) $db->scalar("SELECT public_id FROM apr_forms WHERE code = 'STAFF-26'");
        self::assertStringContainsString('3 หัวข้อ', $hr->get('/appraisal/admin/forms')->body);

        // cycle: dates checked, final review needs its due date
        $cycle = ['le' => $lePid, 'code' => 'Y2026', 'name' => 'ประเมินปลายปี 2569', 'period' => 'year_end', 'form' => $formPid, 'self_due' => '2026-11-12', 'manager_due' => '2026-11-10',
            'final_due' => '', 'results_on' => '2026-11-20', 'final_review' => '1', 'departments' => [$sales], 'groups' => ''];
        $a = $hr->get('/appraisal/admin');
        self::assertSame(200, $a->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($a->body));
        $r = $hr->post('/appraisal/admin/cycles', $cycle);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('กำหนดหัวหน้าต้องไม่ก่อนกำหนดประเมินตนเอง', $r->body);
        self::assertStringContainsString('กรอกกำหนดทบทวนผล', $r->body);
        $cycle['manager_due'] = '2026-11-15';
        $cycle['final_due'] = '2026-11-18';
        self::assertSame(303, $hr->post('/appraisal/admin/cycles', $cycle)->status, $this->errorsLogged());
        $cyclePid = (string) $db->scalar("SELECT public_id FROM apr_cycles WHERE code = 'Y2026'");
        $cp = $hr->get('/appraisal/admin/c/' . $cyclePid);
        self::assertSame(200, $cp->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($cp->body));
        self::assertStringContainsString('SALES', $cp->body);

        // open: one appraisal per working person of SALES; line manager evaluates; the form is frozen
        self::assertSame(303, $hr->post('/appraisal/admin/c/' . $cyclePid . '/open', [])->status);
        $n = (int) $db->scalar("SELECT COUNT(*) FROM apr_appraisals a JOIN apr_cycles c ON c.id = a.cycle_id WHERE c.code = 'Y2026'");
        self::assertGreaterThanOrEqual(4, $n);
        $mine = $this->apr('D1-0004');
        self::assertSame('self_review', $mine['status']);
        self::assertSame($mgrId, (int) $mine['manager_user_id']);
        self::assertNotSame($mgrId, (int) ($this->apr('D1-0003')['manager_user_id'] ?? 0), 'nobody evaluates themselves');
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'appraisal.task'", [$empId]));
        $hr->post('/appraisal/admin/c/' . $cyclePid, ['name' => 'แก้หลังเปิด'] + $cycle); // locked once open
        self::assertSame('ประเมินปลายปี 2569', $db->scalar("SELECT name FROM apr_cycles WHERE code = 'Y2026'"));
        self::assertSame('open', $db->scalar("SELECT status FROM apr_cycles WHERE code = 'Y2026'"));
        // editing the form later does not change the open cycle
        $edit = $this->formBody($lePid, ['50', '20', '30']);
        self::assertSame(303, $hr->post('/appraisal/admin/forms/' . $formPid, $edit)->status, $this->errorsLogged());
        self::assertStringContainsString('"weight":40', (string) $db->scalar("SELECT form_snapshot FROM apr_cycles WHERE code = 'Y2026'"));

        $pid = (string) $mine['public_id'];
        // manager cannot see the self review while the employee is still drafting
        self::assertSame(303, $emp->post('/appraisal/a/' . $pid . '/self', ['score' => [0 => '4'], 'note' => [0 => 'ปิดยอดได้ 105% ของเป้า'], 'self_comment' => 'ปีนี้ตั้งใจมาก', 'submit' => '0'])->status, $this->errorsLogged());
        $mv = $mgr->get('/appraisal/a/' . $pid);
        self::assertSame(200, $mv->status, $this->errorsLogged());
        self::assertStringNotContainsString('ปิดยอดได้ 105%', $mv->body);
        self::assertStringNotContainsString('ปีนี้ตั้งใจมาก', $mv->body);
        // another employee cannot open it
        $other = (string) $this->apr('D1-0005')['public_id'];
        self::assertSame(404, $emp->get('/appraisal/a/' . $other)->status);

        // submit needs every item scored
        $sv = $emp->get('/appraisal/a/' . $pid);
        self::assertSame(200, $sv->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($sv->body));
        $miss = $emp->post('/appraisal/a/' . $pid . '/self', ['score' => [0 => '4', 1 => '3'], 'note' => [], 'self_comment' => 'ปีนี้ตั้งใจมาก', 'submit' => '1']);
        self::assertSame(422, $miss->status);
        self::assertStringContainsString('ให้คะแนนข้อนี้ก่อนส่ง', $miss->body);
        self::assertSame(422, $emp->post('/appraisal/a/' . $pid . '/self', ['score' => [0 => '4', 1 => '3', 2 => '9'], 'submit' => '1'])->status);
        self::assertSame(303, $emp->post('/appraisal/a/' . $pid . '/self', ['score' => [0 => '4', 1 => '3', 2 => '5'], 'note' => [0 => 'ปิดยอดได้ 105% ของเป้า'], 'self_comment' => 'ปีนี้ตั้งใจมาก', 'submit' => '1'])->status);
        $mine = $this->apr('D1-0004');
        self::assertSame('manager_review', $mine['status']);
        self::assertEqualsWithDelta(4.0, (float) $mine['self_score'], 0.001); // (4×40 + 3×30 + 5×30) ÷ 100
        $emp->post('/appraisal/a/' . $pid . '/self', ['score' => [0 => '5'], 'submit' => '0']); // no longer editable
        self::assertSame('4.000', (string) $this->apr('D1-0004')['self_score']);

        // manager review: sees the self review, comment + development plan required
        $mv = $mgr->get('/appraisal/a/' . $pid);
        self::assertStringContainsString('ปิดยอดได้ 105%', $mv->body);
        self::assertStringContainsString('ปีนี้ตั้งใจมาก', $mv->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($mv->body));
        $mbody = ['score' => [0 => '4', 1 => '4', 2 => '3'], 'note' => [], 'strengths' => 'ดูแลลูกค้าดี', 'manager_comment' => '', 'development_plan' => '', 'comp_proposal' => 'เสนอขึ้นเงินเดือนตามรอบ', 'submit' => '1'];
        $r = $mgr->post('/appraisal/a/' . $pid . '/manager', $mbody);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('กรอกแผนพัฒนา', $r->body);
        $mbody['manager_comment'] = 'ทำงานได้ตามเป้า สื่อสารดี';
        $mbody['development_plan'] = 'อบรมการเจรจาต่อรอง ภายในไตรมาส 1';
        self::assertSame(303, $mgr->post('/appraisal/a/' . $pid . '/manager', $mbody)->status, $this->errorsLogged());
        $mine = $this->apr('D1-0004');
        self::assertSame('final_review', $mine['status']);
        self::assertEqualsWithDelta(3.7, (float) $mine['manager_score'], 0.001); // (4×40 + 4×30 + 3×30) ÷ 100
        self::assertSame('ดี', $mine['rating_label']);

        // the employee does not see the manager's part, nor the pay proposal
        $ev = $emp->get('/appraisal/a/' . $pid);
        self::assertStringNotContainsString('ทำงานได้ตามเป้า', $ev->body);
        self::assertStringNotContainsString('เสนอขึ้นเงินเดือน', $ev->body);
        self::assertStringContainsString('ส่งการประเมินตนเองแล้ว', $ev->body);

        // final review (HR — the manager has no manager in the demo): calibrate needs a reason; return needs a comment
        $hv = $hr->get('/appraisal/a/' . $pid);
        self::assertSame(200, $hv->status, $this->errorsLogged());
        self::assertStringContainsString('เสนอขึ้นเงินเดือน', $hv->body);
        self::assertSame(422, $hr->post('/appraisal/a/' . $pid . '/final', ['decision' => 'approve', 'calibrated' => '3.5', 'calibration_reason' => ''])->status);
        self::assertSame(422, $hr->post('/appraisal/a/' . $pid . '/final', ['decision' => 'approve', 'calibrated' => '7', 'calibration_reason' => 'เกินมาตรา'])->status);
        self::assertSame(422, $hr->post('/appraisal/a/' . $pid . '/final', ['decision' => 'return', 'reviewer_comment' => ''])->status);
        self::assertSame(303, $hr->post('/appraisal/a/' . $pid . '/final', ['decision' => 'return', 'reviewer_comment' => 'ขอหลักฐานยอดขายประกอบ'])->status);
        self::assertSame('manager_review', $this->apr('D1-0004')['status']);
        self::assertStringContainsString('ขอหลักฐานยอดขายประกอบ', $mgr->get('/appraisal/a/' . $pid)->body);
        $mbody['note'] = [0 => 'แนบรายงานยอดขาย'];
        self::assertSame(303, $mgr->post('/appraisal/a/' . $pid . '/manager', $mbody)->status);
        $mgr->post('/appraisal/a/' . $pid . '/final', ['decision' => 'approve']); // the evaluator cannot approve their own review
        self::assertSame('final_review', $this->apr('D1-0004')['status']);
        self::assertSame(303, $hr->post('/appraisal/a/' . $pid . '/final', ['decision' => 'approve', 'calibrated' => '3.4', 'calibration_reason' => 'เทียบกับทีมขายทั้งหมดแล้ว', 'reviewer_comment' => ''])->status);
        $mine = $this->apr('D1-0004');
        self::assertSame('finalized', $mine['status']);
        self::assertEqualsWithDelta(3.4, (float) $mine['final_score'], 0.001);
        self::assertSame('พอใช้', $mine['rating_label']);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM apr_events WHERE appraisal_id = ? AND action = 'finalized' AND note LIKE 'calibrated%'", [$mine['id']]));

        // before the results day: the employee sees no result and cannot acknowledge
        $ev = $emp->get('/appraisal/a/' . $pid);
        self::assertStringContainsString('คุณจะเห็นผลและความเห็นของหัวหน้าตั้งแต่', $ev->body);
        self::assertStringNotContainsString('3.4', strip_tags($ev->body));
        $emp->post('/appraisal/a/' . $pid . '/ack', ['appeal' => '0']);
        self::assertSame('finalized', $this->apr('D1-0004')['status']);

        // on the results day: result visible; appeal needs a reason; HR is told
        $this->t->clock->advance('+11 days');
        $emp = $this->browser('employee.demo1'); // sessions expired meanwhile
        $hr = $this->browser('hr.demo1');
        $mgr = $this->browser('manager.demo1');
        $ev = $emp->get('/appraisal/a/' . $pid);
        self::assertStringContainsString('3.4', strip_tags($ev->body));
        self::assertStringContainsString('อบรมการเจรจาต่อรอง', $ev->body);
        self::assertStringNotContainsString('เสนอขึ้นเงินเดือน', $ev->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($ev->body));
        self::assertSame(422, $emp->post('/appraisal/a/' . $pid . '/ack', ['appeal' => '1', 'employee_comment' => 'ไม่ชอบ'])->status);
        self::assertSame(303, $emp->post('/appraisal/a/' . $pid . '/ack', ['appeal' => '1', 'employee_comment' => 'ยอดขายไตรมาส 4 ยังไม่ถูกนับรวม'])->status);
        $mine = $this->apr('D1-0004');
        self::assertSame('acknowledged', $mine['status']);
        self::assertSame('open', $mine['appeal_status']);
        self::assertGreaterThanOrEqual(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'appraisal.appeal'"));
        $cp = $hr->get('/appraisal/admin/c/' . $cyclePid);
        self::assertStringContainsString('อุทธรณ์', $cp->body);

        // HR reopens to the manager; the manager re-scores; final review again
        self::assertSame(422, $hr->post('/appraisal/a/' . $pid . '/appeal', ['outcome_action' => 'reopen', 'outcome' => ''])->status);
        self::assertSame(303, $hr->post('/appraisal/a/' . $pid . '/appeal', ['outcome_action' => 'reopen', 'outcome' => 'ให้หัวหน้านับยอดไตรมาส 4 ใหม่'])->status);
        self::assertSame('manager_review', $this->apr('D1-0004')['status']);
        $mbody['score'] = [0 => '5', 1 => '4', 2 => '3'];
        self::assertSame(303, $mgr->post('/appraisal/a/' . $pid . '/manager', $mbody)->status);
        self::assertSame(303, $hr->post('/appraisal/a/' . $pid . '/final', ['decision' => 'approve', 'calibrated' => '', 'calibration_reason' => '', 'reviewer_comment' => 'ตรวจแล้ว'])->status);
        $mine = $this->apr('D1-0004');
        self::assertSame('finalized', $mine['status']);
        self::assertEqualsWithDelta(4.1, (float) $mine['final_score'], 0.001);
        self::assertSame(303, $emp->post('/appraisal/a/' . $pid . '/ack', ['appeal' => '0', 'employee_comment' => ''])->status);
        self::assertSame('acknowledged', $this->apr('D1-0004')['status']);

        // HR: change an evaluator (reason required, logged), skip a self review
        $o = $this->apr('D1-0005');
        self::assertSame(422, $hr->post('/appraisal/a/' . $o['public_id'] . '/evaluator', ['role' => 'manager', 'username' => 'hr.head1', 'reason' => ''])->status);
        self::assertSame(422, $hr->post('/appraisal/a/' . $o['public_id'] . '/evaluator', ['role' => 'manager', 'username' => 'no.such.user', 'reason' => 'หัวหน้าเดิมลาออก'])->status);
        self::assertSame(303, $hr->post('/appraisal/a/' . $o['public_id'] . '/evaluator', ['role' => 'manager', 'username' => 'hr.head1', 'reason' => 'หัวหน้าเดิมลาออก'])->status);
        self::assertSame((int) $this->t->user('hr.head1')['id'], (int) $this->apr('D1-0005')['manager_user_id']);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM apr_events WHERE appraisal_id = ? AND action = 'manager_changed'", [$o['id']]));
        $s6code = 'D1-0003'; // the sales head has an account; the other sales staff have none, so their self review is skipped on opening
        self::assertSame('manager_review', $this->apr('D1-0006')['status']);
        $s6 = $this->apr($s6code);
        self::assertSame(422, $hr->post('/appraisal/a/' . $s6['public_id'] . '/skip', ['reason' => ''])->status);
        self::assertSame(303, $hr->post('/appraisal/a/' . $s6['public_id'] . '/skip', ['reason' => 'ลาคลอดตลอดรอบ'])->status);
        self::assertSame('manager_review', $this->apr($s6code)['status']);

        // inbox + reminders
        $ib = $mgr->get('/appraisal');
        self::assertSame(200, $ib->status, $this->errorsLogged());
        self::assertStringContainsString('รอฉันประเมิน', $ib->body);
        self::assertStringContainsString('D1-0006', $ib->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($ib->body));
        self::assertGreaterThan(0, AppraisalService::remindersJob($app));

        // report + export (audited)
        $cp = $hr->get('/appraisal/admin/c/' . $cyclePid);
        self::assertSame(200, $cp->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($cp->body));
        self::assertStringContainsString('ดี', $cp->body);
        self::assertStringContainsString('4.1', $cp->body);
        $x = $hr->get('/appraisal/admin/c/' . $cyclePid . '/export');
        self::assertSame(200, $x->status, $this->errorsLogged());
        $csv = $hr->get('/appraisal/admin/c/' . $cyclePid . '/export', ['format' => 'csv']);
        self::assertStringContainsString('D1-0004', $csv->body);
        self::assertSame(2, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'appraisal.exported'"));
        self::assertSame(404, $emp->get('/appraisal/admin/c/' . $cyclePid . '/export')->status);

        // close
        self::assertSame(303, $hr->post('/appraisal/admin/c/' . $cyclePid . '/close', [])->status);
        self::assertSame('closed', $db->scalar("SELECT status FROM apr_cycles WHERE code = 'Y2026'"));
        self::assertStringNotContainsString('PHP Warning', $this->errorsLogged());
        self::assertStringNotContainsString('PHP Fatal', $this->errorsLogged());
    }
}
