<?php

declare(strict_types=1);

namespace Blue\Core\Integrations;

use Blue\Core\Http\Request;
use Blue\Core\Support\Clock;

/**
 * Authentication of machine-to-machine calls (6.30.15, P0-03 §12 HMAC contract).
 *
 *  api_key — header `X-Blue-Key: <key id>.<secret>` (or `Authorization: Bearer <key id>.<secret>`). For simple external
 *            schedulers that can only send a fixed header. Over HTTPS only in production.
 *  hmac    — headers X-Blue-Key-Id, X-Blue-Timestamp (unix seconds, ±300 s), X-Blue-Nonce (≥ 16 random bytes, hex, used once),
 *            X-Blue-Signature = hex(HMAC_SHA256(secret, METHOD \n path_with_query \n timestamp \n nonce)).
 * The same signer is used by Blue OS when it calls the Time Collector (phase 4). Failures are logged without the secret.
 */
final class IntegrationAuth
{
    public const MAX_SKEW = 300;

    public function __construct(private readonly IntegrationRegistry $registry, private readonly Clock $clock)
    {
    }

    public static function signature(string $secret, string $method, string $pathWithQuery, string $timestamp, string $nonce): string
    {
        return hash_hmac('sha256', strtoupper($method) . "\n" . $pathWithQuery . "\n" . $timestamp . "\n" . $nonce, $secret);
    }

    /** @return array<string, string> headers for an outgoing signed call */
    public static function signHeaders(string $keyId, string $secret, string $method, string $pathWithQuery, int $now): array
    {
        $nonce = bin2hex(random_bytes(16));
        $ts = (string) $now;
        return ['X-Blue-Key-Id' => $keyId, 'X-Blue-Timestamp' => $ts, 'X-Blue-Nonce' => $nonce, 'X-Blue-Signature' => self::signature($secret, $method, $pathWithQuery, $ts, $nonce)];
    }

    public static function pathWithQuery(Request $r): string
    {
        return $r->path . ($r->query !== [] ? '?' . http_build_query($r->query) : '');
    }

    /**
     * @param array<string, mixed> $integration
     * @return array{ok: bool, error: ?string, key_id: ?string, credential_id: ?int}
     */
    public function verify(Request $r, array $integration): array
    {
        $fail = static fn (string $code, ?string $key = null): array => ['ok' => false, 'error' => $code, 'key_id' => $key, 'credential_id' => null];
        if ($integration['status'] !== 'active') {
            return $fail('integration_not_active');
        }
        $limit = $integration['rate_limit_per_min'] !== null ? (int) $integration['rate_limit_per_min'] : 0;
        if ($limit > 0 && $this->registry->recentCalls((int) $integration['id']) >= $limit) {
            return $fail('rate_limited');
        }
        if ($integration['auth_method'] === 'api_key') {
            $raw = $r->header('x-blue-key');
            if ($raw === '' && str_starts_with($r->header('authorization'), 'Bearer ')) {
                $raw = substr($r->header('authorization'), 7);
            }
            if (!preg_match('/^(rk_[0-9a-f]{16})\.([A-Za-z0-9_-]{20,100})$/', trim($raw), $m)) {
                return $fail('missing_key');
            }
            $c = $this->registry->checkSecret((int) $integration['id'], $m[1], $m[2]);
            if ($c === null) {
                return $fail('bad_key', $m[1]);
            }
            $this->registry->touch((int) $c['id']);
            return ['ok' => true, 'error' => null, 'key_id' => $m[1], 'credential_id' => (int) $c['id']];
        }
        if ($integration['auth_method'] === 'hmac') {
            $keyId = $r->header('x-blue-key-id');
            $ts = $r->header('x-blue-timestamp');
            $nonce = $r->header('x-blue-nonce');
            $sig = strtolower($r->header('x-blue-signature'));
            if (!preg_match('/^rk_[0-9a-f]{16}$/', $keyId) || !ctype_digit($ts) || !preg_match('/^[0-9a-f]{32,64}$/', $nonce) || !preg_match('/^[0-9a-f]{64}$/', $sig)) {
                return $fail('missing_signature', $keyId !== '' ? mb_substr($keyId, 0, 40) : null);
            }
            if (abs($this->clock->now()->getTimestamp() - (int) $ts) > self::MAX_SKEW) {
                return $fail('stale_timestamp', $keyId);
            }
            $c = $this->registry->secretFor((int) $integration['id'], $keyId);
            if ($c === null || !hash_equals(self::signature((string) $c['secret'], $r->method, self::pathWithQuery($r), $ts, $nonce), $sig)) {
                return $fail('bad_signature', $keyId);
            }
            if (!$this->registry->rememberNonce($keyId, $nonce)) {
                return $fail('replay', $keyId);
            }
            $this->registry->touch((int) $c['id']);
            return ['ok' => true, 'error' => null, 'key_id' => $keyId, 'credential_id' => (int) $c['id']];
        }
        return $fail('unsupported_auth');
    }
}
