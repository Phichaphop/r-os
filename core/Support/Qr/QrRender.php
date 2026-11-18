<?php

declare(strict_types=1);

namespace Blue\Core\Support\Qr;

/**
 * Draws a QR matrix as SVG or PNG with the standard 4-module quiet zone.
 *
 * Style (0.38.0, all optional — the defaults draw the classic black-on-white square symbol):
 *  - fg / bg / eye: colours (#RRGGBB) of the dark modules, the light background and the three finder "eyes";
 *    callers check that dark is darker than light with enough contrast (QrStyle::validate) — scanners need it;
 *  - module: square | rounded | dot;   eye_style: square | rounded | circle;
 *  - logo: share of the symbol width the centre logo plate may cover (LOGO_SIZES; callers raise the error-correction
 *    level to H when a logo is used). The plate never reaches the finder patterns or the timing lines.
 * The logo goes into the SVG with both href and xlink:href (older viewers and office programs read only xlink —
 * 0.38.0 fix for "the logo disappears after download") and into the PNG through GD; a logo GD cannot read is an
 * error (logoReadable) instead of being dropped silently. Without GD a plain black/white PNG is written with zlib.
 */
final class QrRender
{
    public const QUIET = 4;
    public const DEFAULT_BG = '#FFFFFF';
    /** Default share of the width covered by the logo plate (the size before 0.38.0). */
    public const LOGO_MAX = 0.22;
    /** Logo sizes a person may choose: s = the old size, m = ×1.5, l = ×1.75, xl = ×2 (200% — scan-test before printing). */
    public const LOGO_SIZES = ['s' => 0.22, 'm' => 0.30, 'l' => 0.36, 'xl' => 0.44];
    public const MODULES = ['square', 'rounded', 'dot'];
    public const EYES = ['square', 'rounded', 'circle'];

