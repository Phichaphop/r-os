<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Database\Connection;
use Blue\Core\Jobs\HeartbeatJob;
use Blue\Core\Jobs\JobContext;
use Blue\Core\Jobs\JobHandler;
use Blue\Core\Jobs\JobRegistry;
use Blue\Core\Jobs\Scheduler;
use Blue\Core\Log\Logger;
use Blue\Core\Database\MigrationRunner;
use Blue\Core\Support\FrozenClock;
use Blue\Tests\Support\TempDir;
use Blue\Tests\Support\TestDb;

/** P0-02 §8 — same job code under piggyback and cron triggers; time-boxed; single runner. */
final class SchedulerTest extends TestCase
{
    private Connection $db;
    private FrozenClock $clock;
    private string $tmp;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->db = TestDb::fresh($this);
        $this->clock = new FrozenClock('2026-11-02T00:00:00Z');
        $this->tmp = TempDir::make();
        $this->logger = new Logger($this->tmp, $this->clock);
        (new MigrationRunner($this->db, ['core' => __DIR__ . '/../../core/migrations'], $this->clock, $this->logger))->migrate('test');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    private function scheduler(JobHandler ...$extra): Scheduler
    {
        $reg = new JobRegistry();
        $reg->register(new HeartbeatJob());
        foreach ($extra as $h) {
            $reg->register($h);
        }
        return new Scheduler($this->db, $reg, $this->clock, $this->logger);
    }

    public function testRunsDueJobsAndReschedules(): void
    {
        $s = $this->scheduler();
        $r = $s->runDue('piggyback', 2000, 3);
        self::assertSame([['type' => 'system.heartbeat', 'status' => 'succeeded']], array_map(static fn ($x) => ['type' => $x['type'], 'status' => $x['status']], $r['ran']));
        self::assertSame('piggyback', $this->db->scalar("SELECT info_value FROM system_info WHERE info_key = 'scheduler_last_run'"));
        self::assertSame('2026-11-02 00:05:00.000000', $this->db->scalar("SELECT next_run_at FROM scheduled_jobs WHERE job_type = 'system.heartbeat'"));

        self::assertSame([], $s->runDue('cron', 2000, 3)['ran'], 'not due again yet');
        $this->clock->advance('+5 minutes');
        self::assertCount(1, $s->runDue('cron', 2000, 3)['ran']);
        self::assertSame(2, (int) $this->db->scalar('SELECT COUNT(*) FROM job_attempts'));
    }

    public function testFailingJobIsRecordedAndDoesNotStopOthers(): void
    {
        $failing = new class implements JobHandler {
            public function type(): string
            {
                return 'test.boom';
            }

            public function defaultIntervalMinutes(): int
            {
                return 60;
            }

            public function handle(array $payload, JobContext $ctx): void
            {
                throw new \RuntimeException('boom');
            }
        };
        $r = $this->scheduler($failing)->runDue('cron', 2000, 5);
        $byType = array_column($r['ran'], 'status', 'type');
        self::assertSame('failed', $byType['test.boom']);
        self::assertSame('succeeded', $byType['system.heartbeat']);
        self::assertStringContainsString('boom', (string) $this->db->scalar("SELECT a.message FROM job_attempts a JOIN jobs j ON j.id = a.job_id WHERE j.job_type = 'test.boom'"));
    }

    public function testOnlyOneRunnerAtATime(): void
    {
        $other = TestDb::connection();
        self::assertTrue($other->acquireLock('blue_scheduler', 0));
        try {
            self::assertSame('locked', $this->scheduler()->runDue('piggyback', 2000, 3)['skipped']);
        } finally {
            $other->releaseLock('blue_scheduler');
        }
    }
}
