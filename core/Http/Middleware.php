<?php

declare(strict_types=1);

namespace Blue\Core\Http;

/**
 * Pipeline order (P0-02 §7): correlation ID → error boundary → security headers → session
 * → [auth — phase 2] → CSRF → [scope — phase 2] → [rate-limit — phase 2] → handler
 */
interface Middleware
{
    /** @param callable(Request): Response $next */
    public function process(Request $request, callable $next): Response;
}
