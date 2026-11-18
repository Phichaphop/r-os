<?php

declare(strict_types=1);

namespace Blue\Core\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Test clock: fixed time that can be advanced explicitly. */
final class FrozenClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $isoUtc = '2026-11-02T00:00:00Z')
    {
        $this->now = new DateTimeImmutable($isoUtc, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
