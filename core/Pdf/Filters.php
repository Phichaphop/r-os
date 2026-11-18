<?php

declare(strict_types=1);

namespace Blue\Core\Pdf;

/**
 * Stream decoding (ISO 32000-1 §7.4): FlateDecode (+ PNG/TIFF predictors), LZWDecode, ASCIIHexDecode, ASCII85Decode,
 * RunLengthDecode. Output is capped so a decompression bomb stops early. Image codecs (DCT, JPX, JBIG2, CCITT) are
 * never decoded — those streams are copied as they are.
 */
final class Filters
{
    public const IMAGE_FILTERS = ['DCTDecode', 'JPXDecode', 'JBIG2Decode', 'CCITTFaxDecode', 'DCT', 'CCF'];

    /** @return list<array{0: string, 1: ?Dict}> filter name + its parameters */
    public static function chain(Dict $d, ?callable $resolve = null): array
    {
        $r = $resolve ?? static fn (mixed $v): mixed => $v;
        $f = $r($d->get('Filter'));
        $p = $r($d->get('DecodeParms') ?? $d->get('DP'));
        $names = $f instanceof Name ? [$f] : (is_array($f) ? $f : []);
        $params = is_array($p) ? $p : [$p];
        $out = [];
        foreach ($names as $i => $n) {
            $n = $r($n);
            if ($n instanceof Name) {
                $pp = $r($params[$i] ?? null);
                $out[] = [$n->name, $pp instanceof Dict ? $pp : null];
            }
        }
        return $out;
    }

    /** Decodes every non-image filter; throws when an image filter or an unknown filter is in the chain. */
    public static function decode(Stream $s, int $max, ?callable $resolve = null): string
    {
        $data = $s->data;
        foreach (self::chain($s->dict, $resolve) as [$name, $parms]) {
            $data = match ($name) {
                'FlateDecode', 'Fl' => self::predict(self::inflate($data, $max), $parms),
                'LZWDecode', 'LZW' => self::predict(self::lzw($data, $max, (int) ($parms?->get('EarlyChange') ?? 1)), $parms),
                'ASCIIHexDecode', 'AHx' => self::asciiHex($data),
                'ASCII85Decode', 'A85' => self::ascii85($data),
                'RunLengthDecode', 'RL' => self::runLength($data, $max),
                default => throw new PdfError('pdf.error.filter', ['filter' => $name]),
            };
            if (strlen($data) > $max) {
                throw new PdfError('pdf.error.too_big');
            }
        }
        return $data;
    }

    public static function inflate(string $data, int $max): string
    {
        if ($data === '') {
            return '';
        }
        foreach ([ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_RAW] as $enc) {
            $ctx = @inflate_init($enc);
            if ($ctx === false) {
                continue;
            }
            $out = '';
            $ok = true;
            foreach (str_split($data, 65536) as $chunk) {
                $part = @inflate_add($ctx, $chunk, ZLIB_SYNC_FLUSH);
                if ($part === false) {
                    $ok = false;
                    break;
                }
                $out .= $part;
                if (strlen($out) > $max) {
                    throw new PdfError('pdf.error.too_big');
                }
                if (inflate_get_status($ctx) === ZLIB_STREAM_END) {
                    break;
                }
            }
            if ($ok && ($out !== '' || $enc === ZLIB_ENCODING_RAW)) {
                return $out; // a truncated tail still yields what could be read (common in damaged files)
            }
        }
        throw new PdfError('pdf.error.corrupt', [], 'bad flate data');
    }

    public static function deflate(string $data): string
    {
        return (string) gzcompress($data, 9);
    }

    private static function predict(string $data, ?Dict $p): string
    {
        $pred = (int) ($p?->get('Predictor') ?? 1);
        if ($pred < 2) {
            return $data;
        }
        $colors = max(1, (int) ($p?->get('Colors') ?? 1));
        $bpc = max(1, (int) ($p?->get('BitsPerComponent') ?? 8));
        $cols = max(1, (int) ($p?->get('Columns') ?? 1));
        $bpp = max(1, intdiv($colors * $bpc + 7, 8));
        $row = intdiv($colors * $bpc * $cols + 7, 8);
        if ($pred === 2) { // TIFF (8-bit only)
            if ($bpc !== 8) {
                throw new PdfError('pdf.error.filter', ['filter' => 'TIFF predictor']);
            }
            $out = '';
            foreach (str_split($data, $row) as $line) {
                $l = array_values(unpack('C*', $line) ?: []);
                for ($i = $bpp, $n = count($l); $i < $n; $i++) {
                    $l[$i] = ($l[$i] + $l[$i - $bpp]) & 0xFF;
                }
                $out .= pack('C*', ...$l);
            }
            return $out;
        }
        $out = '';
        $prev = array_fill(0, $row, 0);
        $len = strlen($data);
        for ($pos = 0; $pos < $len; $pos += $row + 1) {
            $type = ord($data[$pos]);
            $line = array_values(unpack('C*', str_pad(substr($data, $pos + 1, $row), $row, "\0")) ?: []);
            for ($i = 0; $i < $row; $i++) {
                $a = $i >= $bpp ? $line[$i - $bpp] : 0;
                $b = $prev[$i];
                $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                $line[$i] = match ($type) {
                    0 => $line[$i],
                    1 => ($line[$i] + $a) & 0xFF,
                    2 => ($line[$i] + $b) & 0xFF,
                    3 => ($line[$i] + intdiv($a + $b, 2)) & 0xFF,
                    4 => ($line[$i] + self::paeth($a, $b, $c)) & 0xFF,
                    default => throw new PdfError('pdf.error.corrupt', [], 'bad PNG predictor row'),
                };
            }
            $out .= pack('C*', ...$line);
            $prev = $line;
        }
        return $out;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }

