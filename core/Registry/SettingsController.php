<?php

declare(strict_types=1);

namespace Blue\Core\Registry;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\ImageResizer;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Ui\GuestAccess;
use Blue\Core\Ui\ThemeBuilder;

/** Settings → Appearance (03 §6.8, 6.13): name, tagline, theme preset / own colour, default light/dark. */
final class SettingsController extends Controller
{
    private const LOGO_SIDE = 512;
    private const FAVICON_SIDE = 256;
    private const BRAND_MAX_BYTES = 900 * 1024; // the brand_asset class allows 1 MB

    /** Public stylesheet with the organization's theme overrides (versioned URL → cached for a year). */
    public function themeCss(Request $r): Response
    {
        $look = $this->app->appearance()->current();
        $fresh = ($r->query['v'] ?? '') === $look['version'];
        return (new Response($this->app->appearance()->css($r->basePath()), 200))
            ->withHeader('Content-Type', 'text/css; charset=utf-8')
            ->withHeader('Cache-Control', $fresh ? 'public, max-age=31536000, immutable' : 'no-cache');
    }

    /** Own colour fields on the form => Config Registry key (6.8). */
    private const COLOR_KEYS = ['primary' => 'theme.primary_color', 'menu' => 'theme.menu_color', 'accent' => 'theme.accent_color', 'background' => 'theme.background_color'];

    public function appearance(Request $r): Response
    {
        $this->guard($r);
        $look = $this->app->appearance()->current();
        $preset = $this->preset($r->query['preset'] ?? $look['preset']);
        $colors = [];
        foreach (array_keys(self::COLOR_KEYS) as $f) {
            $colors[$f] = array_key_exists($f, $r->query) ? $this->color($r->query[$f]) : $look[$f];
        }
        return $this->page('admin/settings/appearance', [
            'title' => $this->app->translator()->get('settings.appearance.title'),
            'look' => $look,
            'form' => ['preset' => $preset, 'mode' => $look['mode'], 'pattern' => $look['pattern'], 'app_name' => $look['app_name'], 'tagline' => $look['tagline'], 'show_name' => $look['show_name'], 'logo_tint' => $look['logo_tint'], 'builtin_logo' => $look['builtin_logo']] + $colors,
            'build' => $this->build($preset, $colors),
            'presets' => ThemeBuilder::data($this->app->root)['presets'],
            'errors' => [],
        ]);
    }

