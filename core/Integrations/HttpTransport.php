<?php

declare(strict_types=1);

namespace Blue\Core\Integrations;

/**
 * Outgoing HTTP call made by Blue OS to a registered integration (6.30.15) — e.g. the Punch API of the Time Collector.
 * One small interface so tests (and a future queue) can replace the real network call.
 */
interface HttpTransport
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, error: ?string, ms: int} status 0 = no answer (error says why)
     */
    public function get(string $url, array $headers, int $timeoutSeconds, int $maxBytes): array;
}
