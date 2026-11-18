<?php

declare(strict_types=1);

namespace Blue\Core\Files;

/**
 * Minimal reader for font files (M30): recognises TrueType / OpenType / WOFF / WOFF2 from their first bytes, checks
 * that the table directory is sane, and reads the family/style names, version and the embedding permission (OS/2 fsType)
 * so the administrator sees what the file really is. Nothing in the font is executed or rendered on the server.
 */
final class FontInfo
{
    /** @return 'ttf'|'otf'|'woff'|'woff2'|null */
    public static function kind(string $bytes): ?string
    {
        $h = substr($bytes, 0, 4);
        return match (true) {
            $h === "\x00\x01\x00\x00", $h === 'true' => 'ttf',
            $h === 'OTTO' => 'otf',
            $h === 'wOFF' => 'woff',
            $h === 'wOF2' => 'woff2',
            default => null,
        };
    }

    /** Table directory fits in the file and declares a reasonable number of tables. */
    public static function sane(string $bytes): bool
    {
        $kind = self::kind($bytes);
        $len = strlen($bytes);
        if ($kind === 'ttf' || $kind === 'otf') {
            if ($len < 12) {
                return false;
            }
            $n = self::u16($bytes, 4);
            return $n >= 4 && $n <= 64 && $len >= 12 + 16 * $n;
        }
        if ($kind === 'woff' || $kind === 'woff2') {
            if ($len < 44) {
                return false;
            }
            $declared = self::u32($bytes, 8);
            $n = self::u16($bytes, 12);
            return $declared === $len && $n >= 4 && $n <= 64;
        }
        return false;
    }

    /**
     * @return array{family: ?string, style: ?string, full: ?string, version: ?string, embedding: ?string, weight: ?int, italic: ?bool}
     */
    public static function read(string $bytes): array
    {
        $out = ['family' => null, 'style' => null, 'full' => null, 'version' => null, 'embedding' => null, 'weight' => null, 'italic' => null];
        $tables = self::tables($bytes);
        if (isset($tables['name'])) {
            $n = self::names($tables['name']);
            $out['family'] = $n[16] ?? $n[1] ?? null;
            $out['style'] = $n[17] ?? $n[2] ?? null;
            $out['full'] = $n[4] ?? null;
            $out['version'] = isset($n[5]) ? trim((string) preg_replace('/^Version\s*/i', '', $n[5])) : null;
        }
        if (isset($tables['OS/2']) && strlen($tables['OS/2']) >= 10) {
            $out['weight'] = self::u16($tables['OS/2'], 4);
            $fs = self::u16($tables['OS/2'], 8);
            $out['embedding'] = match (true) {
                ($fs & 0x0002) !== 0 => 'restricted',
                ($fs & 0x0004) !== 0 => 'preview_print',
                ($fs & 0x0008) !== 0 => 'editable',
                default => 'installable',
            };
            if (strlen($tables['OS/2']) >= 64) {
                $out['italic'] = (self::u16($tables['OS/2'], 62) & 0x0001) !== 0;
            }
        }
        return $out;
    }

    /** @return array<string, string> tag => raw table bytes (WOFF tables are inflated; WOFF2 needs Brotli → none) */
    private static function tables(string $b): array
    {
        $kind = self::kind($b);
        $out = [];
        if ($kind === 'ttf' || $kind === 'otf') {
            $n = self::u16($b, 4);
            for ($i = 0; $i < $n; $i++) {
                $e = 12 + 16 * $i;
                $tag = substr($b, $e, 4);
                $off = self::u32($b, $e + 8);
                $len = self::u32($b, $e + 12);
                if (in_array($tag, ['name', 'OS/2'], true) && $off + $len <= strlen($b) && $len < 4_000_000) {
                    $out[$tag] = substr($b, $off, $len);
                }
            }
        } elseif ($kind === 'woff') {
            $n = self::u16($b, 12);
            for ($i = 0; $i < $n; $i++) {
                $e = 44 + 20 * $i;
                $tag = substr($b, $e, 4);
                $off = self::u32($b, $e + 4);
                $comp = self::u32($b, $e + 8);
                $orig = self::u32($b, $e + 12);
                if (!in_array($tag, ['name', 'OS/2'], true) || $off + $comp > strlen($b) || $orig > 4_000_000) {
                    continue;
                }
                $raw = substr($b, $off, $comp);
                if ($comp < $orig) {
                    $raw = @gzuncompress($raw, $orig);
                }
                if (is_string($raw) && strlen($raw) === $orig) {
                    $out[$tag] = $raw;
                }
            }
        }
        return $out;
    }

    /** @return array<int, string> name id => string (prefers Windows Unicode English, then any Unicode) */
    private static function names(string $t): array
    {
        if (strlen($t) < 6) {
            return [];
        }
        $count = self::u16($t, 2);
        $base = self::u16($t, 4);
        $best = [];
        for ($i = 0; $i < $count && 6 + 12 * $i + 12 <= strlen($t); $i++) {
            $r = 6 + 12 * $i;
            [$platform, $enc, $lang, $id, $len, $off] = [self::u16($t, $r), self::u16($t, $r + 2), self::u16($t, $r + 4), self::u16($t, $r + 6), self::u16($t, $r + 8), self::u16($t, $r + 10)];
            if (!in_array($id, [1, 2, 4, 5, 16, 17], true) || $base + $off + $len > strlen($t)) {
                continue;
            }
            $raw = substr($t, $base + $off, $len);
            $unicode = $platform === 0 || ($platform === 3 && in_array($enc, [0, 1, 10], true));
            $str = $unicode ? (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE') : ($platform === 1 ? (string) mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1') : '');
            $rank = ($platform === 3 && $lang === 0x0409 ? 3 : ($unicode ? 2 : 1));
            if ($str !== '' && mb_check_encoding($str, 'UTF-8') && ($best[$id][0] ?? 0) < $rank) {
                $best[$id] = [$rank, trim((string) preg_replace('/[\x00-\x1F]/', '', $str))];
            }
        }
        return array_map(static fn (array $x): string => $x[1], $best);
    }

    private static function u16(string $b, int $o): int
    {
        return strlen($b) >= $o + 2 ? (ord($b[$o]) << 8) | ord($b[$o + 1]) : 0;
    }

    private static function u32(string $b, int $o): int
    {
        return strlen($b) >= $o + 4 ? (int) unpack('N', substr($b, $o, 4))[1] : 0;
    }
}
