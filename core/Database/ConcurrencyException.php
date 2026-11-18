<?php

declare(strict_types=1);

namespace Blue\Core\Database;

use Blue\Core\Error\BusinessException;

/** Someone else saved the record first (row_version mismatch). Shown to the user, not a bug. */
final class ConcurrencyException extends BusinessException
{
    public function __construct(string $table, string $publicId)
    {
        parent::__construct('error.concurrency', ['table' => $table, 'id' => $publicId], 409);
    }
}
