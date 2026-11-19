<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\Xlsx;
use Blue\Core\Support\Ulid;
use Blue\Modules\Filing\FilingService;
use Blue\Modules\Filing\OfficialFile;
use Blue\Modules\Payroll\PayrollService;
use Blue\Tests\Support\TestApp;

/**
 * 0.46.0 (R8-1 · DEC-115): the official upload files of an approved monthly filing — P.N.D. 1 text (RD Prep layout of
 * the sample file) and Sor.Por.Sor. 1-10 workbook. The file codes (payroll.rd_file) have no default; full ids are
 * decrypted only into the file; the file is not stored. Clock: Mon 2026-11-09. Every value here is a TEST value, and the
 * id numbers are made up (1999900000000…).
 */
final class FilingOfficialWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(filing|payroll|workflow|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    /** TEST values in the sample's shape — not taken from the Revenue Department */
    private const RD = ['type_original' => '1', 'type_additional' => '2', 'h_field1' => '0000', 'h_field3' => '000000', 'h_field6' => '0000', 'd_branch' => '000000', 'd_tin' => '0000000000',
        'd_cond' => '1', 'd_tax_rate' => '0.00', 'names_line' => '1'];

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

    /** @return list<string> */
    private static function keys(array $problems): array
    {
        return array_map(static fn (array $c): string => $c['key'] . (isset($c['params']['code']) ? ':' . $c['params']['code'] : ''), $problems);
    }

    public function testOfficialFilesFromAnApprovedRevision(): void
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
                ['payroll.sso_employer', ['account_no' => '00-0000000-0', 'branch_no' => '0', 'name' => 'บริษัทตัวอย่าง จำกัด (DEMO)']],
                ['payroll.filing_due', ['wht_day' => '7', 'sso_day' => '15']],
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
        $db->run("UPDATE employees SET title = 'นาย' WHERE employee_code IN ('D1-0001','D1-0002','D1-0003','D1-0004','D1-0005','D1-0006')");
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

        // ---- before approval: no official file ----------------------------------------------------------------------------------
        $w = $fs->generate($pm, $le, 'wht_monthly', '2026-10', '');
        try {
            $fs->official($pm, $w);
            self::fail('approve first');
        } catch (BusinessException $e) {
            self::assertSame('filing.error.approve_first', $e->messageKey);
        }
        $fs->submit($pm, $w, '');
        $this->approve($w);

        // ---- no file settings yet: blocked, nothing decrypted, nothing recorded ---------------------------------------------------
        $o = $fs->official($pm, $w, false);
        self::assertSame(['filing.official.no_rd_file', 'filing.official.no_title:D1-0007'], self::keys($o['problems']));
        $o = $fs->official($pm, $w);
        self::assertNull($o['bytes']);
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM fl_events WHERE event = 'official'"));

        // ---- the settings: validated, no default, approved by another person --------------------------------------------------------
        try {
            $svc->proposeRules($pm, $le, 'payroll.rd_file', ['type_original' => '1', 'type_additional' => '1', 'h_field1' => '00', 'h_field3' => '000000', 'h_field6' => '0000', 'd_branch' => 'x',
                'd_tin' => '0000000000', 'd_cond' => '1', 'd_tax_rate' => '0'], '2026-01-01', 'ค่าทดสอบเท่านั้น', 'ทดสอบ');
            self::fail('validation');
        } catch (ValidationException $e) {
            self::assertSame(['h_field1', 'd_branch', 'type_additional', 'd_tax_rate'], array_keys($e->errors));
        }
        $this->approve($svc->proposeRules($pm, $le, 'payroll.rd_file', self::RD, '2026-01-01', 'ค่าทดสอบเท่านั้น (ไม่ใช่ข้อกำหนดของกรมสรรพากร)', 'ทดสอบ'));
        $db->run("UPDATE employees SET title = 'นางสาว' WHERE employee_code = 'D1-0007'");

        // ---- P.N.D. 1 text ---------------------------------------------------------------------------------------------------------------
        $o = $fs->official($pm, $w);
        self::assertSame([], $o['problems']);
        self::assertSame('PND1-DEMO1-2026-10-r1.txt', $o['name']);
        $txt = (string) $o['bytes'];
        self::assertStringNotContainsString("\r", $txt);
        self::assertStringEndsNotWith("\n", $txt, 'no newline after the last line, as in the sample');
        $l = explode("\n", $txt);
        $rev = $fs->revision($pm, $w);
        self::assertCount(2 + (int) $rev['people'], $l);
        self::assertStringStartsWith('HEADER_TYPE|FIELD_1|', $l[0]);
        self::assertSame('H|0000|0000000000000|000000|PND1|0000000000000|0000|10|2569|1', $l[1]);
        $d1 = array_values(array_filter($l, static fn (string $x): bool => str_contains($x, '|1999900000000|')))[0];
        $f = explode('|', $d1);
        self::assertSame(['D', '000000', '1999900000000', '0000000000', 'นาย', 'ผู้จัดการฝ่ายบุคคล', '(DEMO)', '31102569', '0.00', '1500.00', '1'], [$f[0], $f[2], $f[3], $f[4], $f[5], $f[6], $f[7], $f[8], $f[9], $f[11], $f[12]]);
        self::assertCount(13, $f);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM fl_events WHERE event = 'official'"));
        $audit = (string) $db->scalar("SELECT diff_json FROM audit_events WHERE action = 'filing.official_exported' ORDER BY id DESC LIMIT 1");
        self::assertStringContainsString(hash('sha256', $txt), $audit);
        self::assertStringNotContainsString('19999', $audit, 'no id numbers in the audit');
        self::assertStringNotContainsString('1500', $audit, 'no amounts in the audit');
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM documents WHERE title LIKE 'PND1-%'"), 'the file is not stored');

        // ---- Sor.Por.Sor. 1-10 workbook ----------------------------------------------------------------------------------------------
        $s = $fs->generate($pm, $le, 'sso_monthly', '2026-10', '');
        $fs->submit($pm, $s, '');
        $this->approve($s);
        $o = $fs->official($pm, $s);
        self::assertSame([], $o['problems']);
        self::assertSame('SSO-1-10-DEMO1-2026-10-r1.xlsx', $o['name']);
        $rows = Xlsx::read((string) $o['bytes'], '000000');
        self::assertSame(['เลขประจำตัวประชาชน', 'คำนำหน้าชื่อ', 'ชื่อผู้ประกันตน', 'นามสกุลผู้ประกันตน', 'ค่าจ้าง', 'จำนวนเงินสมทบ'], array_slice($rows[0], 0, 6));
        $r4 = array_values(array_filter($rows, static fn (array $x): bool => ($x[0] ?? '') === '1999900000003'))[0];
        self::assertSame(['นาย', 'พนักงานตัวอย่าง', '(DEMO)', '18500', '648'], [$r4[1], $r4[2], $r4[3], $r4[4], $r4[5]]);

        // ---- screens ------------------------------------------------------------------------------------------------------------------
        $b = $this->t->browser();
        $b->login('payroll.maker');
        foreach ([$w, $s] as $pid) {
            $res = $b->get('/filing/r/' . $pid);
            self::assertSame(200, $res->status);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body));
            self::assertStringContainsString('/filing/r/' . $pid . '/official', $res->body);
            self::assertStringContainsString('ยังไม่ได้ทดสอบอัปโหลดกับระบบของหน่วยงาน', $res->body);
        }
        $res = $b->get('/payroll/settings', ['le' => (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le])]);
        self::assertSame(200, $res->status);
        self::assertStringContainsString('ไฟล์ตัวอย่าง: 000000', $res->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body));
        $c = $this->t->browser();
        $c->login('payroll.checker');
        self::assertStringNotContainsString('/official', $c->get('/filing/r/' . $w)->body, 'the approver does not get the file');
        $h = $this->t->browser();
        $h->login('hr.demo1');
        self::assertSame(404, $h->get('/filing/r/' . $w . '/official')->status);
    }

    public function testTheFileBuilderRefusesWhatItCannotWrite(): void
    {
        $set = self::RD;
        $people = [['code' => 'A', 'title' => 'นาย', 'name' => 'สมชาย', 'id' => '1999900000001', 'income' => 10000 * 10000, 'wht' => 0],
            ['code' => 'B', 'title' => '', 'name' => 'วิภา|รักสงบ', 'id' => null, 'income' => -1, 'wht' => 0]];
        $o = OfficialFile::pnd1($set, '123', '2026-10', 'original', '2026-10-31', $people);
        self::assertNull($o['text']);
        self::assertSame(['filing.official.no_payer', 'filing.official.name:A', 'filing.official.no_id:B', 'filing.official.no_title:B', 'filing.official.name:B', 'filing.official.negative:B'], self::keys($o['problems']));
        $ok = OfficialFile::pnd1(['names_line' => false] + $set, '0000000000000', '2026-02', 'additional', '2026-02-28', [['code' => 'A', 'title' => 'นาย', 'name' => 'สมชาย  ใจดี', 'id' => '1999900000001', 'income' => 3500012345, 'wht' => 4500000]]);
        self::assertSame("H|0000|0000000000000|000000|PND1|0000000000000|0000|02|2569|2\nD|1|000000|1999900000001|0000000000|นาย|สมชาย|ใจดี|28022569|0.00|350001.23|450.00|1", $ok['text']);
        self::assertSame(['สมชาย', 'ใจดี'], OfficialFile::splitName(' สมชาย ใจดี '));
        self::assertNull(OfficialFile::splitName('สมชาย'));
        self::assertSame(['filing.official.no_sso_branch'], self::keys(OfficialFile::sso('1234567', [['code' => 'A', 'title' => 'นาย', 'name' => 'ก ข', 'id' => '1999900000001', 'wage' => 1, 'ee' => 1]])['problems']));
    }
}
