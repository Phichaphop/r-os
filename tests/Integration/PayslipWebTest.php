<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Money;
use Blue\Core\Support\Ulid;
use Blue\Modules\Payroll\PayrollService;
use Blue\Modules\Payroll\PayslipService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/**
 * Phase 6B payslips + year-to-date (DEC-84). Clock: Mon 2026-11-09. Rule values are TEST VALUES ONLY (same as the 6A
 * test: divisor 30, 8 h, calendar days, daily = worked + holiday; social security 3.5 % / 4 % of 1 000–20 000, to the baht).
 */
final class PayslipWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(payroll|workflow|config|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

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

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    private function empPid(string $code): string
    {
        return (string) $this->t->app->db()->scalar('SELECT public_id FROM employees WHERE employee_code = ?', [$code]);
    }

    private function decide(string $subjectId, string $user): void
    {
        $inst = (string) $this->t->app->db()->scalar('SELECT public_id FROM workflow_instances WHERE subject_id = ? ORDER BY id DESC LIMIT 1', [$subjectId]);
        $this->t->app->workflow()->decide($inst, (int) $this->t->user($user)['id'], 'approve', 'ตรวจแล้ว');
    }

    private function reauth(TestBrowser $b, string $next): void
    {
        $b->get('/reauth');
        $b->post('/reauth', ['password' => $this->t->demoPassword, 'next' => $next]);
    }

    /** rules (test values), a finalized October time period (fixture) and an approved October run */
    private function approvedRun(): string
    {
        $app = $this->t->app;
        $svc = PayrollService::of($app);
        $le = $this->le();
        $maker = $this->az('payroll.maker');
        $pol = $svc->proposeRules(
            $maker,
            $le,
            'payroll.policy',
            ['monthly_divisor' => '30', 'hours_per_day' => '8', 'proration' => 'calendar_days', 'daily_paid' => ['worked_days', 'holiday_days']],
            '2026-01-01',
            'ข้อบังคับการทำงาน (ค่าทดสอบ)',
            'ทดสอบ'
        );
        $sso = $svc->proposeRules(
            $maker,
            $le,
            'payroll.sso',
            ['rate_employee' => '3.5', 'rate_employer' => '4', 'wage_floor' => '1000', 'wage_cap' => '20000', 'rounding' => 'half_up_baht'],
            '2026-01-01',
            'ค่าทดสอบเท่านั้น ไม่ใช่อัตราจริง',
            'ทดสอบ'
        );
        $this->decide($pol, 'payroll.checker');
        $this->decide($sso, 'payroll.checker');
        $lines = [];
        foreach (['D1-0001', 'D1-0002', 'D1-0003', 'D1-0004', 'D1-0005', 'D1-0006', 'D1-0007'] as $c) {
            $lines[] = ['employee_code' => $c, 'name' => $c, 'scheduled_days' => 22, 'worked_days' => 22, 'absent_days' => 0, 'missing_punch_days' => 0, 'late_count' => 0, 'late_minutes' => 0,
                'early_count' => 0, 'early_minutes' => 0, 'worked_minutes' => 22 * 480, 'leave_days' => 0, 'leave' => [], 'holiday_days' => 1, 'off_days' => 8, 'no_data_days' => 0, 'corrected_days' => 0];
        }
        $out = ['lines' => $lines, 'leave_types' => []];
        $calc = $app->ledger()->record('attendance.period', 'TEST', 'TEST', $app->clock->now(), [], [], [], $out, ['legal_entity_id' => $le]);
        $now = $app->clock->now()->format('Y-m-d H:i:s.u');
        $app->db()->run(
            "INSERT INTO att_periods (public_id, created_at, updated_at, legal_entity_id, period_start, period_end, status, revision, snapshot_run_pid, output_hash) VALUES (?, ?, ?, ?, '2026-10-01', '2026-10-31', 'finalized', 1, ?, 'x')",
            [Ulid::generate($app->clock), $now, $now, $le, $calc]
        );
        $run = $svc->createRun($maker, $le, ['label' => 'เงินเดือน ต.ค. 2569 (ทดสอบ)', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-10-31']);
        $svc->calculate($maker, $run);
        $svc->submit($maker, $run, '');
        $this->decide($run, 'payroll.checker');
        self::assertSame('approved', $app->db()->scalar('SELECT status FROM pay_runs WHERE public_id = ?', [$run]));
        return $run;
    }

    public function testOpeningAmountsYearToDateAndPayslips(): void
    {
        $app = $this->t->app;
        $db = $app->db();
        $slips = PayslipService::of($app);
        $maker = $this->az('payroll.maker');
        $checker = $this->az('payroll.checker');
        $e4 = $this->empPid('D1-0004');
        $id4 = (int) $db->scalar('SELECT id FROM employees WHERE public_id = ?', [$e4]);

        // opening amounts (company starts mid-year): checked, then another person approves
        try {
            $slips->proposeOpening($maker, $e4, ['tax_year' => '2026', 'as_of' => '2025-12-31', 'gross' => '', 'taxable_income' => '-1', 'wht' => '', 'sso_employee' => '', 'note' => 'x']);
            self::fail('validation');
        } catch (ValidationException $e) {
            self::assertSame(['as_of', 'taxable_income', 'note'], array_keys($e->errors));
        }
        $open = $slips->proposeOpening($maker, $e4, ['tax_year' => '2026', 'as_of' => '2026-09-30', 'gross' => '166,500', 'taxable_income' => '166500', 'wht' => '1200', 'sso_employee' => '5250',
            'note' => 'ทะเบียนเงินเดือนโปรแกรมเดิม ม.ค.–ก.ย. (ทดสอบ)']);
        try {
            $slips->proposeOpening($maker, $e4, ['tax_year' => '2026', 'as_of' => '2026-09-30', 'gross' => '1', 'note' => 'ซ้ำ ซ้ำ']);
            self::fail('one pending at a time');
        } catch (BusinessException $e) {
            self::assertSame('payroll.error.opening_pending', $e->messageKey);
        }
        try {
            $slips->decideOpening($maker, $open, true);
            self::fail('the maker cannot approve');
        } catch (\Blue\Core\Error\HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'payroll.opening' AND user_id = ?", [$this->t->user('payroll.checker')['id']]));
        self::assertSame(0, $slips->ytd($id4, $this->le(), 2026, '2026-12-31')['gross'], 'not counted while waiting');
        $page = $this->as('payroll.checker')->get('/payroll/people/' . $e4);
        self::assertStringContainsString('อนุมัติยอดยกมา', $page->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page->body));
        $slips->decideOpening($checker, $open, true);

        // an approved run adds one entry per person; opening amounts can no longer go before it
        $run = $this->approvedRun();
        self::assertSame(7, (int) $db->scalar("SELECT COUNT(*) FROM pay_ytd_entries WHERE source_type = 'run' AND source_ref = ?", [$run]));
        self::assertSame(0, $slips->ytdFromRun($db->one('SELECT * FROM pay_runs WHERE public_id = ?', [$run]) ?? []), 'written once');
        // G6B-01 D1-0004: opening 166 500 + October 18 500 = 185 000; tax 1 200 (opening only); SSO 5 250 + 648 (18 500 × 3.5 % = 647.50 → 648)
        $ytd = $slips->ytd($id4, $this->le(), 2026, '2026-10-31');
        self::assertSame(['gross' => '185000.00', 'taxable' => '185000.00', 'wht' => '1200.00', 'sso' => '5898.00'], array_map(static fn (int $u): string => Money::format($u, 2, false), $ytd));
        self::assertSame(166500 * 10000, $slips->ytd($id4, $this->le(), 2026, '2026-09-30')['gross'], 'as of a date');
        try {
            $slips->proposeOpening($maker, $this->empPid('D1-0005'), ['tax_year' => '2026', 'as_of' => '2026-10-31', 'gross' => '1000', 'note' => 'มาหลังรอบ (ทดสอบ)']);
            self::fail('after an approved run');
        } catch (ValidationException $e) {
            self::assertSame('payroll.error.opening_after_run', $e->errors['as_of']);
        }

        // publish: payroll officer only; one encrypted PDF per person; people with an account are notified (no figures)
        try {
            $slips->publish($checker, $run);
            self::fail('the approver does not publish');
        } catch (\Blue\Core\Error\HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        self::assertSame(['published' => 7, 'notified' => 4], $slips->publish($maker, $run));
        self::assertSame(['published' => 0, 'notified' => 0], $slips->publish($maker, $run), 'once per person');
        $slip = $db->one('SELECT s.* FROM pay_payslips s WHERE s.employee_id = ?', [$id4]) ?? [];
        $doc = $db->one('SELECT d.*, v.encrypted FROM documents d JOIN document_versions v ON v.document_id = d.id WHERE d.public_id = ?', [$slip['document_pid']]) ?? [];
        self::assertSame(['payroll.payslip', 'R', 1, 'final'], [$doc['document_class'], $doc['classification'], (int) $doc['encrypted'], $doc['lifecycle_status']]);
        $note = $db->one("SELECT * FROM notifications WHERE event_type = 'payroll.payslip' AND user_id = ?", [$this->t->user('employee.demo1')['id']]) ?? [];
        self::assertSame('/me/payslips/' . $slip['public_id'], $note['link']);
        foreach (['17852', '17,852', '18500', '18,500'] as $needle) {
            self::assertStringNotContainsString($needle, (string) json_encode($note, JSON_UNESCAPED_UNICODE), 'no figures in the notification');
        }
        $runPage = $this->as('payroll.maker')->get('/payroll/runs/' . $run);
        self::assertStringContainsString('เผยแพร่แล้ว 7 จาก 7 คน', $runPage->body);

        // the employee: list without figures; figures only after the password; logged without amounts
        $emp = $this->as('employee.demo1');
        $list = $emp->get('/me/payslips');
        self::assertSame(200, $list->status);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($list->body));
        self::assertStringNotContainsString('17,852', $list->body);
        $x = $emp->get('/me/payslips/' . $slip['public_id']);
        self::assertSame(302, $x->status);
        self::assertStringContainsString('/reauth', (string) ($x->headers['Location'] ?? ''));
        $this->reauth($emp, '/me/payslips/' . $slip['public_id']);
        $view = $emp->get('/me/payslips/' . $slip['public_id']);
        self::assertSame(200, $view->status);
        self::assertStringContainsString('17,852.00', $view->body, 'net 18 500 − 648');
        self::assertStringContainsString('185,000.00', $view->body, 'year to date');
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($view->body));
        self::assertNotNull($db->scalar('SELECT first_viewed_at FROM pay_payslips WHERE id = ?', [$slip['id']]));
        $pdf = $emp->get('/me/payslips/' . $slip['public_id'] . '/pdf');
        self::assertSame(200, $pdf->status);
        self::assertStringStartsWith('%PDF', $pdf->body);
        self::assertSame($slip['sha256'], hash('sha256', $pdf->body));
        $audit = implode("\n", array_map(static fn (array $a): string => (string) ($a['diff_json'] ?? '') . ' ' . ($a['reason'] ?? ''), $db->all("SELECT * FROM audit_events WHERE action LIKE 'payroll.payslip%'")));
        self::assertStringNotContainsString('17852', $audit);
        self::assertStringNotContainsString('18500', $audit);
        self::assertGreaterThanOrEqual(2, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action IN ('payroll.payslip_viewed','payroll.payslip_downloaded') AND target_id = ?", [$slip['public_id']]));

        // the person's own data export (DEC-82) now has a payroll part
        $pkg = (array) json_decode(\Blue\Core\Privacy\PrivacyService::of($app)->package((int) $this->t->user('employee.demo1')['id'], 'self'), true);
        $sec = array_values(array_filter((array) $pkg['sections'], static fn (array $x): bool => $x['module'] === 'payroll' && $x['section'] === 'payslips'));
        self::assertSame('17852.00', $sec[0]['rows'][0]['net'] ?? null);

        // someone else's payslip does not exist for them (before and after the password); HR cannot open it either
        $mgr = $this->as('manager.demo1');
        self::assertSame(404, $mgr->get('/me/payslips/' . $slip['public_id'])->status);
        $this->reauth($mgr, '/me/payslips');
        self::assertSame(404, $mgr->get('/me/payslips/' . $slip['public_id'])->status);
        self::assertSame(404, $mgr->get('/me/payslips/' . $slip['public_id'] . '/pdf')->status);
        $hr = $this->as('hr.demo1');
        $this->reauth($hr, '/me/payslips');
        self::assertSame(404, $hr->get('/payroll/payslips/' . $slip['public_id'] . '/pdf')->status);
        // payroll can download it (password first)
        $ck = $this->as('payroll.checker');
        self::assertSame(302, $ck->get('/payroll/payslips/' . $slip['public_id'] . '/pdf')->status);
        $this->reauth($ck, '/payroll/runs/' . $run);
        self::assertStringStartsWith('%PDF', $ck->get('/payroll/payslips/' . $slip['public_id'] . '/pdf')->body);

        // a damaged stored file is refused, not handed out
        $db->run("UPDATE pay_payslips SET sha256 = REPEAT('0', 64) WHERE id = ?", [$slip['id']]);
        $bad = $emp->get('/me/payslips/' . $slip['public_id'] . '/pdf');
        self::assertSame(303, $bad->status);
        self::assertStringContainsString('/me/payslips', (string) ($bad->headers['Location'] ?? ''));
    }
}
