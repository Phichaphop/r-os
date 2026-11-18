<?php

declare(strict_types=1);

namespace Blue\Core\Jobs;

/**
 * A unit of background work. The same handler runs under every trigger
 * (piggyback on prototype, cron on staging/production — P0-02 §8.2), so moving
 * hosting never changes module code.
 * Handlers must be idempotent and finish within the time budget they are given.
 */
interface JobHandler
{
    public function type(): string;

    /** Default schedule; admins may change it later (config, phase 3). */
    public function defaultIntervalMinutes(): int;

    /** @param array<string, mixed> $payload */
    public function handle(array $payload, JobContext $ctx): void;
}
