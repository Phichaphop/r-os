<?php

declare(strict_types=1);

namespace Blue\Core\Error;

/** Field-level validation problems: field => translation key. Rendered next to each field + a summary. */
final class ValidationException extends BusinessException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('form.has_errors', [], 422);
    }
}