    /**
     * Public access (0.8.1, DEC-68): which apps appear on the home page for visitors, and which tools work without
     * signing in (only modules whose manifest declares a guest block can be opened). System admin; applied directly.
     *
     * @param array<string, string> $errors
     * @param array{home: list<string>, tools: list<string>}|null $form
     */
    public function guest(Request $r, array $errors = [], ?array $form = null, int $status = 200): Response
    {
        $this->guard($r);
        $reg = $this->app->registry();
        $home = $reg->resolveOrNull(GuestAccess::HOME_KEY);
        $candidates = GuestAccess::candidates($this->app);
        $form ??= [
            'home' => $home !== null && is_array($home->value) ? array_map('strval', $home->value) : array_keys($candidates),
            'tools' => GuestAccess::tools($this->app),
        ];
        $t = $this->app->translator();
        $rows = [];
        foreach ($candidates as $code => $c) {
            $rows[] = ['code' => $code, 'label' => $t->get($c['label']), 'icon' => $c['icon'], 'guest' => $c['guest'],
                'summary' => $t->has($code . '.app_summary') ? $t->get($code . '.app_summary') : ''];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['guest'] ? 0 : 1, $a['label']] <=> [$b['guest'] ? 0 : 1, $b['label']]);
        return $this->page('admin/settings/guest', ['title' => $t->get('settings.guest.title'), 'rows' => $rows, 'form' => $form,
            'homeSet' => $home !== null, 'errors' => $errors], $status);
    }

    public function saveGuest(Request $r): Response
    {
        $ctx = $this->guard($r);
        $candidates = GuestAccess::candidates($this->app);
        $pick = static function (mixed $raw, array $allowed): array {
            $out = [];
            foreach (is_array($raw) ? $raw : [] as $c) {
                if (is_string($c) && in_array($c, $allowed, true)) {
                    $out[] = $c;
                }
            }
            sort($out);
            return array_values(array_unique($out));
        };
        $home = $pick($r->body['home'] ?? [], array_keys($candidates));
        $tools = $pick($r->body['tools'] ?? [], array_keys(GuestAccess::guestable($this->app)));
        // a tool open to visitors is always shown on the public home, otherwise nobody could find it
        $home = array_values(array_unique([...$home, ...$tools]));
        sort($home);
        $reg = $this->app->registry();
        $changed = false;
        foreach ([GuestAccess::HOME_KEY => $home, GuestAccess::TOOLS_KEY => $tools] as $key => $list) {
            $cur = $reg->resolveOrNull($key);
            $now = $cur !== null && is_array($cur->value) ? array_values(array_map('strval', $cur->value)) : null;
            if ($now !== null) {
                sort($now);
            }
            if ($now === $list) {
                continue;
            }
            $reg->applyNow($ctx->az(), $key, (string) json_encode($list), 'public access settings');
            $changed = true;
        }
        $this->flash($r, 'success', $changed ? 'settings.guest.saved' : 'settings.appearance.unchanged');
        return $this->redirect($r, '/admin/settings/guest');
    }

    /** Live preview: the same overrides the saved theme would produce (text/css). */
    public function previewCss(Request $r): Response
    {
        $this->guard($r);
        $b = $this->build($this->preset($r->query['preset'] ?? 'steel'), $this->colorsFrom($r->query));
        return (new Response($b['css'], 200))->withHeader('Content-Type', 'text/css; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }

    /** Contrast results for the preview (JSON). */
    public function previewCheck(Request $r): Response
    {
        $this->guard($r);
        $b = $this->build($this->preset($r->query['preset'] ?? 'steel'), $this->colorsFrom($r->query));
        $t = $this->app->translator();
        return Response::json([
            'ok' => $b['ok'], 'suggestion' => $b['suggestion'], 'suggestions' => $b['suggestions'],
            'message' => $this->contrastMessage($b),
            'checks' => array_map(static fn (array $c): array => ['label' => $t->get('theme.check.' . $c['pair']), 'ratio' => $c['ratio'], 'ok' => $c['ok']], $b['checks']),
        ]);
    }

    public function save(Request $r): Response
    {
        $ctx = $this->guard($r);
        $reg = $this->app->registry();
        $look = $this->app->appearance()->current();
        $reset = $r->input('reset') === '1';
        $want = $reset
            ? ['brand.app_name' => $look['app_name'], 'brand.tagline' => $look['tagline'], 'theme.preset' => 'steel', 'theme.default_mode' => 'light', 'theme.pattern' => 'dots',
                'brand.show_name' => 'show', 'brand.logo_tint' => 'original', 'brand.builtin_logo' => 'blue']
            : [
                'brand.app_name' => $this->str($r, 'app_name', 40),
                'brand.tagline' => $this->str($r, 'tagline', 80),
                'theme.preset' => $this->str($r, 'preset', 20),
                'theme.default_mode' => $this->str($r, 'mode', 10),
                'theme.pattern' => $this->str($r, 'pattern', 10) === 'sparkles' ? 'sparkles' : 'dots',
                'brand.show_name' => $r->input('show_name') === '0' ? 'hide' : 'show', // checkbox after a hidden 0; absent = unchanged default
                'brand.logo_tint' => in_array($this->str($r, 'logo_tint', 10), ['light', 'accent'], true) ? $this->str($r, 'logo_tint', 10) : 'original',
                'brand.builtin_logo' => $this->str($r, 'builtin_logo', 10) === 'blue_os' ? 'blue_os' : 'blue',
            ];
        $have = ['brand.app_name' => $look['app_name'], 'brand.tagline' => $look['tagline'], 'theme.preset' => $look['preset'], 'theme.default_mode' => $look['mode'], 'theme.pattern' => $look['pattern'],
            'brand.show_name' => $look['show_name'] ? 'show' : 'hide', 'brand.logo_tint' => $look['logo_tint'], 'brand.builtin_logo' => $look['builtin_logo']];
        foreach (self::COLOR_KEYS as $f => $key) {
            $want[$key] = $reset ? '' : strtoupper($this->str($r, $f, 7));
            $have[$key] = (string) $look[$f];
        }
        $colors = [];
        foreach (self::COLOR_KEYS as $f => $key) {
            $colors[$f] = $this->color($want[$key]);
        }
        $build = $this->build($this->preset($want['theme.preset']), $colors);
        $errors = [];
        if (!$build['ok']) {
            $t = $this->app->translator();
            foreach ($build['suggestions'] as $f => $hex) {
                $errors[$f] = $t->get('theme.error.contrast', ['suggestion' => $hex]);
            }
            if ($errors === []) {
                $errors['primary'] = $this->contrastMessage($build);
            }
        }
        $changed = array_filter($want, static fn (string $v, string $k): bool => $v !== $have[$k], ARRAY_FILTER_USE_BOTH);
        if ($errors === []) {
            try {
                foreach ($changed as $key => $value) {
                    $reg->applyNow($ctx->az(), $key, $value, $reset ? 'reset appearance' : 'appearance settings');
                }
            } catch (ValidationException $e) {
                foreach ($e->errors as $f => $k) {
                    $errors[$f === 'value' ? 'app_name' : $f] = $this->app->translator()->get($k);
                }
            } catch (BusinessException $e) {
                $errors['primary'] = $this->app->translator()->get($e->messageKey);
            }
        }
        if ($errors !== []) {
            return $this->page('admin/settings/appearance', [
                'title' => $this->app->translator()->get('settings.appearance.title'),
                'look' => $look,
                'form' => ['preset' => $this->preset($want['theme.preset']), 'mode' => $want['theme.default_mode'], 'pattern' => $want['theme.pattern'], 'app_name' => $want['brand.app_name'], 'tagline' => $want['brand.tagline'],
                    'show_name' => $want['brand.show_name'] === 'show', 'logo_tint' => $want['brand.logo_tint'], 'builtin_logo' => $want['brand.builtin_logo']] + $colors,
                'build' => $build, 'presets' => ThemeBuilder::data($this->app->root)['presets'], 'errors' => $errors,
            ], 422);
        }
        $this->app->appearance()->reset();
        $this->flash($r, 'success', $changed === [] ? 'settings.appearance.unchanged' : 'settings.appearance.saved');
        return $this->redirect($r, '/admin/settings/appearance');
    }

    /**
     * @param array<string, ?string> $colors
     * @return array{css: string, checks: list<array{pair: string, ratio: float, ok: bool}>, ok: bool, suggestion: ?string, suggestions: array<string, string>, colors: array<string, string>}
     */
    private function build(string $preset, array $colors): array
    {
        return ThemeBuilder::build($this->app->root, $preset, $colors['primary'] ?? null, ['menu' => $colors['menu'] ?? null, 'accent' => $colors['accent'] ?? null, 'background' => $colors['background'] ?? null]);
    }

    /**
     * @param array<string, mixed> $src
     * @return array<string, ?string>
     */
    private function colorsFrom(array $src): array
    {
        $out = [];
        foreach (array_keys(self::COLOR_KEYS) as $f) {
            $out[$f] = $this->color($src[$f] ?? '');
        }
        return $out;
    }

    /** @param array{suggestions: array<string, string>} $b */
    private function contrastMessage(array $b): string
    {
        $t = $this->app->translator();
        if ($b['suggestions'] === []) {
            return $t->get('theme.error.contrast', ['suggestion' => '—']);
        }
        $parts = [];
        foreach ($b['suggestions'] as $f => $hex) {
            $label = ['primary' => 'settings.theme.color_primary', 'menu' => 'settings.theme.color_menu', 'accent' => 'settings.theme.color_accent', 'background' => 'settings.theme.color_background'][$f] ?? 'settings.theme.color_primary';
            $parts[] = $t->get($label) . ' ' . $hex;
        }
        return $t->get('theme.error.contrast_many', ['list' => implode(' · ', $parts)]);
    }

    /**
     * System font (0.6.1): a bundled font, or one imported from a link (Google Fonts CSS or a font file). The import is
     * done by the server (FontImporter) so pages keep loading fonts only from Blue OS itself.
     */
    public function font(Request $r): Response
    {
        $ctx = $this->guard($r);
        $reg = $this->app->registry();
        $t = $this->app->translator();
        $font = $this->str($r, 'font', 20);
        $url = trim($this->str($r, 'font_url', 500));
        try {
            if ($font === 'custom' && $url !== '') {
                $res = $this->app->fonts()->import($url, $this->str($r, 'font_family', 80));
                foreach (['theme.font_custom' => $res['id'], 'theme.font_family' => $res['family'], 'theme.font_url' => $url, 'theme.font' => 'custom'] as $k => $val) {
                    $reg->applyNow($ctx->az(), $k, $val, 'font import');
                }
                $this->app->audit()->record('theme.font_imported', 'success', 'config', 'theme.font', null, null, ['family' => $res['family'], 'faces' => $res['faces'], 'bytes' => $res['bytes'],
                    'host' => (string) parse_url($url, PHP_URL_HOST)]);
                $this->app->appearance()->reset();
                $this->flash($r, 'success', 'settings.font.imported', ['family' => $res['family'], 'faces' => (string) $res['faces']]);
                return $this->redirect($r, '/admin/settings/appearance#font');
            }
            $look = $this->app->appearance()->current();
            if (!\Blue\Core\Ui\FontCatalog::valid($font) && !($font === 'custom' && $look['font_custom'] !== null)) {
                throw new ValidationException(['font' => $font === 'custom' ? 'font.error.url' : 'look.error.font']);
            }
            if ($font !== $look['font']) {
                $reg->applyNow($ctx->az(), 'theme.font', $font, 'font');
            }
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors as $f => $k) {
                $errors[$f] = $t->get($k);
            }
            $errors['_font_url'] = $url;
            $look = $this->app->appearance()->current();
            $preset = $look['preset'];
            $colors = [];
            foreach (array_keys(self::COLOR_KEYS) as $f) {
                $colors[$f] = $look[$f];
            }
            return $this->page('admin/settings/appearance', [
                'title' => $t->get('settings.appearance.title'), 'look' => $look,
                'form' => ['preset' => $preset, 'mode' => $look['mode'], 'pattern' => $look['pattern'], 'app_name' => $look['app_name'], 'tagline' => $look['tagline'], 'show_name' => $look['show_name'], 'logo_tint' => $look['logo_tint'], 'builtin_logo' => $look['builtin_logo']] + $colors,
                'build' => $this->build($preset, $colors), 'presets' => ThemeBuilder::data($this->app->root)['presets'], 'errors' => $errors,
            ], 422);
        } catch (BusinessException $e) {
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/admin/settings/appearance#font');
        }
        $this->app->appearance()->reset();
        $this->flash($r, 'success', 'settings.font.saved');
        return $this->redirect($r, '/admin/settings/appearance#font');
    }

    /** /brand/font/{sha}.{ext} — an imported font file (public like the logo; immutable, the name is its hash). */
    public function fontFile(Request $r): Response
    {
        $f = $this->app->fonts()->file((string) $r->params['file']);
        if ($f === null) {
            throw \Blue\Core\Error\HttpException::notFound();
        }
        return Response::download($f[0], $f[1], basename($f[0]))
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
    }

    /**
     * Logo / favicon upload (6.13): stored as a public brand-asset document (PNG/JPEG/WebP/ICO, ≤ 1 MB, checked from the
     * bytes), then the brand.* key points at it. "Reset" returns to the logo shipped with the system (Blue — 0.37.0).
     */
    public function brand(Request $r): Response
    {
        $ctx = $this->guard($r);
        $kind = $this->str($r, 'kind', 10) === 'favicon' ? 'favicon' : 'logo';
        $key = 'brand.' . $kind . '_document';
        if ($r->input('reset') === '1') {
            try {
                $this->app->registry()->applyNow($ctx->az(), $key, '', 'reset ' . $kind);
            } catch (BusinessException $e) {
                $this->flash($r, 'danger', $e->messageKey);
                return $this->redirect($r, '/admin/settings/appearance#brand');
            }
            $this->app->appearance()->reset();
            $this->flash($r, 'success', 'settings.brand.reset_done');
            return $this->redirect($r, '/admin/settings/appearance#brand');
        }
        $file = $r->file('file');
        try {
            if ($file === null || !$file->ok()) {
                throw new ValidationException(['file' => $file?->errorKey() ?? 'file.error.missing']);
            }
            $bytes = $file->bytes();
            $name = $file->safeName();
            $resized = null;
            try {
                // shrink to what the shell needs (logo shown at ≤ 64 px, favicon ≤ 32 px — kept at 2–8× for sharp screens)
                $fit = ImageResizer::fit($bytes, $kind === 'favicon' ? self::FAVICON_SIDE : self::LOGO_SIDE, self::BRAND_MAX_BYTES);
            } catch (\RuntimeException $e) {
                throw new ValidationException(['file' => $e->getMessage()]);
            }
            if ($fit !== null) {
                $bytes = $fit['bytes'];
                $name = (pathinfo($name, PATHINFO_FILENAME) !== '' ? pathinfo($name, PATHINFO_FILENAME) : $kind) . '.' . $fit['ext'];
                $resized = $fit['resized'] ? $fit['width'] . '×' . $fit['height'] : null;
            } elseif (strlen($bytes) > self::BRAND_MAX_BYTES) {
                throw new ValidationException(['file' => 'settings.brand.error.dimensions']);
            }
            $doc = $this->app->documents()->storeGenerated($ctx->userId(), 'core.brand_asset', $bytes, $name, ['title' => $kind . ' — ' . $name], 'upload', true);
            $this->app->registry()->applyNow($ctx->az(), $key, $doc['document'], 'upload ' . $kind);
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, '/admin/settings/appearance#brand');
        } catch (BusinessException $e) {
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/admin/settings/appearance#brand');
        }
        $this->app->appearance()->reset();
        if ($resized !== null) {
            $this->flash($r, 'success', 'settings.brand.saved_resized', ['size' => $resized]);
        } else {
            $this->flash($r, 'success', 'settings.brand.saved');
        }
        return $this->redirect($r, '/admin/settings/appearance#brand');
    }

    /** Public: the current logo / favicon, or the default mark. Versioned URLs are cached for a year. */
    public function brandFile(Request $r): Response
    {
        $look = $this->app->appearance()->current();
        $kind = str_ends_with($r->path, 'favicon') ? 'favicon' : 'logo';
        $pid = $look[$kind] ?? ($kind === 'favicon' ? $look['logo'] : null);
        $asset = $pid !== null ? $this->app->documents()->publicAsset($pid) : null;
        if ($asset === null) {
            // 0.37.0: the logo shipped with the system (Blue, or the Blue OS alternate) / the star mark as the tab icon
            $file = $kind === 'favicon' ? 'ui/icons/mark-blue.png' : \Blue\Core\Ui\Appearance::builtinLogo($look['builtin_logo']);
            $png = (string) file_get_contents($this->app->root . '/' . $file);
            return (new Response($png, 200, ['Content-Type' => 'image/png']))
                ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
                ->withHeader('Cache-Control', ($r->query['v'] ?? '') === $look['version'] ? 'public, max-age=31536000, immutable' : 'public, max-age=300');
        }
        $fresh = ($r->query['v'] ?? '') === $look['version'];
        return (new Response($asset['bytes'], 200, ['Content-Type' => $asset['mime']]))
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withHeader('Cache-Control', $fresh ? 'public, max-age=31536000, immutable' : 'public, max-age=300');
    }

    private function guard(Request $r): \Blue\Core\Http\RequestContext
    {
        $ctx = $this->ctx($r);
        if (!$ctx->az()->scope('core.config.approve')->all) {
            throw \Blue\Core\Error\HttpException::forbidden();
        }
        return $ctx;
    }

    private function preset(mixed $v): string
    {
        return is_string($v) && in_array($v, ['steel', 'slate', 'teal', 'paper'], true) ? $v : 'steel';
    }

    private function color(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? strtoupper($v) : null;
    }
}
