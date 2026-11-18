<?php

declare(strict_types=1);

namespace Blue\Core\Ui;

use Blue\Core\Registry\ConfigRegistry;
use Throwable;

/**
 * Organization appearance from the Config Registry (6.8 / 6.13): system name, tagline, theme preset, own colours
 * (primary, menu, menu highlight, page background) and default light/dark mode. Missing values fall back to the design-system defaults (DEC-37, DEC-53) —
 * these are cosmetic keys, so a fallback is safe (unlike business rules, which fail safely).
 */
final class Appearance
{
    /** @var array{app_name: string, tagline: string, preset: string, primary: ?string, menu: ?string, accent: ?string, background: ?string, mode: string, pattern: string, version: string, logo: ?string, favicon: ?string, show_name: bool, logo_tint: string, builtin_logo: string, font: string, font_custom: ?string, font_family: ?string, font_url: ?string}|null */
    private ?array $current = null;

    public function __construct(
        private readonly ?ConfigRegistry $registry,
        private readonly string $root,
        private readonly string $defaultName,
        private readonly ?string $fontsDir = null,
    ) {
    }

    /** @return array{app_name: string, tagline: string, preset: string, primary: ?string, menu: ?string, accent: ?string, background: ?string, mode: string, pattern: string, version: string, logo: ?string, favicon: ?string, show_name: bool, logo_tint: string, builtin_logo: string, font: string, font_custom: ?string, font_family: ?string, font_url: ?string} */
    public function current(): array
    {
        if ($this->current !== null) {
            return $this->current;
        }
        $vals = ['brand.app_name' => null, 'brand.tagline' => null, 'theme.preset' => null, 'theme.primary_color' => null, 'theme.menu_color' => null, 'theme.accent_color' => null, 'theme.background_color' => null, 'theme.default_mode' => null, 'theme.pattern' => null, 'brand.logo_document' => null, 'brand.favicon_document' => null, 'brand.show_name' => null, 'brand.logo_tint' => null, 'brand.builtin_logo' => null,
            'theme.font' => null, 'theme.font_custom' => null, 'theme.font_family' => null, 'theme.font_url' => null];
        $ids = [];
        if ($this->registry !== null) {
            foreach (array_keys($vals) as $k) {
                try {
                    $r = $this->registry->resolveOrNull($k);
                } catch (Throwable) {
                    $r = null; // before the phase 3 migration ran — keep the defaults
                }
                if ($r !== null) {
                    $vals[$k] = $r->value;
                    $ids[] = $r->versionPublicId;
                }
            }
        }
        $preset = is_string($vals['theme.preset']) ? $vals['theme.preset'] : 'steel';
        return $this->current = [
            'app_name' => is_string($vals['brand.app_name']) && $vals['brand.app_name'] !== '' ? $vals['brand.app_name'] : $this->defaultName,
            'tagline' => is_string($vals['brand.tagline']) ? $vals['brand.tagline'] : '',
            'preset' => $preset,
            'primary' => self::hex($vals['theme.primary_color']),
            'menu' => self::hex($vals['theme.menu_color']),
            'accent' => self::hex($vals['theme.accent_color']),
            'background' => self::hex($vals['theme.background_color']),
            'mode' => is_string($vals['theme.default_mode']) ? $vals['theme.default_mode'] : 'light',
            'pattern' => $vals['theme.pattern'] === 'sparkles' ? 'sparkles' : 'dots', // 0.8.2: dots unless sparkles chosen
            'version' => substr(hash('sha256', implode(',', $ids)), 0, 12),
            'logo' => is_string($vals['brand.logo_document']) && $vals['brand.logo_document'] !== '' ? $vals['brand.logo_document'] : null,
            'favicon' => is_string($vals['brand.favicon_document']) && $vals['brand.favicon_document'] !== '' ? $vals['brand.favicon_document'] : null,
            'show_name' => $vals['brand.show_name'] !== 'hide',
            'logo_tint' => in_array($vals['brand.logo_tint'], ['light', 'accent'], true) ? (string) $vals['brand.logo_tint'] : 'original',
            'builtin_logo' => $vals['brand.builtin_logo'] === 'blue_os' ? 'blue_os' : 'blue',
            'font' => is_string($vals['theme.font']) && (FontCatalog::valid($vals['theme.font']) || $vals['theme.font'] === 'custom') ? $vals['theme.font'] : 'plex',
            'font_custom' => self::str($vals['theme.font_custom']),
            'font_family' => self::str($vals['theme.font_family']),
            'font_url' => self::str($vals['theme.font_url']),
        ];
    }

