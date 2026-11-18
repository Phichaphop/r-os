<?php

declare(strict_types=1);

namespace Blue\Core\Error;

/** 403/404/405 etc. — expected outcomes with a translated message. */
final class HttpException extends BusinessException
{
    public static function notFound(): self
    {
        return new self('error.not_found', [], 404);
    }

    public static function forbidden(): self
    {
        return new self('error.forbidden', [], 403);
    }

    public static function methodNotAllowed(): self
    {
        return new self('error.method_not_allowed', [], 405);
    }
}
