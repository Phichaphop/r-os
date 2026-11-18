<?php

declare(strict_types=1);

namespace Blue\Core\Http;

/**
 * Session data access. Web requests use the native PHP session; tests and CLI use an in-memory
 * array (one instance = one browser). Only small values live here — the authoritative session
 * record is user_sessions in the database.
 */
class SessionStore
{
    /** @var array<string, mixed> */
    protected array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    /** New session ID after login / privilege change (session fixation). */
    public function regenerate(): void
    {
    }

    public function clear(): void
    {
        $this->data = [];
    }
}
