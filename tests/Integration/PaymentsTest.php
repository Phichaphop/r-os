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
use Blue\Core\Files\Xlsx;
use Blue\Core\Payments\BankFile;
use Blue\Core\Support\Money;
use Blue\Tests\Support\TestApp;

/** DEC-26/31 bank payment file service: import → approve → file (totals, hash, one active) → sent → results → reconcile → posting. */
final class PaymentsTest extends TestCase
{
    private TestApp $t;

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

    private function approve(string $pid, string $user): void
    {
        $inst = (string) $this->t->app->db()->scalar("SELECT public_id FROM workflow_instances WHERE subject_type = 'payment_batch' AND subject_id = ? ORDER BY id DESC LIMIT 1", [$pid]);
        $this->t->app->workflow()->decide($inst, (int) $this->t->user($user)['id'], 'approve');
    }

    /** @param list<list<string>> $rows */
    private function import(string $code, string $batch, string $user, array $rows, array $headers): array
    {
        $imp = $this->t->app->imports();
        $pid = $imp->upload($this->az($user), $code, ['batch' => $batch], UploadedFile::fromBytes('x.csv', Csv::write($headers, $rows), $this->t->tmp));
        return ['pid' => $pid, 'preview' => $imp->preview($this->az($user), $pid, '', 1, 100)];
    }

    private function claimBatch(): string
    {
        $app = $this->t->app;
        $b = $app->payments()->createManual($this->az('payroll.maker'), $this->le('DEMO1'), 'claim', '2026-11-25', '2026-11', 'DEMO');
        $r = $this->import('payment.items', $b, 'payroll.maker', [
            ['employee', 'E001', 'นาย ก (DEMO)', 'HQ', 'BANK-A', '0012345678', '1500.50'],
            ['employee', 'E002', 'นาง ข (DEMO)', 'BR1', 'BANK-B', '001-2-34567-9', '2,000'],
            ['พนักงาน', 'E003', 'นาย ค (DEMO)', '', 'BANK-A', '0098765432', '499.50'],
            ['employee', 'E001', 'นาย ก (DEMO)', 'HQ', 'BANK-A', '0012345678', '1500.50'],  // duplicate row
            ['employee', 'E004', 'x', 'HQ', 'BANK-A', '1.23457E+11', '10'],                  // Excel lost the account
            ['employee', 'E005', 'y', 'ZZ', 'BANK-A', '0011111111', '10.001'],              // bad site + 3 decimals
        ], ['payee_type', 'payee_code', 'payee_name', 'site', 'bank_code', 'account_no', 'amount']);
        $b2 = $r['preview']['batch'];
        self::assertSame([6, 3, 3], [(int) $b2['total_rows'], (int) $b2['ok_rows'], (int) $b2['error_rows']]);
        $errs = array_column($r['preview']['rows'], 'errors', 'row_no');
        self::assertSame('import.error.duplicate_in_file', $errs[5]['payee_code']);
        self::assertSame('payment.error.account', $errs[6]['account_no']);
        self::assertSame(['payment.error.site', 'payment.error.amount'], [$errs[7]['site'], $errs[7]['amount']]);
        self::assertStringNotContainsString('0012345678', (string) $app->db()->scalar('SELECT GROUP_CONCAT(HEX(data_enc)) FROM import_rows'), 'import rows encrypted');
        $app->imports()->confirm($this->az('payroll.maker'), $r['pid'], true);
        return $b;
    }

