<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Pdf\Dict;
use Blue\Core\Pdf\Name;
use Blue\Core\Pdf\Stream;
use Blue\Core\Pdf\Writer;
use Blue\Core\Support\Money;
use Blue\Core\Support\Ulid;
use Blue\Modules\Filing\FilingService;
use Blue\Modules\Payroll\PayrollService;
use Blue\Tests\Support\TestApp;

/**
 * Phase 6E (DEC-88): M58 government filing — monthly withholding tax and social security from approved payroll runs,
 * checks, independent review, review-copy export (hash), submission and result, additional filing, stale source.
 * Clock: Mon 2026-11-09. Rule values are TEST values (social security 3.5 % / 4 %, floor 1 000, cap 20 000, due days 7/15).
 */
final class FilingWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(filing|payroll|workflow|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

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

    private function approve(string $subjectPid, string $who = 'payroll.checker'): void
    {
        $i = (string) $this->t->app->db()->scalar('SELECT public_id FROM workflow_instances WHERE subject_id = ? ORDER BY id DESC LIMIT 1', [$subjectPid]);
        $this->t->app->workflow()->decide($i, (int) $this->t->user($who)['id'], 'approve', 'ตรวจแล้ว');
    }

    private static function pdf(string $label): string
    {
        $w = new Writer();
        $c = $w->add(new Stream(new Dict(), 'BT /F1 18 Tf 72 760 Td (' . $label . ') Tj ET'));
        $w->addPage(new Dict(['Type' => new Name('Page'), 'MediaBox' => [0, 0, 595.28, 841.89],
            'Resources' => new Dict(['Font' => new Dict(['F1' => new Dict(['Type' => new Name('Font'), 'Subtype' => new Name('Type1'), 'BaseFont' => new Name('Helvetica')])])]), 'Contents' => $c]));
        return $w->output();
    }

    private function page(string $user, string $path, int $status = 200): string
    {
        $b = $this->t->browser();
        $b->login($user);
        $q = [];
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $q);
        }
        $r = $b->get($path, $q);
        self::assertSame($status, $r->status, $path . ' as ' . $user);
        if ($status === 200) {
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($r->body), $path);
        }
        return $r->body;
    }

    /** @return list<string> keys of a revision's checks */
    private function checks(string $pid, string $which): array
    {
        $r = FilingService::of($this->t->app)->revision($this->az('payroll.maker'), $pid);
        return array_map(static fn (array $c): string => $c['key'] . (isset($c['params']['code']) ? ':' . $c['params']['code'] : ''), $r[$which]);
    }

    public function testMonthlyFilingFromApprovedRuns(): void
    {
        $app = $this->t->app;
        $db = $app->db();
        $svc = PayrollService::of($app);
        $fs = FilingService::of($app);
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $lePid = (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
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
        $comp = static fn (string $code): string => (string) $db->scalar('SELECT public_id FROM pay_components WHERE legal_entity_id = ? AND code = ?', [$le, $code]);
        $emp = static fn (string $code): array => $db->one('SELECT id, public_id FROM employees WHERE employee_code = ?', [$code]) ?? [];
        $oct = $svc->createRun($pm, $le, ['label' => 'เงินเดือน ต.ค. 2569 (ทดสอบ)', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-10-31']);
        $svc->addManual($pm, $oct, ['employee' => $emp('D1-0001')['public_id'], 'component' => $comp('WHT'), 'amount' => '1500', 'note' => 'ภาษีตามฝ่ายภาษี (ทดสอบ)']);
        $svc->calculate($pm, $oct);
        $svc->submit($pm, $oct, '');
        $this->approve($oct);
        $people = (int) $db->scalar('SELECT employee_count FROM pay_runs WHERE public_id = ?', [$oct]);
        $sum = static fn (string $statutory): int => Money::fromDb($db->scalar(
            'SELECT COALESCE(SUM(l.amount), 0) FROM pay_result_lines l JOIN pay_run_employees re ON re.id = l.run_employee_id JOIN pay_runs r ON r.id = re.run_id JOIN pay_components c ON c.id = l.component_id WHERE r.public_id = ? AND c.statutory = ?',
            [$oct, $statutory]
        ));

        // ---- nothing set up yet: the checks say what is missing ------------------------------------------------------------
        try {
            $fs->generate($pm, $le, 'wht_monthly', '2026-12', '');
            self::fail('a month that has not started');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('period', $e->errors);
        }
        $r1 = $fs->generate($pm, $le, 'wht_monthly', '2026-10', '');
        $rev = $fs->revision($pm, $r1);
        self::assertSame(['validation_failed', 'original', 1], [$rev['status'], $rev['purpose'], (int) $rev['revision_no']]);
        $errs = $this->checks($r1, 'errors');
        self::assertContains('filing.check.payer_missing', $errs);
        self::assertContains('filing.check.no_id:D1-0001', $errs);
        self::assertContains('filing.check.due_missing', $this->checks($r1, 'warnings'));
        try {
            $fs->submit($pm, $r1, '');
            self::fail('errors block');
        } catch (BusinessException $e) {
            self::assertSame('filing.error.has_errors', $e->messageKey);
        }

        // ---- company details, due days (TEST values), identifiers -------------------------------------------------------------
        foreach (
            [
                ['payroll.wht_payer', ['tax_id' => '0-0000-00000-00-0', 'name' => 'บริษัทตัวอย่าง จำกัด (DEMO)', 'address' => '1 ถนนทดสอบ แขวงทดสอบ เขตทดสอบ กรุงเทพฯ (DEMO)', 'branch' => '00000']],
                ['payroll.sso_employer', ['account_no' => '00-0000000-0', 'branch_no' => '000000', 'name' => 'บริษัทตัวอย่าง จำกัด (DEMO)']],
                ['payroll.filing_due', ['wht_day' => '7', 'sso_day' => '15']],
            ] as [$key, $val]
        ) {
            $this->approve($svc->proposeRules($pm, $le, $key, $val, '2026-01-01', 'ค่าทดสอบเท่านั้น (ไม่ใช่วันตามกฎหมาย)', 'ทดสอบ'));
        }
        try {
            $svc->proposeRules($pm, $le, 'payroll.filing_due', ['wht_day' => '0', 'sso_day' => 'x'], '2026-01-01', 'ค่าทดสอบ', 'ทดสอบ');
            self::fail('days');
        } catch (ValidationException $e) {
            self::assertSame(['wht_day', 'sso_day'], array_keys($e->errors));
        }
        foreach (['D1-0001', 'D1-0002', 'D1-0003', 'D1-0004', 'D1-0005', 'D1-0006', 'D1-0007'] as $i => $code) {
            $db->run(
                "INSERT INTO employee_identifiers (public_id, created_at, updated_at, employee_id, id_type, value_enc, value_hash, last4) VALUES (?, ?, ?, ?, 'TH_CITIZEN_ID', ?, ?, ?)",
                [Ulid::generate($app->clock), $now, $now, $emp($code)['id'], $app->crypto()->encrypt('199990000000' . $i), random_bytes(32), '000' . $i]
            );
        }

        // ---- G6E-01 withholding tax of October = the lines of the approved run ------------------------------------------------
        $r2 = $fs->generate($pm, $le, 'wht_monthly', '2026-10', '');
        $rev = $fs->revision($pm, $r2);
        self::assertSame(['ready', 2, []], [$rev['status'], (int) $rev['revision_no'], $rev['errors']]);
        self::assertSame('superseded', $fs->revision($pm, $r1)['status'], 'the earlier revision is replaced, not overwritten');
        self::assertSame([$people, Money::fromDb($rev['total_b'])], [(int) $rev['people'], $sum('wht')]);
        self::assertSame(1500 * 10000, $sum('wht'));
        $row1 = array_values(array_filter($rev['rows'], static fn (array $x): bool => $x['code'] === 'D1-0001'))[0];
        self::assertSame(['0000', 1500 * 10000], [$row1['id_last4'], $row1['wht']]);
        self::assertSame('2026-11-07', $rev['due']);
        self::assertTrue($fs->replay($pm, $r2), 'the rows and checks come out the same from the stored inputs');

        // ---- review by another person -------------------------------------------------------------------------------------------
        try {
            $fs->submit($pc, $r2, '');
            self::fail('checker cannot prepare');
        } catch (\Blue\Core\Error\HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        $fs->submit($pm, $r2, 'ตรวจยอดแล้ว (ทดสอบ)');
        try {
            $fs->generate($pm, $le, 'wht_monthly', '2026-10', '');
            self::fail('pending');
        } catch (BusinessException $e) {
            self::assertSame('filing.error.pending', $e->messageKey);
        }
        try {
            $fs->export($pm, $r2);
            self::fail('approve first');
        } catch (BusinessException $e) {
            self::assertSame('filing.error.approve_first', $e->messageKey);
        }
        $this->approve($r2);
        $rev = $fs->revision($pm, $r2);
        self::assertSame(['approved', (int) $this->t->user('payroll.checker')['id']], [$rev['status'], (int) $rev['approved_by']]);

        // ---- export → submission → result ---------------------------------------------------------------------------------------
        $x1 = $fs->export($pm, $r2);
        self::assertStringStartsWith('PK', $x1['bytes']);
        self::assertSame('filing-review-DEMO1-wht_monthly-2026-10-r2.xlsx', $x1['name']);
        $rev = $fs->revision($pm, $r2);
        self::assertSame(['exported', hash('sha256', $x1['bytes'])], [$rev['status'], $rev['export_sha256']]);
        $fs->export($pm, $r2);
        self::assertSame(hash('sha256', $x1['bytes']), $fs->revision($pm, $r2)['export_sha256'], 'the first file\'s hash is kept');
        try {
            $fs->recordSubmission($pm, $r2, ['submitted_on' => '2026-11-10', 'reference' => 'x'], null);
            self::fail('validation');
        } catch (ValidationException $e) {
            self::assertSame(['submitted_on', 'reference'], array_keys($e->errors));
        }
        $tmp = $this->t->tmp;
        $fs->recordSubmission($pm, $r2, ['submitted_on' => '2026-11-06', 'reference' => 'TEST-REF-0001'], UploadedFile::fromBytes('receipt.pdf', self::pdf('Filing receipt TEST'), $tmp));
        $rev = $fs->revision($pm, $r2);
        self::assertSame(['submitted', '2026-11-06', 'TEST-REF-0001'], [$rev['status'], $rev['submitted_on'], $rev['submission_ref']]);
        self::assertSame(['filing.receipt', 'R'], array_values($db->one('SELECT document_class, classification FROM documents WHERE public_id = ?', [$rev['receipt_document_pid']]) ?? []));
        try {
            $fs->recordResult($pm, $r2, 'rejected', '');
            self::fail('note');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('note', $e->errors);
        }
        $fs->recordResult($pm, $r2, 'accepted', '');
        self::assertSame('accepted', $fs->revision($pm, $r2)['status']);
        $ov = $fs->overview($pm, $le, 2026);
        $octRow = array_values(array_filter($ov, static fn (array $m): bool => $m['period'] === '2026-10'))[0];
        self::assertSame([true, false, '2026-11-07', 1], [$octRow['kinds']['wht_monthly']['done'], $octRow['kinds']['wht_monthly']['overdue'], $octRow['kinds']['wht_monthly']['due'], $octRow['runs']]);
        self::assertSame([false, false, '2026-11-15'], [$octRow['kinds']['sso_monthly']['done'], $octRow['kinds']['sso_monthly']['overdue'], $octRow['kinds']['sso_monthly']['due']], 'due 15 Nov, today 9 Nov');

        // ---- G6E-02 social security of October ----------------------------------------------------------------------------------
        $s1 = $fs->generate($pm, $le, 'sso_monthly', '2026-10', '');
        $rev = $fs->revision($pm, $s1);
        self::assertSame(['ready', $people, $sum('sso_employee'), $sum('sso_employer')], [$rev['status'], (int) $rev['people'], Money::fromDb($rev['total_b']), Money::fromDb($rev['total_c'])]);
        $r4 = array_values(array_filter($rev['rows'], static fn (array $x): bool => $x['code'] === 'D1-0004'))[0];
        self::assertSame([18500 * 10000, 18500 * 10000, 648 * 10000, 740 * 10000, false], [$r4['wage'], $r4['wage_used'], $r4['ee'], $r4['er'], $r4['wage_unknown']]);
        $fs->submit($pm, $s1, '');

        // ---- a correction paid in October: the filed month needs an additional filing; the open one is out of date ---------------
        $this->t->clock->advance('+1 hour');
        $this->approve($svc->proposeProfile($pm, $emp('D1-0004')['public_id'], ['effective_from' => '2026-10-01', 'pay_basis' => 'monthly', 'base_amount' => '20000', 'reason' => 'ปรับย้อนหลัง (ทดสอบ)', 'items' => [], 'retro' => true]));
        $corr = $svc->createCorrection($pm, $oct, ['pay_date' => '2026-10-31', 'purpose' => 'ปรับเงินเดือนย้อนหลัง (ทดสอบ)']);
        $svc->calculate($pm, $corr);
        $svc->submit($pm, $corr, '');
        $this->t->clock->advance('+1 hour');
        $this->approve($corr);
        // the social security revision waiting for approval was made before the correction: approving still works, but
        // submitting a ready one made from older runs is refused
        $this->approve($s1);
        self::assertTrue($fs->revision($pm, $s1)['source_changed']);
        try {
            $fs->export($pm, $s1);
            self::fail('approved but out of date');
        } catch (BusinessException $e) {
            self::assertSame('filing.error.stale', $e->messageKey);
        }
        try {
            $fs->generate($pm, $le, 'wht_monthly', '2026-10', 'สั้น');
            self::fail('reason');
        } catch (ValidationException $e) {
            self::assertSame('filing.error.reason_additional', $e->errors['reason']);
        }
        $r3 = $fs->generate($pm, $le, 'wht_monthly', '2026-10', 'มีรอบแก้ไขจ่ายในเดือน ต.ค. (ทดสอบ)');
        $rev = $fs->revision($pm, $r3);
        self::assertSame(['additional', 3, 'ready'], [$rev['purpose'], (int) $rev['revision_no'], $rev['status']]);
        self::assertSame('accepted', $fs->revision($pm, $r2)['status'], 'the filed revision stays as it was');
        $r4w = array_values(array_filter($rev['rows'], static fn (array $x): bool => $x['code'] === 'D1-0004'))[0];
        self::assertSame(20000 * 10000, $r4w['income'], 'October income now includes the 1 500 difference');
        $s2 = $fs->generate($pm, $le, 'sso_monthly', '2026-10', '');
        self::assertSame('superseded', $fs->revision($pm, $s1)['status']);
        $rev = $fs->revision($pm, $s2);
        $r4s = array_values(array_filter($rev['rows'], static fn (array $x): bool => $x['code'] === 'D1-0004'))[0];
        self::assertSame([700 * 10000, 800 * 10000, true], [$r4s['ee'], $r4s['er'], $r4s['wage_unknown']]);
        self::assertContains('filing.check.wage_unknown:D1-0004', $this->checks($s2, 'warnings'));
        // a run approved after a revision was made → that revision cannot be sent
        $bonus = $svc->createRun($pm, $le, ['type' => 'off_cycle', 'label' => 'โบนัส (ทดสอบ)', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-10-31', 'purpose' => 'โบนัส (ทดสอบ)']);
        $svc->addManual($pm, $bonus, ['employee' => $emp('D1-0004')['public_id'], 'component' => $comp('BONUS'), 'amount' => '1000', 'note' => 'โบนัส (ทดสอบ)']);
        $svc->calculate($pm, $bonus);
        $svc->submit($pm, $bonus, '');
        $this->approve($bonus);
        try {
            $fs->submit($pm, $s2, '');
            self::fail('stale');
        } catch (BusinessException $e) {
            self::assertSame('filing.error.stale', $e->messageKey);
        }

        // ---- who may see it; screens; nothing about amounts in the audit -----------------------------------------------------
        $this->page('employee.demo1', '/filing', 403);
        $this->page('hr.demo1', '/filing', 403);
        $this->page('payroll.checker', '/filing?le=' . $lePid);
        $body = $this->page('payroll.maker', '/filing?le=' . $lePid);
        self::assertStringContainsString('ภาษีหัก ณ ที่จ่ายรายเดือน (ภ.ง.ด.1)', $body);
        $body = $this->page('payroll.maker', '/filing/r/' . $s2);
        self::assertStringContainsString('รอบเงินเดือนของเดือนนี้เปลี่ยนไปแล้ว', $body);
        self::assertStringContainsString('ตรวจค่าจ้างเอง', $body);
        $this->page('payroll.maker', '/filing/r/' . $r2);
        $this->page('payroll.maker', '/filing/r/' . $r1);
        $this->page('payroll.maker', '/filing/r/' . $r3);
        $this->page('payroll.maker', '/payroll/settings?le=' . $lePid);
        $b = $this->t->browser();
        $b->login('hr.demo1');
        self::assertSame(404, $b->get('/filing/r/' . $r2)->status);
        foreach ($db->all("SELECT diff_json FROM audit_events WHERE action LIKE 'filing.%'") as $a) {
            self::assertStringNotContainsString('1500', (string) $a['diff_json']);
            self::assertStringNotContainsString('18500', (string) $a['diff_json']);
        }
        self::assertGreaterThanOrEqual(8, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action LIKE 'filing.%'"));
    }
}
