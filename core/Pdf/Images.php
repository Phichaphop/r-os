<?php

declare(strict_types=1);

namespace Blue\Core\Pdf;

/**
 * Picture → PDF image XObject (Convert to PDF, S01 item 6). JPEG is embedded as is (DCTDecode — no quality loss);
 * 8-bit PNG without transparency and palette PNGs are embedded from their compressed data; anything else (transparency,
 * interlaced/16-bit PNG, GIF, WebP) is decoded with PHP GD when available — otherwise refused with a clear message.
 */
final class Images
{
    public const MAX_PIXELS = 40_000_000;

    /** @return array{0: Ref, 1: int, 2: int} XObject, width px, height px */
    public static function xobject(Writer $w, string $bytes): array
    {
        if (str_starts_with($bytes, "\xFF\xD8")) {
            return self::jpeg($w, $bytes);
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            $direct = self::pngDirect($w, $bytes);
            if ($direct !== null) {
                return $direct;
            }
        }
        return self::viaGd($w, $bytes);
    }

    /** @return array{0: Ref, 1: int, 2: int} */
    private static function jpeg(Writer $w, string $b): array
    {
        $pos = 2;
        $len = strlen($b);
        $adobe = false;
        while ($pos + 4 < $len) {
            if ($b[$pos] !== "\xFF") {
                $pos++;
                continue;
            }
            $marker = ord($b[$pos + 1]);
            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $pos += 2;
                continue;
            }
            $segLen = (int) (unpack('n', substr($b, $pos + 2, 2))[1] ?? 0);
            if ($marker === 0xEE && substr($b, $pos + 4, 5) === 'Adobe') {
                $adobe = true;
            }
            if ($marker >= 0xC0 && $marker <= 0xCF && !in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                $bpc = ord($b[$pos + 4]);
                $h = (int) (unpack('n', substr($b, $pos + 5, 2))[1] ?? 0);
                $wd = (int) (unpack('n', substr($b, $pos + 7, 2))[1] ?? 0);
                $comp = ord($b[$pos + 9]);
                if ($wd < 1 || $h < 1 || $wd * $h > self::MAX_PIXELS) {
                    throw new PdfError('pdf.error.image_size');
                }
                $cs = match ($comp) {
                    1 => 'DeviceGray',
                    4 => 'DeviceCMYK',
                    default => 'DeviceRGB',
                };
                $d = new Dict(['Type' => new Name('XObject'), 'Subtype' => new Name('Image'), 'Width' => $wd, 'Height' => $h,
                    'ColorSpace' => new Name($cs), 'BitsPerComponent' => $bpc === 12 ? 8 : $bpc, 'Filter' => new Name('DCTDecode')]);
                if ($comp === 4 && $adobe) {
                    $d->set('Decode', [1, 0, 1, 0, 1, 0, 1, 0]);
                }
                return [$w->add(new Stream($d, $b)), $wd, $h];
            }
            $pos += 2 + $segLen;
        }
        throw new PdfError('pdf.error.image');
    }

    /** @return array{0: Ref, 1: int, 2: int}|null null = needs GD */
    private static function pngDirect(Writer $w, string $b): ?array
    {
        $pos = 8;
        $idat = '';
        $ihdr = null;
        $plte = null;
        $len = strlen($b);
        while ($pos + 8 <= $len) {
            $l = (int) (unpack('N', substr($b, $pos, 4))[1] ?? 0);
            $type = substr($b, $pos + 4, 4);
            $data = substr($b, $pos + 8, $l);
            if ($type === 'IHDR') {
                $ihdr = unpack('Nw/Nh/Cbits/Ccolor/Ccomp/Cfilter/Cinterlace', $data) ?: null;
            } elseif ($type === 'PLTE') {
                $plte = $data;
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'tRNS') {
                return null; // transparency → GD
            } elseif ($type === 'IEND') {
                break;
            }
            $pos += 12 + $l;
        }
        if ($ihdr === null) {
            throw new PdfError('pdf.error.image');
        }
        $wd = (int) $ihdr['w'];
        $h = (int) $ihdr['h'];
        if ($wd < 1 || $h < 1 || $wd * $h > self::MAX_PIXELS) {
            throw new PdfError('pdf.error.image_size');
        }
        if ($ihdr['interlace'] !== 0 || in_array($ihdr['color'], [4, 6], true) || ($ihdr['bits'] === 16)) {
            return null;
        }
        $colors = match ((int) $ihdr['color']) {
            0, 3 => 1,
            2 => 3,
            default => 0,
        };
        if ($colors === 0) {
            return null;
        }
        $cs = match ((int) $ihdr['color']) {
            0 => new Name('DeviceGray'),
            2 => new Name('DeviceRGB'),
            default => [new Name('Indexed'), new Name('DeviceRGB'), intdiv(strlen((string) $plte), 3) - 1, new Str((string) $plte, true)],
        };
        $d = new Dict(['Type' => new Name('XObject'), 'Subtype' => new Name('Image'), 'Width' => $wd, 'Height' => $h, 'ColorSpace' => $cs,
            'BitsPerComponent' => (int) $ihdr['bits'], 'Filter' => new Name('FlateDecode'),
            'DecodeParms' => new Dict(['Predictor' => 15, 'Colors' => $colors, 'BitsPerComponent' => (int) $ihdr['bits'], 'Columns' => $wd])]);
        return [$w->add(new Stream($d, $idat)), $wd, $h];
    }

    /** @return array{0: Ref, 1: int, 2: int} */
    private static function viaGd(Writer $w, string $b): array
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new PdfError('pdf.error.image_needs_gd');
        }
        $info = @getimagesizefromstring($b);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new PdfError('pdf.error.image');
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS / 4) { // decoded pixel by pixel in PHP — keep it bounded
            throw new PdfError('pdf.error.image_size');
        }
        $im = @imagecreatefromstring($b);
        if ($im === false) {
            throw new PdfError('pdf.error.image');
        }
        $wd = imagesx($im);
        $h = imagesy($im);
        $rgb = '';
        $alpha = '';
        $hasAlpha = false;
        $true = imageistruecolor($im);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $wd; $x++) {
                $c = imagecolorat($im, $x, $y);
                if ($true) {
                    $a = ($c >> 24) & 0x7F;
                    $rgb .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF);
                } else {
                    $col = imagecolorsforindex($im, $c);
                    $a = $col['alpha'];
                    $rgb .= chr($col['red']) . chr($col['green']) . chr($col['blue']);
                }
                $alpha .= chr(255 - (int) round($a * 255 / 127));
                $hasAlpha = $hasAlpha || $a > 0;
            }
        }
        imagedestroy($im);
        $d = new Dict(['Type' => new Name('XObject'), 'Subtype' => new Name('Image'), 'Width' => $wd, 'Height' => $h,
            'ColorSpace' => new Name('DeviceRGB'), 'BitsPerComponent' => 8, 'Filter' => new Name('FlateDecode')]);
        if ($hasAlpha) {
            $mask = new Dict(['Type' => new Name('XObject'), 'Subtype' => new Name('Image'), 'Width' => $wd, 'Height' => $h,
                'ColorSpace' => new Name('DeviceGray'), 'BitsPerComponent' => 8, 'Filter' => new Name('FlateDecode')]);
            $d->set('SMask', $w->add(new Stream($mask, Filters::deflate($alpha))));
        }
        return [$w->add(new Stream($d, Filters::deflate($rgb))), $wd, $h];
    }
}
