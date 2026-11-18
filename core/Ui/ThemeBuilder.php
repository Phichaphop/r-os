<?php

declare(strict_types=1);

namespace Blue\Core\Ui;

/**
 * Theme customization (03 §6.8, P0-05 §1.1–1.2): a preset and/or own colours (primary, menu, menu highlight,
 * page background) become a small set of
 * semantic-token overrides for light AND dark. Shades are derived in OKLCH so they step evenly; every pair that
 * is actually used is contrast-checked (WCAG 2.2 AA, 4.5:1 for text) and a colour that fails cannot be saved —
 * the nearest passing colour is suggested instead.
 * Only the semantic layer changes (P0-05 §1.1); components never see raw colours.
 */
final class ThemeBuilder
{
    public const MIN_CONTRAST = 4.5;

    /** @var array{base: array<string,string>, presets: array<string, array<string,string>>}|null */
    private static ?array $data = null;

    /** @return array{base: array<string,string>, presets: array<string, array<string,string>>} */
    public static function data(string $root): array
    {
        if (self::$data === null) {
            $json = json_decode((string) file_get_contents($root . '/ui/tokens/theme-presets.json'), true);
            self::$data = ['base' => (array) ($json['base'] ?? []), 'presets' => (array) ($json['presets'] ?? [])];
        }
        return self::$data;
    }

    /** Colours an organization may set itself, besides choosing a preset (6.8): name => preset key it replaces. */
    public const CUSTOM = ['primary' => 'primary', 'menu' => 'navy', 'accent' => 'accent', 'background' => 'bg'];

    /**
     * @param array<string, ?string> $colors own colours by CUSTOM name (menu, accent, background); null/'' = the preset's
     * @return array{css: string, checks: list<array{pair: string, ratio: float, ok: bool}>, ok: bool, suggestion: ?string,
     *               suggestions: array<string, string>, colors: array<string, string>}
     */
    public static function build(string $root, string $preset, ?string $primary, array $colors = []): array
    {
        $d = self::data($root);
        $p = $d['presets'][$preset] ?? $d['presets']['steel'];
        $base = $d['base'];
        $own = ['primary' => $primary] + $colors;
        $set = [];
        foreach (self::CUSTOM as $name => $key) {
            $v = $own[$name] ?? null;
            $set[$name] = is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v) === 1;
            $p[$key] = strtoupper($set[$name] ? (string) $v : $p[$key]);
        }
        $main = $p['primary'];
        [$l, $c, $h] = self::toOklch($main);
        // a page background of its own also tints the second surface (table headers, sunken rows) towards it
        $surface2 = $set['background'] ? self::between($p['bg'], $base['surface']) : $p['surface2'];

        $light = [
            '--color-action-primary' => $main,
            '--color-action-primary-hover' => self::fromOklch(max(0.0, $l - 0.07), $c, $h),
            '--color-primary-soft' => self::fromOklch(0.95, min($c * 0.3, 0.03), $h),
            '--color-focus-ring' => $main,
            '--color-navy' => $p['navy'],
            '--color-navy-accent' => $p['accent'],
            '--color-brand-mark' => $p['accent'],
            '--color-bg' => $p['bg'],
            '--color-surface-2' => $surface2,
        ];
        if ($set['menu']) {
            [$ml, $mc, $mh] = self::toOklch($p['navy']);
            $light['--color-navy-raised'] = self::fromOklch(min(1.0, $ml + 0.06), $mc, $mh);
        }
        $darkMain = self::fromOklch(0.80, min($c * 0.7, 0.12), $h);
        $dark = [
            '--color-action-primary' => $darkMain,
            '--color-action-primary-hover' => self::fromOklch(0.86, min($c * 0.6, 0.10), $h),
            '--color-primary-soft' => self::fromOklch(0.30, min($c * 0.4, 0.05), $h),
            '--color-focus-ring' => $darkMain,
            '--color-navy-accent' => $p['accent'],
            '--color-brand-mark' => $p['accent'],
        ];
        $darkMenu = null;
        if ($set['menu']) {
            // dark theme keeps a dark menu, in the same hue as the organization's menu colour
            [$ml, $mc, $mh] = self::toOklch($p['navy']);
            $darkMenu = self::fromOklch(min($ml, 0.24), min($mc, 0.06), $mh);
            $dark['--color-navy'] = $darkMenu;
        }

