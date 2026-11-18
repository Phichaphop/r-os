<?php

declare(strict_types=1);

namespace Blue\Core\Support;

use DateTimeImmutable;

/**
 * The ONLY way to read "now" (P0-02 §4 rule 5) — lets tests freeze time
 * for effective dates and period boundaries. Always UTC.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
