<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Modules\Employees\ChangeService;
use Blue\Modules\Employees\FileRules;
use Blue\Modules\Employees\PersonnelFileService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/**
 * Phase 5A (DEC-67) — M09 Employee Records through the HTTP kernel.
 * Test clock: Mon 2026-11-09 09:00 Bangkok. Demo: D1-0001 hr.head1 (HRA), D1-0002 hr.demo1 (HRA), D1-0003 manager.demo1,
 * D1-0004 employee.demo1. ID numbers below start with 0 (no real person has one) and only pass the check digit.
 */
final class EmployeeRecordsWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w])(employees|workflow|config)\.[a-z_]+\.?[a-z_.]*\b(?![^<]*>)/';
    private const ID1 = '0000000000019';
    private const ID2 = '0000000000027';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

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

    private function pid(string $code): string
    {
        return (string) $this->db()->scalar('SELECT public_id FROM employees WHERE employee_code = ?', [$code]);
    }

    private function le(): int
    {
        return (int) $this->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    private function decide(TestBrowser $b, string $inst, string $decision): void
    {
        $b->get('/approvals/' . $inst);
        $res = $b->post('/approvals/' . $inst . '/decide', ['decision' => $decision, 'comment' => '', '_back' => '/approvals/' . $inst]);
        if (str_contains((string) ($res->headers['Location'] ?? ''), '/reauth')) {
            $b->get('/reauth');
            $b->post('/reauth', ['password' => $this->t->demoPassword, 'next' => '/approvals/' . $inst]);
            $b->get('/approvals/' . $inst);
            $b->post('/approvals/' . $inst . '/decide', ['decision' => $decision, 'comment' => '', '_back' => '/approvals/' . $inst]);
        }
    }

    private function lastInstance(string $subjectType): string
    {
        return (string) $this->db()->scalar('SELECT public_id FROM workflow_instances WHERE subject_type = ? ORDER BY id DESC LIMIT 1', [$subjectType]);
    }

    /** Required documents: ID card + contract for everyone, 30 days notice — proposed by hr.demo1, approved by hr.head1. */
    private function rules(): void
    {
        $hr = $this->as('hr.demo1');
        $hr->get('/employees/rules');
        $res = $hr->post('/employees/rules', ['le' => (string) $this->le(), 'required' => ['*' => ['id_card', 'contract']], 'expiry_notice_days' => '30',
            'offboarding' => [['code' => 'assets', 'label' => 'คืนอุปกรณ์', 'owner' => 'admin', 'days' => '0'], ['code' => 'accounts', 'label' => 'ปิดบัญชี', 'owner' => 'it', 'days' => '-1']],
            'effective_from' => '2026-01-01', 'source_reference' => 'TEST ONLY — not company rules', 'reason' => 'test']);
        self::assertSame(303, $res->status, $res->body);
        $this->decide($this->as('hr.head1'), $this->lastInstance('config_version'), 'approve');
        self::assertNotNull(PersonnelFileService::of($this->t->app)->rules($this->le()));
    }

    public function testRightsTabsAndSelfService(): void
    {
        $p4 = $this->pid('D1-0004');
        $hr = $this->as('hr.demo1');
        foreach (['overview', 'personal', 'job', 'file', 'changes', 'log'] as $tab) {
            $res = $hr->get('/employees/' . $p4, ['tab' => $tab]);
            self::assertSame(200, $res->status, $tab);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body), $tab);
        }
        foreach (['/employees', '/employees/report', '/employees/rules', '/employees/new'] as $path) {
            $res = $hr->get($path);
            self::assertSame(200, $res->status, $path);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body), $path);
        }
        // a line manager and an employee see no employee records; HR of another company gets 404; the admin sees none
        self::assertSame(403, $this->as('manager.demo1')->get('/employees')->status);
        self::assertSame(404, $this->as('employee.demo1')->get('/employees/' . $p4)->status);
        self::assertSame(404, $this->as('hr.demo2')->get('/employees/' . $p4)->status);
        self::assertSame(403, $this->as(TestApp::ADMIN)->get('/employees')->status);

        // HR: personal data — the birth date is stored encrypted and the log says only that it changed
        self::assertSame(303, $hr->post('/employees/' . $p4 . '/personal', ['title' => 'นาย', 'birth_date' => '1995-05-17', 'gender' => 'male', 'nationality' => 'th', 'work_email' => 'd4@demo.invalid'])->status);
        $row = $this->db()->one('SELECT * FROM employees WHERE public_id = ?', [$p4]);
        self::assertStringNotContainsString('1995-05-17', (string) $row['birth_date_enc']);
        self::assertSame('TH', $row['nationality']);
        $diff = (string) $this->db()->scalar("SELECT diff_json FROM audit_events WHERE action = 'employee.personal_updated' ORDER BY id DESC LIMIT 1");
        self::assertStringContainsString('"birth_date":"changed"', $diff);
        self::assertStringNotContainsString('1995', $diff);
        self::assertSame(422, $hr->post('/employees/' . $p4 . '/personal', ['birth_date' => '2030-01-01'])->status, 'birth date after the hire date');

        // self-service: own contacts; another person's record and files are not reachable
        $e = $this->as('employee.demo1');
        $me = $e->get('/me/profile');
        self::assertSame(200, $me->status);
        self::assertStringContainsString('17 พ.ค. 2538', $me->body, 'own birth date in Buddhist era');
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($me->body));
        self::assertSame(303, $e->post('/me/profile/contacts', ['contacts' => ['personal_phone' => '081-000-0000', 'address_current' => 'ที่อยู่ทดสอบ'],
            'emergency' => [['name' => 'ผู้ติดต่อทดสอบ', 'relation' => 'พี่', 'phone' => '082-000-0000']]])->status);
        self::assertSame(422, $e->post('/me/profile/contacts', ['emergency' => [['name' => 'ไม่มีเบอร์', 'relation' => 'พี่', 'phone' => '']]])->status);
        self::assertSame('081-000-0000', $this->db()->scalar("SELECT value FROM employee_contacts WHERE contact_type = 'personal_phone'"));
        self::assertSame(404, $e->post('/employees/' . $this->pid('D1-0005') . '/contacts', ['contacts' => ['personal_phone' => '1']])->status, 'not visible = not found');
        self::assertStringContainsString('081-000-0000', $hr->get('/employees/' . $p4, ['tab' => 'personal'])->body, 'HR sees personal contacts');
        self::assertSame(1, (int) $this->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'employee.contacts_updated' AND reason = 'self-service'"));
    }

    public function testIdentifiersAreEncryptedUniqueAndRevealedOnlyAfterReauth(): void
    {
        $p4 = $this->pid('D1-0004');
        $p5 = $this->pid('D1-0005');
        $hr = $this->as('hr.demo1');
        $hr->get('/employees/' . $p4, ['tab' => 'personal']);
        self::assertSame(422, $hr->post('/employees/' . $p4 . '/identifiers', ['id_type' => 'TH_CITIZEN_ID', 'id_value' => '0000000000010'])->status, 'bad check digit');
        self::assertTrue(FileRules::thaiIdValid(self::ID1));
        self::assertSame(303, $hr->post('/employees/' . $p4 . '/identifiers', ['id_type' => 'TH_CITIZEN_ID', 'id_value' => '0-0000-00000-01-9'])->status);
        $id = $this->db()->one('SELECT * FROM employee_identifiers');
        self::assertSame('0019', $id['last4']);
        self::assertStringNotContainsString(self::ID1, (string) $id['value_enc']);
        self::assertSame(422, $hr->post('/employees/' . $p5 . '/identifiers', ['id_type' => 'TH_CITIZEN_ID', 'id_value' => self::ID1])->status, 'same number on another employee');
        self::assertSame(1, (int) $this->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'employee.identifier_duplicate'"));
        // a new number replaces the current one (history kept)
        self::assertSame(303, $hr->post('/employees/' . $p4 . '/identifiers', ['id_type' => 'TH_CITIZEN_ID', 'id_value' => self::ID2])->status);
        self::assertSame(['replaced', 'current'], array_column($this->db()->all('SELECT status FROM employee_identifiers ORDER BY id'), 'status'));
        $page = $hr->get('/employees/' . $p4, ['tab' => 'personal'])->body;
        self::assertStringNotContainsString(self::ID2, $page);
        self::assertStringContainsString('•••• 0027', $page);
        // the full number needs a fresh sign-in check, is shown once and is logged
        $iid = (string) $this->db()->scalar("SELECT public_id FROM employee_identifiers WHERE status = 'current'");
        $first = $hr->post('/employees/' . $p4 . '/identifiers/' . $iid . '/reveal', ['_back' => '/employees/' . $p4 . '?tab=personal']);
        self::assertStringContainsString('/reauth', (string) ($first->headers['Location'] ?? ''));
        $hr->get('/reauth');
        $hr->post('/reauth', ['password' => $this->t->demoPassword, 'next' => '/employees/' . $p4 . '?tab=personal']);
        $hr->get('/employees/' . $p4, ['tab' => 'personal']);
        $shown = $hr->post('/employees/' . $p4 . '/identifiers/' . $iid . '/reveal', ['_back' => '/employees/' . $p4]);
        self::assertSame(200, $shown->status);
        self::assertStringContainsString(self::ID2, $shown->body);
        self::assertSame('no-store', $shown->headers['Cache-Control'] ?? null);
        self::assertSame(1, (int) $this->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'employee.identifier_revealed'"));
        self::assertStringNotContainsString(self::ID2, (string) $this->db()->scalar("SELECT GROUP_CONCAT(COALESCE(diff_json, '')) FROM audit_events"), 'never in the audit trail');
        // the employee sees the last 4 only; a manager cannot open the page at all
        self::assertStringContainsString('•••• 0027', $this->as('employee.demo1')->get('/me/profile')->body);
        self::assertSame(404, $this->as('manager.demo1')->post('/employees/' . $p4 . '/identifiers/' . $iid . '/reveal')->status);
    }

    public function testPersonnelFileCategoriesVerificationCompletenessAndExpiry(): void
    {
        $this->rules();
        $p4 = $this->pid('D1-0004');
        $png = (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/alpha.png');
        $hr = $this->as('hr.demo1');
        $hr->get('/employees/' . $p4, ['tab' => 'file']);
        self::assertSame(0, \Blue\Modules\Employees\PersonnelFileService::of($this->t->app)->completeness(\Blue\Modules\Employees\EmployeeService::of($this->t->app)->byId((int) $this->db()->scalar('SELECT id FROM employees WHERE public_id = ?', [$p4])) ?? [])['percent']);
        $up = $hr->post('/employees/' . $p4 . '/files', ['doc_type' => 'id_card', 'expiry_date' => '2026-11-30', 'employee_visible' => '1'], ['file' => ['id.png', $png]]);
        self::assertSame(303, $up->status, $up->body);
        $item = $this->db()->one('SELECT * FROM personnel_file_items');
        self::assertSame(['A', 'pending', 1], [$item['category'], $item['verify_status'], (int) $item['employee_visible']]);
        self::assertSame('R', $this->db()->scalar('SELECT classification FROM documents WHERE public_id = ?', [$item['document_pid']]));
        self::assertStringNotContainsString('id.png', $hr->get('/documents')->body, 'not in the central document list');
        // uploaded ≠ verified: the uploader cannot verify; another HR person does
        self::assertSame(422, $hr->post('/employees/' . $p4 . '/files/' . $item['public_id'] . '/verify', ['result' => 'verified', 'method' => 'original'])->status);
        $head = $this->as('hr.head1');
        $head->get('/employees/' . $p4, ['tab' => 'file']);
        self::assertSame(422, $head->post('/employees/' . $p4 . '/files/' . $item['public_id'] . '/verify', ['result' => 'rejected', 'method' => 'original'])->status, 'rejection needs a note');
        self::assertSame(303, $head->post('/employees/' . $p4 . '/files/' . $item['public_id'] . '/verify', ['result' => 'verified', 'method' => 'original'])->status);
        $page = $hr->get('/employees/' . $p4, ['tab' => 'file'])->body;
        self::assertStringContainsString('เอกสารครบ 50%', $page, 'ID card verified, contract missing');
        self::assertStringContainsString('ใกล้หมดอายุ', $page);
        // categories: HR general may add employee relations (H) but not payroll (E); payroll officer is the other way round
        self::assertSame(303, $hr->post('/employees/' . $p4 . '/files', ['doc_type' => 'warning_letter'], ['file' => ['w.png', $png]])->status);
        self::assertSame(403, $hr->post('/employees/' . $p4 . '/files', ['doc_type' => 'bank_book'], ['file' => ['b.png', $png]])->status);
        self::assertSame(422, $hr->post('/employees/' . $p4 . '/files', ['doc_type' => 'other'], ['file' => ['o.png', $png]])->status, '"other" needs a category and title');
        // the employee sees and downloads only files marked visible (not the warning letter)
        $e = $this->as('employee.demo1');
        $me = $e->get('/me/profile')->body;
        self::assertStringContainsString('สำเนาบัตรประชาชน', $me);
        self::assertStringNotContainsString('หนังสือเตือน', $me);
        self::assertSame(200, $e->get('/me/profile/files/' . $item['public_id'])->status);
        $warn = (string) $this->db()->scalar("SELECT public_id FROM personnel_file_items WHERE doc_type = 'warning_letter'");
        self::assertSame(404, $e->get('/me/profile/files/' . $warn)->status);
        self::assertSame(404, $e->get('/employees/' . $p4 . '/files/' . $warn)->status);
        // expiry: HR with the file permission is told once, inside the 30 notice days
        $sent = PersonnelFileService::expiryJob($this->t->app);
        self::assertGreaterThanOrEqual(2, $sent, 'hr.demo1 and hr.head1');
        self::assertSame(0, PersonnelFileService::expiryJob($this->t->app), 'once per file');
        self::assertSame(1, (int) $this->db()->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'employees.file_expiring'", [(int) $this->t->user('hr.demo1')['id']]));
        // a new version replaces the old one (history kept) and needs verifying again
        self::assertSame(303, $hr->post('/employees/' . $p4 . '/files', ['doc_type' => 'id_card', 'expiry_date' => '2032-01-01', 'replaces' => $item['public_id']], ['file' => ['id2.png', $png]])->status);
        self::assertSame('superseded', $this->db()->scalar('SELECT status FROM personnel_file_items WHERE id = ?', [$item['id']]));
        self::assertStringContainsString('เอกสารครบ 0%', $hr->get('/employees/' . $p4, ['tab' => 'file'])->body);
        // report + export (counts only), audited
        $rep = $hr->get('/employees/report');
        self::assertSame(200, $rep->status);
        self::assertStringContainsString('D1-0004', $rep->body);
        $x = $hr->get('/employees/report/export');
        self::assertStringStartsWith('PK', $x->body);
        self::assertSame(1, (int) $this->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'employee.report_exported'"));
        self::assertSame(403, $this->as('employee.demo1')->get('/employees/report')->status);
    }

    public function testSeparationIsApprovedEndsAccessStartsChecklistAndKeepsTheFile(): void
    {
        $this->rules();
        $p4 = $this->pid('D1-0004');
        $hr = $this->as('hr.demo1');
        $hr->get('/employees/' . $p4, ['tab' => 'changes']);
        self::assertSame(422, $hr->post('/employees/' . $p4 . '/status', ['to_status' => 'former', 'last_working_day' => '2026-11-06', 'reason' => 'x'])->status, 'reason + type');
        self::assertSame(303, $hr->post('/employees/' . $p4 . '/status', ['to_status' => 'former', 'separation_type' => 'resigned', 'last_working_day' => '2026-11-06',
            'reason' => 'ลาออกตามใบลาออก'])->status);
        $req = $this->db()->one('SELECT * FROM emp_change_requests');
        self::assertSame(['pending', 1, '2026-11-07'], [$req['status'], (int) $req['retroactive'], (string) $req['effective_from']]);
        self::assertSame('active', $this->db()->scalar('SELECT status FROM employees WHERE public_id = ?', [$p4]), 'nothing before approval');
        // the person who asked cannot approve; the system admin is never asked
        $inst = $this->lastInstance('emp_change');
        $assignees = array_column($this->db()->all("SELECT u.username FROM workflow_tasks k JOIN users u ON u.id = k.assignee_id JOIN workflow_instances i ON i.id = k.instance_id WHERE i.public_id = ? AND k.status = 'open'", [$inst]), 'username');
        self::assertNotContains('hr.demo1', $assignees);
        self::assertNotContains(TestApp::ADMIN, $assignees);
        $approval = $this->as('hr.head1')->get('/approvals/' . $inst)->body;
        self::assertStringContainsString('ลาออก', $approval);
        self::assertStringContainsString('ย้อนหลัง', $approval);
        $e = $this->as('employee.demo1');
        self::assertSame(200, $e->get('/me/profile')->status);
        $this->decide($this->as('hr.head1'), $inst, 'approve');
        $emp = $this->db()->one('SELECT * FROM employees WHERE public_id = ?', [$p4]);
        self::assertSame(['former', '2026-11-06', 'resigned'], [$emp['status'], (string) $emp['termination_date'], $emp['separation_type']]);
        self::assertSame('applied', $this->db()->scalar('SELECT status FROM emp_change_requests WHERE id = ?', [$req['id']]));
        self::assertSame(['active', 'former'], array_column($this->db()->all('SELECT status FROM employee_status_history WHERE employee_id = ? ORDER BY effective_from', [$emp['id']]), 'status'));
        self::assertSame('2026-11-06', $this->db()->scalar("SELECT effective_to FROM employee_status_history WHERE employee_id = ? AND status = 'active'", [$emp['id']]));
        // the exit checklist comes from the approved rules; the account is offboarded and its sessions end
        self::assertSame(['assets', 'accounts'], array_column($this->db()->all('SELECT item_code FROM emp_offboarding_items ORDER BY id'), 'item_code'));
        self::assertSame('2026-11-05', $this->db()->scalar("SELECT due_date FROM emp_offboarding_items WHERE item_code = 'accounts'"));
        self::assertSame('offboarding', $this->db()->scalar("SELECT status FROM users WHERE username = 'employee.demo1'"));
        self::assertNotSame(200, $e->get('/me/profile')->status, 'signed out');
        self::assertNotNull($this->db()->scalar("SELECT id FROM outbox_events WHERE event_name = 'employees.separated'"));
        // the file is kept; checklist items are ticked with a note when not applicable
        $item = (string) $this->db()->scalar("SELECT public_id FROM emp_offboarding_items WHERE item_code = 'assets'");
        self::assertSame(422, $hr->post('/employees/' . $p4 . '/offboarding/' . $item, ['status' => 'not_applicable'])->status);
        self::assertSame(303, $hr->post('/employees/' . $p4 . '/offboarding/' . $item, ['status' => 'done', 'note' => 'คืนครบ'])->status);
        self::assertSame(200, $hr->get('/employees/' . $p4, ['tab' => 'changes'])->status);
        // a person who left cannot be changed back — a return is a new record with a new code
        self::assertSame(422, $hr->post('/employees/' . $p4 . '/status', ['to_status' => 'active', 'effective_from' => '2026-12-01', 'reason' => 'กลับมา'])->status);
        self::assertSame(422, $hr->post('/employees/' . $p4 . '/employment', ['legal_entity_id' => (string) $this->le(), 'employee_group' => 'MONTHLY', 'employment_type' => 'permanent',
            'effective_from' => '2026-12-01', 'change_reason' => 'ย้าย'])->status);
    }

    public function testRetroactiveChangesRespectClosedPeriodsAndFailedChangesCanBeRetried(): void
    {
        $le = $this->le();
        $p5 = $this->pid('D1-0005');
        $hr = $this->as('hr.demo1');
        $admin = (int) $this->t->user(TestApp::ADMIN)['id'];
        $lock = $this->t->app->locks()->lock('attendance', 'legal_entity', $le, '2026-10-01..2026-10-31', 'hard', $admin, 'test: October closed');
        $hr->get('/employees/' . $p5, ['tab' => 'changes']);
        $retro = $hr->post('/employees/' . $p5 . '/employment', ['legal_entity_id' => (string) $le, 'employee_group' => 'MONTHLY', 'employment_type' => 'permanent', 'position_title' => 'หัวหน้าทีม',
            'effective_from' => '2026-10-15', 'change_reason' => 'แต่งตั้งย้อนหลัง']);
        self::assertSame(422, $retro->status);
        self::assertStringContainsString('งวดเวลาทำงานที่ส่งปิดหรือปิดแล้ว', $retro->body);
        // after the closed period it is fine (retroactive, flagged)
        self::assertSame(303, $hr->post('/employees/' . $p5 . '/employment', ['legal_entity_id' => (string) $le, 'employee_group' => 'MONTHLY', 'employment_type' => 'permanent',
            'position_title' => 'หัวหน้าทีม', 'effective_from' => '2026-12-01', 'change_reason' => 'แต่งตั้ง'])->status);
        $req = $this->db()->one('SELECT * FROM emp_change_requests');
        self::assertSame(0, (int) $req['retroactive']);
        $this->decide($this->as('hr.head1'), $this->lastInstance('emp_change'), 'approve');
        self::assertSame('approved', $this->db()->scalar('SELECT status FROM emp_change_requests'));
        // the period of the date gets closed before the date comes → applying fails visibly, nothing half-done
        $this->t->app->locks()->release($lock, $admin, 'test');
        $dec = $this->t->app->locks()->lock('attendance', 'legal_entity', $le, '2026-11-26..2026-12-25', 'hard', $admin, 'test: December closed');
        $this->t->clock->advance('+23 days'); // 2026-12-02
        self::assertSame(0, ChangeService::dueJob($this->t->app));
        $r = $this->db()->one('SELECT * FROM emp_change_requests');
        self::assertSame(['failed', 'employees.error.period_closed'], [$r['status'], $r['error']]);
        self::assertSame(1, (int) $this->db()->scalar('SELECT COUNT(*) FROM employment_history WHERE employee_id = ?', [$r['employee_id']]), 'history untouched');
        $hr = $this->as('hr.demo1'); // the earlier session has expired after the clock moved
        self::assertStringContainsString('ทำรายการไม่สำเร็จ', $hr->get('/employees/' . $p5)->body);
        // reopen → retry applies it once
        $this->t->app->locks()->release($dec, $admin, 'test');
        $hr->get('/employees/' . $p5, ['tab' => 'changes']);
        self::assertSame(303, $hr->post('/employees/' . $p5 . '/changes/' . $r['public_id'] . '/retry')->status);
        self::assertSame('applied', $this->db()->scalar('SELECT status FROM emp_change_requests'));
        self::assertSame(['2026-11-30', null], array_column($this->db()->all('SELECT effective_to FROM employment_history WHERE employee_id = ? ORDER BY effective_from', [$r['employee_id']]), 'effective_to'));
        self::assertSame(1, (int) $this->db()->scalar("SELECT COUNT(*) FROM outbox_events WHERE event_name = 'employees.employment.changed'"));
        self::assertFalse(ChangeService::of($this->t->app)->apply((string) $r['public_id'], null), 'applied once');
        // withdrawing: only the person who asked, only while pending
        $hr->post('/employees/' . $p5 . '/status', ['to_status' => 'on_leave', 'effective_from' => '2027-01-04', 'reason' => 'ลาบวช']);
        $w = $this->db()->one("SELECT * FROM emp_change_requests WHERE kind = 'status'");
        self::assertSame(403, $this->as('hr.head1')->post('/employees/' . $p5 . '/changes/' . $w['public_id'] . '/withdraw')->status);
        self::assertSame(303, $hr->post('/employees/' . $p5 . '/changes/' . $w['public_id'] . '/withdraw')->status);
        self::assertSame('withdrawn', $this->db()->scalar('SELECT status FROM emp_change_requests WHERE id = ?', [$w['id']]));
        $this->t->clock->advance('-23 days');
    }
}