    public function testFullCycleFromImportToReconciledPosting(): void
    {
        $app = $this->t->app;
        $b = $this->claimBatch();
        $row = $app->payments()->row($b);
        self::assertSame([3, '4000.0000'], [(int) $row['item_count'], (string) $row['total_amount']]);
        self::assertStringNotContainsString('0012345678', (string) $app->db()->scalar('SELECT GROUP_CONCAT(HEX(account_no_enc)) FROM pmt_batch_items'), 'accounts encrypted at rest');
        self::assertSame(['5678', '5679', '5432'], array_column($app->db()->all('SELECT account_last4 FROM pmt_batch_items ORDER BY seq'), 'account_last4'));

        $app->payments()->submit($this->az('payroll.maker'), $b);
        try {
            $this->approve($b, 'payroll.maker');
            self::fail('maker approved own batch');
        } catch (HttpException | BusinessException) {
            self::assertTrue(true);
        }
        try {
            $app->payments()->generateFile($this->az('payroll.checker'), $b);
            self::fail('file before approval');
        } catch (BusinessException $e) {
            self::assertSame('payment.error.not_approved', $e->messageKey);
        }
        $this->approve($b, 'payroll.checker');
        self::assertSame('approved', $app->payments()->row($b)['status']);

        try {
            $app->payments()->generateFile($this->az('payroll.maker'), $b);
            self::fail('maker made the bank file');
        } catch (HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        $app->payments()->generateFile($this->az('payroll.checker'), $b);
        try {
            $app->payments()->generateFile($this->az('payroll.checker'), $b);
            self::fail('second active file');
        } catch (BusinessException $e) {
            self::assertSame('payment.error.not_approved', $e->messageKey);
        }
        $file = $app->payments()->downloadFile($this->az('payroll.checker'), $b);
        $read = BankFile::readTotals($file['bytes']);
        self::assertSame([3, 40000000], [$read['count'], $read['total']]);
        self::assertSame(['0012345678', '0012345679', '0098765432'], array_column($read['items'], 'account'));
        $f = $app->db()->one('SELECT * FROM pmt_files');
        self::assertSame(hash('sha256', $file['bytes']), $f['sha256']);
        self::assertSame('R', $app->db()->scalar('SELECT classification FROM documents WHERE id = ?', [$f['document_id']]));
        self::assertSame(1, (int) $f['download_count']);
        self::assertSame(1, (int) $app->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'payment.file_downloaded'"));

        // supersede (wrong file) → new file, old one kept as history
        $app->payments()->supersedeFile($this->az('payroll.checker'), $b, 'regenerate (test)');
        $app->payments()->generateFile($this->az('payroll.checker'), $b);
        self::assertSame(['superseded', 'active'], array_column($app->db()->all('SELECT status FROM pmt_files ORDER BY id'), 'status'));
        try {
            $app->payments()->markSent($this->az('payroll.checker'), $b);
            self::fail('sent without downloading the new file');
        } catch (BusinessException $e) {
            self::assertSame('payment.error.not_downloaded', $e->messageKey);
        }
        $app->payments()->downloadFile($this->az('payroll.checker'), $b);
        $app->payments()->markSent($this->az('payroll.checker'), $b);
        try {
            $app->payments()->supersedeFile($this->az('payroll.checker'), $b, 'after sending');
            self::fail('file replaced after it went to the bank');
        } catch (BusinessException $e) {
            self::assertSame('payment.error.cannot_supersede', $e->messageKey);
        }

        // bank results: two paid, one failed; one row that matches nothing
        $r = $this->import('payment.results', $b, 'payroll.maker', [
            ['0012345678', '1500.50', 'สำเร็จ', '2026-11-25', 'R1', ''],
            ['001-2-34567-9', '2000', 'paid', '25/11/2569', 'R2', ''],
            ['0098765432', '499.50', 'ไม่สำเร็จ', '', '', 'บัญชีปิด'],
            ['0098765432', '1.00', 'paid', '', '', ''],
        ], ['account_no', 'amount', 'result', 'date', 'bank_ref', 'reason']);
        self::assertSame('payment.error.no_match', array_column($r['preview']['rows'], 'errors', 'row_no')[5]['account_no']);
        $app->imports()->confirm($this->az('payroll.maker'), $r['pid'], true);
        $row = $app->payments()->row($b);
        self::assertSame(['reconciled', '3500.5000', 'requested'], [$row['status'], (string) $row['paid_amount'], $row['posting_status']]);
        self::assertSame('2026-11-25', $app->db()->scalar("SELECT result_date FROM pmt_batch_items WHERE result_ref = 'R2'"), 'Buddhist-era date converted');

        // posting interface → demo rule for claims → draft journal → checker posts → batch learns it was posted
        $app->outbox()->dispatch();
        $req = $app->db()->one("SELECT * FROM fin_posting_requests WHERE source_module = 'core.payment'");
        self::assertSame(['draft_journal', '3500.5000'], [$req['status'], (string) $req['total_amount']]);
        $j = (string) $app->db()->scalar('SELECT public_id FROM fin_journals WHERE id = ?', [$req['journal_id']]);
        $app->journals()->submit(new Authorizer($app->db(), $this->t->clock, (int) $this->t->user('finance.maker')['id']), $j);
        $inst = (string) $app->db()->scalar("SELECT public_id FROM workflow_instances WHERE subject_type = 'journal' AND subject_id = ?", [$j]);
        $app->workflow()->decide($inst, (int) $this->t->user('finance.checker')['id'], 'approve');
        $app->outbox()->dispatch();
        self::assertSame('posted', $app->payments()->row($b)['posting_status']);

        // failed item → new batch that needs approval again; it cannot be re-issued twice
        $nb = $app->payments()->reissueFailed($this->az('payroll.maker'), $b, '2026-11-30');
        $n = $app->payments()->row($nb);
        self::assertSame(['draft', 1, '499.5000', 'reissue'], [$n['status'], (int) $n['item_count'], (string) $n['total_amount'], $n['source_type']]);
        try {
            $app->payments()->reissueFailed($this->az('payroll.maker'), $b, '2026-11-30');
            self::fail('re-issued twice');
        } catch (BusinessException $e) {
            self::assertSame('payment.error.nothing_to_reissue', $e->messageKey);
        }
    }

    public function testSourceTotalsMustMatchAndPolicyDecidesManualTypes(): void
    {
        $app = $this->t->app;
        $le = $this->le('DEMO1');
        try {
            $app->payments()->createManual($this->az('payroll.maker'), $le, 'payroll', '2026-11-25', '', '');
            self::fail('payroll batch from a file without the policy');
        } catch (ValidationException $e) {
            self::assertSame('payment.error.type_not_manual', $e->errors['payment_type']);
        }
        $base = ['legal_entity_id' => $le, 'payment_type' => 'payroll', 'source_type' => 'payroll_run', 'source_id' => 'RUN-1', 'idempotency_key' => 'payroll-run:RUN-1',
            'pay_date' => '2026-11-30', 'currency_code' => 'THB', 'actor_user_id' => (int) $this->t->user('payroll.maker')['id'],
            'items' => [['payee_type' => 'employee', 'payee_code' => 'E1', 'payee_name' => 'A', 'bank_code' => 'B', 'account_no' => '0000012345', 'amount' => '100.00'],
                ['payee_type' => 'employee', 'payee_code' => 'E2', 'payee_name' => 'B', 'bank_code' => 'B', 'account_no' => '0000012346', 'amount' => '200.00']]];
        try {
            $app->payments()->createFromSource($base + ['expected_total' => '299.99']);
            self::fail('items differ from the approved run total');
        } catch (ValidationException $e) {
            self::assertSame('payment.error.total_mismatch', $e->errors['expected_total']);
        }
        $pid = $app->payments()->createFromSource($base + ['expected_total' => '300']);
        self::assertSame($pid, $app->payments()->createFromSource($base + ['expected_total' => '300']), 'same idempotency key → same batch');
        // the expected total is re-checked at approval: a changed item is caught
        $app->db()->run("UPDATE pmt_batch_items SET amount = 201 WHERE payee_code = 'E2'");
        try {
            $app->payments()->submit($this->az('payroll.maker'), $pid);
            self::fail('tampered batch submitted');
        } catch (BusinessException $e) {
            self::assertSame('payment.error.total_mismatch', $e->messageKey);
        }
        // import is refused into a batch that came from a source
        try {
            $app->imports()->upload($this->az('payroll.maker'), 'payment.items', ['batch' => $pid], UploadedFile::fromBytes('x.csv', "payee_type\nemployee\n", $this->t->tmp));
            self::fail('imported into a payroll batch');
        } catch (BusinessException $e) {
            self::assertSame('payment.error.not_editable', $e->messageKey);
        }
    }

    public function testOtherCompaniesAndRolesCannotReachBatchesOrFiles(): void
    {
        $app = $this->t->app;
        $b = $this->claimBatch();
        foreach (['hr.demo2', 'employee.demo1', TestApp::ADMIN] as $u) {
            try {
                $app->payments()->get($this->az($u), $b);
                self::fail($u . ' opened the batch');
            } catch (HttpException) {
                self::assertTrue(true);
            }
        }
        // the bank file document cannot be uploaded or replaced from the documents screens
        self::assertArrayNotHasKey('core.payment_file', $app->documents()->uploadableClasses($this->az('payroll.checker')));
        $app->payments()->submit($this->az('payroll.maker'), $b);
        $this->approve($b, 'finance.checker');
        $app->payments()->generateFile($this->az('finance.checker'), $b);
        $doc = (string) $app->db()->scalar('SELECT d.public_id FROM documents d JOIN pmt_files f ON f.document_id = d.id');
        try {
            $app->documents()->supersede($this->az('finance.checker'), $doc, UploadedFile::fromBytes('y.xlsx', Xlsx::write(['a' => ['headers' => ['x'], 'rows' => []]]), $this->t->tmp), 'swap');
            self::fail('bank file replaced through documents');
        } catch (BusinessException $e) {
            self::assertSame('document.error.generated_only', $e->messageKey);
        }
        try {
            $app->documents()->download($this->az('hr.demo1'), $doc);
            self::fail('HR opened the bank file');
        } catch (HttpException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        self::assertSame(Money::toDb(40000000), (string) $app->db()->scalar('SELECT total_amount FROM pmt_files'));
    }
}
