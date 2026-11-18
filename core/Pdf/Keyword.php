<?php

declare(strict_types=1);

namespace Blue\Core\Pdf;

/** A bare keyword met while parsing (obj, endobj, stream, R …) — never written back. */
final class Keyword
{
    public function __construct(public readonly string $word)
    {
    }
}
