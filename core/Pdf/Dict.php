<?php

declare(strict_types=1);

namespace Blue\Core\Pdf;

/** PDF dictionary — keys are names without the slash, order kept. */
final class Dict
{
    /** @param array<string, mixed> $items */
    public function __construct(public array $items = [])
    {
    }

    public function get(string $key): mixed
    {
        return $this->items[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function set(string $key, mixed $value): void
    {
        $this->items[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->items[$key]);
    }

    public function name(string $key): ?string
    {
        $v = $this->items[$key] ?? null;
        return $v instanceof Name ? $v->name : null;
    }
}
