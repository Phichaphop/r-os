<?php

declare(strict_types=1);

namespace Blue\Modules\Complaints;

use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;

/**
 * Evidence pictures for anonymous complaints. Every picture is decoded and drawn again (GD), so nothing travels with it
 * but the pixels — no camera model, GPS position, time, author or editing-software fields (EXIF / XMP / PNG text). The
 * original name is not kept. Documents (PDF, Office) are refused because their metadata (author, organisation, device)
 * cannot be removed reliably here; the form asks for a screenshot instead. Without GD no picture is accepted.
 */
final class Evidence
{
    public const MAX_IN = 8 * 1024 * 1024;
    public const MAX_SIDE = 2000;
    private const MAX_PIXELS = 40_000_000;

    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagejpeg') && function_exists('imagepng');
    }

    /**
     * @param list<UploadedFile> $files
     * @return list<array{bytes: string, mime: string, ext: string}>
     * @throws ValidationException ['files' => key]
     */
    public static function scrubAll(array $files, int $max): array
    {
        if ($files === []) {
            return [];
        }
        if (count($files) > $max) {
            throw new ValidationException(['files' => 'complaints.error.too_many_files']);
        }
        if (!self::available()) {
            throw new ValidationException(['files' => 'complaints.error.no_gd']);
        }
        $out = [];
        foreach ($files as $f) {
            if (!$f->ok()) {
                throw new ValidationException(['files' => $f->errorKey()]);
            }
            if ($f->size > self::MAX_IN) {
                throw new ValidationException(['files' => 'complaints.error.file_size']);
            }
            $p = self::scrub($f->bytes());
            if ($p === null) {
                throw new ValidationException(['files' => 'complaints.error.file_type']);
            }
            $out[] = $p;
        }
        return $out;
    }

    /** @return array{bytes: string, mime: string, ext: string}|null null = not a PNG/JPEG/WebP picture */
    public static function scrub(string $bytes): ?array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            return null;
        }
        [$w, $h, $type] = [(int) $info[0], (int) $info[1], (int) $info[2]];
        if ($w < 1 || $h < 1 || $w * $h > self::MAX_PIXELS) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
            $angle = match ((int) ($exif['Orientation'] ?? 1)) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };
            if ($angle !== 0) {
                $rot = imagerotate($src, $angle, 0);
                if ($rot !== false) {
                    imagedestroy($src);
                    $src = $rot;
                }
            }
        }
        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1.0, self::MAX_SIDE / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($dst === false) {
            imagedestroy($src);
            return null;
        }
        $png = $type === IMAGETYPE_PNG;
        if ($png) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, (int) imagecolorallocatealpha($dst, 0, 0, 0, 127));
        } else {
            imagefill($dst, 0, 0, (int) imagecolorallocate($dst, 255, 255, 255));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        ob_start();
        $png ? imagepng($dst, null, 6) : imagejpeg($dst, null, 85);
        $out = (string) ob_get_clean();
        imagedestroy($dst);
        return $out === '' ? null : ['bytes' => $out, 'mime' => $png ? 'image/png' : 'image/jpeg', 'ext' => $png ? 'png' : 'jpg'];
    }
}
