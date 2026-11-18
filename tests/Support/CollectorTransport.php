<?php

declare(strict_types=1);

namespace Blue\Tests\Support;

use Blue\Core\Integrations\HttpTransport;
use BlueCollector\Api;

/**
 * Sends Blue OS's outgoing calls straight into the simulated Time Collector's Punch API (same process) — the real
 * HTTP path is exercised in the browser walkthrough. `$override` lets a test answer with an error instead.
 */
final class CollectorTransport implements HttpTransport
{
    /** @var list<array{url: string, headers: array<string, string>}> */
    public array $calls = [];
    /** @var (callable(string): ?array{status: int, body: string, error: ?string, ms: int})|null */
    public $override = null;

    public function __construct(private readonly Api $api, private readonly \Closure $now)
    {
    }

    public function get(string $url, array $headers, int $timeoutSeconds, int $maxBytes): array
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers];
        if ($this->override !== null) {
            $r = ($this->override)($url);
            if ($r !== null) {
                return $r;
            }
        }
        $p = parse_url($url);
        $pathQuery = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower($k)] = $v;
        }
        $res = $this->api->handle('GET', $pathQuery, $h, ($this->now)());
        return ['status' => $res['status'], 'body' => (string) json_encode($res['body']), 'error' => null, 'ms' => 1];
    }
}
