<?php

declare(strict_types=1);

namespace Blue\Core\Backup;

use Closure;
use Blue\Core\Jobs\JobContext;
use Blue\Core\Jobs\JobHandler;

/**
 * Every minute: continues a running backup within the scheduler's time budget, starts the scheduled backup when due
 * (backup.interval_hours) and alerts System Admins when the newest verified backup is too old (backup.max_age_hours).
 */
final class BackupJob implements JobHandler
{
    /** @param Closure(): BackupService $service */
    public function __construct(private readonly Closure $service)
    {
    }

    public function type(): string
    {
        return 'backup.run';
    }

    public function defaultIntervalMinutes(): int
    {
        return 1;
    }

    public function handle(array $payload, JobContext $ctx): void
    {
        ($this->service)()->tick($ctx->timeLeftMs());
    }
}
