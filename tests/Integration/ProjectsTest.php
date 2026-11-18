<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Modules\Projects\ProjectService;
use Blue\Modules\Projects\TaskService;
use Blue\Tests\Support\TestApp;

/**
 * Phase 9E-2 (DEC-101): M24 project management. Clock: Mon 2026-11-09 09:00 (Asia/Bangkok). DEMO1 employees with accounts:
 * employee.demo1, manager.demo1, hr.demo1, hr.head1; ga.demo1 has no employee record. Demo data already holds PJ2611-0001
 * (manager.demo1 lead, employee.demo1 member).
 */
final class ProjectsTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(projects|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function ps(): ProjectService
    {
        return ProjectService::of($this->t->app);
    }

    private function ts(): TaskService
    {
        return TaskService::of($this->t->app);
    }

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    private function uid(string $user): int
    {
        return (int) $this->t->user($user)['id'];
    }

    private function code(string $user): string
    {
        return (string) $this->t->app->db()->scalar('SELECT e.employee_code FROM employees e JOIN users u ON u.employee_id = e.id WHERE u.username = ?', [$user]);
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
        return (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title_key = ?', [$this->uid($user), $key]);
    }

    private function status(string $pid): string
    {
        return (string) $this->t->app->db()->scalar('SELECT status FROM pj_projects WHERE public_id = ?', [$pid]);
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function charter(array $over = []): array
    {
        return $over + ['name' => 'ย้ายระบบเอกสารขึ้นคลาวด์', 'goal' => 'ลดเวลาค้นหาเอกสารของฝ่ายบัญชีลงครึ่งหนึ่ง', 'scope' => 'เอกสารบัญชีปี 2568–2569', 'starts_on' => '2026-11-09', 'ends_on' => '2026-12-31'];
    }

    public function testCharterTeamApprovalAndPrivacy(): void
    {
        $ps = $this->ps();
        $emp = $this->az('employee.demo1');
        $mgr = $this->az('manager.demo1');
        $head = $this->az('hr.head1');
        // G9E2-01 who may create, the charter form
        self::assertSame('projects.error.employees_only', $this->refused(fn () => $ps->create($this->az('ga.demo1'), $this->charter())));
        $e = $this->errors(fn () => $ps->create($emp, ['name' => 'x', 'goal' => 'สั้น', 'starts_on' => '2026-02-30', 'ends_on' => '2026-11-01', 'budget' => '12,3x', 'approver' => 'employee.demo1']));
        self::assertSame(['name', 'goal', 'starts_on', 'budget', 'approver'], array_keys($e));
        self::assertSame(['ends_on' => 'projects.error.ends_on'], $this->errors(fn () => $ps->create($emp, $this->charter(['ends_on' => '2026-11-08']))));
        // G9E2-02 the creator is the lead; an approver named on the charter
        $pid = $ps->create($emp, $this->charter(['budget' => '150,000.50', 'approver' => $this->code('hr.head1')]));
        $p = $ps->project($emp, $pid);
        self::assertSame(['PJ2611-0002', 'planning', '150000.50'], [$p['ref'], $p['status'], $p['budget']]);
        self::assertTrue($p['roles']['lead']);
        // G9E2-03 only the team (and the approver, read only) can see it — not other leads or office admins
        self::assertSame('http.404', $this->refused(fn () => $ps->project($mgr, $pid)));
        self::assertSame('http.404', $this->refused(fn () => $ps->project($this->az('ga.demo1'), $pid)));
        // 0.37.0 (DEC-102, Q-27): auditors and system administrators read it (projects.oversee) — read only, audited
        $aud = $this->az('auditor.demo');
        $seen = (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'projects.oversee_viewed' AND target_id = ?", [$pid]);
        self::assertSame(['member' => false, 'lead' => false, 'approver' => false, 'oversee' => true], $ps->project($aud, $pid)['roles']);
        self::assertSame($seen + 1, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'projects.oversee_viewed' AND target_id = ?", [$pid]));
        self::assertContains($pid, array_column($ps->mine($aud)['overseeing'], 'public_id'));
        self::assertSame([], $ps->mine($mgr)['overseeing']);
        self::assertSame('http.403', $this->refused(fn () => $ps->invite($aud, $pid, 'manager.demo1')), 'read only');
        $ap = $ps->project($head, $pid);
        self::assertSame(['member' => false, 'lead' => false, 'approver' => true, 'oversee' => false], $ap['roles']);
        self::assertSame('http.403', $this->refused(fn () => $ps->invite($head, $pid, 'manager.demo1')));
        self::assertSame([$pid], array_column($ps->mine($head)['approving'], 'public_id'));
        self::assertCount(1, $ps->mine($mgr)['projects']); // the demo project only
        // G9E2-04 invitations: accept / decline; the lead hears about both
        self::assertSame(['who' => 'projects.error.unknown_person'], $this->errors(fn () => $ps->invite($emp, $pid, 'ga.demo1')));
        $ps->invite($emp, $pid, 'manager.demo1');
        $ps->invite($emp, $pid, $this->code('hr.demo1'));
        self::assertSame(['who' => 'projects.error.already_member'], $this->errors(fn () => $ps->invite($emp, $pid, 'manager.demo1')));
        self::assertSame(1, $this->notes('manager.demo1', 'projects.notify.invited'));
        self::assertSame('http.404', $this->refused(fn () => $ps->project($mgr, $pid)));
        self::assertCount(1, $ps->mine($mgr)['invites']);
        $ps->respond($mgr, $pid, true);
        $ps->respond($this->az('hr.demo1'), $pid, false);
        self::assertSame('http.404', $this->refused(fn () => $ps->respond($this->az('hr.demo1'), $pid, true)));
        self::assertSame([1, 1], [$this->notes('employee.demo1', 'projects.notify.joined'), $this->notes('employee.demo1', 'projects.notify.declined')]);
        self::assertTrue($ps->project($mgr, $pid)['roles']['member']);
        self::assertSame('http.403', $this->refused(fn () => $ps->invite($mgr, $pid, 'hr.demo1')));
        // G9E2-05 start needs the approver (central approval engine): returned → planning; again → approved → active
        $ps->start($emp, $pid);
        self::assertSame('pending_approval', $this->status($pid));
        self::assertSame('projects.error.waiting_approval', $this->refused(fn () => $ps->update($emp, $pid, $this->charter())));
        $wf = (string) $this->t->app->db()->scalar('SELECT workflow_id FROM pj_projects WHERE public_id = ?', [$pid]);
        $this->t->app->workflow()->decide($wf, $this->uid('hr.head1'), 'return', 'ใส่งบประมาณแยกรายการ');
        self::assertSame('planning', $this->status($pid));
        self::assertSame(1, $this->notes('employee.demo1', 'projects.notify.approval_returned'));
        $ps->update($emp, $pid, $this->charter(['budget' => '120000', 'approver' => 'hr.head1']));
        $ps->start($emp, $pid);
        self::assertSame('pending_approval', $this->status($pid));
        $this->t->app->workflow()->decide($wf, $this->uid('hr.head1'), 'approve');
        self::assertSame('active', $this->status($pid));
        self::assertSame(1, $this->notes('employee.demo1', 'projects.notify.approved'));
        self::assertSame(['approver' => 'projects.error.approver_locked'], $this->errors(fn () => $ps->update($emp, $pid, $this->charter())));
        // G9E2-06 leads: one must stay; a member can become a lead
        self::assertSame('projects.error.last_lead', $this->refused(fn () => $ps->setMember($emp, $pid, $this->uid('employee.demo1'), 'remove')));
        $ps->setMember($emp, $pid, $this->uid('manager.demo1'), 'lead');
        self::assertTrue($ps->project($mgr, $pid)['roles']['lead']);
        $ps->setMember($mgr, $pid, $this->uid('employee.demo1'), 'member');
        self::assertFalse($ps->project($emp, $pid)['roles']['lead']);
        $events = array_column($this->t->app->db()->all('SELECT event FROM pj_events WHERE project_id = (SELECT id FROM pj_projects WHERE public_id = ?) ORDER BY id', [$pid]), 'event');
        self::assertSame(['created', 'invited', 'invited', 'joined', 'declined', 'approval_requested', 'approval_returned', 'charter_changed', 'approval_requested', 'started', 'member_lead', 'member_member'], $events);
    }

    public function testTasksDependenciesSubmissionsAndClosing(): void
    {
        $ps = $this->ps();
        $ts = $this->ts();
        $db = $this->t->app->db();
        $mgr = $this->az('manager.demo1');
        $emp = $this->az('employee.demo1');
        $hr = $this->az('hr.demo1');
        // no approver: the lead starts at once
        $pid = $ps->create($mgr, $this->charter(['name' => 'อบรม Excel ให้ฝ่ายขาย']));
        $ps->invite($mgr, $pid, 'employee.demo1');
        $ps->invite($mgr, $pid, 'hr.demo1');
        $ps->respond($emp, $pid, true);
        $ps->respond($hr, $pid, true);
        $ps->start($mgr, $pid);
        self::assertSame('active', $this->status($pid));
        // G9E2-07 tasks: only leads plan; the assignee must be a member; one level of subtasks; no dependency loops
        self::assertSame('http.403', $this->refused(fn () => $ts->create($emp, $pid, ['title' => 'งานของฉันเอง'])));
        self::assertSame(['assignee' => 'projects.error.not_member'], $this->errors(fn () => $ts->create($mgr, $pid, ['title' => 'ทำสไลด์', 'assignee' => 'hr.head1'])));
        $a = $ts->create($mgr, $pid, ['title' => 'ทำสไลด์และแบบฝึกหัด', 'assignee' => 'employee.demo1', 'starts_on' => '2026-11-09', 'due_on' => '2026-11-13', 'criteria' => 'ครบ 3 บท มีเฉลย']);
        $b = $ts->create($mgr, $pid, ['title' => 'จองห้องและส่งคำเชิญ', 'assignee' => 'hr.demo1', 'starts_on' => '2026-11-13', 'due_on' => '2026-11-16', 'deps' => [$a]]);
        $c = $ts->create($mgr, $pid, ['title' => 'บทที่ 1 สูตรพื้นฐาน', 'assignee' => 'employee.demo1', 'parent' => $a, 'due_on' => '2026-11-11']);
        self::assertSame(['parent' => 'projects.error.parent'], $this->errors(fn () => $ts->create($mgr, $pid, ['title' => 'ย่อยของย่อย', 'parent' => $c])));
        self::assertSame(['deps' => 'projects.error.dep_loop'], $this->errors(fn () => $ts->edit($mgr, $a, ['title' => 'ทำสไลด์และแบบฝึกหัด', 'assignee' => 'employee.demo1', 'deps' => [$b]])));
        self::assertSame(['due_on' => 'projects.error.due_before_start'], $this->errors(fn () => $ts->create($mgr, $pid, ['title' => 'ผิดวัน', 'starts_on' => '2026-11-12', 'due_on' => '2026-11-10'])));
        self::assertSame(2, $this->notes('employee.demo1', 'projects.notify.assigned'));
        // G9E2-08 warnings, never blocks
        $warn = array_column($ts->task($hr, $b)['warnings'], 0);
        self::assertSame(['projects.warn.dep_open', 'projects.warn.starts_before_dep'], $warn);
        // G9E2-09 the assignee's own update
        self::assertSame('http.403', $this->refused(fn () => $ts->updateMine($emp, $b, ['progress' => '10'])));
        self::assertSame(['progress' => 'projects.error.progress'], $this->errors(fn () => $ts->updateMine($emp, $a, ['progress' => '100', 'starts_on' => '2026-11-09', 'due_on' => '2026-11-13'])));
        $ts->updateMine($emp, $a, ['status' => 'in_progress', 'progress' => '50', 'starts_on' => '2026-11-09', 'due_on' => '2026-11-13', 'risk' => 'ยังไม่ได้ไฟล์ตัวอย่างจากฝ่ายขาย']);
        self::assertSame(['in_progress', 50], [(string) $db->scalar('SELECT status FROM pj_tasks WHERE public_id = ?', [$a]), (int) $db->scalar('SELECT progress FROM pj_tasks WHERE public_id = ?', [$a])]);
        // G9E2-10 submissions as revisions: returned with feedback, submitted again, accepted
        self::assertSame(['note' => 'projects.error.empty_submission'], $this->errors(fn () => $ts->submit($emp, $a, '', '', [])));
        self::assertSame(['link' => 'projects.error.link'], $this->errors(fn () => $ts->submit($emp, $a, 'ดูได้ที่', 'javascript:alert(1)', [])));
        $pdf = UploadedFile::fromBytes('slides-v1.pdf', (new \Blue\Core\Pdf\SimpleDocument())->title('Excel บทที่ 1')->output(), $this->t->tmp);
        self::assertSame(1, $ts->submit($emp, $a, 'ร่างแรก 3 บท', 'https://example.com/slides', [$pdf]));
        self::assertSame(1, $this->notes('manager.demo1', 'projects.notify.submitted'));
        self::assertSame('projects.error.task_locked', $this->refused(fn () => $ts->submit($emp, $a, 'ซ้ำ', '', [])));
        self::assertSame(['feedback' => 'projects.error.feedback'], $this->errors(fn () => $ts->decide($mgr, $a, false, '')));
        self::assertSame('http.403', $this->refused(fn () => $ts->decide($emp, $a, true, '')));
        $ts->decide($mgr, $a, false, 'บทที่ 3 ยังไม่มีเฉลย');
        self::assertSame(1, $this->notes('employee.demo1', 'projects.notify.returned'));
        self::assertSame(2, $ts->submit($emp, $a, 'เพิ่มเฉลยบทที่ 3 แล้ว', '', []));
        $ts->decide($mgr, $a, true, 'เรียบร้อย');
        $task = $ts->task($emp, $a);
        self::assertSame(['accepted', 100], [$task['status'], (int) $task['progress']]);
        self::assertSame([[2, 'accepted'], [1, 'returned']], array_map(static fn (array $s): array => [(int) $s['rev'], $s['decision']], $task['subs']));
        $fid = (int) $task['subs'][1]['files'][0]['id'];
        self::assertStringStartsWith('%PDF', $ts->file($hr, $a, $fid)['bytes']);
        self::assertSame('http.404', $this->refused(fn () => $ts->file($this->az('hr.head1'), $a, $fid)));
        self::assertSame(['projects.warn.starts_before_dep'], array_column($ts->task($hr, $b)['warnings'], 0));
        // comments: members only
        $ts->comment($hr, $b, 'จองห้อง 3A ได้วันพุธ');
        self::assertSame(1, $this->notes('manager.demo1', 'projects.notify.comment'));
        self::assertSame(['body' => 'projects.error.comment'], $this->errors(fn () => $ts->comment($hr, $b, '')));
        // G9E2-11 figures: progress (accepted = 100), late, workload; reminders
        self::assertSame(33, $ps->progress((int) $db->scalar('SELECT id FROM pj_projects WHERE public_id = ?', [$pid])));
        $this->t->clock->advance('+1 day'); // Tue 10 — task C is due tomorrow
        $ts->tick();
        $ts->tick();
        self::assertSame(1, $this->notes('employee.demo1', 'projects.notify.due'));
        $this->t->clock->advance('+2 days'); // Thu 12 — C is late: the assignee and the lead, once
        $ts->tick();
        $ts->tick();
        self::assertSame([1, 1], [$this->notes('employee.demo1', 'projects.notify.late'), $this->notes('manager.demo1', 'projects.notify.late')]);
        $view = $ps->project($mgr, $pid);
        self::assertSame(1, $view['late']);
        self::assertSame(1, array_values(array_filter($view['workload'], static fn (array $w): bool => $w['late'] === 1))[0]['open']);
        $tl = $ps->timeline((string) $view['starts_on'], (string) $view['ends_on'], $view['tasks']);
        self::assertCount(3, $tl['bars']);
        self::assertSame([0, 5], [$tl['bars'][0]['x'], $tl['bars'][0]['w']]); // A: 9–13 November
        self::assertSame(3, $tl['today']);
        // G9E2-12 closing: every task accepted or cancelled; summary and lessons; reopening needs a reason
        self::assertSame('projects.error.tasks_open', $this->refused(fn () => $ps->complete($mgr, $pid, 'อบรมเสร็จตามแผน 20 คน', 'ควรเตรียมไฟล์ตัวอย่างล่วงหน้า')));
        $ps->setMember($mgr, $pid, $this->uid('hr.demo1'), 'remove');
        self::assertNull($db->scalar('SELECT assignee_id FROM pj_tasks WHERE public_id = ?', [$b]));
        self::assertSame('http.404', $this->refused(fn () => $ps->project($hr, $pid)));
        $ts->cancel($mgr, $b, 'ใช้ห้องประชุมใหญ่แทน');
        $ts->cancel($mgr, $c, 'รวมกับบทที่ 2');
        self::assertSame(['summary' => 'projects.error.summary', 'lessons' => 'projects.error.summary'], $this->errors(fn () => $ps->complete($mgr, $pid, 'สั้น', '')));
        $ps->complete($mgr, $pid, 'อบรมเสร็จตามแผน 20 คน', 'ควรเตรียมไฟล์ตัวอย่างล่วงหน้า');
        self::assertSame('completed', $this->status($pid));
        self::assertSame(1, $this->notes('employee.demo1', 'projects.notify.completed'));
        self::assertSame('projects.error.not_live', $this->refused(fn () => $ts->submit($emp, $a, 'x', '', [])));
        self::assertSame(['reason' => 'projects.error.reason'], $this->errors(fn () => $ps->reopen($mgr, $pid, 'สั้น')));
        $ps->reopen($mgr, $pid, 'ฝ่ายขายขอรอบที่สองสำหรับสาขา');
        $ps->statusUpdate($mgr, $pid, 'at_risk', 'ยังไม่ได้วันอบรมรอบสอง', 'on_hold');
        self::assertSame('on_hold', $this->status($pid));
        $ps->close($mgr, $pid, 'สาขายกเลิกรอบที่สองแล้ว');
        self::assertSame('closed', $this->status($pid));
        $snaps = array_column($db->all('SELECT u.status_to FROM pj_updates u JOIN pj_projects p ON p.id = u.project_id WHERE p.public_id = ? ORDER BY u.id', [$pid]), 'status_to');
        self::assertSame(['active', 'completed', 'active', 'on_hold', 'closed'], $snaps);

        // G9E2-13 screens
        $pid2 = $ps->create($mgr, $this->charter(['name' => 'โครงการทดสอบหน้าจอ']));
        $ps->invite($mgr, $pid2, 'employee.demo1');
        $ps->respond($emp, $pid2, true);
        $ps->start($mgr, $pid2);
        $t1 = $ts->create($mgr, $pid2, ['title' => 'งานทดสอบหน้าจอ', 'assignee' => 'employee.demo1', 'starts_on' => '2026-11-12', 'due_on' => '2026-11-20']);
        $g = $this->t->browser();
        $g->login('manager.demo1');
        foreach (['/projects', '/projects/new', '/projects/t/' . $t1, '/projects/t/' . $a] as $path) {
            $res = $g->get($path);
            self::assertSame(200, $res->status, $path);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body)), $path);
        }
        foreach (['board', 'table', 'timeline', 'team', 'updates'] as $view) {
            $res = $g->get('/projects/p/' . $pid2, ['view' => $view]);
            self::assertSame(200, $res->status, $view);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body)), $view);
        }
        self::assertStringContainsString('<svg', $g->get('/projects/p/' . $pid2, ['view' => 'timeline'])->body);
        self::assertSame(200, $g->get('/projects/p/' . $pid, ['view' => 'updates'])->status);
        self::assertSame(422, $g->post('/projects/new', ['name' => 'x'])->status);
        self::assertSame(422, $g->post('/projects/p/' . $pid2 . '/task', ['title' => ''])->status);
        self::assertSame(303, $g->post('/projects/p/' . $pid2 . '/status', ['health' => 'on_track', 'note' => 'เริ่มตามแผน'])->status);
        $e1 = $this->t->browser();
        $e1->login('employee.demo1');
        self::assertSame(200, $e1->get('/projects/t/' . $t1)->status);
        self::assertSame(303, $e1->post('/projects/t/' . $t1 . '/mine', ['status' => 'in_progress', 'progress' => '30', 'starts_on' => '2026-11-12', 'due_on' => '2026-11-20'])->status);
        self::assertSame(303, $e1->post('/projects/t/' . $t1 . '/submit', ['note' => 'เสร็จแล้ว'], ['files' => ['notes.txt', "งานเสร็จ\n"]])->status);
        self::assertSame(303, $e1->post('/projects/t/' . $t1 . '/comment', ['body' => 'ส่งแล้วครับ'])->status);
        self::assertSame(403, $e1->post('/projects/t/' . $t1 . '/accept', [])->status);
        $h1 = $this->t->browser();
        $h1->login('hr.head1');
        self::assertSame(404, $h1->get('/projects/p/' . $pid2)->status);
        self::assertSame(404, $h1->get('/projects/t/' . $t1)->status);
        self::assertSame(303, $g->post('/projects/t/' . $t1 . '/accept', ['feedback' => ''])->status);
    }
}
