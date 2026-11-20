<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\Csv;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Payments\BankTemplate;
use Blue\Tests\Support\TestApp;

/**
 * 0.48.0 (R8-3 · DEC-117): bank file formats as templates with variables — drafted by someone who prepares payments,
 * switched on by another person who approves payments, used to make a batch's file; made-up samples only.
 */
final class BankFormatsWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(payment|finance|form|common)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function az(string $u): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($u)['id']);
    }

    private function le(string $code): int
    {
        return (int) $this->t->app->db()->scalar('SELECT id FROM legal_entities WHERE code = ?', [$code]);
    }

    private function batch(): string
    {
        $app = $this->t->app;
        $b = $app->payments()->createManual($this->az('payroll.maker'), $this->le('DEMO1'), 'claim', '2026-11-25', '2026-11', 'DEMO');
        $imp = $app->imports();
        $pid = $imp->upload($this->az('payroll.maker'), 'payment.items', ['batch' => $b], UploadedFile::fromBytes('x.csv', Csv::write(
            ['payee_type', 'payee_code', 'payee_name', 'site', 'bank_code', 'account_no', 'amount'],
            [['employee', 'E001', 'นาย ก (DEMO)', 'HQ', '001', '0012345678', '1500.50'], ['employee', 'E002', 'นาง ข (DEMO)', 'BR1', '002', '001-2-34567-9', '2,000']]
        ), $this->t->tmp));
        $imp->confirm($this->az('payroll.maker'), $pid, true);
        $app->payments()->submit($this->az('payroll.maker'), $b);
        $inst = (string) $app->db()->scalar("SELECT public_id FROM workflow_instances WHERE subject_type = 'payment_batch' AND subject_id = ? ORDER BY id DESC LIMIT 1", [$b]);
        $app->workflow()->decide($inst, (int) $this->t->user('payroll.checker')['id'], 'approve');
        return $b;
    }

    public function testFormatLifecycleAndBatchFile(): void
    {
        $app = $this->t->app;
        $db = $app->db();
        $bf = $app->bankFormats();
        $le = $this->le('DEMO1');
        $maker = $this->az('payroll.maker');
        $checker = $this->az('payroll.checker');

        // ---- a draft from the made-up fixed-width sample -------------------------------------------------------------------
        foreach ([['x', 'code', 'payment.format.error_code']] as [$code, $field, $key]) {
            try {
                $bf->create($maker, $le, 'sample:fixed', $code);
                self::fail('code');
            } catch (ValidationException $e) {
                self::assertSame($key, $e->errors[$field]);
            }
        }
        $f1 = $bf->create($maker, $le, 'sample:fixed', 'test_fixed');
        try {
            $bf->create($maker, $le, 'blank', 'TEST_FIXED');
            self::fail('taken');
        } catch (ValidationException $e) {
            self::assertSame('payment.format.error_code_taken', $e->errors['code']);
        }
        $f = $bf->get($maker, $f1);
        self::assertSame(['TEST_FIXED', 1, 'draft', 1], [$f['code'], (int) $f['version'], $f['status'], (int) $f['from_sample']]);
        try {
            $bf->create($this->az('hr.demo1'), $le, 'blank', 'HR_X');
            self::fail('hr');
        } catch (HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }

        // ---- the templates are checked ----------------------------------------------------------------------------------------
        $in = ['name' => 'ธนาคารทดสอบ (สมมุติ)', 'bank_code' => '999', 'extension' => 'txt', 'encoding' => 'UTF-8', 'line_end' => 'CRLF', 'final_newline' => '1',
            'header' => 'H{p.company_id|w:10|pad:0|right}{account}', 'detail' => 'D{seq|w:3|pad:0|right}{foo}{amount|date:DD}', 'footer' => 'T{count', 'params' => "company_id=0000000042\nbad key=1"];
        try {
            $bf->save($maker, $f1, $in);
            self::fail('validation');
        } catch (ValidationException $e) {
            self::assertSame(['params', 'header', 'detail', 'footer'], array_keys($e->errors));
            self::assertSame(['payment.format.error_params', 'payment.format.error_detail_only', 'payment.format.error_variable', 'payment.format.error_brace'], array_values($e->errors));
        }
        self::assertSame(['payment.format.error_option', 'payment.format.error_option'], array_column(BankTemplate::check('{amount|date:DD}{pay_date|nodot}', true, []), 'key'));
        $in = ['header' => "H{p.company_id|w:10|pad:0|right}{pay_date|date:DDMMBBBB}{count|w:4|pad:0|right}{total|nodot|w:12|pad:0|right}", 'detail' => 'D{seq|w:3|pad:0|right}{bank_code|w:3}{account|digits|w:10|pad:0|right}{amount|nodot|w:10|pad:0|right}{payee_name|w:12|cut}|{ref}',
            'footer' => "T{count|w:4|pad:0|right}\nEND", 'params' => 'company_id=0000000042'] + $in;
        $bf->save($maker, $f1, $in);

        // ---- switched on by another person who records the bank's specification -----------------------------------------------
        try {
            $bf->activate($maker, $f1, true, 'ข้อกำหนดทดสอบ v1');
            self::fail('maker');
        } catch (HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        try {
            $bf->activate($checker, $f1, false, 'x');
            self::fail('checked');
        } catch (ValidationException $e) {
            self::assertSame(['checked', 'reference'], array_keys($e->errors));
        }
        $bf->activate($checker, $f1, true, 'ข้อกำหนดไฟล์ทดสอบ (สมมุติ) ฉบับ 1');
        self::assertSame('active', $bf->get($checker, $f1)['status']);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'payment.format_activated'"));

        // ---- a batch's file in this format ------------------------------------------------------------------------------------
        $b = $this->batch();
        try {
            $app->payments()->generateFile($checker, $b, '01ZZZZZZZZZZZZZZZZZZZZZZZZ');
            self::fail('unknown format');
        } catch (BusinessException $e) {
            self::assertSame('payment.format.error_not_active', $e->messageKey);
        }
        $app->payments()->generateFile($checker, $b, $f1);
        $file = $db->one("SELECT format_code, format_version FROM pmt_files WHERE status = 'active'") ?? [];
        self::assertSame(['TEST_FIXED', '1'], [$file['format_code'], $file['format_version']]);
        $dl = $app->payments()->downloadFile($checker, $b);
        self::assertStringEndsWith('_TEST_FIXED_v1.txt', $dl['filename']);
        $bytes = $dl['bytes'];
        self::assertStringEndsWith("\r\n", $bytes);
        $lines = explode("\r\n", rtrim($bytes, "\r\n"));
        self::assertSame('H0000000042251125690002000000350050', $lines[0]);
        self::assertSame('D00100100123456780000150050นาย ก (DEMO)|' . $app->payments()->row($b)['batch_no'] . '-0001', $lines[1]);
        self::assertSame('D00200200123456790000200000นาง ข (DEMO)|' . $app->payments()->row($b)['batch_no'] . '-0002', $lines[2]);
        self::assertSame(['T0002', 'END'], [$lines[3], $lines[4]]);
        self::assertCount(5, $lines);

        // ---- a new version replaces the active one; a retired format cannot be used ------------------------------------------
        $f2 = $bf->create($maker, $le, 'copy:' . $f1, '');
        self::assertSame(2, (int) $bf->get($maker, $f2)['version']);
        try {
            $bf->create($maker, $le, 'copy:' . $f1, '');
            self::fail('one draft');
        } catch (BusinessException $e) {
            self::assertSame('payment.format.error_draft_exists', $e->messageKey);
        }
        $bf->activate($checker, $f2, true, 'ข้อกำหนดไฟล์ทดสอบ (สมมุติ) ฉบับ 2');
        self::assertSame('retired', $bf->get($checker, $f1)['status']);
        $bf->retire($checker, $f2, 'เลิกใช้ธนาคารนี้ (ทดสอบ)');
        self::assertSame([], $bf->active($le));
        $app->payments()->supersedeFile($checker, $b, 'ทดสอบรูปแบบใหม่');
        try {
            $app->payments()->generateFile($checker, $b, $f2);
            self::fail('retired');
        } catch (BusinessException $e) {
            self::assertSame('payment.format.error_not_active', $e->messageKey);
        }
        $app->payments()->generateFile($checker, $b); // the standard file is always there
        self::assertSame('BLUE_STANDARD_XLSX_v1', (string) $db->scalar("SELECT format_code FROM pmt_files WHERE status = 'active'"));

        // ---- screens -----------------------------------------------------------------------------------------------------------
        $m = $this->t->browser();
        $m->login('payroll.maker');
        $res = $m->get('/payments/formats');
        self::assertSame(200, $res->status);
        self::assertStringContainsString('TEST_FIXED v2', $res->body);
        self::assertStringContainsString('ตัวอย่างสมมุติ — ธนาคารสมมุติ ข (CSV)', $res->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body));
        $res = $m->get('/payments/formats/' . $f1);
        self::assertSame(200, $res->status);
        self::assertStringContainsString('H000000004', $res->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body));
        $c = $this->t->browser();
        $c->login('payroll.checker');
        $f3 = $bf->create($maker, $le, 'sample:csv', 'TEST_CSV');
        $res = $c->get('/payments/formats/' . $f3);
        self::assertStringContainsString('/activate', $res->body);
        self::assertStringContainsString('"สมมุติ·ใจดี"', html_entity_decode($res->body, ENT_QUOTES));
        $bf->activate($checker, $f3, true, 'ข้อกำหนดไฟล์ทดสอบ (สมมุติ) CSV');
        $b2 = $this->batch();
        $res = $c->get('/payments/' . $b2);
        self::assertStringContainsString('name="format"', $res->body);
        self::assertStringContainsString('TEST_CSV v1', $res->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body));
        $h = $this->t->browser();
        $h->login('hr.demo1');
        self::assertSame(403, $h->get('/payments/formats')->status);
    }

    public function testTemplateEngine(): void
    {
        $f = ['header' => '', 'detail' => '{payee_name|w:4}', 'footer' => null, 'encoding' => 'UTF-8', 'line_end' => 'LF', 'final_newline' => false, 'params' => []];
        $it = ['seq' => 7, 'payee_name' => 'สมมุติ', 'amount' => 1];
        $out = BankTemplate::build($f, [], [$it]);
        self::assertNull($out['bytes']);
        self::assertSame(['key' => 'payment.format.error_width', 'params' => ['t' => '{payee_name|w:4}', 'seq' => '7']], $out['problems'][0]);
        self::assertSame('สมมุ', BankTemplate::build(['detail' => '{payee_name|w:4|cut}'] + $f, [], [$it])['bytes']);
        $th = BankTemplate::build(['detail' => '{payee_name}', 'encoding' => 'TIS-620'] + $f, [], [$it])['bytes'];
        self::assertSame('สมมุติ', iconv('TIS-620', 'UTF-8', (string) $th));
        self::assertSame(6, strlen((string) $th), 'one byte per Thai character in TIS-620');
        self::assertSame([['key' => 'payment.format.error_encoding', 'params' => []]], BankTemplate::build(['detail' => '{payee_name}', 'encoding' => 'TIS-620'] + $f, [], [['payee_name' => 'é'] + $it])['problems']);
        self::assertSame(['"a ""b"""', null], BankTemplate::line('{payee_name|q}', ['payee_name' => 'a "b"']));
        self::assertSame(['{1.50}|000150|0002|25/11/2569|26', null], BankTemplate::line(
            '{{{amount}}}|{amount|nodot|w:6|pad:0|right}|{amount|dec:0|w:4|pad:0|right}|{pay_date|date:DD/MM/BBBB}|{pay_date|date:YY}',
            ['amount' => 15000, 'pay_date' => '2026-11-25']
        ));
        self::assertSame(['ABC  |', null], BankTemplate::line('{payee_code|upper|w:5}|', ['payee_code' => 'abc']));
        self::assertSame([], BankTemplate::check(BankTemplate::samples()['fixed']['detail'], true, []));
        self::assertSame([], BankTemplate::check(BankTemplate::samples()['fixed']['header'], false, ['company_id']));
        self::assertSame([], BankTemplate::check(BankTemplate::samples()['csv']['detail'], true, []));
    }
}
