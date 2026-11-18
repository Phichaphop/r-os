<?php

declare(strict_types=1);

namespace Blue\Core\Http\Middleware;

use Blue\Core\Error\ErrorRenderer;
use Blue\Core\Http\Middleware;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Throwable;

/** Catches everything below it and renders a safe error page (no stack traces outside local/ci). */
final class ErrorBoundary implements Middleware
{
    /** @param \Closure(): ErrorRenderer $renderer lazy — built only when an error happens */
    public function __construct(private readonly \Closure $renderer)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (Throwable $e) {
            return ($this->renderer)()->render($e, $request);
        }
    }
}