    /** 0.38.0: the system's primary colour as #RRGGBB (the organization's own, else the preset's) — e.g. QR code colour. */
    public function primaryHex(): string
    {
        $c = $this->current();
        if ($c['primary'] !== null) {
            return strtoupper($c['primary']);
        }
        $presets = ThemeBuilder::data($this->root)['presets'];
        $p = (array) ($presets[$c['preset']] ?? $presets['steel'] ?? []);
        return is_string($p['primary'] ?? null) ? strtoupper($p['primary']) : '#000000';
    }

    /** 0.37.0: the logo shipped with the system (ui/icons), used while no logo is uploaded. */
    public static function builtinLogo(string $which): string
    {
        return $which === 'blue_os' ? 'ui/icons/logo-blue-os.png' : 'ui/icons/logo-blue.png';
    }

    /** Forget the cached values (after saving, within the same process). */
    public function reset(): void
    {
        $this->current = null;
    }

    /** Organization theme overrides: colours, then the system font (an imported font brings its own @font-face). */
    public function css(string $basePath = ''): string
    {
        $c = $this->current();
        $css = ThemeBuilder::build($this->root, $c['preset'], $c['primary'], ['menu' => $c['menu'], 'accent' => $c['accent'], 'background' => $c['background']])['css'];
        return $css . $this->logoCss($basePath) . $this->fontCss($c['font'], $basePath);
    }

    /**
     * 0.37.0: the logo (uploaded or built in — /brand/logo serves either) drawn as a one-colour silhouette (white or the menu's light accent) on the navy areas (side bar, sign-in bar) — the image is
     * moved out of its own box and the box's background colour is cut to the logo's shape with a CSS mask, so the
     * logo keeps its proportions. Needs a logo with a transparent background. The URL lives in this stylesheet
     * because the strict CSP forbids inline styles.
     */
    public function logoCss(string $basePath = ''): string
    {
        $c = $this->current();
        if ($c['logo_tint'] === 'original') {
            return '';
        }
        $src = $basePath . '/brand/logo?v=' . $c['version'];
        $color = $c['logo_tint'] === 'light' ? 'var(--color-on-navy)' : 'var(--color-navy-accent)';
        return '.brand-logo--tint { object-position: -99999px 0; background-color: ' . $color . '; -webkit-mask: url("' . $src . '") center / contain no-repeat; mask: url("' . $src . '") center / contain no-repeat; border-radius: 0; }' . "\n";
    }

    /**
     * CSS for a font choice ("org" = the organization's). An imported font whose files are missing falls back to the default.
     */
    public function fontCss(string $font, string $basePath = ''): string
    {
        $c = $this->current();
        if ($font === 'org') {
            $font = $c['font'];
        }
        if ($font === 'custom') {
            $faces = $c['font_custom'] !== null ? (new FontImporter(new \Blue\Core\Integrations\CurlTransport(), $this->fontsDir ?? $this->root . '/storage/fonts'))->css($c['font_custom'], $basePath) : null;
            if ($faces === null || $c['font_family'] === null) {
                return '';
            }
            return $faces . FontCatalog::css('custom', $c['font_family'])
                . '.font-sample--custom .font-sample__text { font-family: ' . FontCatalog::stack('custom', $c['font_family']) . "; }\n";
        }
        return FontCatalog::css($font);
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    private static function hex(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v) === 1 ? strtoupper($v) : null;
    }
}
