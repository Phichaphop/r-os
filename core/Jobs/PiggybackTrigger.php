<?php

declare(strict_types=1);

namespace Blue\Core\Jobs;

use Blue\Core\Log\Logger;
use Throwable;

/**
 * Prototype scheduler trigger (InfinityFree has no cron and blocks non-browser requests — CF-16).
 * Called AFTER the page has been flushed to the user, so it never slows the visible response.
 * A tiny file stamp avoids hitting the database on every request when nothing is due.
 */
final class PiggybackTrigger
{
    public function __construct(
        private readonly \Closure $scheduler,
        private readonly string $stampFile,
        private readonly Logger $logger,
        private readonly int $budgetMs,
        private readonly int $maxJobs,
    ) {
    }

    public function afterResponse(): void
    {
        if (!is_dir(dirname($this->stampFile))) {
            @mkdir(dirname($this->stampFile), 0750, true);
        }
        $nextCheck = is_file($this->stampFile) ? (int) @file_get_contents($this->stampFile) : 0;
        if (time() < $nextCheck) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            /** @var Scheduler $s */
            $s = ($this->scheduler)();
            $result = $s->runDue('piggyback', $this->budgetMs, $this->maxJobs);
            $next = $s->nextDueAt();
            $nextTs = $next !== null ? (int) strtotime($next . ' UTC') : time() + 300;
            @file_put_contents($this->stampFile, (string) max(time() + 30, $nextTs), LOCK_EX);
            if ($result['ran'] !== []) {
                $this->logger->info('piggyback_jobs', $result);
            }
        } catch (Throwable $e) {
            @file_put_contents($this->stampFile, (string) (time() + 300), LOCK_EX);
            $this->logger->error('piggyback_failed', Logger::exceptionContext($e));
        }
    }
}
