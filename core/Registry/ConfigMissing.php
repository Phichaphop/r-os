<?php

declare(strict_types=1);

namespace Blue\Core\Registry;

use Blue\Core\Error\BusinessException;

/** A required configuration has no value for the date/scope asked — calculations must stop (6.25.2 fail safely). */
final class ConfigMissing extends BusinessException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct('config.error.missing', ['key' => $key], 409);
    }
}
