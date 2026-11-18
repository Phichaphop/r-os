<?php

declare(strict_types=1);

namespace Blue\Core\Files;

use Blue\Core\Error\BusinessException;

/**
 * Minimal ZIP reader/writer for Office files (XLSX) — written here because ext-zip is not enabled on every XAMPP /
 * shared host, while zlib (gzdeflate/gzinflate) is. Supports "stored" and "deflate" entries only; no ZIP64,
 * no encryption. The reader refuses archive bombs: too many entries, too large in total, or an extreme ratio.
 */
final class Zip
{
    public const MAX_ENTRIES = 2000;
    public const MAX_TOTAL_BYTES = 200 * 1024 * 1024;
    public const MAX_RATIO = 200;

    /** @var array<string, array{method: int, csize: int, usize: int, offset: int, crc: int}> */
    private array $entries = [];

    private function __construct(private readonly string $data)
    {
    }

    // ---- writing --------------------------------------------------------------------------------

    /** @param array<string, string> $files name => contents (in this order) */
    public static function build(array $files): string
    {
        $out = '';
        $central = '';
        $time = 0x0000; // 00:00:00
        $date = (2026 - 1980) << 9 | 1 << 5 | 1; // fixed date → identical input gives identical bytes
        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $deflated = function_exists('gzdeflate') ? gzdeflate($content, 6) : false;
            $method = $deflated !== false && strlen($deflated) < strlen($content) ? 8 : 0;
            $body = $method === 8 ? (string) $deflated : $content;
            $offset = strlen($out);
            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $time, $date, $crc, strlen($body), strlen($content), strlen($name), 0) . $name . $body;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $time, $date, $crc, strlen($body), strlen($content), strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        }
        return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($out), 0);
    }

    // ---- reading --------------------------------------------------------------------------------

    public static function open(string $data): self
    {
        $zip = new self($data);
        $zip->readDirectory();
        return $zip;
    }

    public static function looksLikeZip(string $data): bool
    {
        return strncmp($data, "PK\x03\x04", 4) === 0;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    public function get(string $name, int $maxBytes = 50 * 1024 * 1024): ?string
    {
        $e = $this->entries[$name] ?? null;
        if ($e === null) {
            return null;
        }
        if ($e['usize'] > $maxBytes) {
            throw new BusinessException('file.error.archive_too_large', [], 422);
        }
        $h = unpack('Vsig/vver/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', substr($this->data, $e['offset'], 30));
        if ($h === false || $h['sig'] !== 0x04034b50) {
            throw new BusinessException('file.error.archive_corrupt', [], 422);
        }
        $raw = substr($this->data, $e['offset'] + 30 + $h['nlen'] + $h['elen'], $e['csize']);
        $content = match ($e['method']) {
            0 => $raw,
            8 => function_exists('gzinflate') ? @gzinflate($raw, $maxBytes) : false,
            default => false,
        };
        if (!is_string($content) || strlen($content) !== $e['usize'] || (crc32($content) & 0xFFFFFFFF) !== $e['crc']) {
            throw new BusinessException('file.error.archive_corrupt', [], 422);
        }
        return $content;
    }

    private function readDirectory(): void
    {
        $len = strlen($this->data);
        $eocd = strrpos(substr($this->data, max(0, $len - 65557)), "PK\x05\x06");
        if ($eocd === false) {
            throw new BusinessException('file.error.archive_corrupt', [], 422);
        }
        $eocd += max(0, $len - 65557);
        $d = unpack('Vsig/vdisk/vcdisk/vdisk_entries/ventries/Vcd_size/Vcd_offset/vclen', substr($this->data, $eocd, 22));
        if ($d === false) {
            throw new BusinessException('file.error.archive_corrupt', [], 422);
        }
        $entries = (int) $d['entries'];
        if ($entries > self::MAX_ENTRIES || (int) $d['cd_offset'] + (int) $d['cd_size'] > $len) {
            throw new BusinessException($entries > self::MAX_ENTRIES ? 'file.error.archive_too_large' : 'file.error.archive_corrupt', [], 422);
        }
        $pos = $d['cd_offset'];
        $total = 0;
        for ($i = 0; $i < $entries; $i++) {
            $c = unpack('Vsig/vmade/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vint/Vext/Voffset', substr($this->data, $pos, 46));
            if ($c === false || $c['sig'] !== 0x02014b50 || ($c['flag'] & 0x1) === 1) {
                throw new BusinessException('file.error.archive_corrupt', [], 422); // bad header or encrypted entry
            }
            $name = substr($this->data, $pos + 46, $c['nlen']);
            $pos += 46 + $c['nlen'] + $c['elen'] + $c['clen'];
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                throw new BusinessException('file.error.archive_corrupt', [], 422); // path traversal inside the archive
            }
            $total += $c['usize'];
            if ($total > self::MAX_TOTAL_BYTES || ($c['csize'] > 0 && $c['usize'] / $c['csize'] > self::MAX_RATIO && $c['usize'] > 1024 * 1024)) {
                throw new BusinessException('file.error.archive_too_large', [], 422);
            }
            $this->entries[$name] = ['method' => $c['method'], 'csize' => $c['csize'], 'usize' => $c['usize'], 'offset' => $c['offset'], 'crc' => $c['crc']];
        }
    }
}
