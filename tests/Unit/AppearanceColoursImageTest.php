<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\Files\ImageResizer;
use Blue\Core\Ui\ThemeBuilder;

/** Own theme colours (6.8: primary, menu, menu highlight, background) and automatic shrinking of brand pictures (6.13). */
final class AppearanceColoursImageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testOwnMenuHighlightAndBackgroundBecomeTokensForBothThemes(): void
    {
        $b = ThemeBuilder::build($this->root, 'steel', null, ['menu' => '#1E3A2F', 'accent' => '#B8E2C8', 'background' => '#F1F5F2']);
        self::assertTrue($b['ok'], json_encode($b['checks']) ?: '');
        self::assertStringContainsString('--color-navy: #1E3A2F', $b['css']);
        self::assertStringContainsString('--color-navy-accent: #B8E2C8', $b['css']);
        self::assertStringContainsString('--color-bg: #F1F5F2', $b['css']);
        self::assertMatchesRegularExpression('/--color-surface-2: #[0-9A-F]{6}/', $b['css'], 'second surface follows the background');
        [$dark] = array_slice(explode(':root[data-theme="dark"]', $b['css']), 1);
        self::assertMatchesRegularExpression('/--color-navy: #[0-9A-F]{6}/', $dark, 'dark theme gets a deep shade of the menu colour');
        self::assertContains('dark_text_on_menu', array_column($b['checks'], 'pair'));
        self::assertSame(['primary' => '#2F5A7E', 'menu' => '#1E3A2F', 'accent' => '#B8E2C8', 'background' => '#F1F5F2'], $b['colors']);
        self::assertSame('', ThemeBuilder::build($this->root, 'steel', null, ['menu' => '', 'accent' => null])['css'], 'nothing own on the default preset = no overrides');
    }

    public function testUnreadableOwnColoursAreRefusedWithAPassingSuggestionEach(): void
    {
        // a pale menu (white text unreadable), a highlight as dark as the menu, a dark page background
        $b = ThemeBuilder::build($this->root, 'steel', null, ['menu' => '#9FB4D0', 'accent' => '#2A3A55', 'background' => '#4A4A4A']);
        self::assertFalse($b['ok']);
        foreach (['menu', 'accent', 'background'] as $f) {
            self::assertArrayHasKey($f, $b['suggestions'], $f);
        }
        $fixed = ThemeBuilder::build($this->root, 'steel', null, ['menu' => $b['suggestions']['menu'], 'accent' => $b['suggestions']['accent'], 'background' => $b['suggestions']['background']]);
        self::assertTrue($fixed['ok'], 'taking every suggestion gives a readable theme: ' . (json_encode($fixed['checks']) ?: ''));
        self::assertArrayNotHasKey('primary', $b['suggestions'], 'only the colours that were set get suggestions');
    }

    public function testLargePicturesAreShrunkAndSmallPngKeptByteForByte(): void
    {
        if (!ImageResizer::available()) {
            self::markTestSkipped('GD not installed');
        }
        $big = imagecreatetruecolor(3000, 1500);
        imagesavealpha($big, true);
        imagefill($big, 0, 0, (int) imagecolorallocatealpha($big, 0, 0, 0, 127));
        imagefilledrectangle($big, 100, 100, 2900, 1400, (int) imagecolorallocate($big, 47, 90, 126));
        ob_start();
        imagepng($big);
        $png = (string) ob_get_clean();
        $fit = ImageResizer::fit($png, 512, 900 * 1024);
        self::assertNotNull($fit);
        self::assertSame([512, 256, 'png', true], [$fit['width'], $fit['height'], $fit['ext'], $fit['resized']]);
        $out = imagecreatefromstring($fit['bytes']);
        self::assertNotFalse($out);
        self::assertSame(127, (imagecolorat($out, 0, 0) >> 24) & 0x7F, 'transparency kept');

        $small = imagecreatetruecolor(64, 64);
        ob_start();
        imagepng($small);
        $smallPng = (string) ob_get_clean();
        self::assertSame($smallPng, ImageResizer::fit($smallPng, 512, 900 * 1024)['bytes'] ?? null);

        ob_start();
        imagejpeg($big, null, 95);
        $jpg = (string) ob_get_clean();
        $j = ImageResizer::fit($jpg, 256, 900 * 1024);
        self::assertSame([256, 128, 'jpg'], [$j['width'] ?? 0, $j['height'] ?? 0, $j['ext'] ?? '']);
        self::assertStringStartsWith("\xFF\xD8", (string) ($j['bytes'] ?? ''));

        self::assertNull(ImageResizer::fit("\x00\x00\x01\x00not-an-image", 256, 1024), 'ICO / unknown kept as uploaded');
    }

    public function testDecompressionBombsAreRefusedBeforeDecoding(): void
    {
        if (!ImageResizer::available()) {
            self::markTestSkipped('GD not installed');
        }
        // a PNG header that claims 20000 × 20000 pixels (400 MP) — getimagesize reads only the header
        $ihdr = pack('N', 20000) . pack('N', 20000) . "\x08\x06\x00\x00\x00";
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
        $this->expectExceptionMessage('image.error.too_large');
        ImageResizer::fit($png, 512, 900 * 1024);
    }
}
