<?php

declare(strict_types=1);

namespace BlueCollector;

/**
 * Punch API v1 (P0-03 §12) — read only.
 *   GET /v1/punches?after={cursor}&limit={1..1000}   (default limit 500)
 *   GET /v1/health
 * Every call is signed (HMAC-SHA256, see Signature): unknown key / bad signature / stale timestamp / reused nonce → 401.
 * Errors: 400 bad cursor or limit · 401 auth · 404 unknown path · 405 not GET · 429 too many calls.
 * There is no endpoint that writes or deletes, and no field ever carries biometric data (DEC-25).
 */
final class Api
{
    public const VERSION = '1.0.0';
    public const RATE_PER_MIN = 120;

    public function __construct(private readonly Store $store)
    {
    }

    /**
     * @param array<string, string> $headers lower-case names
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(string $method, string $pathWithQuery, array $headers, int $now): array
    {
        $path = (string) parse_url($pathWithQuery, PHP_URL_PATH);
        $base = (string) preg_replace('#^.*(?=/v1/)#', '', $path); // the Collector may live in a sub-folder
        if (!in_array($base, ['/v1/punches', '/v1/health'], true)) {
            return self::error(404, 'not_found');
        }
        if (strtoupper($method) !== 'GET') {
            return self::error(405, 'method_not_allowed');
        }
        if ($this->store->countCall($now) > self::RATE_PER_MIN) {
            return self::error(429, 'rate_limited');
        }
        $auth = $this->authenticate($method, $pathWithQuery, $headers, $now);
        if ($auth !== null) {
            return self::error(401, $auth);
        }
        return $base === '/v1/health' ? $this->health($now) : $this->punches((string) (parse_url($pathWithQuery, PHP_URL_QUERY) ?? ''));
    }

    /** @param array<string, string> $h */
    private function authenticate(string $method, string $pathWithQuery, array $h, int $now): ?string
    {
        $keyId = (string) ($h['x-blue-key-id'] ?? '');
        $ts = (string) ($h['x-blue-timestamp'] ?? '');
        $nonce = (string) ($h['x-blue-nonce'] ?? '');
        $sig = strtolower((string) ($h['x-blue-signature'] ?? ''));
        if (preg_match('/^rk_[0-9a-f]{16}$/', $keyId) !== 1 || !ctype_digit($ts) || preg_match('/^[0-9a-f]{32,64}$/', $nonce) !== 1 || preg_match('/^[0-9a-f]{64}$/', $sig) !== 1) {
            return 'missing_signature';
        }
        if (abs($now - (int) $ts) > Signature::MAX_SKEW) {
            return 'stale_timestamp';
        }
        $secret = $this->store->secret($keyId);
        if ($secret === null || !hash_equals(Signature::sign($secret, $method, $pathWithQuery, $ts, $nonce), $sig)) {
            return 'bad_signature';
        }
        if (!$this->store->rememberNonce($keyId, $nonce, $now)) {
            return 'replay';
        }
        return null;
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function punches(string $query): array
    {
        parse_str($query, $q);
        $after = (string) ($q['after'] ?? '');
        $limitRaw = (string) ($q['limit'] ?? '500');
        if (!ctype_digit($limitRaw) || (int) $limitRaw < 1 || (int) $limitRaw > 1000) {
            return self::error(400, 'bad_limit');
        }
        $seq = self::decodeCursor($after);
        if ($seq === null) {
            return self::error(400, 'bad_cursor');
        }
        $limit = (int) $limitRaw;
        $rows = $this->store->after($seq, $limit + 1);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows === [] ? $seq : (int) $rows[count($rows) - 1]['seq'];
        $items = array_map(static fn (array $r): array => [
            'event_id' => (string) $r['event_id'], 'device_id' => (string) $r['device_id'], 'device_serial' => (string) $r['device_serial'],
            'device_user_code' => (string) $r['device_user_code'], 'occurred_at' => (string) $r['occurred_at'], 'device_local_time' => (string) $r['device_local_time'],
            'device_timezone' => (string) $r['device_timezone'], 'verify_method' => (string) $r['verify_method'], 'collected_at' => (string) $r['collected_at'],
        ], $rows);
        return ['status' => 200, 'body' => ['items' => $items, 'next_cursor' => self::encodeCursor($last), 'has_more' => $more]];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function health(int $now): array
    {
        $devices = array_map(static fn (array $d): array => [
            'device_id' => (string) $d['device_id'], 'model' => (string) $d['model'], 'last_seen_at' => $d['last_seen_at'],
            'clock_drift_seconds' => $d['clock_drift_seconds'] !== null ? (int) $d['clock_drift_seconds'] : null, 'pending_records' => 0,
            'status' => (string) $d['status'],
        ], $this->store->devices());
        return ['status' => 200, 'body' => ['collector_version' => self::VERSION, 'server_time' => gmdate('Y-m-d\TH:i:s\Z', $now), 'devices' => $devices]];
    }

    /** The cursor is opaque to Blue OS: base64url("c1:<seq>"). An empty cursor starts at the beginning. */
    public static function encodeCursor(int $seq): string
    {
        return rtrim(strtr(base64_encode('c1:' . $seq), '+/', '-_'), '=');
    }

    public static function decodeCursor(string $cursor): ?int
    {
        if ($cursor === '') {
            return 0;
        }
        if (preg_match('/^[A-Za-z0-9_-]{4,40}$/', $cursor) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        return is_string($raw) && preg_match('/^c1:(\d{1,15})$/', $raw, $m) === 1 ? (int) $m[1] : null;
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private static function error(int $status, string $code): array
    {
        return ['status' => $status, 'body' => ['error' => $code]];
    }
}
