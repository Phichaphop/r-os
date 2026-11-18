<?php

declare(strict_types=1);

namespace Blue\Core\Error;

use RuntimeException;

/**
 * An expected, user-facing problem (validation, rule violation, conflict).
 * Message is a translation key — rendered in the user's language, never raw (6.30.6).
 * Technical failures are any other Throwable.
 */
class BusinessException extends RuntimeException
{
    /** @param array<string, scalar> $params */
    public function __construct(
        public readonly string $messageKey,
        public readonly array $params = [],
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($messageKey, $httpStatus);
    }
}
