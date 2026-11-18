<?php

declare(strict_types=1);

namespace Blue\Core\Registry;

/**
 * Validates and normalizes a raw input value against a definition (6.25.1: type, unit, precision, min/max, allowed values).
 * Decimals stay strings end-to-end so money never passes through binary floating point.
 */
final class ConfigValue
{
    /**
     * @param array<string, mixed> $def row of config_definitions
     * @return array{0:mixed, 1:?string} [normalized value, error translation key]
     */
    public static function normalize(array $def, string $raw): array
    {
        $raw = trim($raw);
        $rules = self::rules($def);
        switch ((string) $def['data_type']) {
            case 'decimal':
                if (!preg_match('/^-?\d{1,15}(\.\d{1,6})?$/', $raw)) {
                    return [null, 'config.error.decimal'];
                }
                $precision = (int) ($rules['precision'] ?? ($def['precision_digits'] ?? 4));
                $parts = explode('.', $raw);
                if (isset($parts[1]) && strlen($parts[1]) > $precision) {
                    return [null, 'config.error.precision'];
                }
                $value = $parts[0] . ($precision > 0 ? '.' . str_pad($parts[1] ?? '', $precision, '0') : '');
                if (isset($rules['min']) && self::cmp($value, (string) $rules['min']) < 0) {
                    return [null, 'config.error.min'];
                }
                if (isset($rules['max']) && self::cmp($value, (string) $rules['max']) > 0) {
                    return [null, 'config.error.max'];
                }
                return [$value, null];
            case 'integer':
                if (!preg_match('/^-?\d{1,12}$/', $raw)) {
                    return [null, 'config.error.integer'];
                }
                $v = (int) $raw;
                if ((isset($rules['min']) && $v < (int) $rules['min']) || (isset($rules['max']) && $v > (int) $rules['max'])) {
                    return [null, 'config.error.range'];
                }
                return [$v, null];
            case 'boolean':
                return in_array($raw, ['1', '0', 'true', 'false'], true) ? [in_array($raw, ['1', 'true'], true), null] : [null, 'config.error.boolean'];
            case 'enum':
                return in_array($raw, (array) ($rules['values'] ?? []), true) ? [$raw, null] : [null, 'config.error.enum'];
            case 'color':
                if ($raw === '' && !empty($rules['nullable'])) {
                    return [null, null];
                }
                return preg_match('/^#[0-9A-Fa-f]{6}$/', $raw) ? [strtoupper($raw), null] : [null, 'config.error.color'];
            case 'json':
                $d = json_decode($raw, true);
                return json_last_error() === JSON_ERROR_NONE ? [$d, null] : [null, 'config.error.json'];
            default:
                $len = mb_strlen($raw);
                if ($len < (int) ($rules['min'] ?? 0) || $len > (int) ($rules['max'] ?? 255)) {
                    return [null, 'config.error.length'];
                }
                return [$raw, null];
        }
    }

    /**
     * @param array<string, mixed> $def
     * @return array<string, mixed>
     */
    public static function rules(array $def): array
    {
        $r = json_decode((string) ($def['validation_json'] ?? ''), true);
        return is_array($r) ? $r : [];
    }

    /** Human-readable value (for lists, diffs). */
    public static function display(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }

    /** Decimal comparison without bcmath (not always enabled on shared hosting / XAMPP). */
    public static function cmp(string $a, string $b): int
    {
        $norm = static function (string $x): array {
            $neg = str_starts_with($x, '-');
            $x = ltrim($x, '-');
            [$i, $f] = array_pad(explode('.', $x, 2), 2, '');
            return [$neg, ltrim($i, '0'), rtrim($f, '0')];
        };
        [$na, $ia, $fa] = $norm($a);
        [$nb, $ib, $fb] = $norm($b);
        $zeroA = $ia === '' && $fa === '';
        $zeroB = $ib === '' && $fb === '';
        $na = $na && !$zeroA;
        $nb = $nb && !$zeroB;
        if ($na !== $nb) {
            return $na ? -1 : 1;
        }
        $cmp = strlen($ia) <=> strlen($ib);
        if ($cmp === 0) {
            $cmp = strcmp($ia, $ib) <=> 0;
        }
        if ($cmp === 0) {
            $len = max(strlen($fa), strlen($fb));
            $cmp = strcmp(str_pad($fa, $len, '0'), str_pad($fb, $len, '0')) <=> 0;
        }
        return $na ? -$cmp : $cmp;
    }
}
