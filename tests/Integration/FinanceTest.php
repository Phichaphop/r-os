<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Tests\Support\TestApp;

/** DEC-12 posting interface + minimal Finance Core: rules, draft journals, maker-checker, immutability, reversal, period lock. */
final class FinanceTest extends TestCase
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

    /** @param list<array{line_type: string, amount: string}> $lines */
    private function request(string $key, array $lines, string $date = '2026-11-09', string $module = 'demo.source'): string
    {
        return $this->t->app->posting()->request([
            'source_module' => $module, 'source_document_type' => 'demo_doc', 'source_document_id' => 'D-' . $key, 'legal_entity_id' => $this->le('DEMO1'),
            'period_date' => $date, 'currency_code' => 'THB', 'lines' => $lines, 'idempotency_key' => 'test-key:' . $key,
        ]);
    }

    private function approve(string $subjectType, string $subjectId, string $user): void
    {
        $inst = (string) $this->t->app->db()->scalar("SELECT public_id FROM workflow_instances WHERE subject_type = ? AND subject_id = ? ORDER BY id DESC LIMIT 1", [$subjectType, $subjectId]);
        $this->t->app->workflow()->decide($inst, (int) $this->t->user($user)['id'], 'approve');
    }

    private function status(string $pid): string
    {
        return (string) $this->t->app->db()->scalar('SELECT status FROM fin_posting_requests WHERE public_id = ?', [$pid]);
    }

    public function testRequestWithoutRuleWaitsThenARuleApprovedBySomeoneElseMapsIt(): void
    {
        $app = $this->t->app;
        $pid = $this->request('a1', [['line_type' => 'demo.expense', 'amount' => '1200.50']]);
        self::assertSame($pid, $this->request('a1', [['line_type' => 'demo.expense', 'amount' => '999']]), 'same idempotency key = same request (no second journal)');
        $app->outbox()->dispatch();
        $app->outbox()->dispatch(); // a repeated delivery does nothing
        self::assertSame('needs_attention', $this->status($pid), 'no rule → waits, nothing guessed');
        self::assertSame('finance.error.no_rule', $app->db()->scalar('SELECT error_key FROM fin_posting_requests WHERE public_id = ?', [$pid]));
        self::assertSame(0, (int) $app->db()->scalar('SELECT COUNT(*) FROM fin_journals'));
        self::assertGreaterThan(0, (int) $app->db()->scalar("SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.username = 'finance.maker'"));

        $rules = $app->postingRules();
        $rule = $rules->propose($this->az('finance.maker'), $this->le('DEMO1'), ['module' => 'demo.source', 'line_type' => 'demo.expense', 'debit' => '5130', 'credit' => '2110',
            'effective_from' => '2026-01-01', 'reason' => 'test', 'source' => '']);
        try {
            $rules->submit($this->az('finance.maker'), $rule);
            self::fail('submitted without a source reference');
        } catch (ValidationException $e) {
            self::assertSame('finance.error.source_required', $e->errors['source']);
        }
        $rule = $rules->propose($this->az('finance.maker'), $this->le('DEMO1'), ['module' => 'demo.source', 'line_type' => 'demo.expense', 'debit' => '5130', 'credit' => '2110',
            'effective_from' => '2026-01-01', 'reason' => 'test', 'source' => 'ผู้ทำบัญชี (ทดสอบ)']);
        $rules->submit($this->az('finance.maker'), $rule);
        try {
            $this->approve('posting_rule', $rule, 'finance.maker');
            self::fail('proposer approved own rule');
        } catch (HttpException | BusinessException) {
            self::assertTrue(true);
        }
        $this->approve('posting_rule', $rule, 'finance.checker');
        self::assertSame('draft_journal', $this->status($pid), 'waiting request retried after the rule was approved');
        $j = (string) $app->db()->scalar('SELECT j.public_id FROM fin_journals j JOIN fin_posting_requests r ON r.journal_id = j.id WHERE r.public_id = ?', [$pid]);
        $lines = $app->journals()->lines((int) $app->db()->scalar('SELECT id FROM fin_journals WHERE public_id = ?', [$j]));
        self::assertSame(['5130', '2110'], array_column($lines, 'account_code'));
        self::assertSame(['1200.5000', '0.0000'], array_column($lines, 'debit'));
    }

    public function testMakerCheckerPostsFreezesAndReversalNetsToZero(): void
    {
        $app = $this->t->app;
        $le = $this->le('DEMO1');
        try {
            $app->journals()->createManual($this->az('finance.maker'), $le, '2026-11-09', 'unbalanced', [
                ['account' => '5130', 'debit' => '100', 'credit' => '', 'description' => '', 'site' => null],
                ['account' => '1110', 'debit' => '', 'credit' => '90', 'description' => '', 'site' => null],
            ]);
            self::fail('unbalanced journal saved');
        } catch (ValidationException $e) {
            self::assertSame('finance.error.unbalanced', $e->errors['lines']);
        }
        try {
            $app->journals()->createManual($this->az('finance.maker'), $le, '2026-11-09', 'header account', [
                ['account' => '5000', 'debit' => '100', 'credit' => '', 'description' => '', 'site' => null],
                ['account' => '1110', 'debit' => '', 'credit' => '100', 'description' => '', 'site' => null],
            ]);
            self::fail('posted to a header account');
        } catch (ValidationException $e) {
            self::assertSame('finance.error.account_postable', $e->errors['line_0']);
        }
        $pid = $app->journals()->createManual($this->az('finance.maker'), $le, '2026-11-09', 'ค่าเครื่องเขียน (DEMO)', [
            ['account' => '5130', 'debit' => '1,000.25', 'credit' => '', 'description' => 'paper', 'site' => null],
            ['account' => '1110', 'debit' => '', 'credit' => '1000.25', 'description' => '', 'site' => null],
        ]);
        $app->journals()->submit($this->az('finance.maker'), $pid);
        try {
            $this->approve('journal', $pid, 'finance.maker');
            self::fail('maker approved own journal');
        } catch (HttpException | BusinessException) {
            self::assertTrue(true);
        }
        $this->approve('journal', $pid, 'finance.checker');
        $j = $app->journals()->row($pid);
        self::assertSame('posted', $j['status']);
        self::assertSame('JV-2026-00001', $j['journal_no'], 'number taken at posting');
        self::assertSame((int) $this->t->user('finance.checker')['id'], (int) $j['approved_by']);

        // immutable: service refuses edits; the database refuses too (trigger)
        try {
            $app->journals()->updateDraft($this->az('finance.maker'), $pid, '2026-11-09', 'x', []);
            self::fail('posted journal edited');
        } catch (BusinessException $e) {
            self::assertSame('finance.error.not_editable', $e->messageKey);
        }
        if ($app->db()->scalar("SELECT info_value FROM system_info WHERE info_key = 'finance_guard'") === 'triggers') {
            foreach (['UPDATE fin_journal_lines SET debit = 1 WHERE journal_id = ' . (int) $j['id'], 'UPDATE fin_journals SET total_debit = 5 WHERE id = ' . (int) $j['id'], 'DELETE FROM fin_journals WHERE id = ' . (int) $j['id']] as $sql) {
                try {
                    $app->db()->exec($sql);
                    self::fail('database allowed: ' . $sql);
                } catch (\PDOException) {
                    self::assertTrue(true);
                }
            }
        }
        $tb = $app->journals()->trialBalance($this->az('finance.checker'), $le, '2026-01-01', '2026-12-31');
        self::assertSame($tb['debit'], $tb['credit']);
        self::assertSame(10002500, $tb['debit']);

        $rev = $app->journals()->reverse($this->az('finance.maker'), $pid, '2026-11-10', 'ผิดบัญชี');
        try {
            $app->journals()->reverse($this->az('finance.maker'), $pid, '2026-11-10', 'again');
            self::fail('two reversals');
        } catch (BusinessException $e) {
            self::assertSame('finance.error.reversal_exists', $e->messageKey);
        }
        $app->journals()->submit($this->az('finance.maker'), $rev);
        $this->approve('journal', $rev, 'finance.checker');
        self::assertSame('reversed', $app->journals()->row($pid)['status']);
        self::assertSame('JV-2026-00002', $app->journals()->row($rev)['journal_no']);
        $tb = $app->journals()->trialBalance($this->az('finance.checker'), $le, '2026-01-01', '2026-12-31');
        foreach ($tb['rows'] as $r) {
            self::assertSame(0, $r['balance_units'], 'reversal nets every account to zero: ' . $r['code']);
        }
    }

    public function testClosedPeriodRefusesPostingsFromEveryPath(): void
    {
        $app = $this->t->app;
        $le = $this->le('DEMO1');
        $pid = $app->journals()->createManual($this->az('finance.maker'), $le, '2026-10-15', 'October', [
            ['account' => '5130', 'debit' => '50', 'credit' => '', 'description' => '', 'site' => null],
            ['account' => '1110', 'debit' => '', 'credit' => '50', 'description' => '', 'site' => null],
        ]);
        try {
            $app->accounts()->closePeriod($this->az('finance.maker'), $le, 'FY2026-10', 'month end');
            self::fail('maker closed a period');
        } catch (HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        $app->accounts()->closePeriod($this->az('finance.checker'), $le, 'FY2026-10', 'month end');
        try {
            $app->journals()->submit($this->az('finance.maker'), $pid);
            self::fail('submitted into a closed period');
        } catch (BusinessException $e) {
            self::assertSame(423, $e->httpStatus);
        }
        $req = $this->request('closed', [['line_type' => 'demo.expense', 'amount' => '10']], '2026-10-20');
        $app->outbox()->dispatch();
        self::assertSame('needs_attention', $this->status($req));
        self::assertSame('lock.error.locked', $app->db()->scalar('SELECT error_key FROM fin_posting_requests WHERE public_id = ?', [$req]));
        $none = $this->request('nofy', [['line_type' => 'demo.expense', 'amount' => '10']], '2025-06-01');
        $app->outbox()->dispatch();
        self::assertSame('finance.error.no_period', $app->db()->scalar('SELECT error_key FROM fin_posting_requests WHERE public_id = ?', [$none]));
        // reopening goes through someone else
        $app->accounts()->requestReopen($this->az('finance.checker'), $le, 'FY2026-10', 'late invoice');
        self::assertSame(1, (int) $app->db()->scalar("SELECT COUNT(*) FROM workflow_instances WHERE subject_type = 'lock_reopen' AND status = 'pending'"));
    }

    public function testScopeRightsAndNoFallbackToTheSystemAdmin(): void
    {
        $app = $this->t->app;
        $le1 = $this->le('DEMO1');
        $le2 = $this->le('DEMO2');
        $pid = $app->journals()->createManual($this->az('finance.maker'), $le1, '2026-11-09', 'x', [
            ['account' => '5130', 'debit' => '1', 'credit' => '', 'description' => '', 'site' => null],
            ['account' => '1110', 'debit' => '', 'credit' => '1', 'description' => '', 'site' => null],
        ]);
        foreach ([TestApp::ADMIN, 'hr.demo2', 'employee.demo1'] as $u) {
            try {
                $app->journals()->get($this->az($u), $pid);
                self::fail($u . ' opened a journal');
            } catch (HttpException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('draft', $app->journals()->get($this->az('auditor.demo'), $pid)['status'], 'auditor may read');
        try {
            $app->journals()->submit($this->az('auditor.demo'), $pid);
            self::fail('auditor submitted');
        } catch (HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        try {
            $app->accounts()->createAccount($this->az('finance.maker'), $le2, ['code' => '9', 'name' => 'x', 'type' => 'asset', 'parent' => '', 'postable' => true]);
            self::fail('maker of DEMO1 changed DEMO2');
        } catch (HttpException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        // DEMO2 has no finance checker: the request waits as "needs attention" — never routed to the system administrator
        $app->db()->run("INSERT INTO access_scopes (assignment_id, scope_level, scope_id) SELECT a.id, 'legal_entity', ? FROM user_role_assignments a JOIN users u ON u.id = a.user_id WHERE u.username = 'finance.maker'", [$le2]);
        $app->accounts()->createAccount($this->az('finance.maker'), $le2, ['code' => '1', 'name' => 'a', 'type' => 'asset', 'parent' => '', 'postable' => true]);
        $app->accounts()->createAccount($this->az('finance.maker'), $le2, ['code' => '2', 'name' => 'b', 'type' => 'liability', 'parent' => '', 'postable' => true]);
        $app->accounts()->createFiscalYear($this->az('finance.maker'), $le2, 'Y26', '2026-04-01', 12);
        self::assertSame('Y26-08', $app->accounts()->periodFor($le2, '2026-11-09')['period_code'], 'fiscal year starting in April (company policy)');
        $p2 = $app->journals()->createManual($this->az('finance.maker'), $le2, '2026-11-09', 'y', [
            ['account' => '1', 'debit' => '1', 'credit' => '', 'description' => '', 'site' => null],
            ['account' => '2', 'debit' => '', 'credit' => '1', 'description' => '', 'site' => null],
        ]);
        $app->journals()->submit($this->az('finance.maker'), $p2);
        self::assertSame('needs_attention', $app->db()->scalar("SELECT status FROM workflow_instances WHERE subject_type = 'journal' AND subject_id = ?", [$p2]));
        self::assertSame(0, (int) $app->db()->scalar(
            "SELECT COUNT(*) FROM workflow_tasks t JOIN workflow_instances i ON i.id = t.instance_id JOIN users u ON u.id = t.assignee_id WHERE i.subject_id = ? AND u.username IN (?, 'sysadmin2.demo')",
            [$p2, TestApp::ADMIN]
        ));
    }

    public function testRejectedRequestTellsTheSource(): void
    {
        $app = $this->t->app;
        $pid = $this->request('rej', [['line_type' => 'demo.unknown', 'amount' => '5']]);
        $app->outbox()->dispatch();
        $app->posting()->reject($this->az('finance.maker'), $pid, 'ไม่ใช่รายการของบริษัท');
        self::assertSame('rejected', $this->status($pid));
        self::assertSame(1, (int) $app->db()->scalar("SELECT COUNT(*) FROM outbox_events WHERE event_name = 'posting.rejected'"));
        try {
            $this->request('bad', [['line_type' => 'Bad Type', 'amount' => '-1']]);
            self::fail('invalid request accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('lines.0', $e->errors);
        }
    }
}
