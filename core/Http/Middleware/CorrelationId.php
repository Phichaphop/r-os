<?php

declare(strict_types=1);

namespace Blue\Core\Http\Middleware;

use Blue\Core\Http\Middleware;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Log\Logger;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * First middleware: every request gets a ULID correlation ID that flows to logs,
 * audit, outbox and jobs (P0-02 §10) and is shown to users as "reference code".
 * An upstream X-Request-Id is recorded but never trusted as our own ID.
 */
final class CorrelationId implements Middleware
{
    public function __construct(private readonly Logger $logger, private readonly Clock $clock)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $cid = Ulid::generate($this->clock);
        $request->attributes['correlation_id'] = $cid;
        $this->logger->setCorrelationId($cid);
        $upstream = $request->header('x-request-id');
        if ($upstream !== '') {
            $request->attributes['upstream_request_id'] = substr(preg_replace('/[^A-Za-z0-9._-]/', '', $upstream) ?? '', 0, 64);
        }
        $response = $next($request);
        return $response->withHeader('X-Correlation-Id', $cid);
    }
}
