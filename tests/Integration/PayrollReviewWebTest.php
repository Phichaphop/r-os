<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Support\Ulid;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/**
 * 0.49.0 (R8-4, DEC-118 · Q-11): the Payroll Checker reviews approved runs afterwards, one run at a time, listed by period.
 * The runs here are fixtures written the way an approved run looks (status approved, maker/approver, result hash) —
 * calculating and approving runs has its own tests (PayrollWebTest). No amounts are involved.
 */
final class PayrollReviewWebTest extends TestCase
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

    private function as(string $user): TestBrowser
    {
        $b = $this->t->browser();
        $b->login($user);
        return $b;
    }

    private function payRun(int $no, string $type, string $from, string $to, string $status = 'approved'): string
    {
        $db = $this->t->app->db();
        $pid = Ulid::generate($this->t->clock);
        $now = $this->t->clock->now()->format('Y-m-d H:i:s.u');
        $maker = (int) $this->t->user('payroll.maker')['id'];
        $checker = (int) $this->t->user('payroll.checker')['id'];
        $db->run(
            "INSERT INTO pay_runs (public_id, created_at, created_by, updated_at, legal_entity_id, run_no, run_type, label, period_start, period_end, pay_date, status,
                calc_hash, calculated_by, submitted_by, approved_by, approved_at, employee_count)
             VALUES (?, ?, ?, ?, (SELECT id FROM legal_entities WHERE code = 'DEMO1'), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 3)",
            [$pid, $now, $maker, $now, $no, $type, 'TEST RUN ' . $no, $from, $to, $to, $status, substr(str_repeat((string) $no, 22), 0, 64), $maker, $maker,
                $status === 'approved' ? $checker : null, $status === 'approved' ? $now : null]
        );
        return $pid;
    }

    public function testCheckerReviewsApprovedRunsOneByOneAndKeepsHistory(): void
    {
        $db = $this->t->app->db();
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM user_role_assignments a JOIN roles r ON r.id = a.role_id JOIN users u ON u.id = a.user_id
            WHERE r.code = 'PYC' AND u.username = 'auditor.demo' AND a.status = 'active'"), 'demo Payroll Checker');
        self::assertContains('payroll.review', array_column($db->all("SELECT rp.permission_code FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.code = 'PYC'"), 'permission_code'));
        self::assertNotContains('payroll.review', array_column($db->all("SELECT rp.permission_code FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.code IN ('PYO', 'PYA')"), 'permission_code'));

        $oct = $this->payRun(901, 'regular', '2026-10-01', '2026-10-31');
        $bonus = $this->payRun(902, 'off_cycle', '2026-10-01', '2026-10-31');
        $sep = $this->payRun(903, 'regular', '2026-09-01', '2026-09-30');
        $draft = $this->payRun(904, 'regular', '2026-11-01', '2026-11-30', 'draft');

        $maker = $this->as('payroll.maker');
        $list = $maker->get('/payroll/reviews');
        self::assertSame(200, $list->status);
        self::assertStringContainsString('TEST RUN 901', $list->body);
        self::assertStringNotContainsString('TEST RUN 904', $list->body, 'only approved runs wait for review');
        self::assertSame(2, substr_count($list->body, 'class="card stack-sm" aria-labelledby="rv-'), 'two pay periods');
        self::assertLessThan(strpos($list->body, 'TEST RUN 903'), strpos($list->body, 'TEST RUN 901'), 'newest period first');
        self::assertStringNotContainsString('name="result"', $maker->get('/payroll/runs/' . $oct)->body, 'the maker cannot review');
        $maker->get('/payroll/runs/' . $oct);
        $maker->post('/payroll/runs/' . $oct . '/review', ['result' => 'ok']);
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM pay_run_reviews'), 'no permission → nothing recorded');

        $approver = $this->as('payroll.checker');
        $approver->get('/payroll/runs/' . $oct);
        self::assertSame(403, $approver->post('/payroll/runs/' . $oct . '/review', ['result' => 'ok'])->status, 'the approver holds no review permission');

        $pyc = $this->as('auditor.demo');
        $page = $pyc->get('/payroll/runs/' . $oct);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('name="result"', $page->body);
        $bad = $pyc->post('/payroll/runs/' . $oct . '/review', ['result' => 'issues', 'note' => 'x']);
        self::assertSame(422, $bad->status, 'issues need a note');
        self::assertStringContainsString('field__error', $bad->body);
        self::assertSame(422, $pyc->post('/payroll/runs/' . $oct . '/review', ['result' => 'maybe'])->status);
        $pyc->get('/payroll/runs/' . $oct);
        self::assertSame(303, $pyc->post('/payroll/runs/' . $oct . '/review', ['result' => 'issues', 'note' => 'D1-0003 ค่าตำแหน่งไม่ตรงคำสั่ง — ทำรอบแก้ไข'])->status);
        $pyc->get('/payroll/runs/' . $oct);
        self::assertSame(303, $pyc->post('/payroll/runs/' . $oct . '/review', ['result' => 'ok', 'note' => 'ตรวจรอบแก้ไขแล้ว'])->status);
        $pyc->get('/payroll/runs/' . $bonus);
        self::assertSame(303, $pyc->post('/payroll/runs/' . $bonus . '/review', ['result' => 'ok'])->status, 'the off-cycle run is reviewed on its own');
        $pyc->get('/payroll/runs/' . $draft);
        $pyc->post('/payroll/runs/' . $draft . '/review', ['result' => 'ok']);

        self::assertSame(['issues', 'ok'], array_column($db->all('SELECT v.result FROM pay_run_reviews v JOIN pay_runs r ON r.id = v.run_id WHERE r.public_id = ? ORDER BY v.id', [$oct]), 'result'), 'history kept');
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM pay_run_reviews v JOIN pay_runs r ON r.id = v.run_id WHERE r.public_id = ?', [$draft]), 'a draft cannot be reviewed');
        self::assertSame(substr(str_repeat('901', 22), 0, 64), (string) $db->scalar('SELECT v.calc_hash FROM pay_run_reviews v JOIN pay_runs r ON r.id = v.run_id WHERE r.public_id = ? LIMIT 1', [$oct]), 'the hash of the reviewed result');
        $audit = $db->all("SELECT diff_json FROM audit_events WHERE action = 'payroll.run_reviewed'");
        self::assertCount(3, $audit);
        foreach ($audit as $a) {
            self::assertStringNotContainsString('D1-0003', (string) $a['diff_json'], 'no note in the audit');
        }
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM pay_run_events e JOIN pay_runs r ON r.id = e.run_id WHERE r.public_id = ? AND e.action = 'reviewed:issues' AND e.note IS NULL", [$oct]));

        $run = $pyc->get('/payroll/runs/' . $oct);
        self::assertStringContainsString('D1-0003 ค่าตำแหน่งไม่ตรงคำสั่ง', $run->body);
        $waiting = $pyc->get('/payroll/reviews', ['state' => 'waiting']);
        self::assertStringContainsString('TEST RUN 903', $waiting->body);
        self::assertStringNotContainsString('TEST RUN 901', $waiting->body);
        self::assertStringContainsString('(1)', $waiting->body);
        self::assertStringNotContainsString('payroll.review.', $waiting->body, 'translated');
    }

    public function testListWarnsUntilACheckerIsAppointed(): void
    {
        $db = $this->t->app->db();
        $this->payRun(905, 'regular', '2026-10-01', '2026-10-31');
        $db->run("UPDATE user_role_assignments SET status = 'revoked' WHERE role_id = (SELECT id FROM roles WHERE code = 'PYC')");
        $body = $this->as('payroll.checker')->get('/payroll/reviews')->body;
        self::assertStringContainsString('TEST RUN 905', $body, 'approved runs wait for a checker');
        self::assertStringContainsString($this->t->app->translator()->get('payroll.review.none_title'), $body);
    }
}
