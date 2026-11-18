<?php

declare(strict_types=1);

namespace Blue\Core\Ui;

use Blue\Core\App;

/**
 * A person's own display settings (0.6.1): light/dark/auto theme, highlight (accent) colour and system font. Stored in
 * user_preferences; anything not set follows the organization (Settings → Appearance). Served as /me/theme.css after
 * the organization theme, so the person's choice wins only for themselves.
 */
final class UserLook
{
    public const THEMES = ['light', 'dark', 'auto'];

    /** Ready-made highlight colours (all ≥ 4.5:1 with white text — WCAG AA). key => hex; classes in ui/tokens/theme-swatches.css */
    public const ACCENTS = [
        'blue' => '#1D4ED8', 'teal' => '#0F766E', 'green' => '#166534', 'purple' => '#6D28D9',
        'pink' => '#BE185D', 'red' => '#B91C1C', 'orange' => '#9A3412', 'graphite' => '#374151',
    ];

    /** @return array{theme: ?string, accent: ?string, font: ?string} */
    public static function load(App $app, int $userId): array
    {
        $out = ['theme' => null, 'accent' => null, 'font' => null];
        try {
            $rows = $app->db()->all("SELECT pref_key, pref_value FROM user_preferences WHERE user_id = ? AND pref_key IN ('ui.theme', 'ui.accent', 'ui.font')", [$userId]);
        } catch (\Throwable) {
            return $out; // before the preferences table exists
        }
        foreach ($rows as $r) {
            $v = (string) $r['pref_value'];
            match ((string) $r['pref_key']) {
                'ui.theme' => $out['theme'] = in_array($v, self::THEMES, true) ? $v : null,
                'ui.accent' => $out['accent'] = preg_match('/^#[0-9A-F]{6}$/', $v) === 1 ? $v : null,
                'ui.font' => $out['font'] = FontCatalog::valid($v) || $v === 'custom' ? $v : null,
                default => null,
            };
        }
        return $out;
    }

    /** @param array{theme: ?string, accent: ?string, font: ?string} $look */
    public static function hasCss(array $look): bool
    {
        return $look['accent'] !== null || $look['font'] !== null;
    }

    /** @param array{theme: ?string, accent: ?string, font: ?string} $look */
    public static function version(App $app, array $look): string
    {
        return substr(hash('sha256', json_encode($look) . '|' . $app->appearance()->current()['version']), 0, 12);
    }

    /**
     * CSS overrides for this person: their accent (derived like an organization primary, light and dark) and font.
     *
     * @param array{theme: ?string, accent: ?string, font: ?string} $look
     */
    public static function css(App $app, array $look, string $basePath = ''): string
    {
        $org = $app->appearance()->current();
        $css = '';
        if ($look['accent'] !== null) {
            $b = ThemeBuilder::build($app->root, $org['preset'], $look['accent'], ['menu' => $org['menu'], 'accent' => $org['accent'], 'background' => $org['background']]);
            $css .= $b['css'];
            // the selected menu item follows the person's colour too: a light tint of it, kept only if it stays readable on the menu
            [, $c, $h] = ThemeBuilder::toOklch($look['accent']);
            $tint = ThemeBuilder::fromOklch(0.86, min($c, 0.10), $h);
            if (ThemeBuilder::contrast($b['colors']['menu'], $tint) >= ThemeBuilder::MIN_CONTRAST) {
                $css .= ":root,\n:root[data-theme=\"light\"],\n:root[data-theme=\"dark\"] {\n  --color-navy-accent: {$tint};\n  --color-brand-mark: {$tint};\n}\n";
            }
        }
        if ($look['font'] !== null) {
            $css .= $look['font'] === 'plex'
                ? ":root {\n  --font-sans: var(--rds-font-sans);\n  --font-display: var(--rds-font-display);\n}\n" // back to the default even when the organization chose another
                : $app->appearance()->fontCss($look['font'], $basePath);
        }
        return $css;
    }

    /**
     * Contrast check of a highlight colour (the pairs that involve the main colour, light and dark).
     *
     * @return array{ok: bool, suggestion: ?string}
     */
    public static function checkAccent(App $app, string $hex): array
    {
        $org = $app->appearance()->current();
        $b = ThemeBuilder::build($app->root, $org['preset'], $hex, ['menu' => $org['menu'], 'accent' => $org['accent'], 'background' => $org['background']]);
        $mine = ['primary_on_surface', 'text_on_primary', 'primary_on_soft', 'dark_primary_on_surface', 'dark_text_on_primary'];
        $ok = true;
        foreach ($b['checks'] as $c) {
            if (in_array($c['pair'], $mine, true) && !$c['ok']) {
                $ok = false;
            }
        }
        return ['ok' => $ok, 'suggestion' => $ok ? null : ($b['suggestions']['primary'] ?? null)];
    }
}
