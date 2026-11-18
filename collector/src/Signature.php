<?php

declare(strict_types=1);

namespace BlueCollector;

/**
 * HMAC-SHA256 request signature of the Punch API (P0-03 §12). The same formula Blue OS uses when it calls the
 * Collector (core/Integrations/IntegrationAuth) — written again here on purpose: the Collector runs in the office
 * on its own and must not need Blue OS code.
 *
 *   signature = hex(HMAC_SHA256(secret, METHOD \n path_with_query \n timestamp \n nonce))
 */
final class Signature
{
    public const MAX_SKEW = 300;

    public static function sign(string $secret, string $method, string $pathWithQuery, string $timestamp, string $nonce): string
    {
        return hash_hmac('sha256', strtoupper($method) . "\n" . $pathWithQuery . "\n" . $timestamp . "\n" . $nonce, $secret);
    }

    /** @return array<string, string> headers for a signed call (used by tests and the "selftest" command) */
    public static function headers(string $keyId, string $secret, string $method, string $pathWithQuery, int $now): array
    {
        $nonce = bin2hex(random_bytes(16));
        return [
            'x-blue-key-id' => $keyId, 'x-blue-timestamp' => (string) $now, 'x-blue-nonce' => $nonce,
            'x-blue-signature' => self::sign($secret, $method, $pathWithQuery, (string) $now, $nonce),
        ];
    }
}
