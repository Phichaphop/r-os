<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\ValidationException;
use Blue\Modules\Overtime\OvertimeService;
use Blue\Modules\Shifts\ShiftService;
use Blue\Tests\Support\TestApp;

/** Phase 5B (DEC-70): M33 OT — request, day type, approval, scans vs requested, certify, payroll batch, report. */
final class OvertimeWebTest extends TestCase
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

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    private function emp(string $code): array
    {
        return $this->t->app->db()->one('SELECT id, public_id FROM employees WHERE employee_code = ?', [$code]) ?? [];
    }

    /** publish M (06–15) on 2026-11-05 and OFF on 2026-11-07 for D1-0004 (employee.demo1) */
    private function shifts(): void
    {
        $svc = ShiftService::of($this->t->app);
        $hr = $this->az('hr.demo1');
        $lePid = (string) $this->t->app->db()->scalar("SELECT public_id FROM legal_entities WHERE code = 'DEMO1'");
        $m = (string) $this->t->app->db()->scalar("SELECT public_id FROM sh_patterns WHERE code = 'M' AND legal_entity_id = ?", [$this->le()]);
        $rid = $svc->createRoster($hr, ['legal_entity' => $lePid, 'name' => 'OT test', 'start_date' => '2026-11-02', 'end_date' => '2026-11-08']);
        $svc->saveCells($hr, $rid, [$this->emp('D1-0004')['public_id'] => ['2026-11-05' => $m, '2026-11-07' => 'OFF']]);
        $svc->publish($hr, $rid, true);
    }

    private function approve(string $pid): void
    {
        $db = $this->t->app->db();
        $inst = (string) $db->scalar('SELECT i.public_id FROM workflow_instances i JOIN ot_requests r ON r.workflow_instance_id = i.id WHERE r.public_id = ?', [$pid]);
        $who = (int) $db->scalar("SELECT k.assignee_id FROM workflow_tasks k JOIN workflow_instances i ON i.id = k.instance_id WHERE i.public_id = ? AND k.status = 'open' LIMIT 1", [$inst]);
        self::assertGreaterThan(0, $who);
        $this->t->app->workflow()->decide($inst, $who, 'approve');
    }

    private function punch(int $employeeId, string $utc): void
    {
        $this->t->app->db()->run(
            "INSERT INTO att_raw_punches (source, source_event_id, employee_id, device_user_code, occurred_at_utc, verify_method, received_at) VALUES ('file', ?, ?, '000104', ?, 'file', ?)",
            [sha1($utc . $employeeId), $employeeId, $utc, $utc]
        );
    }

    public function testRequestIsOutsideTheShiftClassifiedApprovedAndCertifiedAgainstScans(): void
    {
        $this->shifts();
        $svc = OvertimeService::of($this->t->app);
        $me = $this->az('employee.demo1');
        $db = $this->t->app->db();
        $e = $this->emp('D1-0004');
        try {
            $svc->submit($me, ['work_date' => '2026-11-05', 'start' => '14:00', 'end' => '17:00', 'reason' => 'ปิดงาน']);
            self::fail('inside the shift');
        } catch (ValidationException $x) {
            self::assertSame('overtime.error.in_working_time', $x->errors['start'] ?? null);
        }
        [$pid] = $svc->submit($me, ['work_date' => '2026-11-05', 'start' => '15:30', 'end' => '18:30', 'reason' => 'ปิดงานส่งลูกค้า']);
        $r = $db->one('SELECT * FROM ot_requests WHERE public_id = ?', [$pid]);
        self::assertSame(['workday', 'M', 180, 'pending'], [$r['day_type'], $r['shift_code'], (int) $r['minutes_requested'], $r['status']]);
        self::assertSame('2026-11-05 08:30:00', $r['start_utc'], 'Bangkok 15:30 = 08:30 UTC');
        try {
            $svc->submit($me, ['work_date' => '2026-11-05', 'start' => '18:00', 'end' => '19:00', 'reason' => 'ซ้อน']);
            self::fail('overlap');
        } catch (ValidationException $x) {
            self::assertSame('overtime.error.overlap', $x->errors['start'] ?? null);
        }
        [$off] = $svc->submit($me, ['work_date' => '2026-11-07', 'start' => '09:00', 'end' => '12:00', 'reason' => 'งานด่วนวันหยุด']);
        self::assertSame('dayoff', $db->scalar('SELECT day_type FROM ot_requests WHERE public_id = ?', [$off]), 'shift day off');
        $assignee = (int) $db->scalar("SELECT k.assignee_id FROM workflow_tasks k JOIN ot_requests r ON r.workflow_instance_id = k.instance_id WHERE r.public_id = ? AND k.status = 'open'", [$pid]);
        self::assertSame((int) $this->t->user('manager.demo1')['id'], $assignee, 'line manager decides');
        $this->approve($pid);
        self::assertSame('approved', $db->scalar('SELECT status FROM ot_requests WHERE public_id = ?', [$pid]));

        // scans 06:02 in, 18:40 out → inside 15:30–18:30 = 180 min
        $this->punch((int) $e['id'], '2026-11-04 23:02:00');
        $this->punch((int) $e['id'], '2026-11-05 11:40:00');
        $mgr = $this->az('manager.demo1');
        self::assertCount(1, $svc->toVerify($mgr));
        $req = $svc->request($mgr, $pid);
        self::assertSame(['actual' => 180, 'payable' => 180, 'round' => 1], $svc->proposal($req));
        try {
            $svc->verify($mgr, $pid, 'verify', '200', '');
            self::fail('more than requested');
        } catch (ValidationException $x) {
            self::assertArrayHasKey('payable_minutes', $x->errors);
        }
        try {
            $svc->verify($mgr, $pid, 'verify', '120', '');
            self::fail('a different figure needs a note');
        } catch (ValidationException $x) {
            self::assertSame('overtime.error.note_differs', $x->errors['note'] ?? null);
        }
        try {
            $svc->verify($me, $pid, 'verify', '', '');
            self::fail('the employee cannot certify own hours');
        } catch (\Blue\Core\Error\HttpException) {
            self::assertTrue(true);
        }
        $svc->verify($mgr, $pid, 'verify', '', '');
        $r = $db->one('SELECT status, actual_minutes, payable_minutes FROM ot_requests WHERE public_id = ?', [$pid]);
        self::assertSame(['verified', 180, 180], [$r['status'], (int) $r['actual_minutes'], (int) $r['payable_minutes']]);
    }

    public function testRulesCapBackdateAndClosedPeriod(): void
    {
        $svc = OvertimeService::of($this->t->app);
        $hr = $this->az('hr.demo1');
        $db = $this->t->app->db();
        try {
            $svc->proposeRules($hr, $this->le(), ['rates' => ['workday' => ['class' => 'OT1', 'multiplier' => 'x']]], '2026-11-01', 'ข้อบังคับ หมวด 4 (ทดสอบ)', 'ทดสอบ');
            self::fail('bad multiplier');
        } catch (ValidationException $x) {
            self::assertArrayHasKey('rates.workday.multiplier', $x->errors);
        }
        $cv = $svc->proposeRules($hr, $this->le(), ['rates' => ['workday' => ['class' => 'OT1', 'multiplier' => '1.5'], 'holiday' => ['class' => 'OTH', 'multiplier' => '3']],
            'monthly_cap_hours' => '4', 'cap_mode' => 'block', 'backdate_days' => '3', 'round_minutes' => '15', 'min_minutes' => '30'], '2026-11-01', 'ข้อบังคับ หมวด 4 (ทดสอบ)', 'ทดสอบ');
        $inst = (string) $db->scalar('SELECT i.public_id FROM workflow_instances i JOIN config_versions v ON v.workflow_instance_id = i.id WHERE v.public_id = ?', [$cv]);
        $this->t->app->workflow()->decide($inst, (int) $this->t->user('hr.head1')['id'], 'approve');
        self::assertSame('1.5', $svc->rules($this->le(), '2026-11-09')['rates']['workday']['multiplier'] ?? null, 'stored as entered');
        $me = $this->az('employee.demo1');
        $try = function (array $in, string $field, string $key) use ($svc, $me): void {
            try {
                $svc->submit($me, $in + ['reason' => 'ทดสอบ']);
                self::fail('expected ' . $key);
            } catch (ValidationException $x) {
                self::assertSame($key, $x->errors[$field] ?? null);
            }
        };
        $try(['work_date' => '2026-11-01', 'start' => '18:00', 'end' => '20:00'], 'work_date', 'overtime.error.backdate');
        $try(['work_date' => '2026-11-09', 'start' => '18:00', 'end' => '18:15'], 'end', 'overtime.error.too_short');
        $svc->submit($me, ['work_date' => '2026-11-09', 'start' => '18:00', 'end' => '21:00', 'reason' => 'สามชั่วโมง']);
        $try(['work_date' => '2026-11-10', 'start' => '18:00', 'end' => '20:00'], 'end', 'overtime.error.cap');
        $this->t->app->locks()->lock('attendance', 'legal_entity', $this->le(), '2026-11-01..2026-11-08', 'hard', (int) $this->t->user('hr.demo1')['id'], 'test close');
        $try(['work_date' => '2026-11-08', 'start' => '18:00', 'end' => '19:00'], 'work_date', 'overtime.error.locked');
    }

    public function testManagerFilesForTheTeamAndHrSendsAPayrollBatchOnce(): void
    {
        $svc = OvertimeService::of($this->t->app);
        $db = $this->t->app->db();
        $mgr = $this->az('manager.demo1');
        try {
            $svc->submit($mgr, ['work_date' => '2026-11-06', 'start' => '18:00', 'end' => '20:00', 'reason' => 'ทีม'], [$this->emp('D1-0002')['public_id']]);
            self::fail('not a direct report');
        } catch (ValidationException $x) {
            self::assertSame('overtime.error.not_yours', $x->errors['employees'] ?? null);
        }
        $pids = $svc->submit($mgr, ['work_date' => '2026-11-06', 'start' => '18:00', 'end' => '20:00', 'reason' => 'นับสต็อก'], [$this->emp('D1-0004')['public_id'], $this->emp('D1-0005')['public_id']]);
        self::assertCount(2, $pids);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(DISTINCT group_pid) FROM ot_requests WHERE public_id IN (?, ?)', $pids));
        foreach ($pids as $pid) {
            $assignee = (int) $db->scalar("SELECT k.assignee_id FROM workflow_tasks k JOIN ot_requests r ON r.workflow_instance_id = k.instance_id WHERE r.public_id = ? AND k.status = 'open'", [$pid]);
            self::assertNotSame((int) $this->t->user('manager.demo1')['id'], $assignee, 'the manager who filed does not approve');
            $this->approve($pid);
        }
        $hr = $this->az('hr.demo1');
        // no scans: HR certifies with a note for one, disputes the other
        try {
            $svc->verify($hr, $pids[0], 'verify', '120', '');
            self::fail('paying more than the scans needs a note');
        } catch (ValidationException $x) {
            self::assertSame('overtime.error.note_no_scan', $x->errors['note'] ?? null);
        }
        $svc->verify($hr, $pids[0], 'verify', '120', 'หัวหน้ายืนยัน เครื่องสแกนเสีย');
        $svc->verify($hr, $pids[1], 'dispute', '', 'ไม่มีเวลาสแกน รอตรวจ');
        self::assertSame('disputed', $db->scalar('SELECT status FROM ot_requests WHERE public_id = ?', [$pids[1]]));

        try {
            $svc->createBatch($mgr, $this->le(), '2026-11-01', '2026-11-30', '');
            self::fail('export is HR/payroll');
        } catch (\Blue\Core\Error\HttpException) {
            self::assertTrue(true);
        }
        $bid = $svc->createBatch($hr, $this->le(), '2026-11-01', '2026-11-30', 'รอบ พ.ย.');
        $b = $svc->batch($hr, $bid);
        self::assertSame([1, 1, 120, true], [(int) $b['batch_no'], (int) $b['line_count'], (int) $b['total_minutes'], $b['intact']]);
        self::assertSame('sent', $db->scalar('SELECT status FROM ot_requests WHERE public_id = ?', [$pids[0]]));
        try {
            $svc->createBatch($hr, $this->le(), '2026-11-01', '2026-11-30', '');
            self::fail('a line goes out once');
        } catch (BusinessException $x) {
            self::assertSame('overtime.error.batch_empty', $x->messageKey);
        }
        $x = $svc->export($hr, $bid, 'xlsx');
        self::assertStringStartsWith('PK', $x['bytes']);
        self::assertStringContainsString('BLUE_PAYROLL_OT_INPUT_v1_DEMO1_B0001', $x['name']);
        $c = $svc->export($hr, $bid, 'csv');
        self::assertStringContainsString('employee_code,employee_name,site,department,cost_center,work_date,day_type', $c['bytes']);
        self::assertSame(2, (int) $db->scalar('SELECT COUNT(*) FROM ot_batch_exports'));
        $db->run("UPDATE ot_batches SET lines_json = REPLACE(lines_json, '120', '480') WHERE public_id = ?", [$bid]);
        try {
            $svc->export($hr, $bid, 'xlsx');
            self::fail('tampered batch');
        } catch (BusinessException $x) {
            self::assertSame('overtime.error.batch_damaged', $x->messageKey);
        }
        $rep = $svc->report($hr, '2026-11', 'employee');
        self::assertNotEmpty($rep);
    }

    public function testScreensRender(): void
    {
        $b = $this->t->browser();
        $b->login('manager.demo1');
        $page = $b->get('/overtime');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('name="employees[]"', $page->body, 'team form for a manager');
        self::assertSame(403, $b->get('/overtime/admin')->status);
        $res = $b->post('/overtime', ['for' => 'me', 'work_date' => '2026-11-09', 'start' => '18:00', 'end' => '20:00', 'reason' => 'ทดสอบหน้าจอ']);
        self::assertSame(303, $res->status);
        $show = $b->get((string) parse_url((string) $res->headers['Location'], PHP_URL_PATH));
        self::assertSame(200, $show->status);
        $hr = $this->t->browser();
        $hr->login('hr.demo1');
        foreach (['/overtime/admin', '/overtime/report', '/overtime/verify'] as $p) {
            $r = $hr->get($p);
            self::assertSame(200, $r->status, $p);
            self::assertDoesNotMatchRegularExpression('/\bovertime\.[a-z_]+\.[a-z_.]+\b(?![^<]*>)/', strip_tags($r->body), "no raw keys on {$p}");
        }
    }
}
