<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\ProfileService;
use Blue\Modules\Filing\FilingCalc;
use Blue\Modules\Filing\FilingService;
use Blue\Modules\Filing\OfficialFile;
use Blue\Modules\Payroll\PayrollService;
use Blue\Modules\Payroll\PayslipService;
use Blue\Tests\Support\TestApp;

/**
 * 0.47.0 (R8-2 · DEC-116): the annual withholding tax filing (P.N.D. 1A) — the year's approved runs plus approved opening
 * balances, the registered address in parts on the employee, and the official text file in the layout of the sample.
 * Clock: Mon 2026-11-09 (the year 2026 has not ended). Every value is a TEST value; id numbers are made up (19999…).
 */
final class FilingAnnualWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(filing|payroll|employees|workflow|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    private const RD = ['type_original' => '1', 'type_additional' => '2', 'h_field1' => '0000', 'h_field3' => '000000', 'h_field6' => '0000', 'd_branch' => '000000', 'd_tin' => '0000000000',
        'd_cond' => '1', 'd_tax_rate' => '0.00', 'names_line' => '1'];

    private const ADDRESS = ['house_no' => '99/1', 'building' => '', 'alley' => 'สุขุมวิท 21', 'road' => 'สุขุมวิท', 'subdistrict' => 'คลองเตยเหนือ', 'district' => 'วัฒนา', 'province' => 'กรุงเทพมหานคร', 'postcode' => '10110'];

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

    /** @return list<string> */
    private static function keys(array $problems): array
    {
        return array_map(static fn (array $c): string => $c['key'] . (isset($c['params']['code']) ? ':' . $c['params']['code'] : ''), $problems);
    }

    public function testAnnualFilingWithOpeningBalancesAndAddresses(): void
    {
        $app = $this->t->app;
        $db = $app->db();
        $svc = PayrollService::of($app);
        $fs = FilingService::of($app);
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $pm = $this->az('payroll.maker');
        foreach (
            [
                ['payroll.policy', ['monthly_divisor' => '30', 'hours_per_day' => '8', 'proration' => 'calendar_days', 'daily_paid' => ['worked_days', 'holiday_days']]],
                ['payroll.sso', ['rate_employee' => '3.5', 'rate_employer' => '4', 'wage_floor' => '1000', 'wage_cap' => '20000', 'rounding' => 'half_up_baht']],
                ['payroll.wht_payer', ['tax_id' => '0-0000-00000-00-0', 'name' => 'บริษัทตัวอย่าง จำกัด (DEMO)', 'address' => '1 ถนนทดสอบ (DEMO)', 'branch' => '00000']],
                ['payroll.filing_due', ['wht_day' => '7', 'sso_day' => '15']],
                ['payroll.rd_file', self::RD],
            ] as [$key, $val]
        ) {
            $this->approve($svc->proposeRules($pm, $le, $key, $val, '2026-01-01', 'ค่าทดสอบเท่านั้น', 'ทดสอบ'));
        }
        $codes = ['D1-0001', 'D1-0002', 'D1-0003', 'D1-0004', 'D1-0005', 'D1-0006', 'D1-0007'];
        $lines = [];
        $now = $app->clock->now()->format('Y-m-d H:i:s.u');
        $emp = static fn (string $code): array => $db->one('SELECT id, public_id FROM employees WHERE employee_code = ?', [$code]) ?? [];
        foreach ($codes as $i => $code) {
            $lines[] = ['employee_code' => $code, 'name' => $code, 'scheduled_days' => 22, 'worked_days' => 22, 'absent_days' => 0, 'missing_punch_days' => 0, 'late_count' => 0, 'late_minutes' => 0,
                'early_count' => 0, 'early_minutes' => 0, 'worked_minutes' => 22 * 480, 'leave_days' => 0, 'leave' => [], 'holiday_days' => 1, 'off_days' => 8, 'no_data_days' => 0, 'corrected_days' => 0];
            $db->run(
                "INSERT INTO employee_identifiers (public_id, created_at, updated_at, employee_id, id_type, value_enc, value_hash, last4) VALUES (?, ?, ?, ?, 'TH_CITIZEN_ID', ?, ?, ?)",
                [Ulid::generate($app->clock), $now, $now, $emp($code)['id'], $app->crypto()->encrypt('199990000000' . $i), random_bytes(32), '000' . $i]
            );
        }
        $db->run("UPDATE employees SET title = 'นาย' WHERE employee_code LIKE 'D1-000%'");

        // ---- opening balance of D1-0001 (paid by the old program Jan–Sep), approved before the first run of the year ----------
        $slips = PayslipService::of($app);
        $open = $slips->proposeOpening($pm, $emp('D1-0001')['public_id'], ['tax_year' => '2026', 'as_of' => '2026-09-30', 'gross' => '300000', 'taxable_income' => '300000', 'wht' => '4500',
            'sso_employee' => '6750', 'note' => 'ทะเบียนเงินเดือนโปรแกรมเดิม ม.ค.–ก.ย. (ทดสอบ)']);
        $slips->decideOpening($this->az('payroll.checker'), $open, true);

        $calc = $app->ledger()->record('attendance.period', 'TEST', 'TEST', $app->clock->now(), [], [], [], ['lines' => $lines, 'leave_types' => []], ['legal_entity_id' => $le]);
        $db->run(
            "INSERT INTO att_periods (public_id, created_at, updated_at, legal_entity_id, period_start, period_end, status, revision, snapshot_run_pid, output_hash) VALUES (?, ?, ?, ?, '2026-10-01', '2026-10-31', 'finalized', 1, ?, 'x')",
            [Ulid::generate($app->clock), $now, $now, $le, $calc]
        );
        $comp = (string) $db->scalar("SELECT public_id FROM pay_components WHERE legal_entity_id = ? AND code = 'WHT'", [$le]);
        $oct = $svc->createRun($pm, $le, ['label' => 'เงินเดือน ต.ค. 2569 (ทดสอบ)', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-10-31']);
        $svc->addManual($pm, $oct, ['employee' => $emp('D1-0001')['public_id'], 'component' => $comp, 'amount' => '1500', 'note' => 'ภาษีตามฝ่ายภาษี (ทดสอบ)']);
        $svc->calculate($pm, $oct);
        $svc->submit($pm, $oct, '');
        $this->approve($oct);

        // ---- a year is not a month (and a month is not a year) ---------------------------------------------------------------
        foreach ([['wht_annual', '2026-10'], ['wht_monthly', '2026'], ['wht_annual', '2027']] as [$k, $p]) {
            try {
                $fs->generate($pm, $le, $k, $p, '');
                self::fail($k . ' ' . $p);
            } catch (ValidationException $e) {
                self::assertSame(['period'], array_keys($e->errors));
            }
        }

        // ---- the annual revision = the year's runs + the opening balance -----------------------------------------------------
        $mo = $fs->revision($pm, $fs->generate($pm, $le, 'wht_monthly', '2026-10', ''));
        $y = $fs->generate($pm, $le, 'wht_annual', '2026', '');
        $an = $fs->revision($pm, $y);
        self::assertSame('ready', $an['status']);
        self::assertSame('BLUE_FILING_WHT_ANNUAL_REVIEW_v1', $an['schema_version']);
        self::assertNull($an['due']);
        $wk = array_map(static fn (array $c): string => $c['key'] . (isset($c['params']['code']) ? ':' . $c['params']['code'] : ''), $an['warnings']);
        self::assertContains('filing.check.year_open', $wk);
        self::assertContains('filing.check.no_address:D1-0001', $wk);
        self::assertNotContains('filing.check.due_missing', $wk);
        $byCode = static fn (array $rows): array => array_column($rows, null, 'code');
        $m1 = $byCode($mo['rows'])['D1-0001'];
        $a1 = $byCode($an['rows'])['D1-0001'];
        self::assertSame($m1['income'] + 300000 * 10000, $a1['income']);
        self::assertSame($m1['wht'] + 4500 * 10000, $a1['wht']);
        self::assertSame([300000 * 10000, 4500 * 10000], [$a1['opening_income'], $a1['opening_wht']]);
        self::assertSame(count($mo['rows']), count($an['rows']));
        self::assertTrue($fs->replay($pm, $y), 'worked out again from its stored inputs, the same rows come out');

        // ---- a new opening balance makes the annual revision out of date ----------------------------------------------------
        $o2 = $slips->proposeOpening($pm, $emp('D1-0002')['public_id'], ['tax_year' => '2026', 'as_of' => '2026-09-30', 'gross' => '10', 'taxable_income' => '10', 'wht' => '', 'sso_employee' => '',
            'note' => 'ยอดยกมาเพิ่ม (ทดสอบ)']);
        $slips->decideOpening($this->az('payroll.checker'), $o2, true);
        self::assertTrue($fs->revision($pm, $y)['source_changed']);
        self::assertFalse($fs->revision($pm, $mo['public_id'])['source_changed'], 'the monthly revision does not look at opening balances');
        $y = $fs->generate($pm, $le, 'wht_annual', '2026', '');
        self::assertSame(10 * 10000, $byCode($fs->revision($pm, $y)['rows'])['D1-0002']['opening_income']);
        $fs->submit($pm, $y, '');
        $this->approve($y);

        // ---- the official file needs everyone's address in parts --------------------------------------------------------------
        $o = $fs->official($pm, $y, false);
        self::assertContains('filing.official.no_address:D1-0001', self::keys($o['problems']));
        $hr = $this->az('hr.demo1');
        $ps = ProfileService::of($app);
        try {
            $ps->saveContacts($hr, $emp('D1-0001')['public_id'], ['tax_address' => ['house_no' => '1', 'province' => 'ไม่มีจังหวัดนี้', 'postcode' => '1011', 'road' => 'ก|ข']], false);
            self::fail('validation');
        } catch (ValidationException $e) {
            self::assertSame(['tax_address_road', 'tax_address_subdistrict', 'tax_address_district', 'tax_address_province', 'tax_address_postcode'], array_keys($e->errors));
        }
        foreach ($codes as $code) {
            $ps->saveContacts($hr, $emp($code)['public_id'], ['tax_address' => self::ADDRESS], false);
        }
        self::assertSame(7, (int) $db->scalar('SELECT COUNT(*) FROM employee_tax_addresses'));
        self::assertNull($db->scalar("SELECT building FROM employee_tax_addresses WHERE employee_id = ?", [$emp('D1-0001')['id']]), 'an empty part is kept empty');
        $audit = (string) $db->scalar("SELECT diff_json FROM audit_events WHERE action = 'employee.contacts_updated' ORDER BY id DESC LIMIT 1");
        self::assertStringContainsString('tax_address', $audit);
        self::assertStringNotContainsString('คลองเตยเหนือ', $audit, 'the address itself is not in the audit');

        $o = $fs->official($pm, $y);
        self::assertSame([], $o['problems']);
        self::assertSame('PND1A-DEMO1-2026-r2.txt', $o['name']);
        $l = explode("\n", (string) $o['bytes']);
        self::assertCount(2 + count($an['rows']), $l);
        self::assertStringStartsWith('RECORD_TYPE|SEQ_NO|BRANCH|PIN|TIN|TITLE|FIRST_NAME|LAST_NAME|PAID_DATE|', $l[0]);
        self::assertSame('H|0000|0000000000000|000000|PND1A|0000000000000|0000|00|2569|1', $l[1]);
        $f = explode('|', array_values(array_filter($l, static fn (string $x): bool => str_contains($x, '|1999900000000|')))[0]);
        self::assertCount(21, $f);
        self::assertSame(['31122569', '0.00', \Blue\Core\Support\Money::format($a1['income'], 2, false), \Blue\Core\Support\Money::format($a1['wht'], 2, false), '1'], [$f[8], $f[9], $f[10], $f[11], $f[12]]);
        self::assertSame(['99/1', '-', 'สุขุมวิท 21', 'สุขุมวิท', 'คลองเตยเหนือ', 'วัฒนา', 'กรุงเทพมหานคร', '10110'], array_slice($f, 13));
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM fl_events WHERE event = 'official' AND note LIKE 'RD_PND1A_TXT_v1%'"));

        // ---- screens ------------------------------------------------------------------------------------------------------------
        $b = $this->t->browser();
        $b->login('payroll.maker');
        $lePid = (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        $res = $b->get('/filing', ['le' => $lePid, 'year' => '2026']);
        self::assertSame(200, $res->status);
        self::assertStringContainsString('ภ.ง.ด.1ก) ปี 2026', $res->body);
        self::assertStringContainsString('/filing/r/' . $y, $res->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body));
        $res = $b->get('/filing/r/' . $y);
        self::assertSame(200, $res->status);
        self::assertStringContainsString('ดาวน์โหลดไฟล์ ภ.ง.ด.1ก', $res->body);
        self::assertStringContainsString('จากยอดยกมา', $res->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body));
        $h = $this->t->browser();
        $h->login('hr.demo1');
        $res = $h->get('/employees/' . $emp('D1-0001')['public_id'], ['tab' => 'personal']);
        self::assertSame(200, $res->status);
        self::assertStringContainsString('name="tax_address[postcode]"', $res->body);
        self::assertStringContainsString('value="คลองเตยเหนือ"', $res->body);
        self::assertMatchesRegularExpression('/<option value="กรุงเทพมหานคร"[^>]*selected/u', $res->body);
        self::assertSame('99/1 สุขุมวิท 21 สุขุมวิท คลองเตยเหนือ วัฒนา กรุงเทพมหานคร 10110', ProfileService::addressLine(self::ADDRESS));
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body));
    }

    public function testCalcAndFileForAPersonWithOnlyAnOpeningBalance(): void
    {
        $base = ['kind' => 'wht_annual', 'employer' => ['present' => true], 'runs' => [['pid' => 'r', 'run_no' => 1, 'run_type' => 'regular', 'intact' => true]], 'open_runs' => [], 'not_paid' => [], 'year_open' => false];
        $p = ['code' => 'X1', 'name' => 'ทดสอบ สมมุติ', 'id_ok' => true, 'id_last4' => '0001', 'taxable' => 0, 'wht' => 0, 'sso_ee' => 0, 'sso_er' => 0, 'sso_base' => 0, 'sso_wage_used' => 0,
            'sso_wage_unknown' => false, 'ytd' => null, 'in_runs' => false, 'opening' => ['taxable' => 120000 * 10000, 'wht' => 900 * 10000], 'address_ok' => true];
        $out = FilingCalc::build($base + ['people' => [$p]]);
        self::assertSame([], $out['errors'], 'no year-to-date check for a person who was not in any run');
        self::assertSame([], $out['warnings']);
        self::assertSame(['code' => 'X1', 'name' => 'ทดสอบ สมมุติ', 'id_last4' => '0001', 'income' => 1200000000, 'wht' => 9000000, 'opening_income' => 1200000000, 'opening_wht' => 9000000], $out['rows'][0]);
        $txt = OfficialFile::pnd1a(['names_line' => false] + self::RD, '0000000000000', '2025', 'additional', [['code' => 'X1', 'title' => 'นาง', 'name' => 'ทดสอบ สมมุติ', 'id' => '1999900000009',
            'income' => 1200000000, 'wht' => 9000000, 'address' => self::ADDRESS]])['text'];
        self::assertSame("H|0000|0000000000000|000000|PND1A|0000000000000|0000|00|2568|2\nD|1|000000|1999900000009|0000000000|นาง|ทดสอบ|สมมุติ|31122568|0.00|120000.00|900.00|1|99/1|-|สุขุมวิท 21|สุขุมวิท|คลองเตยเหนือ|วัฒนา|กรุงเทพมหานคร|10110", $txt);
        self::assertSame(['filing.official.no_address:X1'], self::keys(OfficialFile::pnd1a(self::RD, '0000000000000', '2025', 'original', [['code' => 'X1', 'title' => 'นาง', 'name' => 'ทดสอบ สมมุติ',
            'id' => '1999900000009', 'income' => 1, 'wht' => 0, 'address' => array_merge(self::ADDRESS, ['postcode' => '1'])]])['problems']));
    }
}
