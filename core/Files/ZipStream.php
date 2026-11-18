<?php

declare(strict_types=1);

namespace Blue\Core\Files;

use RuntimeException;

/**
 * Streaming ZIP for large archives (backups): entries are copied file-to-file in chunks and never held in memory.
 * Writer stores entries uncompressed ("stored") — backup parts are already gzip-compressed or encrypted. No ZIP64:
 * an archive stays below 4 GB and 65 535 entries (checked). The reader only accepts stored/deflated entries and
 * checks every CRC; names are refused when they could escape the target directory.
 */
final class ZipStream
{
    /** @var resource|null */
    private $fh;
    private string $central = '';
    private int $count = 0;

    private function __construct(private readonly string $path)
    {
    }

    // ---- writing --------------------------------------------------------------------------------

    public static function create(string $path): self
    {
        $z = new self($path);
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Cannot create archive');
        }
        $z->fh = $fh;
        return $z;
    }

    public function addFile(string $name, string $sourcePath): void
    {
        $size = filesize($sourcePath);
        $crc = hash_file('crc32b', $sourcePath);
        if ($size === false || $crc === false) {
            throw new RuntimeException('Cannot read ' . basename($sourcePath));
        }
        $in = fopen($sourcePath, 'rb');
        if ($in === false) {
            throw new RuntimeException('Cannot read ' . basename($sourcePath));
        }
        $this->header($name, (int) hexdec($crc), $size);
        stream_copy_to_stream($in, $this->handle());
        fclose($in);
    }

    public function addString(string $name, string $content): void
    {
        $this->header($name, crc32($content), strlen($content));
        fwrite($this->handle(), $content);
    }

    public function finish(): void
    {
        $fh = $this->handle();
        $offset = (int) ftell($fh);
        fwrite($fh, $this->central);
        fwrite($fh, pack('VvvvvVVv', 0x06054b50, 0, 0, $this->count, $this->count, strlen($this->central), $offset, 0));
        fclose($fh);
        $this->fh = null;
    }

    private function header(string $name, int $crc, int $size): void
    {
        $fh = $this->handle();
        $offset = (int) ftell($fh);
        if ($offset + $size > 0xFFFFFFF0 || $this->count >= 65535) {
            throw new RuntimeException('Archive too large for this format (4 GB / 65 535 files)');
        }
        $date = (2026 - 1980) << 9 | 1 << 5 | 1;
        fwrite($fh, pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, 0, $date, $crc, $size, $size, strlen($name), 0) . $name);
        $this->central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, 0, $date, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $this->count++;
    }

    /** @return resource */
    private function handle()
    {
        if ($this->fh === null) {
            throw new RuntimeException('Archive already finished');
        }
        return $this->fh;
    }

    // ---- reading --------------------------------------------------------------------------------

    /** @var array<string, array{method: int, csize: int, usize: int, offset: int, crc: int}> */
    private array $entries = [];

    public static function open(string $path): self
    {
        $z = new self($path);
        $z->readDirectory();
        return $z;
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

    public function size(string $name): int
    {
        return $this->entries[$name]['usize'] ?? 0;
    }

    /** Small entries (manifest) as a string. */
    public function read(string $name, int $maxBytes = 50 * 1024 * 1024): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zs');
        if ($tmp === false) {
            throw new RuntimeException('No temp file');
        }
        try {
            if ($this->size($name) > $maxBytes) {
                throw new RuntimeException('Entry too large');
            }
            $this->extractTo($name, $tmp);
            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** Copies one entry to a file, checking the CRC. Returns the SHA-256 of the extracted bytes. */
    public function extractTo(string $name, string $target): string
    {
        $e = $this->entries[$name] ?? throw new RuntimeException('Missing entry ' . $name);
        $fh = fopen($this->path, 'rb');
        $out = fopen($target, 'wb');
        if ($fh === false || $out === false) {
            throw new RuntimeException('Cannot open archive');
        }
        try {
            fseek($fh, $e['offset']);
            $local = (string) fread($fh, 30);
            $h = unpack('Vsig/vver/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', $local);
            if ($h === false || (int) $h['sig'] !== 0x04034b50) {
                throw new RuntimeException('Broken entry ' . $name);
            }
            fseek($fh, $e['offset'] + 30 + (int) $h['nlen'] + (int) $h['elen']);
            $crc = hash_init('crc32b');
            $sha = hash_init('sha256');
            $left = $e['csize'];
            $inflate = $e['method'] === 8 ? inflate_init(ZLIB_ENCODING_RAW) : null;
            if ($e['method'] !== 0 && $inflate === null) {
                throw new RuntimeException('Unsupported compression in ' . $name);
            }
            while ($left > 0) {
                $chunk = (string) fread($fh, min(1 << 20, $left));
                if ($chunk === '') {
                    throw new RuntimeException('Truncated entry ' . $name);
                }
                $left -= strlen($chunk);
                if ($inflate !== null) {
                    $chunk = (string) inflate_add($inflate, $chunk, $left === 0 ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
                }
                hash_update($crc, $chunk);
                hash_update($sha, $chunk);
                fwrite($out, $chunk);
            }
            if ((int) hexdec(hash_final($crc)) !== $e['crc']) {
                throw new RuntimeException('CRC mismatch in ' . $name);
            }
            return hash_final($sha);
        } finally {
            fclose($fh);
            fclose($out);
        }
    }

    private function readDirectory(): void
    {
        $size = filesize($this->path);
        $fh = fopen($this->path, 'rb');
        if ($size === false || $fh === false || $size < 22) {
            throw new RuntimeException('Not an archive');
        }
        try {
            $tail = min($size, 65557);
            fseek($fh, $size - $tail);
            $buf = (string) fread($fh, $tail);
            $pos = strrpos($buf, "PK\x05\x06");
            if ($pos === false) {
                throw new RuntimeException('Not an archive');
            }
            $eocd = unpack('Vsig/vdisk/vcd/vthis/vtotal/Vsize/Voffset/vclen', substr($buf, $pos, 22));
            if ($eocd === false || (int) $eocd['total'] > 65535) {
                throw new RuntimeException('Not an archive');
            }
            fseek($fh, (int) $eocd['offset']);
            $cd = (string) fread($fh, (int) $eocd['size']);
            $p = 0;
            for ($i = 0; $i < (int) $eocd['total']; $i++) {
                $c = unpack('Vsig/vmade/vver/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vint/Vext/Voffset', substr($cd, $p, 46));
                if ($c === false || (int) $c['sig'] !== 0x02014b50) {
                    throw new RuntimeException('Broken archive directory');
                }
                $name = substr($cd, $p + 46, (int) $c['nlen']);
                $p += 46 + (int) $c['nlen'] + (int) $c['elen'] + (int) $c['clen'];
                if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
                    throw new RuntimeException('Unsafe name in archive');
                }
                $this->entries[$name] = ['method' => (int) $c['method'], 'csize' => (int) $c['csize'], 'usize' => (int) $c['usize'], 'offset' => (int) $c['offset'], 'crc' => (int) $c['crc']];
            }
        } finally {
            fclose($fh);
        }
    }
}
