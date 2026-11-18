<?php

declare(strict_types=1);

namespace Blue\Core\Support;

/**
 * Exact money arithmetic without floats (NFR-INT-02): amounts are DECIMAL(19,4) strings in the database and
 * integers of 1/10 000 of a currency unit in PHP (64-bit int → ±922 trillion units, far above any batch total).
 */
final class Money
{
    public const SCALE = 10000;

    /** Parses "1,500.5" / "1500.50" / "-12" into units; null when the text is not a plain decimal with ≤ $maxDecimals places. */
    public static function parse(string $text, int $maxDecimals = 2): ?int
    {
        $t = str_replace([',', ' ', "\u{00A0}"], '', trim($text));
        $dec = max(0, min(4, $maxDecimals));
        $frac = $dec > 0 ? '(?:\.(\d{1,' . $dec . '}))?' : '';
        if (!preg_match('/^(-?)(\d{1,15})' . $frac . '$/', $t, $m)) {
            return null;
        }
        $units = (int) $m[2] * self::SCALE + (int) str_pad($m[3] ?? '', 4, '0');
        return $m[1] === '-' ? -$units : $units;
    }

    /** Units from a DECIMAL column value ("1500.5000"). */
    public static function fromDb(mixed $value): int
    {
        return self::parse((string) $value, 4) ?? 0;
    }

    /** Units → DECIMAL string with 4 places for the database. */
    public static function toDb(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $u = abs($units);
        return $sign . intdiv($u, self::SCALE) . '.' . str_pad((string) ($u % self::SCALE), 4, '0', STR_PAD_LEFT);
    }

    /** Units → "1,500.50" (2 places, thousands separators) for screens and files. */
    public static function format(int $units, int $decimals = 2, bool $grouping = true): string
    {
        $sign = $units < 0 ? '-' : '';
        $u = abs($units);
        $whole = intdiv($u, self::SCALE);
        $frac = $u % self::SCALE;
        if ($decimals < 4) {
            $div = 10 ** (4 - $decimals);
            $rounded = intdiv($frac + intdiv($div, 2), $div); // half up on the absolute value
            if ($rounded >= 10 ** $decimals) {
                $whole++;
                $rounded = 0;
            }
            $frac = $rounded;
        }
        $w = $grouping ? number_format($whole, 0, '.', ',') : (string) $whole;
        return $sign . $w . ($decimals > 0 ? '.' . str_pad((string) $frac, $decimals, '0', STR_PAD_LEFT) : '');
    }

    /** True when the amount has no more than $decimals places (e.g. a bank file can only carry satang). */
    public static function hasAtMostDecimals(int $units, int $decimals): bool
    {
        return $decimals >= 4 || $units % (10 ** (4 - $decimals)) === 0;
    }
}
