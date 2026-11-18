<?php

declare(strict_types=1);

namespace Blue\Core\Jobs;

use Blue\Core\Database\Connection;
use Blue\Core\Log\Logger;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;
use Throwable;

/**
 * Runs due scheduled jobs, time-boxed (P0-02 §8). Trigger adapters:
 *   - piggyback: after a page has been sent to a user (prototype / InfinityFree — no cron)
 *   - cron:      `bin/blue jobs:run` every minute (staging / production)
 *   - manual:    System Admin "run now" button (phase 2+, needs auth)
 * A DB advisory lock guarantees only one runner at a time.
 * Retry with backoff, dead-letter and the failed-jobs screen arrive in phase 3 (6.30.6).
 */
final class Scheduler
{
    private const LOCK = 'blue_scheduler';

    public function __construct(
        private readonly Connection $db,
        private readonly JobRegistry $registry,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /** Make sure every registered handler has a schedule row (new ones are due immediately). */
    public function syncSchedules(): void
    {
        $now = $this->now();
        foreach ($this->registry->all() as $type => $h) {
            $this->db->run(
                'INSERT IGNORE INTO scheduled_jobs (job_type, interval_minutes, enabled, next_run_at, created_at, updated_at) VALUES (?, ?, 1, ?, ?, ?)',
                [$type, $h->defaultIntervalMinutes(), $now, $now, $now]
            );
        }
    }

    public function nextDueAt(): ?string
    {
        $v = $this->db->scalar('SELECT MIN(next_run_at) FROM scheduled_jobs WHERE enabled = 1');
        return is_string($v) ? $v : null;
    }

    /**
     * @return array{ran:list<array{type:string,status:string,ms:int}>, skipped:?string}
     */
    public function runDue(string $trigger, int $budgetMs, int $maxJobs): array
    {
        $deadline = microtime(true) + $budgetMs / 1000;
        if (!$this->db->acquireLock(self::LOCK, 0)) {
            return ['ran' => [], 'skipped' => 'locked'];
        }
        $ran = [];
        try {
            $this->syncSchedules();
            $due = $this->db->all(
                'SELECT * FROM scheduled_jobs WHERE enabled = 1 AND next_run_at <= ? ORDER BY next_run_at LIMIT ' . max(1, $maxJobs),
                [$this->now()]
            );
            foreach ($due as $row) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                $ran[] = $this->runOne($row, $trigger, $deadline);
            }
        } finally {
            $this->db->releaseLock(self::LOCK);
        }
        return ['ran' => $ran, 'skipped' => null];
    }

    /**
     * @param array<string, mixed> $row scheduled_jobs row
     * @return array{type:string,status:string,ms:int}
     */
    private function runOne(array $row, string $trigger, float $deadline): array
    {
        $type = (string) $row['job_type'];
        $cid = Ulid::generate($this->clock);
        $startedAt = $this->now();
        $this->db->run(
            "INSERT INTO jobs (public_id, job_type, status, attempts, trigger_source, correlation_id, created_at, started_at)
             VALUES (?, ?, 'running', 1, ?, ?, ?, ?)",
            [$cid, $type, $trigger, $cid, $startedAt, $startedAt]
        );
        $jobId = (int) $this->db->pdo()->lastInsertId();

        $handler = $this->registry->get($type);
        $t0 = hrtime(true);
        $status = 'succeeded';
        $message = null;
        try {
            if ($handler === null) {
                throw new \RuntimeException("No handler registered for {$type}");
            }
            $handler->handle([], new JobContext($this->db, $this->clock, $this->logger, $cid, $trigger, $deadline));
        } catch (Throwable $e) {
            $status = 'failed';
            $message = $e::class . ': ' . $e->getMessage();
            $this->logger->error('job_failed', ['job' => $type, 'job_cid' => $cid] + Logger::exceptionContext($e));
        }
        $ms = (int) ((hrtime(true) - $t0) / 1_000_000);
        $now = $this->now();

        $this->db->run(
            'INSERT INTO job_attempts (job_id, attempt_no, status, message, duration_ms, started_at) VALUES (?, 1, ?, ?, ?, ?)',
            [$jobId, $status, $message, $ms, $startedAt]
        );
        $this->db->run('UPDATE jobs SET status = ?, finished_at = ?, progress = 100 WHERE id = ?', [$status, $now, $jobId]);
        $next = $this->clock->now()->modify('+' . (int) $row['interval_minutes'] . ' minutes')->format('Y-m-d H:i:s.u');
        $this->db->run(
            'UPDATE scheduled_jobs SET last_run_at = ?, last_status = ?, next_run_at = ?, updated_at = ? WHERE id = ?',
            [$now, $status, $next, $now, $row['id']]
        );
        return ['type' => $type, 'status' => $status, 'ms' => $ms];
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