        $pairs = [
            'primary_on_surface' => [$main, $base['surface']],
            'text_on_primary' => [$base['on_primary'], $main],
            'primary_on_soft' => [$main, $light['--color-primary-soft']],
            'text_on_background' => [$base['text'], $p['bg']],
            'text_on_menu' => [$base['on_navy'], $p['navy']],
            'menu_on_highlight' => [$p['navy'], $p['accent']],
            'dark_primary_on_surface' => [$darkMain, $base['dark_surface']],
            'dark_text_on_primary' => [$base['dark_on_primary'], $darkMain],
        ];
        if ($darkMenu !== null) {
            $pairs['dark_text_on_menu'] = [$base['on_navy'], $darkMenu];
            $pairs['dark_menu_on_highlight'] = [$darkMenu, $p['accent']];
        }
        $checks = [];
        $ok = true;
        foreach ($pairs as $pair => [$fg, $bg]) {
            $ratio = round(self::contrast($fg, $bg), 2);
            $checks[] = ['pair' => $pair, 'ratio' => $ratio, 'ok' => $ratio >= self::MIN_CONTRAST];
            $ok = $ok && $ratio >= self::MIN_CONTRAST;
        }

        // nearest passing colour (same hue, only lightness moves) for each own colour that takes part in a failing pair
        $suggestions = [];
        if (!$ok) {
            $soft = fn (string $x): string => self::fromOklch(0.95, min(self::toOklch($x)[1] * 0.3, 0.03), self::toOklch($x)[2]);
            $menu = $p['navy'];
            $tests = [
                'primary' => fn (string $x): bool => self::contrast($x, $base['surface']) >= self::MIN_CONTRAST
                    && self::contrast($base['on_primary'], $x) >= self::MIN_CONTRAST && self::contrast($x, $soft($x)) >= self::MIN_CONTRAST,
                // the menu is judged with white text and with the highlight
                // (an own highlight may still move: the menu then only has to leave room for a light highlight of that hue)
                'menu' => fn (string $x): bool => self::contrast($base['on_navy'], $x) >= self::MIN_CONTRAST
                    && self::contrast($x, $set['accent'] ? self::fromOklch(0.93, self::toOklch($p['accent'])[1], self::toOklch($p['accent'])[2]) : $p['accent']) >= self::MIN_CONTRAST,
                // the highlight is judged against the menu as it will be after its own suggestion
                'accent' => function (string $x) use (&$menu): bool {
                    return self::contrast($menu, $x) >= self::MIN_CONTRAST;
                },
                'background' => fn (string $x): bool => self::contrast($base['text'], $x) >= self::MIN_CONTRAST,
            ];
            // menu/primary get darker, highlight/background lighter (white text on the first two, dark text on the others)
            $dir = ['primary' => -1, 'menu' => -1, 'accent' => 1, 'background' => 1];
            $pairsOf = [
                'primary' => ['primary_on_surface', 'text_on_primary', 'primary_on_soft', 'dark_primary_on_surface', 'dark_text_on_primary'],
                'menu' => ['text_on_menu', 'menu_on_highlight', 'dark_text_on_menu', 'dark_menu_on_highlight'],
                'accent' => ['menu_on_highlight', 'dark_menu_on_highlight'],
                'background' => ['text_on_background'],
            ];
            $failing = array_column(array_filter($checks, static fn (array $c): bool => !$c['ok']), 'pair');
            foreach ($tests as $name => $test) {
                $current = $p[self::CUSTOM[$name]];
                if (!$set[$name] || array_intersect($pairsOf[$name], $failing) === [] && $name !== 'accent' || $test($current)) {
                    continue;
                }
                [$cl, $cc, $ch] = self::toOklch($current);
                for ($try = $cl; $try > 0.12 && $try < 0.995; $try += 0.01 * $dir[$name]) {
                    $cand = self::fromOklch($try, $cc, $ch);
                    if ($test($cand)) {
                        $suggestions[$name] = $cand;
                        break;
                    }
                }
                if ($name === 'menu' && isset($suggestions['menu'])) {
                    $menu = $suggestions['menu'];
                }
            }
        }