    /**
     * @param list<list<bool>> $m
     * @param array<string, mixed> $style
     */
    public static function svg(array $m, int $scale = 8, ?string $logoDataUri = null, string $title = '', array $style = []): string
    {
        $s = self::style($style);
        $n = count($m);
        $vb = $n + 2 * self::QUIET;
        $w = $vb * $scale;
        $q = self::QUIET;
        [$lx, $plate] = $logoDataUri !== null ? self::logoBox($n, $s['logo']) : [0.0, 0.0];
        $inPlate = static fn (int $x, int $y): bool => $plate > 0 && $x >= $lx && $x < $lx + $plate && $y >= $lx && $y < $lx + $plate;
        $body = '';
        if ($s['module'] === 'square') {
            $path = '';
            foreach ($m as $y => $row) {
                $run = 0;
                foreach ($row as $x => $dark) {
                    $on = $dark && !self::isEye($x, $y, $n) && !$inPlate($x, $y);
                    if ($on) {
                        $run++;
                    }
                    if ((!$on || $x === $n - 1) && $run > 0) {
                        $end = $on ? $x + 1 : $x;
                        $path .= 'M' . ($end - $run + $q) . ',' . ($y + $q) . 'h' . $run . 'v1h-' . $run . 'z';
                        $run = 0;
                    }
                }
            }
            $body .= '<path fill="' . $s['fg'] . '" d="' . $path . '"/>';
        } else {
            $parts = '';
            foreach ($m as $y => $row) {
                foreach ($row as $x => $dark) {
                    if (!$dark || self::isEye($x, $y, $n) || $inPlate($x, $y)) {
                        continue;
                    }
                    $parts .= $s['module'] === 'dot'
                        ? '<circle cx="' . self::f($x + $q + 0.5) . '" cy="' . self::f($y + $q + 0.5) . '" r="0.45"/>'
                        : '<rect x="' . self::f($x + $q + 0.04) . '" y="' . self::f($y + $q + 0.04) . '" width="0.92" height="0.92" rx="0.32"/>';
                }
            }
            $body .= '<g fill="' . $s['fg'] . '">' . $parts . '</g>';
        }
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$ex, $ey]) {
            $body .= self::svgEye($ex + $q, $ey + $q, $s['eye'], $s['eye_style']);
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 ' . $vb . ' ' . $vb . '" width="' . $w . '" height="' . $w . '"'
            . ($s['module'] === 'square' && $s['eye_style'] === 'square' ? ' shape-rendering="crispEdges"' : '') . ' role="img"'
            . ($title !== '' ? ' aria-label="' . htmlspecialchars($title, ENT_QUOTES | ENT_XML1) . '"' : '') . '>'
            . '<rect width="' . $vb . '" height="' . $vb . '" fill="' . $s['bg'] . '"/>' . $body;
        if ($logoDataUri !== null) {
            $inner = $plate * 0.84;
            $href = htmlspecialchars($logoDataUri, ENT_QUOTES | ENT_XML1);
            $svg .= '<rect x="' . self::f($lx + $q) . '" y="' . self::f($lx + $q) . '" width="' . self::f($plate) . '" height="' . self::f($plate) . '" rx="' . self::f($plate * 0.12) . '" fill="' . $s['bg'] . '"/>'
                . '<image x="' . self::f($lx + $q + ($plate - $inner) / 2) . '" y="' . self::f($lx + $q + ($plate - $inner) / 2) . '" width="' . self::f($inner) . '" height="' . self::f($inner) . '"'
                . ' preserveAspectRatio="xMidYMid meet" href="' . $href . '" xlink:href="' . $href . '"/>';
        }
        return $svg . '</svg>';
    }

    /**
     * @param list<list<bool>> $m
     * @param array<string, mixed> $style
     */
    public static function png(array $m, int $scale = 10, ?string $logoBytes = null, array $style = []): string
    {
        $s = self::style($style);
        $n = count($m);
        if (!function_exists('imagecreatetruecolor')) {
            return self::plainPng($m, $scale);
        }
        // round shapes are drawn at twice the size and scaled down — GD has no anti-aliasing for filled shapes
        $k = $s['module'] === 'square' && $s['eye_style'] === 'square' ? 1 : 2;
        $u = $scale * $k;
        $w = ($n + 2 * self::QUIET) * $u;
        $img = imagecreatetruecolor($w, $w);
        $bg = self::color($img, $s['bg']);
        $fg = self::color($img, $s['fg']);
        $eye = self::color($img, $s['eye']);
        imagefill($img, 0, 0, $bg);
        [$lx, $plate] = $logoBytes !== null ? self::logoBox($n, $s['logo']) : [0.0, 0.0];
        foreach ($m as $y => $row) {
            foreach ($row as $x => $dark) {
                if (!$dark || self::isEye($x, $y, $n) || ($plate > 0 && $x >= $lx && $x < $lx + $plate && $y >= $lx && $y < $lx + $plate)) {
                    continue;
                }
                $x0 = ($x + self::QUIET) * $u;
                $y0 = ($y + self::QUIET) * $u;
                if ($s['module'] === 'dot') {
                    imagefilledellipse($img, $x0 + intdiv($u, 2), $y0 + intdiv($u, 2), (int) round($u * 0.9), (int) round($u * 0.9), $fg);
                } elseif ($s['module'] === 'rounded') {
                    self::roundRect($img, $x0 + 1, $y0 + 1, $x0 + $u - 2, $y0 + $u - 2, (int) round($u * 0.32), $fg);
                } else {
                    imagefilledrectangle($img, $x0, $y0, $x0 + $u - 1, $y0 + $u - 1, $fg);
                }
            }
        }
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$ex, $ey]) {
            self::pngEye($img, ($ex + self::QUIET) * $u, ($ey + self::QUIET) * $u, $u, $eye, $bg, $s['eye_style']);
        }
        if ($logoBytes !== null) {
            $logo = @imagecreatefromstring($logoBytes);
            if ($logo === false) {
                imagedestroy($img);
                throw new \InvalidArgumentException('logo'); // callers check logoReadable() first — never drop the logo silently
            }
            $x0 = (int) round(($lx + self::QUIET) * $u);
            $size = (int) round($plate * $u);
            self::roundRect($img, $x0, $x0, $x0 + $size - 1, $x0 + $size - 1, (int) round($size * 0.12), $bg);
            $inner = (int) round($size * 0.84);
            $lw = imagesx($logo);
            $lh = imagesy($logo);
            $ratio = min($inner / max(1, $lw), $inner / max(1, $lh));
            $dw = max(1, (int) round($lw * $ratio));
            $dh = max(1, (int) round($lh * $ratio));
            imagealphablending($img, true);
            imagecopyresampled($img, $logo, $x0 + intdiv($size - $dw, 2), $x0 + intdiv($size - $dh, 2), 0, 0, $dw, $dh, $lw, $lh);
            imagedestroy($logo);
        }
        if ($k > 1) {
            $out = imagecreatetruecolor(intdiv($w, $k), intdiv($w, $k));
            imagecopyresampled($out, $img, 0, 0, 0, 0, intdiv($w, $k), intdiv($w, $k), $w, $w);
            imagedestroy($img);
            $img = $out;
        }
        ob_start();
        imagepng($img, null, 9);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    /** Whether a PNG with this logo can be drawn here (GD present and able to read the format, e.g. WebP support). */
    public static function logoReadable(string $bytes): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return false;
        }
        imagedestroy($img);
        return true;
    }

    /**
     * Logo plate position and size in modules: centred, an odd number of modules, at most $share of the width and
     * always clear of the finder patterns, their separators and the timing lines (8 modules from each edge + 1).
     *
     * @return array{0: float, 1: float} top-left offset inside the symbol, plate size
     */
    public static function logoBox(int $n, float $share = self::LOGO_MAX): array
    {
        $plate = min(floor($n * $share), (float) ($n - 18));
        if ((int) $plate % 2 === 0) {
            $plate--;
        }
        $plate = max(1.0, $plate);
        return [($n - $plate) / 2, $plate];
    }

    /**
     * Normalized style with defaults (black on white, square) — unknown values fall back to the defaults.
     *
     * @param array<string, mixed> $style
     * @return array{fg: string, bg: string, eye: string, module: string, eye_style: string, logo: float}
     */
    public static function style(array $style): array
    {
        $hex = static fn (mixed $v, string $d): string => is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v) === 1 ? strtoupper($v) : $d;
        $fg = $hex($style['fg'] ?? null, '#000000');
        $logo = $style['logo'] ?? self::LOGO_MAX;
        return [
            'fg' => $fg,
            'bg' => $hex($style['bg'] ?? null, self::DEFAULT_BG),
            'eye' => $hex($style['eye'] ?? null, $fg),
            'module' => in_array($style['module'] ?? '', self::MODULES, true) ? (string) $style['module'] : 'square',
            'eye_style' => in_array($style['eye_style'] ?? '', self::EYES, true) ? (string) $style['eye_style'] : 'square',
            'logo' => is_float($logo) || is_int($logo) ? max(0.1, min(0.44, (float) $logo)) : self::LOGO_MAX,
        ];
    }

    private static function isEye(int $x, int $y, int $n): bool
    {
        return ($x < 7 && $y < 7) || ($x >= $n - 7 && $y < 7) || ($x < 7 && $y >= $n - 7);
    }

    private static function svgEye(int $x, int $y, string $color, string $kind): string
    {
        if ($kind === 'circle') {
            $cx = self::f($x + 3.5);
            $cy = self::f($y + 3.5);
            return '<path fill="' . $color . '" fill-rule="evenodd" d="M' . self::f($x) . ',' . $cy . 'a3.5,3.5 0 1,0 7,0a3.5,3.5 0 1,0 -7,0z'
                . 'M' . self::f($x + 1) . ',' . $cy . 'a2.5,2.5 0 1,0 5,0a2.5,2.5 0 1,0 -5,0z"/>'
                . '<circle fill="' . $color . '" cx="' . $cx . '" cy="' . $cy . '" r="1.5"/>';
        }
        if ($kind === 'rounded') {
            return '<path fill="' . $color . '" fill-rule="evenodd" d="' . self::roundPath($x, $y, 7, 2) . self::roundPath($x + 1, $y + 1, 5, 1.2) . '"/>'
                . '<rect fill="' . $color . '" x="' . ($x + 2) . '" y="' . ($y + 2) . '" width="3" height="3" rx="0.8"/>';
        }
        return '<path fill="' . $color . '" fill-rule="evenodd" d="M' . $x . ',' . $y . 'h7v7h-7zM' . ($x + 1) . ',' . ($y + 1) . 'v5h5v-5z"/>'
            . '<rect fill="' . $color . '" x="' . ($x + 2) . '" y="' . ($y + 2) . '" width="3" height="3"/>';
    }

    private static function roundPath(float $x, float $y, float $s, float $r): string
    {
        return 'M' . self::f($x + $r) . ',' . self::f($y) . 'h' . self::f($s - 2 * $r) . 'a' . self::f($r) . ',' . self::f($r) . ' 0 0 1 ' . self::f($r) . ',' . self::f($r)
            . 'v' . self::f($s - 2 * $r) . 'a' . self::f($r) . ',' . self::f($r) . ' 0 0 1 -' . self::f($r) . ',' . self::f($r)
            . 'h-' . self::f($s - 2 * $r) . 'a' . self::f($r) . ',' . self::f($r) . ' 0 0 1 -' . self::f($r) . ',-' . self::f($r)
            . 'v-' . self::f($s - 2 * $r) . 'a' . self::f($r) . ',' . self::f($r) . ' 0 0 1 ' . self::f($r) . ',-' . self::f($r) . 'z';
    }

    private static function pngEye(\GdImage $img, int $x, int $y, int $u, int $eye, int $bg, string $kind): void
    {
        if ($kind === 'circle') {
            imagefilledellipse($img, $x + (int) (3.5 * $u), $y + (int) (3.5 * $u), 7 * $u, 7 * $u, $eye);
            imagefilledellipse($img, $x + (int) (3.5 * $u), $y + (int) (3.5 * $u), 5 * $u, 5 * $u, $bg);
            imagefilledellipse($img, $x + (int) (3.5 * $u), $y + (int) (3.5 * $u), 3 * $u, 3 * $u, $eye);
            return;
        }
        $r = $kind === 'rounded' ? [2 * $u, (int) (1.2 * $u), (int) (0.8 * $u)] : [0, 0, 0];
        self::roundRect($img, $x, $y, $x + 7 * $u - 1, $y + 7 * $u - 1, $r[0], $eye);
        self::roundRect($img, $x + $u, $y + $u, $x + 6 * $u - 1, $y + 6 * $u - 1, $r[1], $bg);
        self::roundRect($img, $x + 2 * $u, $y + 2 * $u, $x + 5 * $u - 1, $y + 5 * $u - 1, $r[2], $eye);
    }

    private static function roundRect(\GdImage $img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        $r = max(0, min($r, intdiv($x2 - $x1, 2), intdiv($y2 - $y1, 2)));
        if ($r === 0) {
            imagefilledrectangle($img, $x1, $y1, $x2, $y2, $color);
            return;
        }
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, 2 * $r + 1, 2 * $r + 1, $color);
        }
    }

    private static function color(\GdImage $img, string $hex): int
    {
        return (int) imagecolorallocate($img, (int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2)));
    }

    /** @param list<list<bool>> $m */
    private static function plainPng(array $m, int $scale): string
    {
        $n = count($m);
        $w = ($n + 2 * self::QUIET) * $scale;
        $raw = '';
        for ($py = 0; $py < $w; $py++) {
            $my = intdiv($py, $scale) - self::QUIET;
            $line = "\0";
            for ($px = 0; $px < $w; $px++) {
                $mx = intdiv($px, $scale) - self::QUIET;
                $dark = $my >= 0 && $my < $n && $mx >= 0 && $mx < $n && $m[$my][$mx];
                $line .= $dark ? "\x00" : "\xFF";
            }
            $raw .= $line;
        }
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $w, 8, 0, 0, 0, 0)) . $chunk('IDAT', (string) gzcompress($raw, 9)) . $chunk('IEND', '');
    }

    private static function f(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
    }
}
