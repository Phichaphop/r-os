<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\ValidationException;
use Blue\Modules\Attendance\AttendanceService;
use Blue\Modules\Shifts\ShiftService;
use Blue\Modules\Shifts\SwapService;
use Blue\Tests\Support\TestApp;

/** Phase 5B (DEC-70): M35 shift scheduling — patterns, rosters, publish/revise, swaps, attendance with shifts. */
final class ShiftsWebTest extends TestCase
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

    private function lePid(): string
    {
        return (string) $this->t->app->db()->scalar("SELECT public_id FROM legal_entities WHERE code = 'DEMO1'");
    }

    /** @return array<string, string> code => pattern pid (DEMO patterns M 06–15, A 14–23, N 22–07) */
    private function patterns(): array
    {
        $out = [];
        foreach ($this->t->app->db()->all('SELECT code, public_id FROM sh_patterns WHERE legal_entity_id = ?', [$this->le()]) as $p) {
            $out[(string) $p['code']] = (string) $p['public_id'];
        }
        return $out;
    }

    private function emp(string $code): array
    {
        return $this->t->app->db()->one('SELECT id, public_id FROM employees WHERE employee_code = ?', [$code]) ?? [];
    }

    private function roster(string $from, string $to): string
    {
        return ShiftService::of($this->t->app)->createRoster($this->az('hr.demo1'), ['legal_entity' => $this->lePid(), 'name' => 'ทดสอบ', 'start_date' => $from, 'end_date' => $to]);
    }

    public function testPatternsAreValidatedAndOvernightIsAllowed(): void
    {
        $svc = ShiftService::of($this->t->app);
        self::assertCount(3, $this->patterns(), 'DEMO patterns');
        $hr = $this->az('hr.demo1');
        $fail = function (array $in, string $field) use ($svc, $hr): void {
            try {
                $svc->savePattern($hr, null, $in + ['legal_entity' => $this->lePid(), 'name' => 'x', 'code' => 'X1', 'start' => '08:00', 'end' => '17:00']);
                self::fail('expected error on ' . $field);
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->errors);
            }
        };
        $fail(['code' => 'OFF'], 'code');
        $fail(['code' => 'M'], 'code');
        $fail(['start' => '25:00'], 'start');
        $fail(['start' => '08:00', 'end' => '08:00'], 'end');
        $fail(['start' => '06:00', 'end' => '23:30'], 'end'); // 17.5 h
        $fail(['break_minutes' => '600'], 'break_minutes');
        $pid = $svc->savePattern($hr, null, ['legal_entity' => $this->lePid(), 'code' => 'G', 'name' => 'ยาม', 'start' => '20:00', 'end' => '04:00', 'break_minutes' => '30', 'tone' => 'night']);
        self::assertSame('04:00', $this->t->app->db()->scalar('SELECT end_time FROM sh_patterns WHERE public_id = ?', [$pid]));
        try {
            $svc->savePattern($hr, $pid, ['code' => 'G', 'name' => 'ยาม', 'start' => '21:00', 'end' => '04:00', 'break_minutes' => '30']);
            self::fail('times changed without a reason');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('reason', $e->errors);
        }
        $emp = $this->az('employee.demo1');
        $this->expectException(\Blue\Core\Error\HttpException::class);
        $svc->savePattern($emp, null, ['legal_entity' => $this->lePid(), 'code' => 'Z', 'name' => 'z', 'start' => '08:00', 'end' => '17:00']);
    }

    public function testRosterDraftPublishReviseAndChecks(): void
    {
        $svc = ShiftService::of($this->t->app);
        $hr = $this->az('hr.demo1');
        $p = $this->patterns();
        [$a, $b] = [$this->emp('D1-0003'), $this->emp('D1-0004')];
        try {
            $svc->createRoster($hr, ['legal_entity' => $this->lePid(), 'name' => 'x', 'start_date' => '2026-11-16', 'end_date' => '2026-12-31']);
            self::fail('over 31 days');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('end_date', $e->errors);
        }
        $rid = $this->roster('2026-11-16', '2026-11-29');
        $ro = $svc->roster($hr, $rid);
        self::assertSame('draft', $ro['status']);
        self::assertContains($a['public_id'], array_column($svc->people($ro), 'public_id'));
        self::assertSame(['empty'], array_map(static fn ($e) => substr($e['key'], 13), $svc->check($ro)['errors']));

        // fill Mon–Fri with M for A, copy the first week onto the second, night shift then morning = overlap
        $svc->fill($hr, $rid, [$a['public_id']], '2026-11-16', '2026-11-22', [1, 2, 3, 4, 5], $p['M']);
        $svc->fill($hr, $rid, [$a['public_id']], '2026-11-16', '2026-11-22', [0, 6], 'OFF');
        $svc->copyWeek($hr, $rid, '2026-11-16', '2026-11-23');
        $cells = $svc->cells((int) $ro['id']);
        self::assertCount(14, $cells[(int) $a['id']]);
        self::assertNull($cells[(int) $a['id']]['2026-11-29'], 'Sunday off copied');
        // a cleared cell in the source week clears the target cell too
        $svc->saveCells($hr, $rid, [$a['public_id'] => ['2026-11-16' => '']]);
        $svc->copyWeek($hr, $rid, '2026-11-16', '2026-11-23');
        self::assertArrayNotHasKey('2026-11-23', $svc->cells((int) $ro['id'])[(int) $a['id']]);
        $svc->fill($hr, $rid, [$a['public_id']], '2026-11-16', '2026-11-23', [1], $p['M']);
        $svc->saveCells($hr, $rid, [$b['public_id'] => ['2026-11-17' => $p['N'], '2026-11-18' => $p['M']]]);
        $chk = $svc->check($svc->roster($hr, $rid));
        self::assertContains('shifts.check.overlap', array_column($chk['errors'], 'key'), 'N 22–07 then M 06:00 overlaps');
        try {
            $svc->publish($hr, $rid, true);
            self::fail('cannot publish with errors');
        } catch (BusinessException $e) {
            self::assertSame('shifts.error.check_failed', $e->messageKey);
        }
        $svc->saveCells($hr, $rid, [$b['public_id'] => ['2026-11-18' => $p['A']]]);
        self::assertSame([], $svc->check($svc->roster($hr, $rid))['errors']);

        // company rules (another HR approves) turn short rest into a warning that must be acknowledged
        $cv = $svc->proposeRules($hr, $this->le(), ['min_rest_hours' => '12', 'max_consecutive_days' => '6'], '2026-11-01', 'ข้อบังคับการทำงาน หมวด 3 (ทดสอบ)', 'ทดสอบ');
        $inst = (string) $this->t->app->db()->scalar('SELECT i.public_id FROM workflow_instances i JOIN config_versions v ON v.workflow_instance_id = i.id WHERE v.public_id = ?', [$cv]);
        $this->t->app->workflow()->decide($inst, (int) $this->t->user('hr.head1')['id'], 'approve');
        $chk = $svc->check($svc->roster($hr, $rid));
        self::assertContains('shifts.check.rest', array_column($chk['warnings'], 'key'), 'N ends 07:00, A starts 14:00 = 7 h < 12 h');
        try {
            $svc->publish($hr, $rid, false);
            self::fail('warnings need acknowledgement');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('acknowledge', $e->errors);
        }
        $res = $svc->publish($hr, $rid, true);
        self::assertSame(2, $res['notified']);
        self::assertSame('published', $this->t->app->db()->scalar('SELECT status FROM sh_rosters WHERE public_id = ?', [$rid]));
        self::assertNotNull($this->t->app->db()->scalar('SELECT warnings_json FROM sh_rosters WHERE public_id = ?', [$rid]));
        self::assertSame('M', $svc->shiftFor((int) $a['id'], '2026-11-16')['code'] ?? null);
        self::assertTrue($svc->shiftFor((int) $b['id'], '2026-11-17')['overnight'] ?? false);
        self::assertTrue($svc->shiftFor((int) $a['id'], '2026-11-22')['off'] ?? false);
        try {
            $svc->saveCells($hr, $rid, [$a['public_id'] => ['2026-11-16' => 'OFF']]);
            self::fail('published roster is not edited in place');
        } catch (BusinessException $e) {
            self::assertSame('shifts.error.not_draft', $e->messageKey);
        }

        // revise: a draft copy; publishing supersedes and logs only the changed cell
        $rev = $svc->revise($hr, $rid);
        self::assertSame($rev, $svc->revise($hr, $rid), 'one open revision');
        $svc->saveCells($hr, $rev, [$a['public_id'] => ['2026-11-16' => $p['A']]]);
        self::assertSame(1, count($svc->diff($svc->roster($hr, $rev))));
        $res = $svc->publish($hr, $rev, true);
        self::assertSame(['changes' => 1, 'notified' => 1], $res);
        self::assertSame('superseded', $this->t->app->db()->scalar('SELECT status FROM sh_rosters WHERE public_id = ?', [$rid]));
        self::assertSame('A', $svc->shiftFor((int) $a['id'], '2026-11-16')['code'] ?? null);
        self::assertSame(1, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM sh_changes WHERE source = 'revision'"));

        // the same person in a second published roster on the same date is refused
        $other = $this->roster('2026-11-20', '2026-11-21');
        $svc->saveCells($hr, $other, [$a['public_id'] => ['2026-11-20' => $p['M']]]);
        self::assertContains('shifts.check.twice', array_column($svc->check($svc->roster($hr, $other))['errors'], 'key'));
    }

    public function testClosedAttendancePeriodBlocksShiftChanges(): void
    {
        $svc = ShiftService::of($this->t->app);
        $hr = $this->az('hr.demo1');
        $p = $this->patterns();
        $a = $this->emp('D1-0003');
        $this->t->app->locks()->lock('attendance', 'legal_entity', $this->le(), '2026-11-01..2026-11-30', 'hard', (int) $this->t->user('hr.demo1')['id'], 'test close');
        $rid = $this->roster('2026-11-02', '2026-11-08');
        $svc->saveCells($hr, $rid, [$a['public_id'] => ['2026-11-02' => $p['M']]]);
        self::assertContains('shifts.check.locked', array_column($svc->check($svc->roster($hr, $rid))['errors'], 'key'));
    }

    public function testAttendanceUsesTheShiftIncludingOvernight(): void
    {
        $svc = ShiftService::of($this->t->app);
        $hr = $this->az('hr.demo1');
        $p = $this->patterns();
        $a = $this->emp('D1-0003');
        $rid = $this->roster('2026-11-02', '2026-11-08');
        $svc->saveCells($hr, $rid, [$a['public_id'] => ['2026-11-02' => $p['N'], '2026-11-03' => 'OFF', '2026-11-04' => $p['M']]]);
        $svc->publish($hr, $rid, true);
        $db = $this->t->app->db();
        // N 22:00–07:00 (Bangkok): in 21:55 Mon, out 07:02 Tue · M 06:00–15:00: in 06:20 Wed (late 20), out 15:00
        foreach (['2026-11-02 14:55:00', '2026-11-03 00:02:00', '2026-11-03 23:20:00', '2026-11-04 08:00:00'] as $utc) {
            $db->run(
                "INSERT INTO att_raw_punches (source, source_event_id, employee_id, device_user_code, occurred_at_utc, verify_method, received_at) VALUES ('file', ?, ?, '000103', ?, 'file', ?)",
                [sha1($utc), $a['id'], $utc, $utc]
            );
        }
        $att = AttendanceService::of($this->t->app);
        $att->forget();
        $att->recalc((int) $a['id'], '2026-11-02', '2026-11-04');
        $days = [];
        foreach ($db->all('SELECT work_date, status, shift_code, worked_minutes, late_minutes, day_type FROM att_days WHERE employee_id = ? ORDER BY work_date', [$a['id']]) as $d) {
            $days[(string) $d['work_date']] = $d;
        }
        self::assertSame('N', $days['2026-11-02']['shift_code']);
        self::assertSame('ok', $days['2026-11-02']['status'], 'the 07:02 punch belongs to Monday\'s night shift');
        self::assertSame(9 * 60 + 7 - 60, (int) $days['2026-11-02']['worked_minutes'], '21:55 → 07:02 minus 60 break');
        self::assertSame('off', $days['2026-11-03']['status']);
        self::assertSame('weekend', $days['2026-11-03']['day_type'], 'a shift day off counts as an off day');
        self::assertSame('late', $days['2026-11-04']['status']);
        self::assertSame(20, (int) $days['2026-11-04']['late_minutes']);
    }

    public function testSwapNeedsColleagueThenApproverAndChangesTheSchedule(): void
    {
        $svc = ShiftService::of($this->t->app);
        $sw = SwapService::of($this->t->app);
        $hr = $this->az('hr.demo1');
        $p = $this->patterns();
        $db = $this->t->app->db();
        // employee.demo1 = D1-0007? find the employee linked to the account and a colleague
        $me = $db->one("SELECT e.id, e.public_id FROM employees e JOIN users u ON u.employee_id = e.id WHERE u.username = 'employee.demo1'") ?? [];
        $mate = $db->one("SELECT e.id, e.public_id, u.username FROM employees e JOIN users u ON u.employee_id = e.id WHERE u.username NOT IN ('employee.demo1') AND e.employee_code LIKE 'D1-%' AND u.username <> 'hr.head1' ORDER BY e.id LIMIT 1") ?? [];
        self::assertNotEmpty($me);
        self::assertNotEmpty($mate);
        $rid = $this->roster('2026-11-16', '2026-11-22');
        $svc->saveCells($hr, $rid, [$me['public_id'] => ['2026-11-17' => $p['M'], '2026-11-18' => 'OFF'], $mate['public_id'] => ['2026-11-17' => $p['A'], '2026-11-18' => $p['M']]]);
        $svc->publish($hr, $rid, true);

        $mine = $this->az('employee.demo1');
        try {
            $sw->request($mine, ['partner' => $mate['public_id'], 'my_date' => '2026-11-01', 'reason' => 'ธุระ']);
            self::fail('past date');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('my_date', $e->errors);
        }
        $pid = $sw->request($mine, ['partner' => $mate['public_id'], 'my_date' => '2026-11-17', 'reason' => 'ไปหาหมอตอนเช้า']);
        self::assertSame('pending_partner', $db->scalar('SELECT status FROM sh_swaps WHERE public_id = ?', [$pid]));
        try {
            $sw->request($mine, ['partner' => $mate['public_id'], 'my_date' => '2026-11-17', 'reason' => 'ซ้ำ']);
            self::fail('one open swap per cell');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('my_date', $e->errors);
        }
        try {
            $sw->answer($mine, $pid, true);
            self::fail('only the colleague answers');
        } catch (\Blue\Core\Error\HttpException) {
            self::assertTrue(true);
        }
        $sw->answer($this->az((string) $mate['username']), $pid, true);
        self::assertSame('pending', $db->scalar('SELECT status FROM sh_swaps WHERE public_id = ?', [$pid]));
        $inst = (string) $db->scalar('SELECT i.public_id FROM workflow_instances i JOIN sh_swaps s ON s.workflow_instance_id = i.id WHERE s.public_id = ?', [$pid]);
        $assignee = (int) $db->scalar("SELECT k.assignee_id FROM workflow_tasks k JOIN workflow_instances i ON i.id = k.instance_id WHERE i.public_id = ? AND k.status = 'open' LIMIT 1", [$inst]);
        self::assertGreaterThan(0, $assignee, 'an approver got the task');
        $this->t->app->workflow()->decide($inst, $assignee, 'approve');
        self::assertSame('approved', $db->scalar('SELECT status FROM sh_swaps WHERE public_id = ?', [$pid]));
        $svc->forget();
        self::assertSame('A', $svc->shiftFor((int) $me['id'], '2026-11-17')['code'] ?? null);
        self::assertSame('M', $svc->shiftFor((int) $mate['id'], '2026-11-17')['code'] ?? null);
        self::assertSame(2, (int) $db->scalar("SELECT COUNT(*) FROM sh_changes WHERE source = 'swap'"));

        // a day swap needs the colleague free on my date — after the swap they work the 17th, so it is refused
        try {
            $sw->request($mine, ['partner' => $mate['public_id'], 'my_date' => '2026-11-17', 'partner_date' => '2026-11-18', 'reason' => 'x x x']);
            self::fail('colleague is not free on my date');
        } catch (ValidationException $e) {
            self::assertSame('shifts.error.day_swap', $e->errors['partner_date'] ?? null);
        }
    }

    public function testScreensRenderForEmployeeAndPlanner(): void
    {
        $b = $this->t->browser();
        $b->login('employee.demo1');
        $page = $b->get('/shifts');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('ตารางกะของฉัน', $page->body);
        self::assertSame(403, $b->get('/shifts/admin')->status);

        $hr = $this->t->browser();
        $hr->login('hr.demo1');
        $adm = $hr->get('/shifts/admin');
        self::assertSame(200, $adm->status);
        self::assertStringContainsString('กะดึก (DEMO)', $adm->body);
        $res = $hr->post('/shifts/admin/roster', ['legal_entity' => $this->lePid(), 'name' => 'สัปดาห์ทดสอบ', 'start_date' => '2026-11-16', 'end_date' => '2026-11-22']);
        self::assertSame(303, $res->status);
        $grid = $hr->get((string) parse_url((string) $res->headers['Location'], PHP_URL_PATH));
        self::assertSame(200, $grid->status);
        self::assertStringContainsString('shift-grid', $grid->body);
        self::assertStringContainsString('name="cell[', $grid->body);
        self::assertDoesNotMatchRegularExpression('/\bshifts\.[a-z_]+\.[a-z_.]+\b(?![^<]*>)/', strip_tags($grid->body), 'no raw keys');
    }
}
