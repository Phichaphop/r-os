<?php

declare(strict_types=1);

namespace Blue\Core\Jobs;

use Closure;

/** A core background job whose work is a service call (outbox dispatch, workflow escalation …). */
final class CallbackJob implements JobHandler
{
    /** @param Closure(): mixed $work */
    public function __construct(
        private readonly string $type,
        private readonly int $intervalMinutes,
        private readonly Closure $work,
    ) {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function defaultIntervalMinutes(): int
    {
        return $this->intervalMinutes;
    }

    public function handle(array $payload, JobContext $ctx): void
    {
        ($this->work)();
    }
}
