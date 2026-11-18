<?php

declare(strict_types=1);

namespace Blue\Core\Pdf;

/** Indirect reference "num gen R". */
final class Ref
{
    public function __construct(public readonly int $num, public readonly int $gen = 0)
    {
    }
}