    private static function asciiHex(string $data): string
    {
        $end = strpos($data, '>');
        $h = (string) preg_replace('/[^0-9A-Fa-f]/', '', $end === false ? $data : substr($data, 0, $end));
        return (string) hex2bin(strlen($h) % 2 ? $h . '0' : $h);
    }

    private static function ascii85(string $data): string
    {
        $data = (string) preg_replace('/\s+/', '', $data);
        if (str_starts_with($data, '<~')) {
            $data = substr($data, 2);
        }
        $end = strpos($data, '~>');
        if ($end !== false) {
            $data = substr($data, 0, $end);
        }
        $out = '';
        $tuple = [];
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $c = $data[$i];
            if ($c === 'z' && $tuple === []) {
                $out .= "\0\0\0\0";
                continue;
            }
            $v = ord($c) - 33;
            if ($v < 0 || $v > 84) {
                throw new PdfError('pdf.error.corrupt', [], 'bad ASCII85');
            }
            $tuple[] = $v;
            if (count($tuple) === 5) {
                $n = (((($tuple[0] * 85 + $tuple[1]) * 85 + $tuple[2]) * 85 + $tuple[3]) * 85) + $tuple[4];
                $out .= pack('N', $n & 0xFFFFFFFF);
                $tuple = [];
            }
        }
        if ($tuple !== []) {
            $k = count($tuple);
            $tuple = array_pad($tuple, 5, 84);
            $n = (((($tuple[0] * 85 + $tuple[1]) * 85 + $tuple[2]) * 85 + $tuple[3]) * 85) + $tuple[4];
            $out .= substr(pack('N', $n & 0xFFFFFFFF), 0, $k - 1);
        }
        return $out;
    }

    private static function runLength(string $data, int $max): string
    {
        $out = '';
        $i = 0;
        $len = strlen($data);
        while ($i < $len) {
            $n = ord($data[$i++]);
            if ($n === 128) {
                break;
            }
            if ($n < 128) {
                $out .= substr($data, $i, $n + 1);
                $i += $n + 1;
            } else {
                $out .= str_repeat($data[$i] ?? "\0", 257 - $n);
                $i++;
            }
            if (strlen($out) > $max) {
                throw new PdfError('pdf.error.too_big');
            }
        }
        return $out;
    }

    private static function lzw(string $data, int $max, int $early): string
    {
        $dict = [];
        $reset = static function () use (&$dict): void {
            $dict = [];
            for ($i = 0; $i < 256; $i++) {
                $dict[$i] = chr($i);
            }
        };
        $reset();
        $next = 258;
        $bits = 9;
        $buf = 0;
        $nbuf = 0;
        $prev = null;
        $out = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $buf = ($buf << 8) | ord($data[$i]);
            $nbuf += 8;
            while ($nbuf >= $bits) {
                $code = ($buf >> ($nbuf - $bits)) & ((1 << $bits) - 1);
                $nbuf -= $bits;
                $buf &= (1 << $nbuf) - 1;
                if ($code === 256) {
                    $reset();
                    $next = 258;
                    $bits = 9;
                    $prev = null;
                    continue;
                }
                if ($code === 257) {
                    return $out;
                }
                if ($prev === null) {
                    $entry = $dict[$code] ?? throw new PdfError('pdf.error.corrupt', [], 'bad LZW');
                } else {
                    $entry = $dict[$code] ?? ($code === $next ? $prev . $prev[0] : throw new PdfError('pdf.error.corrupt', [], 'bad LZW'));
                    $dict[$next++] = $prev . $entry[0];
                }
                $out .= $entry;
                if (strlen($out) > $max) {
                    throw new PdfError('pdf.error.too_big');
                }
                $prev = $entry;
                if ($next + $early >= (1 << $bits) && $bits < 12) {
                    $bits++;
                }
            }
        }
        return $out;
    }
}
