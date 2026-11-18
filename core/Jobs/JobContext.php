<?php

declare(strict_types=1);

namespace Blue\Core\Jobs;

use Blue\Core\Database\Connection;
use Blue\Core\Log\Logger;
use Blue\Core\Support\Clock;

final class JobContext
{
    public function __construct(
        public readonly Connection $db,
        public readonly Clock $clock,
        public readonly Logger $logger,
        public readonly string $correlationId,
        public readonly string $trigger,
        private readonly float $deadline,
    ) {
    }

    /** Long jobs check this and stop cleanly (they are resumed on the next run). */
    public function timeLeftMs(): int
    {
        return max(0, (int) (($this->deadline - microtime(true)) * 1000));
    }
}