        $css = '';
        if ($preset !== 'steel' || in_array(true, $set, true)) {
            $css = ":root,\n:root[data-theme=\"light\"] {\n" . self::decl($light) . "}\n"
                . ":root[data-theme=\"dark\"] {\n" . self::decl($dark) . "}\n";
        }
        return ['css' => $css, 'checks' => $checks, 'ok' => $ok, 'suggestion' => $suggestions['primary'] ?? null, 'suggestions' => $suggestions,
            'colors' => ['primary' => $p['primary'], 'menu' => $p['navy'], 'accent' => $p['accent'], 'background' => $p['bg']]];
    }

    /** The colour halfway between two colours in OKLab (used to derive the second surface from a background). */
    private static function between(string $a, string $b): string
    {
        [$l1, $c1, $h1] = self::toOklch($a);
        [$l2] = self::toOklch($b);
        return self::fromOklch(($l1 + $l2) / 2, $c1 / 2, $h1);
    }

    /** WCAG 2.x contrast ratio of two #RRGGBB colours. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(static fn (float $v): float => self::toLinear($v), self::rgb($hex));
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** @return array{0: float, 1: float, 2: float} OKLCH (L 0–1, C, h degrees) */
    public static function toOklch(string $hex): array
    {
        [$r, $g, $b] = array_map(static fn (float $v): float => self::toLinear($v), self::rgb($hex));
        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;
        [$l, $m, $s] = [self::cbrt($l), self::cbrt($m), self::cbrt($s)];
        $L = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $A = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $B = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;
        $h = rad2deg(atan2($B, $A));
        return [$L, sqrt($A * $A + $B * $B), $h < 0 ? $h + 360 : $h];
    }

    /** OKLCH → #RRGGBB, reducing chroma until the colour fits in sRGB. */
    public static function fromOklch(float $L, float $C, float $h): string
    {
        for ($c = $C; $c >= 0; $c -= 0.002) {
            $rgb = self::oklchToSrgb($L, $c, $h);
            if ($rgb !== null) {
                return $rgb;
            }
        }
        return self::oklchToSrgb($L, 0.0, $h) ?? '#808080';
    }

    private static function oklchToSrgb(float $L, float $C, float $h): ?string
    {
        $a = $C * cos(deg2rad($h));
        $b = $C * sin(deg2rad($h));
        $l = ($L + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($L - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($L - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;
        $rgb = [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
        $out = '#';
        foreach ($rgb as $v) {
            if ($v < -0.0005 || $v > 1.0005) {
                return null;
            }
            $v = max(0.0, min(1.0, $v));
            $srgb = $v <= 0.0031308 ? 12.92 * $v : 1.055 * ($v ** (1 / 2.4)) - 0.055;
            $out .= sprintf('%02X', (int) round($srgb * 255));
        }
        return $out;
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    }

    private static function toLinear(float $v): float
    {
        return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }

    private static function cbrt(float $v): float
    {
        return $v < 0 ? -((-$v) ** (1 / 3)) : $v ** (1 / 3);
    }

    /** @param array<string, string> $vars */
    private static function decl(array $vars): string
    {
        $out = '';
        foreach ($vars as $k => $v) {
            $out .= "  {$k}: {$v};\n";
        }
        return $out;
    }
}
