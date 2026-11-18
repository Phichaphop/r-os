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
use Blue\Modules\Payroll\TaxService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/**
 * Phase 6C withholding tax + allowance declarations + certificates (DEC-85). Clock: Mon 2026-11-09.
 *
 * EVERY rate here is a TEST VALUE ONLY, chosen not to look like the real Thai tables: expenses 40 % up to 80 000,
 * personal allowance 50 000, social security deductible up to 9 000, brackets 0 % to 20 000 / 10 % to 100 000 / 20 %
 * above, allowance INS (amount, up to 20 000) and CHILD (count × 25 000). Hand-worked results are in the comments.
 */
final class WhtWebTest extends TestCase
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

    private const WHT = ['method' => 'cumulative', 'expense_rate' => '40', 'expense_cap' => '80000', 'personal_allowance' => '50000', 'sso_allowance_cap' => '9000',
        'brackets' => [['up_to' => '20000', 'rate' => '0'], ['up_to' => '100000', 'rate' => '10'], ['up_to' => '', 'rate' => '20']],
        'allowances' => [['code' => 'INS', 'name' => 'เบี้ยประกัน (ทดสอบ)', 'unit' => 'amount', 'per_unit' => '', 'cap' => '20000'],
            ['code' => 'CHILD', 'name' => 'บุตร (ทดสอบ)', 'unit' => 'count', 'per_unit' => '25000', 'cap' => '']],
        'rounding' => 'half_up_satang'];

    public function testDeclarationsTaxAndCertificates(): void
    {
        $app = $this->t->app;
        $db = $app->db();
        $svc = PayrollService::of($app);
        $tax = TaxService::of($app);
        $le = $this->le();
        $maker = $this->az('payroll.maker');
        $checker = $this->az('payroll.checker');

        // the rules: validation, then TEST values approved by another person
        try {
            $svc->proposeRules(
                $maker,
                $le,
                'payroll.wht',
                ['method' => 'x', 'expense_rate' => '140', 'brackets' => [['up_to' => '100', 'rate' => '0'], ['up_to' => '50', 'rate' => '5'], ['up_to' => '900', 'rate' => '10']], 'rounding' => 'x'],
                '2026-01-01',
                'ทดสอบ',
                'ทดสอบ'
            );
            self::fail('validation');
        } catch (ValidationException $e) {
            foreach (['method', 'expense_rate', 'expense_cap', 'personal_allowance', 'brackets.1.up_to', 'brackets.2.up_to', 'rounding'] as $k) {
                self::assertArrayHasKey($k, $e->errors, $k);
            }
        }
        $ids = [];
        $ids[] = $svc->proposeRules($maker, $le, 'payroll.policy', ['monthly_divisor' => '30', 'hours_per_day' => '8', 'proration' => 'calendar_days', 'daily_paid' => ['worked_days', 'holiday_days']], '2026-01-01', 'ข้อบังคับ (ค่าทดสอบ)', 'ทดสอบ');
        $ids[] = $svc->proposeRules($maker, $le, 'payroll.sso', ['rate_employee' => '3.5', 'rate_employer' => '4', 'wage_floor' => '1000', 'wage_cap' => '20000', 'rounding' => 'half_up_baht'], '2026-01-01', 'ค่าทดสอบเท่านั้น', 'ทดสอบ');
        $ids[] = $svc->proposeRules($maker, $le, 'payroll.wht', self::WHT, '2026-01-01', 'ค่าทดสอบเท่านั้น ไม่ใช่อัตราจริง', 'ทดสอบ');
        foreach ($ids as $id) {
            $this->decide($id, 'payroll.checker');
        }
        $page = $this->as('payroll.checker')->get('/payroll/settings');
        self::assertStringContainsString('20000.00', $page->body, 'brackets are shown');
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page->body));
        // the worked-out tax component
        $comp = $svc->saveComponent($maker, $le, ['code' => 'TAX', 'name_th' => 'ภาษีหัก ณ ที่จ่าย (คำนวณ)', 'kind' => 'deduction', 'method' => 'wht']);
        $svc->decideComponent($checker, $comp, 'activate');
        self::assertSame('wht', $db->scalar('SELECT statutory FROM pay_components WHERE public_id = ?', [$comp]));

        // opening amounts of D1-0004 (Jan–Sep elsewhere)
        $e4 = $this->emp('D1-0004');
        $slips = PayslipService::of($app);
        $open = $slips->proposeOpening($maker, (string) $e4['public_id'], ['tax_year' => '2026', 'as_of' => '2026-09-30', 'gross' => '166500', 'taxable_income' => '166500', 'wht' => '1200', 'sso_employee' => '5250', 'note' => 'โปรแกรมเดิม ม.ค.–ก.ย. (ทดสอบ)']);
        $slips->decideOpening($checker, $open, true);

        // the employee declares; payroll checks (the person never approves their own)
        $emp = $this->as('employee.demo1');
        $page = $emp->get('/me/tax', ['year' => '2026']);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('บุตร (ทดสอบ)', $page->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page->body));
        self::assertSame(422, $emp->post('/me/tax', ['tax_year' => '2026', 'v' => ['INS' => '-5', 'CHILD' => '99']])->status);
        $emp->get('/me/tax', ['year' => '2026']);
        self::assertSame(303, $emp->post('/me/tax', ['tax_year' => '2026', 'v' => ['INS' => '30,000', 'CHILD' => '1']])->status);
        $decl = (string) $db->scalar("SELECT public_id FROM pay_tax_declarations WHERE status = 'pending' ORDER BY id DESC LIMIT 1");
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'payroll.declaration' AND user_id = ?", [$this->t->user('payroll.maker')['id']]));
        try {
            $tax->decide($maker, $decl, false, '');
            self::fail('a rejection needs a reason');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('decision_note', $e->errors);
        }
        $tax->decide($maker, $decl, true, '');
        self::assertSame('approved', $db->scalar('SELECT status FROM pay_tax_declarations WHERE public_id = ?', [$decl]));
        $list = $this->as('payroll.maker')->get('/payroll/tax', ['year' => '2026']);
        self::assertSame(200, $list->status);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($list->body));
        self::assertSame(403, $emp->get('/payroll/tax')->status);

        // October run: time fixture (everyone worked), calculate
        $lines = [];
        foreach (['D1-0001', 'D1-0002', 'D1-0003', 'D1-0004', 'D1-0005', 'D1-0006', 'D1-0007'] as $c) {
            $lines[] = ['employee_code' => $c, 'name' => $c, 'scheduled_days' => 22, 'worked_days' => 22, 'absent_days' => 0, 'missing_punch_days' => 0, 'late_count' => 0, 'late_minutes' => 0,
                'early_count' => 0, 'early_minutes' => 0, 'worked_minutes' => 22 * 480, 'leave_days' => 0, 'leave' => [], 'holiday_days' => 1, 'off_days' => 8, 'no_data_days' => 0, 'corrected_days' => 0];
        }
        $calc = $app->ledger()->record('attendance.period', 'TEST', 'TEST', $app->clock->now(), [], [], [], ['lines' => $lines, 'leave_types' => []], ['legal_entity_id' => $le]);
        $now = $app->clock->now()->format('Y-m-d H:i:s.u');
        $db->run(
            "INSERT INTO att_periods (public_id, created_at, updated_at, legal_entity_id, period_start, period_end, status, revision, snapshot_run_pid, output_hash) VALUES (?, ?, ?, ?, '2026-10-01', '2026-10-31', 'finalized', 1, ?, 'x')",
            [Ulid::generate($app->clock), $now, $now, $le, $calc]
        );
        $run = $svc->createRun($maker, $le, ['label' => 'เงินเดือน ต.ค. 2569 (ทดสอบ)', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-10-31']);
        $svc->calculate($maker, $run);
        $tax4 = static fn (string $code): ?string => ($v = $db->scalar(
            "SELECT l.amount FROM pay_result_lines l JOIN pay_run_employees re ON re.id = l.run_employee_id JOIN pay_runs r ON r.id = re.run_id JOIN employees e ON e.id = re.employee_id
              WHERE r.public_id = ? AND e.employee_code = ? AND l.component_code = 'TAX'",
            [$run, $code]
        )) === null ? null : Money::format(Money::fromDb($v), 2, false);
        // G6C-01 D1-0004 (cumulative, October = 3 months left): year income 166 500 + 18 500 × 3 = 222 000; expenses min(88 800, 80 000) = 80 000;
        //   allowances 50 000 + INS min(30 000, 20 000) + CHILD 1 × 25 000 + SSO min(5 250 + 648 × 3 = 7 194, 9 000) = 102 194;
        //   net 39 806 → tax (39 806 − 20 000) × 10 % = 1 980.60; withheld 1 200 → (1 980.60 − 1 200) ÷ 3 = 260.20
        self::assertSame('260.20', $tax4('D1-0004'));
        // G6C-02 D1-0001 (no declaration): 55 000 × 3 = 165 000; expenses 66 000; allowances 50 000 + SSO 700 × 3 = 2 100; net 46 900
        //   → (46 900 − 20 000) × 10 % = 2 690 → ÷ 3 = 896.666… → 896.67
        self::assertSame('896.67', $tax4('D1-0001'));
        self::assertStringContainsString('payroll.warn.no_declaration', (string) $db->scalar('SELECT re.warnings_json FROM pay_run_employees re JOIN employees e ON e.id = re.employee_id JOIN pay_runs r ON r.id = re.run_id WHERE r.public_id = ? AND e.employee_code = ?', [$run, 'D1-0001']));
        $note = (string) $db->scalar("SELECT l.note FROM pay_result_lines l JOIN pay_run_employees re ON re.id = l.run_employee_id JOIN employees e ON e.id = re.employee_id WHERE e.employee_code = 'D1-0004' AND l.component_code = 'TAX'");
        self::assertStringContainsString('year_income:222000.00;expense:80000.00;allowances:102194.00;net:39806.00;year_tax:1980.60;withheld:1200.00;months_left:3', $note);
        self::assertTrue($svc->replay($maker, $run), 'replays from the snapshot (declaration and year-to-date are inputs)');
        $svc->submit($maker, $run, '');
        $this->decide($run, 'payroll.checker');
        // G6C-03 year to date: tax 1 200 + 260.20 = 1 460.20
        self::assertSame(14602000, $slips->ytd((int) $e4['id'], $le, 2026, '2026-12-31')['wht']);

        // certificates: only payroll.approve; payer details first; people without a tax id are listed, not issued
        try {
            $tax->issue($maker, $le, 2026);
            self::fail('maker');
        } catch (\Blue\Core\Error\HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        try {
            $tax->issue($checker, $le, 2026);
            self::fail('no payer');
        } catch (BusinessException $e) {
            self::assertSame('payroll.error.no_payer', $e->messageKey);
        }
        $payer = $svc->proposeRules(
            $maker,
            $le,
            'payroll.wht_payer',
            ['tax_id' => '0-0000-00000-00-0', 'name' => 'บริษัทตัวอย่าง จำกัด (DEMO)', 'address' => '1 ถนนทดสอบ แขวงทดสอบ เขตทดสอบ กรุงเทพฯ (DEMO)', 'branch' => '00000'],
            '2026-01-01',
            'หนังสือรับรองบริษัท (ทดสอบ)',
            'ทดสอบ'
        );
        $this->decide($payer, 'payroll.checker');
        $db->run(
            "INSERT INTO employee_identifiers (public_id, created_at, updated_at, employee_id, id_type, value_enc, value_hash, last4) VALUES (?, ?, ?, ?, 'TH_CITIZEN_ID', ?, ?, '0004')",
            [Ulid::generate($app->clock), $now, $now, $e4['id'], $app->crypto()->encrypt('1999900000004'), random_bytes(32)]
        );
        self::assertSame(['issued' => 1, 'no_id' => 6, 'outdated' => 0], $tax->issue($checker, $le, 2026));
        $cert = $db->one("SELECT * FROM pay_tax_certificates WHERE status = 'issued'") ?? [];
        self::assertSame(['WHT2026-00001', '14602000', '0004'], [$cert['cert_no'], (string) Money::fromDb($cert['wht']), $cert['payee_last4']]);
        self::assertSame(['payroll.tax_certificate', 'R'], array_values($db->one('SELECT document_class, classification FROM documents WHERE public_id = ?', [$cert['document_pid']]) ?? []));
        self::assertSame(['issued' => 0, 'no_id' => 6, 'outdated' => 0], $tax->issue($checker, $le, 2026), 'once');

        // the employee downloads it (password first); someone else cannot
        $x = $emp->get('/me/tax/certificates/' . $cert['public_id'] . '/pdf');
        self::assertSame(302, $x->status);
        $this->reauth($emp, '/me/tax');
        $pdf = $emp->get('/me/tax/certificates/' . $cert['public_id'] . '/pdf');
        self::assertStringStartsWith('%PDF', $pdf->body);
        self::assertSame($cert['sha256'], hash('sha256', $pdf->body));
        $mgr = $this->as('manager.demo1');
        $this->reauth($mgr, '/me/tax');
        self::assertSame(404, $mgr->get('/me/tax/certificates/' . $cert['public_id'] . '/pdf')->status);

        // totals change → out of date → reissue with a reason (old voided, new points to it)
        $db->run("INSERT INTO pay_ytd_entries (public_id, created_at, updated_at, employee_id, legal_entity_id, tax_year, source_type, source_ref, as_of, gross, taxable_income, wht, sso_employee, status)
                  VALUES (?, ?, ?, ?, ?, 2026, 'opening', ?, '2026-09-30', 0, 1000, 100, 0, 'approved')", [$p = Ulid::generate($app->clock), $now, $now, $e4['id'], $le, $p]);
        $c4 = array_values(array_filter($tax->candidates($le, 2026), static fn (array $c): bool => $c['employee_code'] === 'D1-0004'))[0];
        self::assertTrue($c4['outdated']);
        try {
            $tax->reissue($checker, (string) $cert['public_id'], 'x');
            self::fail('reason');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('reason', $e->errors);
        }
        $new = $tax->reissue($checker, (string) $cert['public_id'], 'ยอดยกมาแก้ไข (ทดสอบ)');
        self::assertSame('voided', $db->scalar('SELECT status FROM pay_tax_certificates WHERE id = ?', [$cert['id']]));
        self::assertSame(['WHT2026-00002', (string) $cert['id'], '1460.20'], array_values(array_map('strval', $db->one('SELECT cert_no, supersedes_id, ROUND(wht - 100, 2) FROM pay_tax_certificates WHERE public_id = ?', [$new]) ?? [])));
        self::assertSame(404, $emp->get('/me/tax/certificates/' . $cert['public_id'] . '/pdf')->status, 'a voided certificate is gone for the employee');
        self::assertSame(200, $this->as('payroll.maker')->get('/payroll/tax', ['year' => '2026'])->status);

        // no full tax id in audit or logs
        $audit = implode("\n", array_map(static fn (array $a): string => (string) ($a['diff_json'] ?? '') . ' ' . ($a['reason'] ?? ''), $db->all('SELECT * FROM audit_events')));
        $logs = implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), glob($this->t->tmp . '/logs/*') ?: []));
        self::assertStringNotContainsString('1999900000004', $audit . $logs);
    }

    public function testBahtText(): void
    {
        $u = static fn (string $v): int => Money::parse($v, 2) ?? 0;
        self::assertSame('หนึ่งพันสี่ร้อยหกสิบบาทยี่สิบสตางค์', TaxService::bahtText($u('1460.20')));
        self::assertSame('ยี่สิบเอ็ดบาทถ้วน', TaxService::bahtText($u('21')));
        self::assertSame('หนึ่งร้อยเอ็ดบาทถ้วน', TaxService::bahtText($u('101')));
        self::assertSame('สิบบาทถ้วน', TaxService::bahtText($u('10')));
        self::assertSame('หนึ่งบาทถ้วน', TaxService::bahtText($u('1')));
        self::assertSame('หนึ่งล้านเอ็ดบาทถ้วน', TaxService::bahtText($u('1000001')));
        self::assertSame('สิบเอ็ดล้านสองแสนบาทถ้วน', TaxService::bahtText($u('11200000')));
        self::assertSame('ห้าสิบสตางค์', TaxService::bahtText($u('0.50')));
        self::assertSame('ศูนย์บาทถ้วน', TaxService::bahtText(0));
    }
}
