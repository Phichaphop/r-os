<?php

declare(strict_types=1);

namespace Blue\Core\Jobs;

use InvalidArgumentException;

final class JobRegistry
{
    /** @var array<string, JobHandler> */
    private array $handlers = [];

    public function register(JobHandler $handler): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_.]*$/', $handler->type())) {
            throw new InvalidArgumentException('Job type must look like "<module>.<name>": ' . $handler->type());
        }
        $this->handlers[$handler->type()] = $handler;
    }

    public function get(string $type): ?JobHandler
    {
        return $this->handlers[$type] ?? null;
    }

    /** @return array<string, JobHandler> */
    public function all(): array
    {
        return $this->handlers;
    }
}
