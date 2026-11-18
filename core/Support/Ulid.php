<?php

declare(strict_types=1);

namespace Blue\Core\Support;

use InvalidArgumentException;

/**
 * ULID generator (26 chars, Crockford base32) for `public_id` columns,
 * correlation IDs and event IDs (P0-02 §10, P0-03 §0).
 * Written in-house to avoid a dependency (P0-02 §15).
 *
 * Monotonic within the same millisecond: the random part is incremented,
 * so IDs generated in one process sort in creation order.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private static int $lastMs = -1;

    /** @var list<int> 16 x 5-bit random digits */
    private static array $lastRandom = [];

    public static function generate(?Clock $clock = null): string
    {
        $ms = $clock !== null
            ? (int) $clock->now()->format('Uv')
            : (int) floor(microtime(true) * 1000);

        if ($ms === self::$lastMs) {
            self::$lastRandom = self::increment(self::$lastRandom);
        } else {
            self::$lastMs = $ms;
            self::$lastRandom = [];
            $bytes = random_bytes(16);
            for ($i = 0; $i < 16; $i++) {
                self::$lastRandom[] = ord($bytes[$i]) & 31;
            }
        }

        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = self::ALPHABET[$ms % 32] . $time;
            $ms = intdiv($ms, 32);
        }
        $random = '';
        foreach (self::$lastRandom as $digit) {
            $random .= self::ALPHABET[$digit];
        }
        return $time . $random;
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $value);
    }

    /** Milliseconds since epoch encoded in the ULID. */
    public static function timestampMs(string $ulid): int
    {
        if (!self::isValid($ulid)) {
            throw new InvalidArgumentException('Invalid ULID');
        }
        $ms = 0;
        for ($i = 0; $i < 10; $i++) {
            $ms = $ms * 32 + strpos(self::ALPHABET, $ulid[$i]);
        }
        return $ms;
    }

    /**
     * @param list<int> $digits
     * @return list<int>
     */
    private static function increment(array $digits): array
    {
        for ($i = 15; $i >= 0; $i--) {
            if ($digits[$i] < 31) {
                $digits[$i]++;
                return $digits;
            }
            $digits[$i] = 0;
        }
        // 80-bit overflow within one millisecond is practically impossible; regenerate.
        $bytes = random_bytes(16);
        $out = [];
        for ($i = 0; $i < 16; $i++) {
            $out[] = ord($bytes[$i]) & 31;
        }
        return $out;
    }
}
