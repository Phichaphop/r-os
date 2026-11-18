<?php

declare(strict_types=1);

namespace Blue\Core\Kpi;

/** A computed figure + the parts it was made from (shown so it can be reconciled with the source screen). */
final class MeasureResult
{
    /** @param array<string, float|int> $parts translation key => number */
    public function __construct(
        public readonly ?float $value,
        public readonly array $parts = [],
    ) {
    }
}
