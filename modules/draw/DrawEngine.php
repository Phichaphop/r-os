<?php

declare(strict_types=1);

namespace Blue\Modules\Draw;

/**
 * The draw itself — deterministic from a secret seed, so it can be verified after the seed is revealed (7B item 3):
 *
 *   commitment       = SHA-256(seed), shown when the list is locked (before any draw)
 *   snapshot hash    = SHA-256 of the locked participant list, prizes and rules
 *   pick j of round r = HMAC-SHA256(seed, "BLUE-DRAW|v1|r|j|c") → first 7 bytes as an integer → rejection sampling
 *                       (c = 0, 1, … until the value falls in the unbiased range) → index into the remaining candidates
 *
 * Candidates are kept in snapshot order, so anyone with the seed, the snapshot and the round history gets the same winners.
 * The browser animation only shows the result; it is never the source of randomness.
 */
final class DrawEngine
{
    // fixed identifier of published draws (anyone re-computes the HMAC with it) — never change it once draws exist (renamed from R-OS-DRAW at the fresh-install rename, 0.37.0)
    public const ALGORITHM = 'BLUE-DRAW v1 (HMAC-SHA256, rejection sampling)';
    private const RANGE = 72057594037927936; // 2^56

    public static function newSeed(): string
    {
        return random_bytes(32);
    }

    public static function commitment(string $seed): string
    {
        return hash('sha256', $seed);
    }

    /**
     * @param list<array{position: int, code: ?string, name: string, grp: ?string}> $participants
     * @param list<array{sort: int, name: string, quantity: int}> $prizes
     */
    public static function snapshotHash(array $participants, array $prizes, bool $noRepeat): string
    {
        $lines = ['rules|no_repeat=' . ($noRepeat ? 1 : 0)];
        foreach ($participants as $p) {
            $lines[] = 'p|' . $p['position'] . '|' . ($p['code'] ?? '') . '|' . $p['name'] . '|' . ($p['grp'] ?? '');
        }
        foreach ($prizes as $z) {
            $lines[] = 'z|' . $z['sort'] . '|' . $z['name'] . '|' . $z['quantity'];
        }
        return hash('sha256', implode("\n", $lines));
    }

    /**
     * Picks $count distinct candidates (by position in the given list).
     *
     * @param list<int> $candidates participant ids in snapshot order
     * @return list<int> chosen participant ids in pick order
     */
    public static function pick(string $seed, int $round, array $candidates, int $count): array
    {
        $remaining = array_values($candidates);
        $out = [];
        for ($j = 0; $j < $count && $remaining !== []; $j++) {
            $m = count($remaining);
            $limit = intdiv(self::RANGE, $m) * $m;
            $c = 0;
            do {
                $h = hash_hmac('sha256', 'BLUE-DRAW|v1|' . $round . '|' . $j . '|' . $c, $seed, true);
                $v = (int) hexdec(bin2hex(substr($h, 0, 7)));
                $c++;
            } while ($v >= $limit && $c < 1000);
            $i = $v % $m;
            $out[] = $remaining[$i];
            array_splice($remaining, $i, 1);
        }
        return $out;
    }

    /** @param list<int> $candidates */
    public static function candidatesHash(array $candidates): string
    {
        return hash('sha256', implode(',', $candidates));
    }

    public const QUICK_MAX_NAMES = 2000;
    public const QUICK_MAX_WINNERS = 200;

    /**
     * Quick draw: one name per line (blank lines and repeats dropped), $count distinct winners chosen with random_int
     * (partial Fisher–Yates). Not verifiable afterwards — that is what the full event is for.
     *
     * @return array{winners: list<string>, total: int}
     */
    public static function quick(string $names, string $count): array
    {
        $list = [];
        foreach (preg_split('/\R/u', $names) ?: [] as $line) {
            $n = mb_substr(trim($line), 0, 120);
            if ($n !== '') {
                $list[mb_strtolower($n)] ??= $n;
            }
        }
        $list = array_values($list);
        $errors = [];
        if ($list === []) {
            $errors['names'] = 'draw.quick.error.names';
        } elseif (count($list) > self::QUICK_MAX_NAMES) {
            $errors['names'] = 'draw.quick.error.too_many';
        }
        $k = ctype_digit($count) ? (int) $count : 0;
        if ($k < 1 || $k > self::QUICK_MAX_WINNERS || ($list !== [] && $k > count($list))) {
            $errors['count'] = 'draw.quick.error.count';
        }
        if ($errors !== []) {
            throw new \Blue\Core\Error\ValidationException($errors);
        }
        $n = count($list);
        for ($i = 0; $i < $k; $i++) {
            $j = random_int($i, $n - 1);
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        }
        return ['winners' => array_slice($list, 0, $k), 'total' => $n];
    }
}
