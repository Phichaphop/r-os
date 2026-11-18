<?php

declare(strict_types=1);

namespace Blue\Core\Files;

/**
 * Shrinks an uploaded PNG / JPEG / WebP so it fits inside a box and under a byte budget (6.13 brand files).
 * Uses GD when the host has it; the browser already shrinks the picture before upload (app.js, data-image-max),
 * so a host without GD still works for ordinary pictures. PNG/WebP that already fit are kept byte for byte; JPEG is
 * always re-encoded so EXIF data (location, camera) is dropped. Pictures are never enlarged; transparency is kept; JPEG orientation from EXIF is applied first.
 */
final class ImageResizer
{
    /** Refuse to decode beyond this many pixels (decompression-bomb guard; ~5 bytes per pixel in GD). */
    public const MAX_PIXELS = 40_000_000;

    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagecopyresampled') && function_exists('imagepng');
    }

    /**
     * @return array{bytes: string, ext: string, width: int, height: int, resized: bool}|null
     *         null when the bytes are not a PNG/JPEG/WebP picture (e.g. .ico) — the caller keeps the original
     * @throws \RuntimeException 'image.error.too_large' when the picture cannot be decoded safely,
     *                           'image.error.no_gd' when it must be shrunk but the host has no GD
     */
    public static function fit(string $bytes, int $maxSide, int $maxBytes): ?array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            return null;
        }
        [$w, $h, $type] = [(int) $info[0], (int) $info[1], (int) $info[2]];
        $ext = match ($type) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_WEBP => 'webp',
            default => 'png',
        };
        $needs = $w > $maxSide || $h > $maxSide || strlen($bytes) > $maxBytes;
        if (!self::available()) {
            if ($needs) {
                throw new \RuntimeException('image.error.no_gd');
            }
            return ['bytes' => $bytes, 'ext' => $ext, 'width' => $w, 'height' => $h, 'resized' => false];
        }
        if (!$needs && $type !== IMAGETYPE_JPEG) {
            return ['bytes' => $bytes, 'ext' => $ext, 'width' => $w, 'height' => $h, 'resized' => false]; // already fits — keep it byte for byte
        }
        if ($w < 1 || $h < 1 || $w * $h > self::MAX_PIXELS || !self::ensureMemory($w * $h)) {
            throw new \RuntimeException('image.error.too_large');
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }
        if ($type === IMAGETYPE_JPEG) {
            $src = self::orient($src, $bytes);
            [$w, $h] = [imagesx($src), imagesy($src)];
        }
        $scale = min(1.0, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($dst === false) {
            throw new \RuntimeException('image.error.too_large');
        }
        if ($type !== IMAGETYPE_JPEG) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, (int) imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        $out = '';
        foreach ([88, 80, 70, 60] as $quality) { // lower the quality only if still over budget (JPEG/WebP)
            $out = self::encode($dst, $type, $quality);
            if (strlen($out) <= $maxBytes || $type === IMAGETYPE_PNG) {
                break;
            }
        }
        if ($type === IMAGETYPE_WEBP && !function_exists('imagewebp')) {
            $ext = 'png';
        }
        imagedestroy($dst);
        return ['bytes' => $out, 'ext' => $ext, 'width' => $nw, 'height' => $nh, 'resized' => $nw !== (int) $info[0] || $nh !== (int) $info[1]];
    }

    private static function encode(\GdImage $img, int $type, int $quality): string
    {
        ob_start();
        if ($type === IMAGETYPE_JPEG) {
            imageinterlace($img, true);
            imagejpeg($img, null, $quality);
        } elseif ($type === IMAGETYPE_WEBP && function_exists('imagewebp')) {
            imagewebp($img, null, $quality);
        } else {
            imagepng($img, null, 9);
        }
        return (string) ob_get_clean();
    }

    /** Apply the EXIF orientation of a phone photo so the logo is not sideways. */
    private static function orient(\GdImage $img, string $bytes): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $rotated = match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
        if ($rotated instanceof \GdImage && $rotated !== $img) {
            imagedestroy($img);
            return $rotated;
        }
        return $img;
    }

    /** GD needs about 5 bytes per pixel for the source plus the (small) target; raise the limit once if needed. */
    private static function ensureMemory(int $pixels): bool
    {
        $need = $pixels * 5 + 32 * 1024 * 1024;
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit < 0 || memory_get_usage() + $need <= $limit) {
            return true;
        }
        $want = (int) ceil((memory_get_usage() + $need) / (1024 * 1024)) + 16;
        return @ini_set('memory_limit', $want . 'M') !== false && memory_get_usage() + $need <= self::bytes((string) ini_get('memory_limit'));
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
