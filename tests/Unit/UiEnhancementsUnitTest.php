<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\Error\ValidationException;
use Blue\Core\Integrations\HttpTransport;
use Blue\Core\Modules\ModuleRegistry;
use Blue\Core\Ui\FontCatalog;
use Blue\Core\Ui\FontImporter;
use Blue\Core\Ui\ThemeBuilder;
use Blue\Core\Ui\UserLook;

/** 0.6.1: font catalog/importer safety, highlight colours, the module "home" hook. */
final class UiEnhancementsUnitTest extends TestCase
{
    public function testFontFamilyNamesCannotBreakOutOfCss(): void
    {
        self::assertSame('Sarabun body background urlx', FontCatalog::cleanFamily("Sarabun'; } body { background: url(x) } /*"), 'quotes, braces and punctuation removed');
        self::assertSame('Noto Sans Thai', FontCatalog::cleanFamily('  Noto   Sans  Thai '));
        self::assertSame('', FontCatalog::css('plex'), 'the default adds nothing');
        self::assertSame('', FontCatalog::css('nope'));
        self::assertStringContainsString("--font-sans: 'Kanit'", FontCatalog::css('kanit'));
        self::assertStringContainsString("--font-display: 'Kanit'", FontCatalog::css('kanit'), 'headings follow the chosen font');
        self::assertStringStartsWith('system-ui', FontCatalog::stack('system'));
    }

    public function testEveryBundledFontHasFacesAndALicence(): void
    {
        $root = dirname(__DIR__, 2);
        $css = (string) file_get_contents($root . '/ui/base/fonts.css');
        foreach (FontCatalog::FONTS as $k => [$family]) {
            if ($family === '') {
                continue;
            }
            self::assertStringContainsString("font-family: '{$family}'", $css, $k);
            self::assertMatchesRegularExpression("/font-family: '" . preg_quote($family, '/') . "';\\s+font-style: normal;\\s+font-display: swap;\\s+font-weight: 400;/", $css, "{$k} has a regular weight");
        }
        preg_match_all("#url\\('fonts/([a-z0-9.-]+)'\\)#", $css, $m);
        foreach ($m[1] as $f) {
            self::assertFileExists($root . '/ui/fonts/' . $f);
            self::assertSame('wOF2', substr((string) file_get_contents($root . '/ui/fonts/' . $f), 0, 4), $f);
        }
        foreach (['sarabun', 'prompt', 'kanit', 'noto-sans-thai', 'anuphan', 'chakra-petch', 'bai-jamjuree', 'ibm-plex-sans-thai'] as $slug) {
            self::assertStringContainsString('SIL Open Font License', (string) file_get_contents($root . '/ui/fonts/LICENSES/' . $slug . '-OFL.txt'), $slug);
        }
    }

    public function testReadyMadeHighlightColoursPassContrast(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (UserLook::ACCENTS as $k => $hex) {
            $b = ThemeBuilder::build($root, 'steel', $hex);
            foreach ($b['checks'] as $c) {
                if (in_array($c['pair'], ['primary_on_surface', 'text_on_primary', 'primary_on_soft', 'dark_primary_on_surface', 'dark_text_on_primary'], true)) {
                    self::assertTrue($c['ok'], "{$k} {$c['pair']} {$c['ratio']}");
                }
            }
            self::assertStringContainsString('.accent-swatch--' . $k . ' { --sw: ' . $hex . '; }', (string) file_get_contents($root . '/ui/tokens/theme-swatches.css'));
        }
    }

    public function testFontImporterRefusesUnsafeLinksAndNonFonts(): void
    {
        $dir = sys_get_temp_dir() . '/blue-font-' . bin2hex(random_bytes(4));
        $http = new class () implements HttpTransport {
            /** @var list<string> */
            public array $calls = [];

            public function get(string $url, array $headers, int $timeoutSeconds, int $maxBytes): array
            {
                $this->calls[] = $url;
                return match (true) {
                    str_ends_with($url, '/ok.ttf') => ['status' => 200, 'body' => "\x00\x01\x00\x00" . str_repeat('x', 100), 'error' => null, 'ms' => 1],
                    str_ends_with($url, '/big.woff2') => ['status' => 0, 'body' => '', 'error' => 'response_too_large', 'ms' => 1],
                    default => ['status' => 200, 'body' => 'GIF89a', 'error' => null, 'ms' => 1],
                };
            }
        };
        $imp = new FontImporter($http, $dir, static fn (string $h): array => match ($h) {
            'local.test' => ['127.0.0.1'], 'v6.test' => ['fd00::1'], 'meta.test' => ['169.254.169.254'], default => ['93.184.216.34'],
        });
        foreach (
            ['ftp://cdn.test/a.woff2' => 'font.error.url', 'http://cdn.test/a.woff2' => 'font.error.url', 'https://local.test/a.woff2' => 'font.error.private',
                'https://v6.test/a.woff2' => 'font.error.private', 'https://meta.test/a.woff2' => 'font.error.private', 'https://10.0.0.5/a.woff2' => 'font.error.private',
                'https://cdn.test/gif.woff2' => 'font.error.not_font', 'https://cdn.test/big.woff2' => 'font.error.too_large'] as $url => $key
        ) {
            try {
                $imp->import($url, '');
                self::fail($url);
            } catch (ValidationException $e) {
                self::assertSame($key, $e->errors['font_url'], $url);
            }
        }
        self::assertSame(['https://cdn.test/gif.woff2', 'https://cdn.test/big.woff2'], $http->calls, 'refused links are never requested');
        $r = $imp->import('https://cdn.test/fonts/my-brand_font/ok.ttf', '');
        self::assertSame('Ok', $r['family'], 'name from the file when none is given');
        $css = (string) $imp->css($r['id'], '/blue');
        self::assertStringContainsString("format('truetype')", $css);
        self::assertMatchesRegularExpression('#url\(\'/blue/brand/font/[a-f0-9]{64}\.ttf\'\)#', $css);
        self::assertNull($imp->css('../../etc/passwd', ''));
        self::assertNull($imp->file('../config/env.php'));
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    public function testModuleHomeHookMustBeAStaticMethodOfTheModule(): void
    {
        $base = ['code' => 'links', 'version' => '1', 'pack' => 'x', 'name' => ['th' => 'a', 'en' => 'b']];
        self::assertSame([], ModuleRegistry::validate($base + ['home' => 'Blue\\Modules\\Links\\LinksHome::section'], 'links'));
        self::assertNotSame([], ModuleRegistry::validate($base + ['home' => 'Blue\\Core\\System\\SystemController::home'], 'links'));
        self::assertNotSame([], ModuleRegistry::validate($base + ['home' => 'system'], 'links'));
    }
}
