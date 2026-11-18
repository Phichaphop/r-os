<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Money;
use Blue\Core\Support\Ulid;
use Blue\Modules\Payroll\PayCalc;
use Blue\Modules\Payroll\PayrollService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/**
 * Phase 6A payroll engine (DEC-83). Clock: Mon 2026-11-09. Run: DEMO1, 1–31 Oct 2026.
 *
 * The rule values below are TEST VALUES ONLY (deliberately not the real ones): policy divisor 30, 8 h/day, calendar-day
 * proration, daily staff paid for worked + holiday days; social security 3.5 % / 4 %, wage 1 000–20 000, rounded to the
 * baht. Every expected amount is worked out by hand in the comments (G6A-xx = the golden cases of this test).
 * The time period and the OT batches are fixtures written the way the attendance / OT modules freeze them (ledger
 * snapshot, hashed lines) — those modules have their own tests.
 */
final class PayrollWebTest extends TestCase
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

    private function emp(string $code): array
    {
        return $this->t->app->db()->one('SELECT * FROM employees WHERE employee_code = ?', [$code]) ?? [];
    }

    /** approves the open workflow task of a subject as $user (through the central engine) */
    private function decide(string $subjectId, string $user, string $decision = 'approve'): void
    {
        $inst = (string) $this->t->app->db()->scalar('SELECT public_id FROM workflow_instances WHERE subject_id = ? ORDER BY id DESC LIMIT 1', [$subjectId]);
        $this->t->app->workflow()->decide($inst, (int) $this->t->user($user)['id'], $decision, 'ตรวจแล้ว');
    }

    /** a finalized time period the way attendance freezes it (ledger snapshot) — fixture */
    private function timePeriod(array $lines): string
    {
        $app = $this->t->app;
        $out = ['lines' => $lines, 'leave_types' => []];
        $run = $app->ledger()->record('attendance.period', 'TEST', 'TEST', $app->clock->now(), [], [], [], $out, ['legal_entity_id' => $this->le(), 'status' => 'calculated']);
        $pid = Ulid::generate($app->clock);
        $now = $app->clock->now()->format('Y-m-d H:i:s.u');
        $app->db()->run(
            "INSERT INTO att_periods (public_id, created_at, updated_at, legal_entity_id, period_start, period_end, label, status, revision, snapshot_run_pid, output_hash, finalized_at)
             VALUES (?, ?, ?, ?, '2026-10-01', '2026-10-31', 'ต.ค. (ทดสอบ)', 'finalized', 1, ?, ?, ?)",
            [$pid, $now, $now, $this->le(), $run, hash('sha256', (string) json_encode($out, JSON_UNESCAPED_UNICODE)), $now]
        );
        return $pid;
    }

    private static function line(string $code, int $worked, int $absent, int $holiday, float $leave): array
    {
        return ['employee_code' => $code, 'name' => $code, 'scheduled_days' => 22, 'worked_days' => $worked, 'absent_days' => $absent, 'missing_punch_days' => 0, 'late_count' => 0,
            'late_minutes' => 0, 'early_count' => 0, 'early_minutes' => 0, 'worked_minutes' => $worked * 480, 'leave_days' => $leave, 'leave' => [], 'holiday_days' => $holiday,
            'off_days' => 8, 'no_data_days' => 0, 'corrected_days' => 0];
    }

    /** an OT batch the way M33 freezes it (hashed lines) — fixture */
    private function otBatch(int $no, array $lines): string
    {
        $app = $this->t->app;
        $json = (string) json_encode($lines, JSON_UNESCAPED_UNICODE);
        $pid = Ulid::generate($app->clock);
        $now = $app->clock->now()->format('Y-m-d H:i:s.u');
        $app->db()->run(
            'INSERT INTO ot_batches (public_id, created_at, updated_at, legal_entity_id, batch_no, period_start, period_end, line_count, total_minutes, totals_json, lines_json, lines_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $now, $this->le(), $no, '2026-10-01', '2026-10-31', count($lines), array_sum(array_column($lines, 'payable_minutes')), '{}', $json, hash('sha256', $json)]
        );
        return $pid;
    }

    private static function ot(string $code, string $date, string $mult, int $minutes): array
    {
        return ['request_id' => 'X', 'employee_code' => $code, 'employee_name' => $code, 'site' => '', 'department' => '', 'cost_center' => '', 'work_date' => $date, 'day_type' => 'workday',
            'rate_class' => '', 'multiplier' => $mult, 'start_local' => '18:00', 'end_local' => '20:00', 'requested_minutes' => $minutes, 'actual_minutes' => $minutes, 'payable_minutes' => $minutes, 'rules_version' => ''];
    }

    private function amount(string $runPid, string $code, string $component): ?string
    {
        $v = $this->t->app->db()->scalar(
            'SELECT SUM(l.amount) FROM pay_result_lines l JOIN pay_run_employees re ON re.id = l.run_employee_id JOIN pay_runs r ON r.id = re.run_id JOIN employees e ON e.id = re.employee_id
              WHERE r.public_id = ? AND e.employee_code = ? AND l.component_code = ?',
            [$runPid, $code, $component]
        );
        return $v === null ? null : Money::format(Money::fromDb($v), 2, false);
    }

    private function person(string $runPid, string $code): array
    {
        return $this->t->app->db()->one(
            'SELECT re.* FROM pay_run_employees re JOIN pay_runs r ON r.id = re.run_id JOIN employees e ON e.id = re.employee_id WHERE r.public_id = ? AND e.employee_code = ?',
            [$runPid, $code]
        ) ?? [];
    }

    public function testRulesProfilesRunApprovalPaymentAndPosting(): void
    {
        $app = $this->t->app;
        $db = $app->db();
        $svc = PayrollService::of($app);
        $le = $this->le();
        $maker = $this->az('payroll.maker');
        $checker = $this->az('payroll.checker');

        // who sees payroll: Payroll Officer / Approver only — not HR, not employees, not the system admin
        foreach (['payroll.maker', 'payroll.checker'] as $u) {
            foreach (['/payroll', '/payroll/people', '/payroll/components', '/payroll/settings'] as $path) {
                $page = $this->as($u)->get($path);
                self::assertSame(200, $page->status, $u . ' ' . $path);
                self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page->body), $u . ' ' . $path);
            }
        }
        foreach (['hr.demo1', 'employee.demo1', 'hr.demo2', TestApp::ADMIN] as $u) {
            self::assertSame(403, $this->as($u)->get('/payroll')->status, $u);
        }
        // nothing legal is shipped: no rule values, demo components active, demo profiles approved
        self::assertSame(['policy' => null, 'sso' => null, 'wht' => null], $svc->rules($le, '2026-10-31'));
        self::assertSame(9, count($svc->components($le, true)), '8 payroll demo components + WELFARE from the 6D welfare demo');
        self::assertStringContainsString('ยังไม่มี', $this->as('payroll.maker')->get('/payroll')->body);

        // a run before any rule exists: everything that needs a rule is blocking, nothing is guessed
        $tp = $this->timePeriod([self::line('D1-0001', 22, 0, 1, 0), self::line('D1-0002', 22, 0, 1, 0), self::line('D1-0003', 22, 0, 1, 0), self::line('D1-0004', 20, 2, 1, 0),
            self::line('D1-0005', 10, 0, 1, 0), self::line('D1-0006', 22, 0, 1, 0), self::line('D1-0007', 21, 0, 1, 1)]);
        try {
            $svc->createRun($maker, $le, ['label' => 'x', 'period_start' => '2026-10-31', 'period_end' => '2026-10-01', 'pay_date' => '2026-10-31']);
            self::fail('range');
        } catch (ValidationException $e) {
            self::assertSame('payroll.error.range', $e->errors['period_end']);
        }
        try {
            $svc->createRun($checker, $le, ['label' => 'x', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-10-31']);
            self::fail('the approver does not prepare runs');
        } catch (\Blue\Core\Error\HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        $run = $svc->createRun($maker, $le, ['label' => 'เงินเดือน ต.ค. 2569 (ทดสอบ)', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-10-31']);
        self::assertSame($tp, $db->scalar('SELECT p.public_id FROM pay_runs r JOIN att_periods p ON p.id = r.att_period_id WHERE r.public_id = ?', [$run]), 'the matching closed period is picked up');
        try {
            $svc->createRun($maker, $le, ['label' => 'ซ้อน', 'period_start' => '2026-10-15', 'period_end' => '2026-11-14', 'pay_date' => '2026-11-14']);
            self::fail('overlap');
        } catch (ValidationException $e) {
            self::assertSame('payroll.error.overlap', $e->errors['period_start']);
        }
        $svc->calculate($maker, $run);
        $r = $db->one('SELECT * FROM pay_runs WHERE public_id = ?', [$run]);
        self::assertSame('calculated', $r['status']);
        self::assertGreaterThanOrEqual(7, (int) $r['blocking_count']);
        self::assertStringContainsString('payroll.warn.sso_missing', (string) $this->person($run, 'D1-0001')['warnings_json']);
        self::assertStringContainsString('payroll.warn.policy_missing', (string) $this->person($run, 'D1-0007')['warnings_json'], 'daily pay needs the company rule');
        try {
            $svc->submit($maker, $run, '');
            self::fail('blocking');
        } catch (BusinessException $e) {
            self::assertSame('payroll.error.blocking', $e->messageKey);
        }

        // rules: Payroll proposes with a source, another person approves (maker ≠ checker)
        try {
            $svc->proposeRules($maker, $le, 'payroll.sso', ['rate_employee' => '3.5', 'rate_employer' => '4', 'wage_floor' => '20000', 'wage_cap' => '1000', 'rounding' => 'x'], '2026-10-01', '', '');
            self::fail('validation');
        } catch (ValidationException $e) {
            self::assertSame(['wage_cap', 'rounding', 'source_reference', 'reason'], array_keys($e->errors));
        }
        $pol = $svc->proposeRules(
            $maker,
            $le,
            'payroll.policy',
            ['monthly_divisor' => '30', 'hours_per_day' => '8', 'proration' => 'calendar_days', 'daily_paid' => ['worked_days', 'holiday_days']],
            '2026-10-01',
            'ข้อบังคับการทำงาน (ค่าทดสอบ)',
            'ตั้งค่าครั้งแรก (ทดสอบ)'
        );
        $sso = $svc->proposeRules(
            $maker,
            $le,
            'payroll.sso',
            ['rate_employee' => '3.5', 'rate_employer' => '4', 'wage_floor' => '1000', 'wage_cap' => '20000', 'rounding' => 'half_up_baht'],
            '2026-10-01',
            'ค่าทดสอบเท่านั้น ไม่ใช่อัตราจริง',
            'ตั้งค่าครั้งแรก (ทดสอบ)'
        );
        $tasks = array_column($db->all("SELECT u.username FROM workflow_tasks k JOIN workflow_instances i ON i.id = k.instance_id JOIN users u ON u.id = k.assignee_id WHERE i.subject_id = ? AND k.status = 'open'", [$sso]), 'username');
        self::assertSame(['payroll.checker'], $tasks, 'only the payroll approver — not the maker, not the system admin');
        try {
            $this->decide($sso, 'payroll.maker');
            self::fail('self approval');
        } catch (BusinessException $e) {
            self::assertContains($e->messageKey, ['workflow.error.self_approval', 'error.forbidden'], 'the maker has no task and cannot decide');
        }
        $approval = $this->as('payroll.checker')->get('/approvals/' . $db->scalar('SELECT public_id FROM workflow_instances WHERE subject_id = ?', [$sso]));
        self::assertStringContainsString('3.5 %', $approval->body, 'the approver sees the values');
        $this->decide($pol, 'payroll.checker');
        $this->decide($sso, 'payroll.checker');
        self::assertSame('30', (string) $svc->rules($le, '2026-10-31')['policy']?->value['monthly_divisor']);

        // the run calculated before the rules were approved cannot be sent: calculate again
        try {
            $svc->submit($maker, $run, '');
            self::fail('stale');
        } catch (BusinessException $e) {
            self::assertContains($e->messageKey, ['payroll.error.blocking', 'payroll.error.rules_changed']);
        }

        // pay profile: bank account for D1-0004 — another person approves; the number is stored encrypted, shown as last 4 only
        $e4 = $this->emp('D1-0004');
        try {
            $svc->proposeProfile($maker, (string) $e4['public_id'], ['effective_from' => '2026-10-01', 'pay_basis' => 'monthly', 'base_amount' => '0', 'account_no' => '12ab', 'bank_code' => '', 'reason' => '']);
            self::fail('validation');
        } catch (ValidationException $e) {
            self::assertSame(['base_amount', 'account_no', 'bank_code', 'reason'], array_keys($e->errors));
        }
        $prof = $svc->proposeProfile($maker, (string) $e4['public_id'], ['effective_from' => '2026-10-01', 'pay_basis' => 'monthly', 'base_amount' => '18,500.00',
            'bank_code' => 'TESTBANK', 'account_no' => '123-4-56789-0', 'reason' => 'เพิ่มบัญชีรับเงินเดือน']);
        $row = $db->one('SELECT * FROM pay_profiles WHERE public_id = ?', [$prof]);
        self::assertSame('7890', $row['account_last4']);
        self::assertStringNotContainsString('1234567890', (string) $row['account_enc']);
        try {
            $svc->proposeProfile($maker, (string) $e4['public_id'], ['effective_from' => '2026-10-02', 'pay_basis' => 'monthly', 'base_amount' => '1', 'reason' => 'ซ้ำ']);
            self::fail('one pending change at a time');
        } catch (BusinessException $e) {
            self::assertSame('payroll.error.profile_pending', $e->messageKey);
        }
        $pp = $this->as('payroll.checker')->get('/approvals/' . $db->scalar('SELECT public_id FROM workflow_instances WHERE subject_id = ?', [$prof]));
        self::assertStringContainsString('••••7890', $pp->body);
        self::assertStringNotContainsString('1234567890', $pp->body);
        $this->decide($prof, 'payroll.checker');
        self::assertSame('approved', $db->scalar('SELECT status FROM pay_profiles WHERE public_id = ?', [$prof]));
        $page = $this->as('payroll.maker')->get('/payroll/people/' . $e4['public_id']);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('••••7890', $page->body);
        self::assertStringNotContainsString('1234567890', $page->body);

        // inputs: D1-0005 left on 16 Oct (last day 15 Oct) · OT: B1 fine, B2 has no multiplier · manual amounts
        $db->run("UPDATE employees SET termination_date = '2026-10-16' WHERE employee_code = 'D1-0005'");
        $b1 = $this->otBatch(1, [self::ot('D1-0004', '2026-10-05', '1.5', 120), self::ot('D1-0004', '2026-10-12', '3', 60)]);
        $b2 = $this->otBatch(2, [self::ot('D1-0005', '2026-10-06', '', 90)]);
        $svc->setSources($maker, $run, $tp, [$b1, $b2]);
        $comp = static fn (string $code): string => (string) $db->scalar('SELECT public_id FROM pay_components WHERE legal_entity_id = ? AND code = ?', [$le, $code]);
        try {
            $svc->addManual($maker, $run, ['employee' => (string) $e4['public_id'], 'component' => $comp('SALARY'), 'amount' => '1', 'note' => 'x']);
            self::fail('only manual components');
        } catch (ValidationException $e) {
            self::assertSame(['component', 'note'], array_keys($e->errors));
        }
        $svc->addManual($maker, $run, ['employee' => (string) $this->emp('D1-0001')['public_id'], 'component' => $comp('WHT'), 'amount' => '1,500', 'note' => 'ภาษีคำนวณจากโปรแกรมเดิม (ทดสอบ)']);
        $svc->addManual($maker, $run, ['employee' => (string) $this->emp('D1-0002')['public_id'], 'component' => $comp('BONUS'), 'amount' => '2000', 'note' => 'รางวัลยอดขาย (ทดสอบ)']);
        $svc->calculate($maker, $run);
        self::assertStringContainsString('payroll.warn.ot_multiplier', (string) $this->person($run, 'D1-0005')['warnings_json']);
        self::assertSame(1, (int) $db->scalar('SELECT blocking_count FROM pay_runs WHERE public_id = ?', [$run]), 'only the OT line without a multiplier');

        // drop B2 → no blocking; check every amount (G6A golden cases)
        $svc->setSources($maker, $run, $tp, [$b1]);
        self::assertSame('draft', $db->scalar('SELECT status FROM pay_runs WHERE public_id = ?', [$run]), 'any change → calculate again');
        $svc->calculate($maker, $run);
        $r = $db->one('SELECT * FROM pay_runs WHERE public_id = ?', [$run]);
        self::assertSame(0, (int) $r['blocking_count']);
        self::assertSame(7, (int) $r['employee_count']);
        // G6A-01 D1-0001: 52 000 + 3 000 position; SSO wage 55 000 → cap 20 000 → 3.5 % = 700, 4 % = 800; WHT 1 500 (manual)
        self::assertSame(['52000.00', '3000.00', '700.00', '800.00', '1500.00'], [$this->amount($run, 'D1-0001', 'SALARY'), $this->amount($run, 'D1-0001', 'POS_ALLOW'),
            $this->amount($run, 'D1-0001', 'SSO_EE'), $this->amount($run, 'D1-0001', 'SSO_ER'), $this->amount($run, 'D1-0001', 'WHT')]);
        self::assertSame('52800.0000', $this->person($run, 'D1-0001')['net']);
        // G6A-02 D1-0002: bonus 2 000 is not SSO wage; net 38 000 + 3 000 + 2 000 − 700 = 42 300
        self::assertSame('42300.0000', $this->person($run, 'D1-0002')['net']);
        // G6A-03 D1-0004: OT hourly 18 500 ÷ 30 ÷ 8 = 77.0833…; × 1.5 × 2 h = 231.25; × 3 × 1 h = 231.25
        //          absence 2 × 18 500 ÷ 30 = 1 233.33; SSO wage 18 500 + 462.50 − 1 233.33 = 17 729.17 → 620.52 → 621 · 709.17 → 709
        self::assertSame('462.50', $this->amount($run, 'D1-0004', 'OT'));
        self::assertSame('1233.33', $this->amount($run, 'D1-0004', 'ABSENT'));
        self::assertSame(['621.00', '709.00'], [$this->amount($run, 'D1-0004', 'SSO_EE'), $this->amount($run, 'D1-0004', 'SSO_ER')]);
        self::assertSame('17108.1700', $this->person($run, 'D1-0004')['net']);
        // G6A-04 D1-0005 left 16 Oct: 16 000 × 15 ÷ 31 = 7 741.94; SSO 270.97 → 271 · 309.68 → 310
        self::assertSame('7741.94', $this->amount($run, 'D1-0005', 'SALARY'));
        self::assertSame(['271.00', '310.00'], [$this->amount($run, 'D1-0005', 'SSO_EE'), $this->amount($run, 'D1-0005', 'SSO_ER')]);
        self::assertStringContainsString('payroll.warn.part_month', (string) $this->person($run, 'D1-0005')['warnings_json']);
        // G6A-05 D1-0007 daily 520 × (21 worked + 1 holiday) = 11 440 (the leave day is not paid under this test rule); SSO 400.40 → 400 · 457.60 → 458
        self::assertSame('11440.00', $this->amount($run, 'D1-0007', 'SALARY'));
        self::assertSame(['400.00', '458.00'], [$this->amount($run, 'D1-0007', 'SSO_EE'), $this->amount($run, 'D1-0007', 'SSO_ER')]);
        // G6A-06 totals
        self::assertSame(['190144.4400', '6825.3300', '183319.1100', '4677.0000'], [$r['gross_total'], $r['deduction_total'], $r['net_total'], $r['employer_total']]);
        // the ledger keeps the run and replays it to the same result
        $ledger = $app->ledger()->open((string) $r['calc_run_pid']);
        self::assertTrue($ledger['intact']);
        self::assertSame(['payroll.policy', 'payroll.sso'], array_column($ledger['config_versions'], 'config_key'));
        self::assertTrue($svc->replay($maker, $run));
        self::assertSame(2, (int) $db->scalar("SELECT COUNT(*) FROM calculation_runs WHERE source_type = 'pay_run' AND source_id = ? AND status = 'recalculated'", [$run]), 'older results kept, marked as replaced');

        // screens: run, person, register needs the password again
        $mk = $this->as('payroll.maker');
        $page = $mk->get('/payroll/runs/' . $run);
        self::assertSame(200, $page->status);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page->body));
        self::assertStringContainsString('183,319.11', $page->body);
        $res = $mk->get('/payroll/runs/' . $run . '/e/' . $e4['public_id']);
        self::assertSame(200, $res->status);
        self::assertStringContainsString('231.25', $res->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body));
        $x = $mk->get('/payroll/runs/' . $run . '/register');
        self::assertSame(302, $x->status);
        self::assertStringContainsString('/reauth', (string) ($x->headers['Location'] ?? ''));
        $mk->post('/reauth', ['password' => $this->t->demoPassword, 'next' => '/payroll/runs/' . $run . '/register']);
        $x = $mk->get('/payroll/runs/' . $run . '/register');
        self::assertSame(200, $x->status);
        self::assertStringStartsWith('PK', $x->body);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'payroll.register_exported'"));
        self::assertSame(404, $this->as('payroll.maker')->get('/payroll/runs/' . Ulid::generate($app->clock))->status);

        // submit → only another person approves; the approver cannot be the one who calculated
        $svc->submit($maker, $run, 'ตรวจกับโปรแกรมเดิมแล้ว (ทดสอบ)');
        self::assertSame('pending', $db->scalar('SELECT status FROM pay_runs WHERE public_id = ?', [$run]));
        try {
            $svc->addManual($maker, $run, ['employee' => (string) $e4['public_id'], 'component' => $comp('BONUS'), 'amount' => '1', 'note' => 'เพิ่มหลังส่ง']);
            self::fail('frozen while waiting');
        } catch (BusinessException $e) {
            self::assertSame('payroll.error.not_editable', $e->messageKey);
        }
        try {
            $this->decide($run, 'payroll.maker');
            self::fail('self approval');
        } catch (BusinessException $e) {
            self::assertContains($e->messageKey, ['workflow.error.self_approval', 'error.forbidden'], 'the maker has no task and cannot decide');
        }
        $ap = $this->as('payroll.checker')->get('/approvals/' . $db->scalar('SELECT public_id FROM workflow_instances WHERE subject_id = ?', [$run]));
        self::assertStringContainsString('183,319.11', $ap->body, 'the approver sees the totals');
        $this->decide($run, 'payroll.checker');
        $r = $db->one('SELECT * FROM pay_runs WHERE public_id = ?', [$run]);
        self::assertSame('approved', $r['status']);
        self::assertSame('approved', $db->scalar('SELECT status FROM calculation_runs WHERE public_id = ?', [$r['calc_run_pid']]));
        self::assertSame('locked', $db->scalar('SELECT status FROM locks WHERE public_id = ?', [$r['lock_pid']]));
        // after approval: profile changes inside the period are refused, calculating again is refused
        try {
            $svc->proposeProfile($maker, (string) $this->emp('D1-0002')['public_id'], ['effective_from' => '2026-10-15', 'pay_basis' => 'monthly', 'base_amount' => '40000', 'reason' => 'ปรับเงินเดือนย้อนหลัง']);
            self::fail('inside an approved run');
        } catch (ValidationException $e) {
            self::assertSame('payroll.error.in_approved_run', $e->errors['effective_from']);
        }
        try {
            $svc->calculate($maker, $run);
            self::fail('approved runs are not recalculated');
        } catch (BusinessException $e) {
            self::assertSame('payroll.error.not_editable', $e->messageKey);
        }

        // payment batch: only people with a bank account (D1-0004), total = their net; the rest are listed as left out
        $pay = $svc->createPayment($maker, $run);
        self::assertSame(['items' => 1, 'left_out' => 6], ['items' => $pay['items'], 'left_out' => $pay['left_out']]);
        $batch = $db->one('SELECT * FROM pmt_batches WHERE public_id = ?', [$pay['batch']]);
        self::assertSame(['payroll', 'pay_run', $run, '17108.1700'], [$batch['payment_type'], $batch['source_type'], $batch['source_id'], $batch['total_amount']]);
        self::assertSame($pay['batch'], $svc->createPayment($maker, $run)['batch'], 'idempotent');
        // posting: one line per component and kind; accounts come from Finance's rules
        $req = $svc->requestPosting($maker, $run);
        $pr = $db->one('SELECT * FROM fin_posting_requests WHERE public_id = ?', [$req]);
        $types = array_column((array) json_decode((string) $pr['lines_json'], true), 'line_type');
        self::assertContains('payroll.earning.salary', $types);
        self::assertContains('payroll.deduction.sso_ee', $types);
        self::assertContains('payroll.employer.sso_er', $types);
        self::assertSame('payroll.run', $pr['source_module']);
        self::assertSame($r['calc_run_pid'], $pr['calculation_id']);

        // no pay or account numbers in the audit trail, the logs or notifications
        $audit = implode("\n", array_map(static fn (array $a): string => (string) ($a['reason'] ?? '') . ' ' . ($a['diff_json'] ?? ''), $db->all("SELECT * FROM audit_events WHERE action LIKE 'payroll.%'")));
        $logs = implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), glob($this->t->tmp . '/logs/*') ?: []));
        $notes = implode("\n", array_map(static fn (array $n): string => (string) json_encode($n, JSON_UNESCAPED_UNICODE), $db->all('SELECT * FROM notifications')));
        foreach (['1234567890', '17108', '18500', '52000'] as $needle) {
            self::assertStringNotContainsString($needle, $audit, 'audit: ' . $needle);
            self::assertStringNotContainsString($needle, $logs, 'log: ' . $needle);
            self::assertStringNotContainsString($needle, $notes, 'notification: ' . $needle);
        }
        // other people: HR / employee / another company cannot open the run or a result
        foreach (['hr.demo1', 'employee.demo1', 'hr.demo2'] as $u) {
            self::assertContains($this->as($u)->get('/payroll/runs/' . $run)->status, [403, 404], $u);
            self::assertContains($this->as($u)->get('/payroll/runs/' . $run . '/e/' . $e4['public_id'])->status, [403, 404], $u);
            self::assertContains($this->as($u)->get('/payroll/people/' . $e4['public_id'])->status, [403, 404], $u);
        }
    }

    public function testComponentsNeedASecondPersonAndOnePerMethod(): void
    {
        $app = $this->t->app;
        $db = $app->db();
        $svc = PayrollService::of($app);
        $le = $this->le();
        $maker = $this->az('payroll.maker');
        try {
            $svc->saveComponent($maker, $le, ['code' => 'SALARY2', 'name_th' => 'เงินเดือนซ้ำ', 'kind' => 'earning', 'method' => 'base']);
            self::fail('one base component');
        } catch (ValidationException $e) {
            self::assertSame('payroll.error.method_taken', $e->errors['method']);
        }
        try {
            $svc->saveComponent($maker, $le, ['code' => 'salary', 'name_th' => '', 'kind' => 'employer', 'method' => 'fixed']);
            self::fail('validation');
        } catch (ValidationException $e) {
            self::assertSame(['code', 'name_th', 'kind'], array_keys($e->errors));
        }
        $pid = $svc->saveComponent($maker, $le, ['code' => 'MEAL', 'name_th' => 'ค่าอาหาร', 'kind' => 'earning', 'method' => 'fixed', 'taxable' => '1', 'sort_order' => '25']);
        self::assertSame('draft', $db->scalar('SELECT status FROM pay_components WHERE public_id = ?', [$pid]));
        try {
            $svc->decideComponent($maker, $pid, 'activate');
            self::fail('maker cannot activate');
        } catch (\Blue\Core\Error\HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        $svc->decideComponent($this->az('payroll.checker'), $pid, 'activate');
        self::assertSame('active', $db->scalar('SELECT status FROM pay_components WHERE public_id = ?', [$pid]));
        $page = $this->as('payroll.checker')->get('/payroll/components');
        self::assertStringContainsString('MEAL', $page->body);
        // the pure calculation: rounding helpers
        self::assertSame(7741 * 10000 + 9400, PayCalc::div(16000 * 10000 * 15, 31, 100));
        self::assertSame(750, PayCalc::hundredths('7.5'));
        self::assertSame('0.50', PayCalc::fromHundredths(50));
    }
}
