<?php

declare(strict_types=1);

namespace Blue\Core\Pdf;

/** PDF name object (/Type) — stored without the slash, #xx escapes decoded. */
final class Name
{
    public function __construct(public readonly string $name)
    {
    }

    public function is(string $name): bool
    {
        return $this->name === $name;
    }
}
