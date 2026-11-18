<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PDOException;
use PHPUnit\Framework\TestCase;
use Blue\Core\Amend\AmendmentHandler;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Tests\Support\TestApp;

/**
 * TC-LOCK (6.27): writes into a locked period fail through every channel; reopening needs someone else's approval
 * and re-locking starts a new revision while the old one stays in history.
 * TC-AMD (6.28): request → approve → execute fails → safe retry; stale record refused; original never edited directly.
 */
final class LocksAndAmendmentsTest extends TestCase
{
    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = TestApp::create($this);
    }

    protected function tearDown(): void
    {
        $this->t->app->db()->exec('DROP TABLE IF EXISTS test_records');
        $this->t->cleanup();
    }

    private function uid(string $u): int
    {
        return (int) $this->t->user($u)['id'];
    }

    private function le(string $code): int
    {
        return (int) $this->t->app->db()->scalar('SELECT id FROM legal_entities WHERE code = ?', [$code]);
    }

    public function testHardLockBlocksEveryChannelAndReopenNeedsSomeoneElse(): void
    {
        $locks = $this->t->app->locks();
        $le = $this->le('DEMO1');
        $site = (int) $this->t->app->db()->scalar('SELECT id FROM sites WHERE legal_entity_id = ? AND code = ?', [$le, 'BR1']);
        self::assertNull($locks->assertWritable('demo.timesheet', '2026-11', $le, $site));
        $lock = $locks->lock('demo.timesheet', 'legal_entity', $le, '2026-11', 'hard', $this->uid(TestApp::ADMIN), 'month closed', 'snapshot-r1');
        foreach (['ui', 'api', 'import', 'job', 'integration'] as $channel) {
            try {
                $locks->assertWritable('demo.timesheet', '2026-11', $le, $site, $channel); // site inside a locked company
                self::fail("{$channel} wrote into a locked period");
            } catch (BusinessException $e) {
                self::assertSame([423, 'lock.error.locked'], [$e->httpStatus, $e->messageKey]);
            }
        }
        self::assertSame(5, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM lock_events WHERE event = 'blocked_write'"));
        self::assertNull($locks->assertWritable('demo.timesheet', '2026-11', $this->le('DEMO2')), 'other company not affected');
        self::assertNull($locks->assertWritable('demo.timesheet', '2026-12', $le), 'other period not affected');

        $inst = $locks->requestReopen($lock, $this->uid('hr.demo1'), 'late correction', $le);
        $wf = $this->t->app->workflow();
        try {
            $wf->decide($inst, $this->uid('hr.demo1'), 'approve');
            self::fail('requester reopened their own lock');
        } catch (BusinessException) {
            self::assertTrue(true);
        }
        self::assertSame('reopen_requested', $locks->history($lock)['lock']['status']);
        $wf->decide($inst, $this->uid('sysadmin2.demo'), 'approve');
        self::assertNull($locks->assertWritable('demo.timesheet', '2026-11', $le, $site));

        $locks->lock('demo.timesheet', 'legal_entity', $le, '2026-11', 'hard', $this->uid(TestApp::ADMIN), 'closed again', 'snapshot-r2');
        $h = $locks->history($lock);
        self::assertSame(2, (int) $h['lock']['revision_no']);
        self::assertSame(['created', 'locked', 'reopen_requested', 'reopen_approved', 'locked'], array_values(array_filter(array_column($h['events'], 'event'), static fn ($e) => $e !== 'blocked_write')));
        self::assertSame('snapshot-r2', $h['lock']['snapshot_ref']);
    }

    public function testSoftLockWarnsAndHistoryIsAppendOnly(): void
    {
        $locks = $this->t->app->locks();
        $org = (int) $this->t->app->db()->scalar('SELECT id FROM organizations LIMIT 1');
        $locks->lock('demo.roster', 'organization', $org, '2026-W46', 'soft', $this->uid(TestApp::ADMIN), 'review week');
        self::assertSame('soft', $locks->assertWritable('demo.roster', '2026-W46', $this->le('DEMO2')));
        if ($this->t->app->db()->scalar("SELECT info_value FROM system_info WHERE info_key = 'history_guard'") === 'triggers') {
            $this->expectException(PDOException::class);
            $this->t->app->db()->run('DELETE FROM lock_events');
        }
    }

    private function handler(bool $failFirst): AmendmentHandler
    {
        $db = $this->t->app->db();
        $db->exec('CREATE TABLE IF NOT EXISTS test_records (id VARCHAR(20) PRIMARY KEY, amount VARCHAR(20), revision INT, legal_entity_id BIGINT, applied_keys TEXT)');
        $db->run("INSERT INTO test_records VALUES ('R1', '100.00', 1, ?, '')", [$this->le('DEMO1')]);
        return new class ($db, $failFirst) implements AmendmentHandler {
            public int $calls = 0;

            public function __construct(private readonly \Blue\Core\Database\Connection $db, private readonly bool $failFirst)
            {
            }

            public function targetType(): string
            {
                return 'test_record';
            }

            public function load(string $targetId, int $actorId): ?array
            {
                $r = $this->db->one('SELECT * FROM test_records WHERE id = ?', [$targetId]);
                return $r === null ? null : ['revision' => (int) $r['revision'], 'legal_entity_id' => (int) $r['legal_entity_id'], 'values' => ['amount' => $r['amount']], 'amendable_fields' => ['amount']];
            }

            public function impact(string $targetId, array $changes): array
            {
                return ['difference' => $changes['amount'] ?? null];
            }

            public function execute(string $targetId, int $expectedRevision, string $action, array $changes, string $idempotencyKey, int $approverId): string
            {
                $this->calls++;
                $r = $this->db->one('SELECT * FROM test_records WHERE id = ?', [$targetId]) ?? [];
                if (str_contains((string) $r['applied_keys'], $idempotencyKey)) {
                    return 'rev' . $r['revision']; // already applied
                }
                if ((int) $r['revision'] !== $expectedRevision) {
                    throw new BusinessException('amend.error.stale', [], 409);
                }
                if ($this->failFirst && $this->calls === 1) {
                    throw new \RuntimeException('downstream unavailable');
                }
                $this->db->run('UPDATE test_records SET amount = ?, revision = revision + 1, applied_keys = CONCAT(applied_keys, ?) WHERE id = ?', [$changes['amount'], $idempotencyKey . ';', $targetId]);
                return 'rev' . ($expectedRevision + 1);
            }
        };
    }

    public function testAmendmentExecutesAfterApprovalAndRetriesSafely(): void
    {
        $h = $this->handler(true);
        $am = $this->t->app->amendments();
        $am->register($h);
        $pid = $am->request($this->uid('hr.demo1'), 'test_record', 'R1', 'amend', 'data_error', 'typo in amount', ['amount' => '120.00']);
        self::assertSame('100.00', $this->t->app->db()->scalar("SELECT amount FROM test_records WHERE id = 'R1'"), 'asking changes nothing');
        $changes = $am->get($pid)['changes'];
        self::assertSame([['field' => 'amount', 'old_value' => '100.00', 'new_value' => '120.00']], $changes);
        try {
            $am->request($this->uid('hr.demo1'), 'test_record', 'R1', 'amend', 'data_error', 'again', ['amount' => '1']);
            self::fail('second open request accepted');
        } catch (BusinessException $e) {
            self::assertSame('amend.error.open_request', $e->messageKey);
        }

        $inst = (string) $this->t->app->db()->scalar('SELECT public_id FROM workflow_instances WHERE subject_id = ?', [$pid]);
        $this->t->app->workflow()->decide($inst, $this->uid('sysadmin2.demo'), 'approve');
        self::assertSame('execution_failed', $am->get($pid)['request']['status']);
        self::assertSame('100.00', $this->t->app->db()->scalar("SELECT amount FROM test_records WHERE id = 'R1'"), 'nothing half-written');

        $sys = new Authorizer($this->t->app->db(), $this->t->clock, $this->uid('sysadmin2.demo'));
        $this->t->app->workflow()->retryExecution($inst, $sys);
        self::assertSame('executed', $am->get($pid)['request']['status']);
        self::assertSame(['120.00', 2], [$this->t->app->db()->scalar("SELECT amount FROM test_records WHERE id = 'R1'"), (int) $this->t->app->db()->scalar("SELECT revision FROM test_records WHERE id = 'R1'")]);
        $am->close($pid, $this->uid('sysadmin2.demo'));
        self::assertSame('closed', $am->get($pid)['request']['status']);
    }

    public function testAStaleRecordIsNotOverwritten(): void
    {
        $am = $this->t->app->amendments();
        $am->register($this->handler(false));
        $pid = $am->request($this->uid('hr.demo1'), 'test_record', 'R1', 'amend', 'data_error', 'x', ['amount' => '120.00']);
        $this->t->app->db()->run("UPDATE test_records SET revision = 5 WHERE id = 'R1'"); // changed by someone meanwhile
        $inst = (string) $this->t->app->db()->scalar('SELECT public_id FROM workflow_instances WHERE subject_id = ?', [$pid]);
        $this->t->app->workflow()->decide($inst, $this->uid('sysadmin2.demo'), 'approve');
        $req = $am->get($pid)['request'];
        self::assertSame(['execution_failed', 'amend.error.stale'], [$req['status'], $req['execution_error']]);
        self::assertSame('100.00', $this->t->app->db()->scalar("SELECT amount FROM test_records WHERE id = 'R1'"));
    }
}
