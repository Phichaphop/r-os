<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Modules\Safety\SafetyService;
use Blue\Tests\Support\TestApp;

/**
 * Phase 9E-1 (DEC-100): M39 safety incidents. Clock: Mon 2026-11-09 09:00 (Asia/Bangkok). ga.demo1 (WPA) = safety team
 * (no employee record); hr.demo1 / hr.head1 (HRA) = medical access; auditor.demo (AUD) = read-only; employee.demo1 and
 * manager.demo1 = employees. Demo data already holds SF2611-0001 (a hazard).
 */
final class SafetyTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(safety|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function sf(): SafetyService
    {
        return SafetyService::of($this->t->app);
    }

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    private function code(string $user): string
    {
        return (string) $this->t->app->db()->scalar('SELECT e.employee_code FROM employees e JOIN users u ON u.employee_id = e.id WHERE u.username = ?', [$user]);
    }

    private function png(): UploadedFile
    {
        $img = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($img);
        return UploadedFile::fromBytes('spill.png', (string) ob_get_clean(), $this->t->tmp);
    }

    /**
     * @param callable(): mixed $fn
     * @return array<string, string>
     */
    private function errors(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors;
        }
        self::fail('expected a refusal');
    }

    /** @param callable(): mixed $fn */
    private function refused(callable $fn): string
    {
        try {
            $fn();
        } catch (HttpException $e) {
            return 'http.' . $e->httpStatus;
        } catch (BusinessException $e) {
            return $e->messageKey;
        }
        self::fail('expected a refusal');
    }

    private function notes(string $user, string $key): int
    {
        return (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title_key = ?', [$this->t->user($user)['id'], $key]);
    }

    /** @param array<string, mixed> $over */
    private function report(string $user, array $over = [], array $files = []): array
    {
        return $this->sf()->report($this->az($user), $over + ['kind' => 'near_miss', 'severity' => '2', 'occurred_at' => '2026-11-09T08:15', 'location' => 'ไลน์ผลิต 2 หน้าเครื่องอัด',
            'description' => 'รถยกถอยหลังเกือบชนพนักงานที่เดินผ่าน', 'immediate_action' => 'หยุดรถและกั้นทางเดิน', 'people' => 'สมชาย (คนขับรถยก), สมหญิง (พยาน)'], $files);
    }

    public function testReportTriageInvestigationActionsVerificationAndReopen(): void
    {
        $s = $this->sf();
        $db = $this->t->app->db();
        $ga = $this->az('ga.demo1');
        $emp = $this->az('employee.demo1');
        $le = $this->le();
        // G9E1-01 the form
        $e = $this->errors(fn () => $s->report($emp, ['kind' => 'fire', 'severity' => '9', 'occurred_at' => '2026-11-09T12:00', 'location' => 'x', 'description' => 'สั้น', 'anonymous' => '1']));
        self::assertSame(['kind', 'severity', 'occurred_at', 'location', 'description', 'anonymous'], array_keys($e));
        self::assertSame('safety.error.future', $e['occurred_at']);
        self::assertSame(['occurred_at' => 'safety.error.too_old'], $this->errors(fn () => $this->report('employee.demo1', ['occurred_at' => '2025-10-01T08:00'])));
        // G9E1-02 a level 2 report reaches the safety team now; level 3 also reaches the company's list for level 3
        $s->saveSettings($ga, $le, ['note_3' => 'ต้องพบแพทย์', 'escalate_3' => 'manager.demo1', 'escalate_4' => 'manager.demo1, hr.head1']);
        self::assertSame(['escalate_3' => 'safety.error.unknown_user'], $this->errors(fn () => $s->saveSettings($ga, $le, ['escalate_3' => 'nobody.here'])));
        self::assertSame('http.403', $this->refused(fn () => $s->saveSettings($emp, $le, [])));
        $one = $this->report('employee.demo1', [], [$this->png()]);
        self::assertSame('SF2611-0002', $one['ref']);
        self::assertNull($one['code']);
        self::assertSame(1, $this->notes('ga.demo1', 'safety.notify.reported'));
        self::assertSame(0, $this->notes('employee.demo1', 'safety.notify.reported'));
        $two = $this->report('employee.demo1', ['kind' => 'injury', 'severity' => '3', 'occurred_at' => '2026-11-07T14:00', 'description' => 'ลื่นล้มที่ทางเดินหน้าคลัง ข้อเท้าพลิก']);
        self::assertSame(1, $this->notes('ga.demo1', 'safety.notify.urgent'));
        self::assertSame(1, $this->notes('manager.demo1', 'safety.notify.urgent'));
        self::assertSame(0, $this->notes('hr.head1', 'safety.notify.urgent'));
        // G9E1-03 who sees what: the reporter sees the report without the people; others 404; the auditor sees all
        $mine = $s->incident($emp, $one['pid']);
        self::assertNull($mine['people']);
        self::assertCount(1, $mine['files']);
        self::assertSame('http.404', $this->refused(fn () => $s->incident($this->az('manager.demo1'), $one['pid'])));
        $aud = $s->incident($this->az('auditor.demo'), $one['pid']);
        self::assertStringContainsString('สมหญิง', (string) $aud['people']);
        self::assertSame('employee.demo1', $aud['reporter']);
        self::assertSame(2, $s->board($le)['company']); // the injury on Sat 7th
        // G9E1-04 triage: raising a level to 3 alerts at once; repeat of an earlier report; assign by employee code
        self::assertSame(['related' => 'safety.error.unknown_ref'], $this->errors(fn () => $s->triage($ga, $one['pid'], ['kind' => 'near_miss', 'severity' => '3', 'related' => 'SF9999-0001'])));
        $s->triage($ga, $one['pid'], ['kind' => 'near_miss', 'severity' => '3', 'related' => 'SF2611-0001']);
        self::assertSame(2, $this->notes('manager.demo1', 'safety.notify.urgent'));
        self::assertSame('http.403', $this->refused(fn () => $s->triage($emp, $one['pid'], ['kind' => 'near_miss', 'severity' => '1'])));
        self::assertSame(['who' => 'safety.error.unknown_person'], $this->errors(fn () => $s->assign($ga, $one['pid'], 'NO-SUCH')));
        $s->assign($ga, $one['pid'], $this->code('hr.head1'));
        self::assertSame('investigating', $db->scalar('SELECT status FROM sf_incidents WHERE public_id = ?', [$one['pid']]));
        self::assertSame(1, $this->notes('hr.head1', 'safety.notify.assigned'));
        $head = $this->az('hr.head1');
        // G9E1-05 investigation: facts and root cause before actions; witnesses need-to-know; medical details only for safety.medical
        self::assertSame('safety.error.investigate_first', $this->refused(fn () => $s->addAction($head, $one['pid'], ['kind' => 'corrective', 'description' => 'ติดกระจกโค้ง', 'owner' => 'employee.demo1', 'due_on' => '2026-11-16'])));
        self::assertSame(['likelihood' => 'safety.error.scale'], $this->errors(fn () => $s->investigate($head, $one['pid'], ['facts' => 'x', 'likelihood' => '7'])));
        $s->investigate($head, $one['pid'], ['facts' => 'รถยกถอยหลังโดยไม่มีผู้ให้สัญญาณ ทางเดินไม่มีเส้นแบ่ง', 'root_cause' => 'ไม่มีการแยกทางเดินคนกับรถ', 'temp_control' => 'กั้นทางเดิน', 'likelihood' => '3', 'impact' => '4']);
        $s->addWitness($head, $one['pid'], $this->code('manager.demo1'), 'เห็นรถยกถอยหลังมาโดยไม่มีเสียงเตือน');
        self::assertSame(['who' => 'safety.error.name', 'statement' => 'safety.error.statement'], $this->errors(fn () => $s->addWitness($head, $one['pid'], 'x', 'สั้น')));
        self::assertSame('http.403', $this->refused(fn () => $s->addMedical($ga, $two['pid'], ['who' => 'สมชาย', 'injury' => 'ข้อเท้าแพลง'])));
        $s->addMedical($this->az('hr.demo1'), $two['pid'], ['who' => $this->code('employee.demo1'), 'injury' => 'ข้อเท้าซ้ายแพลง', 'body_part' => 'ข้อเท้า', 'days_off' => '2', 'hospital' => '1']);
        $gaView = $s->incident($ga, $two['pid']);
        self::assertSame([], $gaView['medical']);
        self::assertTrue($gaView['has_medical']);
        self::assertCount(1, $s->incident($this->az('hr.demo1'), $two['pid'])['medical']);
        self::assertSame('http.404', $this->refused(fn () => $s->incident($this->az('manager.demo1'), $two['pid'])));
        // G9E1-06 actions: owners see only their own action; verification once all are done
        self::assertSame(['due_on' => 'safety.error.due'], $this->errors(fn () => $s->addAction($head, $one['pid'], ['kind' => 'corrective', 'description' => 'ติดกระจกโค้ง', 'owner' => 'employee.demo1', 'due_on' => '2026-11-01'])));
        $a1 = $s->addAction($head, $one['pid'], ['kind' => 'corrective', 'description' => 'ทาสีเส้นแบ่งทางเดินคนกับรถ', 'owner' => 'employee.demo1', 'due_on' => '2026-11-16', 'external_ref' => 'HD-0042']);
        $a2 = $s->addAction($head, $one['pid'], ['kind' => 'preventive', 'description' => 'ติดเสียงเตือนถอยหลังรถยกทุกคัน', 'owner' => 'manager.demo1', 'due_on' => '2026-11-20']);
        self::assertSame(1, $this->notes('employee.demo1', 'safety.notify.action'));
        $ownerView = $s->incident($emp, $one['pid']);
        self::assertCount(1, $ownerView['actions']);
        self::assertSame([], $ownerView['witnesses']);
        self::assertSame('ไม่มีการแยกทางเดินคนกับรถ', $ownerView['root_cause']);
        $mgrView = $s->incident($this->az('manager.demo1'), $one['pid']);
        self::assertNull($mgrView['people']);
        self::assertCount(1, $mgrView['actions']);
        self::assertSame('http.403', $this->refused(fn () => $s->completeAction($this->az('manager.demo1'), $a1, 'ทำแล้ว', null)));
        self::assertSame(['note' => 'safety.error.note'], $this->errors(fn () => $s->completeAction($emp, $a1, 'ok', null)));
        $s->completeAction($emp, $a1, 'ทาสีเส้นเหลืองแล้ว 40 เมตร', $this->png());
        self::assertSame('action_assigned', $db->scalar('SELECT status FROM sf_incidents WHERE public_id = ?', [$one['pid']]));
        $this->t->clock->advance('+10 days'); // Thu 19 — action 2 is due tomorrow
        $s->tick();
        $s->tick();
        self::assertSame(1, $this->notes('manager.demo1', 'safety.notify.due'));
        $this->t->clock->advance('+2 days'); // Sat 21 — overdue: the owner and the investigator, once
        $s->tick();
        $s->tick();
        self::assertSame(1, $this->notes('manager.demo1', 'safety.notify.overdue'));
        self::assertSame(1, $this->notes('hr.head1', 'safety.notify.overdue'));
        self::assertSame(14, $s->board($le)['company']);
        $s->completeAction($this->az('manager.demo1'), $a2, 'ติดตั้งเสียงเตือนครบ 3 คัน', null);
        self::assertSame('verification', $db->scalar('SELECT status FROM sf_incidents WHERE public_id = ?', [$one['pid']]));
        self::assertSame(1, $this->notes('ga.demo1', 'safety.notify.verify'));
        // G9E1-07 verification: not by the investigator; not effective → back to actions; effective → closed, reporter told
        self::assertSame('http.403', $this->refused(fn () => $s->verify($head, $one['pid'], true, 'ตรวจแล้วได้ผลดี')));
        self::assertSame(['note' => 'safety.error.outcome'], $this->errors(fn () => $s->verify($ga, $one['pid'], true, 'ok')));
        $s->verify($ga, $one['pid'], false, 'ยังมีคนเดินตัดเส้นทางรถ');
        self::assertSame('action_assigned', $db->scalar('SELECT status FROM sf_incidents WHERE public_id = ?', [$one['pid']]));
        self::assertSame(1, $this->notes('hr.head1', 'safety.notify.not_effective'));
        $a3 = $s->addAction($ga, $one['pid'], ['kind' => 'preventive', 'description' => 'ติดราวกั้นทางเดิน', 'owner' => 'ga.demo1', 'due_on' => '2026-11-30']);
        $s->completeAction($ga, $a3, 'ติดราวกั้นแล้ว', null);
        $s->verify($ga, $one['pid'], true, 'ตรวจหน้างาน 1 สัปดาห์ ไม่มีคนเดินตัดเส้นทางรถ');
        $closed = $s->incident($emp, $one['pid']);
        self::assertSame(['closed', 'done'], [$closed['status'], $closed['closed_reason']]);
        self::assertSame(1, $this->notes('employee.demo1', 'safety.notify.closed'));
        // G9E1-08 reopen, communication log, timeline
        $s->reopen($ga, $one['pid'], 'เกิดเหตุคล้ายกันอีกครั้ง');
        $s->assign($ga, $one['pid'], 'hr.head1');
        self::assertSame('investigating', $db->scalar('SELECT status FROM sf_incidents WHERE public_id = ?', [$one['pid']]));
        self::assertSame(['sent_on' => 'safety.error.date'], $this->errors(fn () => $s->addComm($ga, $two['pid'], ['sent_on' => '2026-12-31', 'recipient' => 'ประกันภัย'])));
        $s->addComm($ga, $two['pid'], ['sent_on' => '2026-11-10', 'recipient' => 'บริษัทประกันภัย', 'channel' => 'อีเมล', 'reference' => 'CLM-1']);
        $full = $s->incident($ga, $one['pid']);
        self::assertSame(['reported', 'triaged', 'assigned', 'investigation_saved', 'witness_added', 'action_added', 'action_added', 'action_done', 'action_done', 'not_effective', 'action_added', 'action_done', 'verified', 'reopened', 'assigned'], array_column($full['events'], 'event'));
        self::assertSame('SF2611-0001', $full['related']['ref']);
        self::assertGreaterThanOrEqual(15, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action LIKE 'safety.%'"));
    }

    public function testAnonymousReportsClosingFiguresAndScreens(): void
    {
        $s = $this->sf();
        $db = $this->t->app->db();
        $ga = $this->az('ga.demo1');
        $emp = $this->az('employee.demo1');
        $le = $this->le();
        // G9E1-09 anonymous: no name, the day only, a code shown once; low levels reach the team the next morning
        $s->saveSettings($ga, $le, ['anonymous_on' => '1']);
        self::assertSame(['files' => 'safety.error.anonymous_files'], $this->errors(fn () => $this->report('employee.demo1', ['anonymous' => '1'], [$this->png()])));
        $anon = $this->report('employee.demo1', ['anonymous' => '1', 'severity' => '1', 'kind' => 'hazard', 'description' => 'สายไฟพาดผ่านทางเดินในห้องประชุม']);
        self::assertMatchesRegularExpression('/^[0-9A-Z]{4}-[0-9A-F]{6}$/', (string) $anon['code']);
        $row = $db->one('SELECT * FROM sf_incidents WHERE public_id = ?', [$anon['pid']]);
        self::assertNull($row['reporter_id']);
        self::assertNull($row['created_by']);
        self::assertSame('00:00:00.000000', substr((string) $row['created_at'], 11));
        self::assertSame(hash('sha256', (string) $anon['code']), $row['track_hash']);
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'safety.reported' AND target_id = ?", [$anon['pid']]));
        self::assertSame(0, $this->notes('ga.demo1', 'safety.notify.reported'));
        $s->tick();
        self::assertSame(0, $this->notes('ga.demo1', 'safety.notify.digest'));
        self::assertNotContains($anon['pid'], array_column($s->mine($emp)['reports'], 'public_id')); // only the demo hazard
        $this->t->clock->advance('+1 day');
        $s->tick();
        $s->tick();
        self::assertSame(1, $this->notes('ga.demo1', 'safety.notify.digest'));
        self::assertSame('reported', $s->track(strtolower((string) $anon['code']))['status']);
        self::assertNull($s->track('ABCD-123456'));
        // a level 4 anonymous report alerts at once (the form says so)
        $s->saveSettings($ga, $le, ['anonymous_on' => '1', 'escalate_4' => 'hr.head1']);
        $this->report('employee.demo1', ['anonymous' => '1', 'severity' => '4', 'kind' => 'injury', 'occurred_at' => '2026-11-10T08:30', 'description' => 'พนักงานตกจากนั่งร้าน หมดสติ']);
        self::assertSame(1, $this->notes('hr.head1', 'safety.notify.urgent'));
        self::assertSame(0, $s->board($le)['company']);
        // G9E1-10 closing at triage: not an incident (the reporter's code shows why); it leaves the figures and the board
        self::assertSame(['reason' => 'safety.error.choice', 'note' => 'safety.error.note'], $this->errors(fn () => $s->reject($ga, $anon['pid'], 'other', '')));
        $s->reject($ga, $anon['pid'], 'not_incident', 'สายไฟอยู่ในรางครอบแล้ว ไม่ใช่จุดเสี่ยง');
        $tr = $s->track((string) $anon['code']);
        self::assertSame(['closed', 'not_incident'], [$tr['status'], $tr['closed_reason']]);
        self::assertSame('safety.error.wrong_status', $this->refused(fn () => $s->assign($ga, $anon['pid'], 'hr.head1')));
        $named = $this->report('employee.demo1');
        $s->reject($ga, $named['pid'], 'duplicate', 'ซ้ำกับรายงานก่อนหน้า');
        self::assertSame(1, $this->notes('employee.demo1', 'safety.notify.closed'));
        // G9E1-11 figures (12 months, without "not an incident" / duplicate)
        $st = $s->stats($ga, $le);
        self::assertSame(['injury' => 1, 'damage' => 0, 'near_miss' => 0, 'hazard' => 1], $st['months']['2026-11']);
        self::assertSame([1 => 0, 2 => 1, 3 => 0, 4 => 1], $st['levels']);
        self::assertSame(['reported' => 2], $st['open']);
        self::assertSame('http.403', $this->refused(fn () => $s->stats($emp, $le)));
        self::assertCount(2, $s->manageList($ga, $le, []));
        self::assertCount(4, $s->manageList($ga, $le, ['status' => 'all']));
        self::assertCount(1, $s->manageList($ga, $le, ['status' => 'all', 'severity' => '4']));

        // G9E1-12 screens
        $g = $this->t->browser();
        $g->login('ga.demo1');
        foreach (['/safety', '/safety/report', '/safety/manage', '/safety/stats', '/safety/settings', '/safety/i/' . $anon['pid'], '/safety/i/' . $named['pid']] as $path) {
            $res = $g->get($path);
            self::assertSame(200, $res->status, $path);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body)), $path);
        }
        self::assertSame(200, $g->get('/safety/manage', ['status' => 'all'])->status);
        self::assertSame(422, $g->post('/safety/settings', ['escalate_3' => 'nobody.here'])->status);
        $e1 = $this->t->browser();
        $e1->login('employee.demo1');
        foreach (['/safety', '/safety/report', '/safety/track', '/safety/i/' . $named['pid']] as $path) {
            $res = $e1->get($path);
            self::assertSame(200, $res->status, $path);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body)), $path);
        }
        self::assertSame(422, $e1->post('/safety/report', ['kind' => 'hazard'])->status);
        $sent = $e1->post('/safety/report', ['kind' => 'hazard', 'severity' => '1', 'occurred_at' => '2026-11-10T08:00', 'location' => 'ลานจอดรถ', 'description' => 'ไฟส่องสว่างดับสองดวง', 'anonymous' => '1']);
        self::assertSame(200, $sent->status);
        self::assertMatchesRegularExpression('/[0-9A-Z]{4}-[0-9A-F]{6}/', $sent->body);
        $ok = $e1->post('/safety/report', ['kind' => 'hazard', 'severity' => '2', 'occurred_at' => '2026-11-10T08:00', 'location' => 'ลานจอดรถ', 'description' => 'ฝาท่อระบายน้ำแตก'], ['files' => ['hole.png', (string) file_get_contents($this->png()->path)]]);
        self::assertSame(303, $ok->status);
        preg_match('/[0-9A-Z]{4}-[0-9A-F]{6}/', $sent->body, $m);
        $tr = $e1->post('/safety/track', ['code' => $m[0]]);
        self::assertSame(200, $tr->status);
        self::assertStringContainsString('role="status"', $tr->body);
        self::assertSame(403, $e1->get('/safety/manage')->status);
        self::assertSame(403, $e1->get('/safety/settings')->status);
        self::assertSame(404, $e1->get('/safety/i/' . $anon['pid'])->status);
    }
}
