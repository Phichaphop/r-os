<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Modules\Recruit\RecruitService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/** Phase 5E (DEC-76): M08 recruitment from requisition to hire, through the HTTP kernel. */
final class RecruitWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(recruit|talent|employees|common|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

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

    /** Server errors of this test (shown when a request fails with 500). */
    private function errorsLogged(): string
    {
        return implode("\n", array_map(static fn (string $f): string => mb_substr((string) file_get_contents($f), -1500), glob($this->t->tmp . '/logs/*') ?: []));
    }

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    private static function thaiId(string $twelve): string
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $twelve[$i] * (13 - $i);
        }
        return $twelve . ((11 - $sum % 11) % 10);
    }

    /** Approves every open step of the workflow about $subjectPid; returns who approved. @return list<int> */
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
            self::assertGreaterThan(0, $u, 'an approver got the task');
            $who[] = $u;
            $this->t->app->workflow()->decide($inst, $u, 'approve');
        }
        return $who;
    }

    /** @return array<string, mixed> */
    private function application(string $name, string $id, string $phone): array
    {
        return ['consent' => '1', 'consent_name' => $name, 'full_name' => $name, 'phone' => $phone, 'email' => 'a@example.invalid', 'id_type' => 'TH_CITIZEN_ID', 'id_number' => $id,
            'expected_salary' => '25,000', 'birth_date' => '1998-04-01', 'province' => 'ชลบุรี', 'available_from' => '2026-12-01', 'talent_consent' => '1',
            'edu' => [['level' => 'bachelor', 'institution' => 'มหาวิทยาลัยตัวอย่าง', 'major' => 'บัญชี', 'year' => '2020', 'gpa' => '3.10']],
            'work' => [['company' => 'บริษัท ก', 'position' => 'ผู้ช่วยบัญชี', 'from' => '2020-06', 'to' => '2024-05', 'reason' => 'ย้ายบ้าน']],
            'lang_th' => 'fluent', 'lang_en' => 'fair', 'skills' => 'Excel, โปรแกรมบัญชี'];
    }

    public function testRequisitionToHire(): void
    {
        $db = $this->t->app->db();
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");

        // a manager asks for people — locked to their own unit
        $mgr = $this->browser('manager.demo1');
        $page = $mgr->get('/recruit/requisitions');
        self::assertSame(200, $page->status);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page->body), 'no raw keys');
        $in = ['position_title' => 'เจ้าหน้าที่บัญชี', 'headcount' => '1', 'employment_type' => 'permanent', 'reason' => 'replacement', 'replaces' => '', 'salary_min' => '20000', 'salary_max' => '28000',
            'needed_by' => '2026-12-15', 'justification' => 'ทดแทนพนักงานที่ลาออกสิ้นเดือน'];
        self::assertSame(422, $mgr->post('/recruit/requisitions', $in)->status, 'replacement must say who');
        $mgr->get('/recruit/requisitions');
        self::assertSame(303, $mgr->post('/recruit/requisitions', ['replaces' => 'D1-0005 พนักงานเดิม'] + $in)->status);
        $req = $db->one('SELECT * FROM rec_requisitions ORDER BY id DESC LIMIT 1');
        self::assertSame('pending', $req['status']);
        $mgrEmp = (int) $db->scalar("SELECT id FROM employees WHERE employee_code = 'D1-0003'");
        self::assertSame((int) $db->scalar('SELECT department_id FROM employment_history WHERE employee_id = ? AND effective_to IS NULL', [$mgrEmp]), (int) $req['department_id'], 'the manager\'s own unit');
        $approvers = $this->approveAll((string) $req['public_id']);
        self::assertCount(2, $approvers, 'two steps: manager, then recruit.approve');
        self::assertNotContains((int) $this->t->user('manager.demo1')['id'], $approvers, 'never the requester');
        self::assertSame('approved', $db->scalar('SELECT status FROM rec_requisitions WHERE id = ?', [$req['id']]));

        // a posting; publishing needs the applicant notice; the public page shows no salary
        $hr = $this->browser('hr.demo1');
        $hr->get('/recruit/r/' . $req['public_id']);
        self::assertSame(303, $hr->post('/recruit/r/' . $req['public_id'] . '/postings', ['title' => 'เจ้าหน้าที่บัญชี', 'description' => 'ดูแลบัญชีเจ้าหนี้และลูกหนี้ ปิดงบรายเดือน', 'location' => 'ชลบุรี'])->status);
        $post = $db->one('SELECT * FROM rec_postings ORDER BY id DESC LIMIT 1');
        $hr->get('/recruit/p/' . $post['public_id']);
        $hr->post('/recruit/p/' . $post['public_id'] . '/status', ['to' => 'published']);
        self::assertSame('draft', $db->scalar('SELECT status FROM rec_postings WHERE id = ?', [$post['id']]), 'no notice = no publishing');
        $hr->get('/recruit/settings');
        self::assertSame(422, $hr->post('/recruit/settings', ['legal_entity' => (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]), 'notice_text' => 'สั้นไป'])->status);
        $hr->get('/recruit/settings');
        $hr->post('/recruit/settings', ['legal_entity' => (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]),
            'notice_text' => '(ตัวอย่าง DEMO — ไม่ใช่ข้อความทางกฎหมาย) บริษัทเก็บข้อมูลผู้สมัครเพื่อพิจารณาการจ้างงานเท่านั้น และจะลบเมื่อครบระยะเก็บที่ประกาศไว้', 'required' => ['education']]);
        self::assertSame(1, RecruitService::of($this->t->app)->settings($le)['notice_version']);
        $hr->get('/recruit/p/' . $post['public_id']);
        $hr->post('/recruit/p/' . $post['public_id'] . '/status', ['to' => 'published']);
        self::assertSame('published', $db->scalar('SELECT status FROM rec_postings WHERE id = ?', [$post['id']]));
        $emp = $this->browser('employee.demo1');
        $jobs = $emp->get('/recruit/jobs')->body;
        self::assertStringContainsString('เจ้าหน้าที่บัญชี', $jobs);
        self::assertStringNotContainsString('28,000', $jobs . $emp->get('/recruit/jobs/' . $post['public_id'])->body, 'salary range stays internal');

        // apply (multipart, files), repeated ID refused, status check needs code + phone
        $form = $emp->get('/recruit/jobs/' . $post['public_id'] . '/apply');
        self::assertSame(200, $form->status);
        self::assertStringContainsString('data-step="start"', $form->body);
        self::assertStringContainsString('ชลบุรี', $form->body, 'provinces');
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($form->body), 'no raw keys');
        $pdf = (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/a.pdf');
        $id1 = self::thaiId('110170012345');
        $bad = $emp->post('/recruit/jobs/' . $post['public_id'] . '/apply', ['consent' => ''] + $this->application('สมศรี ทดสอบ', $id1, '0812345678'));
        self::assertSame(422, $bad->status, 'consent first ' . $this->errorsLogged());
        $emp->get('/recruit/jobs/' . $post['public_id'] . '/apply');
        $ok = $emp->post('/recruit/jobs/' . $post['public_id'] . '/apply', $this->application('สมศรี ทดสอบ', $id1, '081-234-5678'), ['file_education' => ['degree.pdf', $pdf]]);
        self::assertSame(200, $ok->status, $this->errorsLogged());
        $cand = $db->one('SELECT * FROM rec_candidates ORDER BY id DESC LIMIT 1');
        self::assertStringContainsString((string) $cand['app_code'], $ok->body);
        self::assertSame('0812345678', $cand['phone']);
        self::assertSame(substr($id1, -4), $cand['id_last4']);
        self::assertStringNotContainsString($id1, (string) $cand['id_enc'], 'stored encrypted');
        self::assertStringNotContainsString('มหาวิทยาลัยตัวอย่าง', (string) $cand['profile_enc'], 'profile encrypted');
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM rec_files WHERE candidate_id = ? AND status = 'current'", [$cand['id']]));
        $emp->get('/recruit/jobs/' . $post['public_id'] . '/apply');
        self::assertSame(422, $emp->post('/recruit/jobs/' . $post['public_id'] . '/apply', $this->application('สมศรี ทดสอบ', $id1, '0812345678'))->status, 'same ID, same job');
        $emp->get('/recruit/status');
        self::assertStringContainsString('ได้รับใบสมัครแล้ว', $emp->post('/recruit/status', ['code' => (string) $cand['app_code'], 'phone' => '0812345678'])->body);
        $emp->get('/recruit/status');
        self::assertStringContainsString('ไม่พบใบสมัคร', $emp->post('/recruit/status', ['code' => (string) $cand['app_code'], 'phone' => '0899999999'])->body);
        // a second applicant (erased on request later)
        $emp->get('/recruit/jobs/' . $post['public_id'] . '/apply');
        $emp->post('/recruit/jobs/' . $post['public_id'] . '/apply', $this->application('สมหมาย สอง', self::thaiId('310170099999'), '0823334444'));
        $c2 = $db->one('SELECT * FROM rec_candidates ORDER BY id DESC LIMIT 1');

        // pipeline
        $pp = $hr->get('/recruit/p/' . $post['public_id'])->body;
        self::assertStringContainsString('สมศรี ทดสอบ', $pp);
        $c = (string) $cand['public_id'];
        $view = $hr->get('/recruit/c/' . $c);
        self::assertSame(200, $view->status);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($view->body), 'no raw keys');
        self::assertStringContainsString('มหาวิทยาลัยตัวอย่าง', $view->body, 'recruiters see the profile');
        self::assertStringNotContainsString($id1, $view->body, 'never the full ID');
        self::assertSame(303, $hr->post('/recruit/c/' . $c . '/move', ['to' => 'interview'])->status);
        self::assertSame('applied', $db->scalar('SELECT stage FROM rec_candidates WHERE id = ?', [$cand['id']]), 'no skipping screening');
        $hr->get('/recruit/c/' . $c);
        $hr->post('/recruit/c/' . $c . '/move', ['to' => 'screening']);
        $hr->get('/recruit/c/' . $c);
        $hr->post('/recruit/c/' . $c . '/schedule', ['kind' => 'interview', 'date' => '2026-11-12', 'time' => '10:00', 'location' => 'ห้องประชุม 1']);
        self::assertSame('interview', $db->scalar('SELECT stage FROM rec_candidates WHERE id = ?', [$cand['id']]));
        $int = (string) $db->scalar('SELECT public_id FROM rec_interviews WHERE candidate_id = ?', [$cand['id']]);
        $hr->get('/recruit/c/' . $c);
        $hr->post('/recruit/c/' . $c . '/i/' . $int . '/evaluator', ['evaluator' => 'หัวหน้าฝ่ายบัญชี']);
        $shown = $hr->get('/recruit/c/' . $c)->body;
        self::assertSame(1, preg_match('#/recruit/eval/([A-Za-z0-9_-]{32})#', $shown, $m), 'the link is shown once');
        self::assertSame(0, preg_match('#/recruit/eval/[A-Za-z0-9_-]{32}#', $hr->get('/recruit/c/' . $c)->body), 'only once');
        // the manager who asked sees the application without contacts
        $mview = $mgr->get('/recruit/c/' . $c);
        self::assertSame(200, $mview->status);
        self::assertStringNotContainsString('0812345678', $mview->body);
        $mgr->get('/recruit/c/' . $c);
        self::assertSame(404, $mgr->post('/recruit/c/' . $c . '/note', ['note' => 'xx'])->status, 'read only for the manager');

        // evaluator link (here an interviewer with an account; people without one need the recruitment pages opened to visitors)
        $g = $this->browser('hr.head1');
        $evPage = $g->get('/recruit/eval/' . $m[1]);
        self::assertSame(200, $evPage->status);
        self::assertStringNotContainsString('0812345678', $evPage->body);
        self::assertStringContainsString('มหาวิทยาลัยตัวอย่าง', $evPage->body);
        self::assertSame(422, $g->post('/recruit/eval/' . $m[1], ['score' => '9', 'recommendation' => 'hire'])->status);
        $g->get('/recruit/eval/' . $m[1]);
        self::assertSame(200, $g->post('/recruit/eval/' . $m[1], ['score' => '4', 'recommendation' => 'hire', 'comment' => 'พื้นฐานดี'])->status);
        $g->get('/recruit/eval/' . $m[1]);
        self::assertSame(409, $g->post('/recruit/eval/' . $m[1], ['score' => '5', 'recommendation' => 'hire'])->status, 'one answer');
        self::assertSame(404, $g->get('/recruit/eval/' . str_repeat('A', 32))->status);

        // offer → another person approves → candidate accepts
        $hr->get('/recruit/c/' . $c);
        $hr->post('/recruit/c/' . $c . '/offer', ['position_title' => 'เจ้าหน้าที่บัญชี', 'salary' => '26000', 'start_date' => '2026-12-01', 'employment_type' => 'probation']);
        $offer = $db->one('SELECT * FROM rec_offers WHERE candidate_id = ?', [$cand['id']]);
        self::assertSame('pending', $offer['status']);
        $who = $this->approveAll((string) $offer['public_id']);
        self::assertNotContains((int) $this->t->user('hr.demo1')['id'], $who, 'not the recruiter');
        self::assertSame('approved', $db->scalar('SELECT status FROM rec_offers WHERE id = ?', [$offer['id']]));
        $ready = RecruitService::of($this->t->app)->readiness((int) $cand['id']);
        self::assertFalse($ready['offer'], 'not accepted yet');
        self::assertFalse($ready['documents'], 'the education file is not checked yet');
        $hr->get('/recruit/c/' . $c);
        $hr->post('/recruit/c/' . $c . '/answer', ['answer' => 'accepted']);
        $fid = (int) $db->scalar('SELECT id FROM rec_files WHERE candidate_id = ?', [$cand['id']]);
        $hr->get('/recruit/c/' . $c);
        $hr->post('/recruit/c/' . $c . '/file/' . $fid . '/verify', []);
        self::assertNotContains(false, RecruitService::of($this->t->app)->readiness((int) $cand['id']), 'ready');
        self::assertSame(200, $hr->get('/recruit/c/' . $c . '/file/' . $fid)->status, 'download');

        // hire → employee (pre-hire) with ID and file; requisition filled; posting closed; never twice
        $hr->get('/recruit/c/' . $c);
        $hire = $hr->post('/recruit/c/' . $c . '/hire', ['employee_code' => 'D1-0901', 'employee_group' => 'STAFF']);
        self::assertSame(303, $hire->status);
        $e = $db->one("SELECT * FROM employees WHERE employee_code = 'D1-0901'");
        self::assertNotNull($e);
        self::assertSame('pre_hire', $e['status']);
        self::assertSame('สมศรี ทดสอบ', $e['name_th']);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM employee_identifiers WHERE employee_id = ? AND status = 'current'", [$e['id']]));
        self::assertGreaterThanOrEqual(1, (int) $db->scalar('SELECT COUNT(*) FROM personnel_file_items WHERE employee_id = ?', [$e['id']]));
        self::assertSame([], glob($this->t->app->path('storage/tmp') . '/up-*') ?: [], 'no decrypted copy left on disk');
        self::assertSame('hired', $db->scalar('SELECT stage FROM rec_candidates WHERE id = ?', [$cand['id']]));
        self::assertSame((int) $e['id'], (int) $db->scalar('SELECT employee_id FROM rec_candidates WHERE id = ?', [$cand['id']]));
        self::assertSame('filled', $db->scalar('SELECT status FROM rec_requisitions WHERE id = ?', [$req['id']]));
        self::assertSame('closed', $db->scalar('SELECT status FROM rec_postings WHERE id = ?', [$post['id']]));
        $hr->get('/recruit/c/' . $c);
        $hr->post('/recruit/c/' . $c . '/hire', ['employee_code' => 'D1-0902', 'employee_group' => 'STAFF']);
        self::assertNull($db->scalar("SELECT id FROM employees WHERE employee_code = 'D1-0902'"), 'never twice');

        // erase on request; funnel
        $hr->get('/recruit/c/' . $c2['public_id']);
        $hr->post('/recruit/c/' . $c2['public_id'] . '/erase', ['reason' => 'ผู้สมัครขอลบข้อมูลทางอีเมล']);
        $gone = $db->one('SELECT * FROM rec_candidates WHERE id = ?', [$c2['id']]);
        self::assertSame(['—', null, null], [$gone['name'], $gone['profile_enc'], $gone['id_hash']]);
        $f = RecruitService::of($this->t->app)->funnel($this->az('hr.demo1'), $le);
        self::assertSame(1, $f['stages']['hired']['count']);
        self::assertGreaterThanOrEqual(2, $f['stages']['applied']['count']);
        $idx = $hr->get('/recruit');
        self::assertSame(200, $idx->status);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($idx->body), 'no raw keys');
        self::assertSame(403, $emp->get('/recruit')->status, 'employees do not see the pipeline');
        self::assertSame(404, $emp->get('/recruit/c/' . $c)->status);
    }

    public function testRetentionAndGuestAccess(): void
    {
        $db = $this->t->app->db();
        $svc = RecruitService::of($this->t->app);
        self::assertSame(0, $svc->retentionJob(), 'no approved rule = nothing removed');
        // the public pages open to visitors only once the organization allows it
        $g = $this->t->browser();
        self::assertSame(302, $g->get('/recruit/jobs')->status);
        $sys = $this->t->browser();
        $sys->login(TestApp::ADMIN);
        $sys->get('/admin/settings/guest');
        $sys->post('/admin/settings/guest', ['home' => ['recruit'], 'tools' => ['recruit']]);
        self::assertSame(200, $g->get('/recruit/jobs')->status);
        self::assertSame(302, $g->get('/recruit/requisitions')->status, 'the rest needs an account');
        $now = $this->t->clock->now()->format('Y-m-d H:i:s.u');
        $db->run("INSERT INTO retention_rules (public_id, created_at, updated_at, record_class, version_no, trigger_event, duration_months, disposition_action, status, effective_from)
                  VALUES ('01J0000000000000000000RET1', ?, ?, 'applicant_unsuccessful', 99, 'related_closed', 1, 'anonymize', 'approved', '2026-01-01')", [$now, $now]);
        // an old rejected application is anonymised, a recent one is kept
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $db->run("INSERT INTO rec_requisitions (public_id, created_at, updated_at, legal_entity_id, position_title, headcount, reason, employment_type, justification, requester_user_id, status)
                  VALUES ('01J0000000000000000000REQ1', ?, ?, ?, 'x', 1, 'new', 'permanent', 'xxxxxxxxxxxx', 1, 'approved')", [$now, $now, $le]);
        $db->run("INSERT INTO rec_postings (public_id, created_at, updated_at, requisition_id, legal_entity_id, title, description, status) VALUES ('01J0000000000000000000POS1', ?, ?, LAST_INSERT_ID(), ?, 'x', 'xxxxxxxxxxxxxxxxxxxxxx', 'closed')", [$now, $now, $le]);
        $pid = (int) $db->scalar("SELECT id FROM rec_postings WHERE public_id = '01J0000000000000000000POS1'");
        foreach ([['OLD', '2026-08-01 00:00:00.000000'], ['NEW', '2026-11-01 00:00:00.000000']] as [$code, $closed]) {
            $db->run("INSERT INTO rec_candidates (public_id, created_at, updated_at, posting_id, legal_entity_id, app_code, name, phone, stage, stage_at, closed_at)
                      VALUES (?, ?, ?, ?, ?, ?, 'คนทดสอบ', '0800000000', 'rejected', ?, ?)", ['01J00000000000000000000' . $code, $now, $now, $pid, $le, 'CODE' . $code . '000', $closed, $closed]);
        }
        self::assertSame(1, $svc->retentionJob());
        self::assertSame('—', $db->scalar("SELECT name FROM rec_candidates WHERE app_code = 'CODEOLD000'"));
        self::assertSame('คนทดสอบ', $db->scalar("SELECT name FROM rec_candidates WHERE app_code = 'CODENEW000'"));
    }
}
