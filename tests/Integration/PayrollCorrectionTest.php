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
 * Phase 6F (DEC-87): off-cycle runs, retroactive pay changes, correction runs, payroll ↔ payment reconciliation.
 * Clock: Mon 2026-11-09. Rule values are TEST values (social security 3.5 % / 4 %, floor 1 000, cap 20 000, to the baht).
 */
final class PayrollCorrectionTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(payroll|workflow|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

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

    private function approve(string $subjectPid): void
    {
        $i = (string) $this->t->app->db()->scalar('SELECT public_id FROM workflow_instances WHERE subject_id = ? ORDER BY id DESC LIMIT 1', [$subjectPid]);
        $this->t->app->workflow()->decide($i, (int) $this->t->user('payroll.checker')['id'], 'approve', 'ตรวจแล้ว');
    }

    /** @return array<string, string> component code => amount of a person in a run */
    private function lines(string $run, string $code): array
    {
        $out = [];
        foreach (
            $this->t->app->db()->all(
                'SELECT l.component_code, l.amount, l.source_type FROM pay_result_lines l JOIN pay_run_employees re ON re.id = l.run_employee_id JOIN pay_runs r ON r.id = re.run_id JOIN employees e ON e.id = re.employee_id
                  WHERE r.public_id = ? AND e.employee_code = ? ORDER BY l.id',
                [$run, $code]
            ) as $l
        ) {
            $out[$l['component_code'] . ':' . $l['source_type']] = Money::format(Money::fromDb($l['amount']), 2, false);
        }
        return $out;
    }

    private function page(string $user, string $path): string
    {
        $b = $this->t->browser();
        $b->login($user);
        $r = $b->get($path);
        self::assertSame(200, $r->status, $path);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($r->body), $path);
        return $r->body;
    }

    public function testOffCycleRetroCorrectionAndReconciliation(): void
    {
        $app = $this->t->app;
        $db = $app->db();
        $svc = PayrollService::of($app);
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $pm = $this->az('payroll.maker');
        $pc = $this->az('payroll.checker');
        foreach (
            [
                ['payroll.policy', ['monthly_divisor' => '30', 'hours_per_day' => '8', 'proration' => 'calendar_days', 'daily_paid' => ['worked_days', 'holiday_days']]],
                ['payroll.sso', ['rate_employee' => '3.5', 'rate_employer' => '4', 'wage_floor' => '1000', 'wage_cap' => '20000', 'rounding' => 'half_up_baht']],
            ] as [$key, $val]
        ) {
            $this->approve($svc->proposeRules($pm, $le, $key, $val, '2026-01-01', 'ค่าทดสอบเท่านั้น', 'ทดสอบ'));
        }
        $lines = [];
        foreach (['D1-0001', 'D1-0002', 'D1-0003', 'D1-0004', 'D1-0005', 'D1-0006', 'D1-0007'] as $code) {
            $lines[] = ['employee_code' => $code, 'name' => $code, 'scheduled_days' => 22, 'worked_days' => 22, 'absent_days' => 0, 'missing_punch_days' => 0, 'late_count' => 0, 'late_minutes' => 0,
                'early_count' => 0, 'early_minutes' => 0, 'worked_minutes' => 22 * 480, 'leave_days' => 0, 'leave' => [], 'holiday_days' => 1, 'off_days' => 8, 'no_data_days' => 0, 'corrected_days' => 0];
        }
        $calc = $app->ledger()->record('attendance.period', 'TEST', 'TEST', $app->clock->now(), [], [], [], ['lines' => $lines, 'leave_types' => []], ['legal_entity_id' => $le]);
        $now = $app->clock->now()->format('Y-m-d H:i:s.u');
        $db->run(
            "INSERT INTO att_periods (public_id, created_at, updated_at, legal_entity_id, period_start, period_end, status, revision, snapshot_run_pid, output_hash) VALUES (?, ?, ?, ?, '2026-10-01', '2026-10-31', 'finalized', 1, ?, 'x')",
            [Ulid::generate($app->clock), $now, $now, $le, $calc]
        );

        // ---- the regular October run ------------------------------------------------------------------------------------
        $oct = $svc->createRun($pm, $le, ['label' => 'เงินเดือน ต.ค. 2569 (ทดสอบ)', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-10-31']);
        $svc->calculate($pm, $oct);
        $svc->submit($pm, $oct, '');
        $this->approve($oct);
        self::assertSame(['SALARY:profile' => '18500.00', 'SSO_EE:sso' => '648.00', 'SSO_ER:sso' => '740.00'], $this->lines($oct, 'D1-0004'));
        try {
            $svc->createRun($pm, $le, ['label' => 'ซ้ำ', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-10-31']);
            self::fail('one regular run per period');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('period_start', $e->errors);
        }

        // ---- off-cycle: a bonus in the same period, only the amounts entered ------------------------------------------------
        try {
            $svc->createRun($pm, $le, ['type' => 'off_cycle', 'label' => 'โบนัส', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-11-15', 'purpose' => 'x']);
            self::fail('purpose');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('purpose', $e->errors);
        }
        $bonus = $svc->createRun($pm, $le, ['type' => 'off_cycle', 'label' => 'โบนัสพิเศษ (ทดสอบ)', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-10-20', 'purpose' => 'โบนัสผลงาน (ทดสอบ)']);
        $comp = static fn (string $code): string => (string) $db->scalar('SELECT public_id FROM pay_components WHERE legal_entity_id = ? AND code = ?', [$le, $code]);
        $e4 = (string) $db->scalar("SELECT public_id FROM employees WHERE employee_code = 'D1-0004'");
        $svc->addManual($pm, $bonus, ['employee' => $e4, 'component' => $comp('BONUS'), 'amount' => '10000', 'note' => 'โบนัส (ทดสอบ)']);
        $svc->addManual($pm, $bonus, ['employee' => $e4, 'component' => $comp('WHT'), 'amount' => '500', 'note' => 'ภาษีตามฝ่ายภาษี (ทดสอบ)']);
        $svc->calculate($pm, $bonus);
        $b = $db->one('SELECT * FROM pay_runs WHERE public_id = ?', [$bonus]) ?? [];
        self::assertSame([1, 0, '9500.0000'], [(int) $b['employee_count'], (int) $b['blocking_count'], (string) $b['net_total']], 'only D1-0004, nothing worked out');
        self::assertSame(['BONUS:manual' => '10000.00', 'WHT:manual' => '500.00'], $this->lines($bonus, 'D1-0004'));
        self::assertContains('payroll.warn.offcycle_statutory', (array) json_decode((string) $app->ledger()->open((string) $b['calc_run_pid'])['run']['warnings_json'], true));
        $svc->submit($pm, $bonus, '');
        $this->approve($bonus);
        self::assertSame(['approved', null], array_values($db->one('SELECT status, lock_pid FROM pay_runs WHERE public_id = ?', [$bonus]) ?? []), 'the regular run holds the lock');
        $emp4 = (int) $db->scalar("SELECT id FROM employees WHERE employee_code = 'D1-0004'");
        $ytd = PayslipService::of($app)->ytd($emp4, $le, 2026, '2026-12-31');
        self::assertSame([(18500 + 10000) * 10000, 500 * 10000], [$ytd['taxable'], $ytd['wht']], 'the bonus and its manual tax count in the year to date');

        $this->t->clock->advance('+1 hour');
        // ---- a pay rise back to 1 October: refused unless the person says it reaches back ---------------------------------
        $in = ['effective_from' => '2026-10-01', 'pay_basis' => 'monthly', 'base_amount' => '20000', 'reason' => 'ปรับเงินเดือนย้อนหลัง (ทดสอบ)', 'items' => []];
        try {
            $svc->proposeProfile($pm, $e4, $in);
            self::fail('retro without saying so');
        } catch (ValidationException $e) {
            self::assertSame('payroll.error.in_approved_run', $e->errors['effective_from']);
        }
        $profile = $svc->proposeProfile($pm, $e4, $in + ['retro' => true]);
        $this->approve($profile);
        self::assertSame(['approved', 1], [(string) $db->scalar('SELECT status FROM pay_profiles WHERE public_id = ?', [$profile]), (int) $db->scalar('SELECT retro FROM pay_profiles WHERE public_id = ?', [$profile])]);
        $octRow = $svc->run($pm, $oct);
        self::assertSame([['key' => 'payroll.correct_reason.profile', 'params' => ['code' => 'D1-0004', 'from' => '2026-10-01']]], $svc->correctionReasons($octRow));
        self::assertSame([], $svc->correctionReasons($svc->run($pm, $bonus)), 'off-cycle runs are not recalculated');

        // ---- the correction run ---------------------------------------------------------------------------------------------
        try {
            $svc->createCorrection($pm, $oct, ['pay_date' => '2026-10-15', 'purpose' => 'x']);
            self::fail('validation');
        } catch (ValidationException $e) {
            self::assertSame(['pay_date', 'purpose'], array_keys($e->errors));
        }
        $corr = $svc->createCorrection($pm, $oct, ['pay_date' => '2026-11-30', 'purpose' => 'ปรับเงินเดือนย้อนหลัง ต.ค. (ทดสอบ)']);
        try {
            $svc->createCorrection($pm, $oct, ['pay_date' => '2026-11-30', 'purpose' => 'อีกรอบ (ทดสอบ)']);
            self::fail('one open correction');
        } catch (BusinessException $e) {
            self::assertSame('payroll.error.correction_open', $e->messageKey);
        }
        $c = $svc->run($pm, $corr);
        self::assertSame(['correction', '2026-10-01', '2026-10-31', (int) $octRow['id'], 'แก้ไข: เงินเดือน ต.ค. 2569 (ทดสอบ)'], [$c['run_type'], $c['period_start'], $c['period_end'], (int) $c['corrects_run_id'], $c['label']]);
        $svc->calculate($pm, $corr);
        $c = $svc->run($pm, $corr);
        // G6F-01 only the difference, only the person who changed: 20 000 − 18 500 = 1 500; social security 700 − 648 = 52 and 800 − 740 = 60; net 1 448
        self::assertSame([1, '1448.0000', '60.0000'], [(int) $c['employee_count'], (string) $c['net_total'], (string) $c['employer_total']]);
        self::assertSame(['SALARY:correction' => '1500.00', 'SSO_EE:correction' => '52.00', 'SSO_ER:correction' => '60.00'], $this->lines($corr, 'D1-0004'));
        self::assertSame('was:18500.00;now:20000.00', $db->scalar("SELECT l.note FROM pay_result_lines l JOIN pay_run_employees re ON re.id = l.run_employee_id JOIN pay_runs r ON r.id = re.run_id WHERE r.public_id = ? AND l.component_code = 'SALARY'", [$corr]));
        $taxOf = static function (string $runPid) use ($app, $db): array {
            $snap = $app->ledger()->open((string) $db->scalar('SELECT calc_run_pid FROM pay_runs WHERE public_id = ?', [$runPid]));
            return array_column(array_map(static fn (array $x): array => ['c' => $x['code'], 't' => $x['tax']], $snap['inputs']['employees']), 't', 'c')['D1-0004'];
        };
        self::assertSame($taxOf($oct), $taxOf($corr), 'the correction keeps the tax position of the month it recalculates (the later bonus is not in it)');
        self::assertContains('payroll.warn.correction_no_tax', (array) json_decode((string) $app->ledger()->open((string) $c['calc_run_pid'])['run']['warnings_json'], true), 'October had no tax rule: no tax worked out now');
        self::assertTrue($svc->replay($pm, $corr), 'replays from the snapshot');
        $svc->submit($pm, $corr, '');
        $this->t->clock->advance('+1 hour');
        $this->approve($corr);
        self::assertSame('approved', $db->scalar('SELECT status FROM pay_runs WHERE public_id = ?', [$corr]));
        self::assertSame([], $svc->correctionReasons($svc->run($pm, $oct)), 'answered by the correction');
        // G6F-02 year to date: 18 500 + 10 000 bonus + 1 500 difference; social security 648 + 52
        $ytd = PayslipService::of($app)->ytd($emp4, $le, 2026, '2026-12-31');
        self::assertSame([300000000, 7000000], [$ytd['taxable'], $ytd['sso']]);
        // a second correction finds nothing more to pay
        $again = $svc->createCorrection($pm, $oct, ['pay_date' => '2026-11-30', 'purpose' => 'ตรวจซ้ำ (ทดสอบ)']);
        $svc->calculate($pm, $again);
        self::assertSame(0, (int) $db->scalar('SELECT employee_count FROM pay_runs WHERE public_id = ?', [$again]));
        try {
            $svc->submit($pm, $again, '');
            self::fail('empty');
        } catch (BusinessException $e) {
            self::assertSame('payroll.error.empty', $e->messageKey);
        }
        $svc->cancel($pm, $again, 'ไม่มีส่วนต่าง');

        // ---- reconciliation: payroll ↔ payment ------------------------------------------------------------------------------
        $db->run("UPDATE pay_profiles SET bank_code = 'TESTBANK', account_enc = ?, account_last4 = '7890' WHERE employee_id = ? AND status = 'approved'", [$app->crypto()->encrypt('1234567890'), $emp4]);
        $pay = $svc->createPayment($pm, $corr);
        self::assertSame(1, $pay['items']);
        $rc = $svc->reconciliation($svc->run($pm, $corr));
        self::assertSame([true, true, 14480000, 14480000, 0], [$rc['ok'], $rc['intact'], $rc['totals']['net'], $rc['totals']['batch'], $rc['totals']['diff']]);
        $pbid = (int) $db->scalar('SELECT id FROM pmt_batches WHERE public_id = ?', [$pay['batch']]);
        $db->run("UPDATE pmt_batch_items SET amount = '1300.0000' WHERE batch_id = ?", [$pbid]);
        $rc = $svc->reconciliation($svc->run($pm, $corr));
        self::assertSame([false, 'differs'], [$rc['ok'], $rc['rows'][0]['state']]);
        $octRc = $svc->reconciliation($svc->run($pm, $oct));
        self::assertNull($octRc['batch'], 'no payment batch yet for October');

        // ---- screens ------------------------------------------------------------------------------------------------------------
        $body = $this->page('payroll.maker', '/payroll/runs/' . $corr);
        self::assertStringContainsString('เดิม 18,500.00 → ใหม่ 20,000.00', $this->page('payroll.maker', '/payroll/runs/' . $corr . '/e/' . $e4));
        self::assertStringContainsString('รอบแก้ไขของ', $body);
        self::assertStringContainsString('รอบเดิมไม่ได้คำนวณภาษีหัก ณ ที่จ่ายด้วยระบบ', $body, 'run-level notes are shown');
        self::assertStringContainsString('รอบเพิ่มเติมไม่คำนวณเงินเดือน', $this->page('payroll.maker', '/payroll/runs/' . $bonus));
        $this->page('payroll.maker', '/payroll/runs/' . $oct);
        $this->page('payroll.maker', '/payroll/runs/' . $bonus);
        $this->page('payroll.maker', '/payroll');
        self::assertSame($pc->userId, (int) $db->scalar('SELECT approved_by FROM pay_runs WHERE public_id = ?', [$corr]));
    }
}
