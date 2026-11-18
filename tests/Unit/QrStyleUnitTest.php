<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use Blue\Core\Support\Barcode\Code128;
use Blue\Core\Support\Qr\QrEncoder;
use Blue\Core\Support\Qr\QrRender;
use Blue\Modules\Qr\QrPolicy;
use PHPUnit\Framework\TestCase;

/**
 * 0.38.0 (R2): QR colours and decoration, logo sizes, the logo kept in downloads (xlink:href in SVG, never dropped
 * from PNG), content types (tel / mailto / Wi-Fi / vCard) and the Code 128 barcode of office-supply requests.
 * Scannability of every style × logo size was checked with ZXing (see the R2 test report); here the drawing itself.
 */
final class QrStyleUnitTest extends TestCase
{
    private const LOGO = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function testSvgKeepsTheLogoForOldViewersAndDrawsStyles(): void
    {
        $q = QrEncoder::encode('https://blue.example.com/q/Ab3dE5fG7h', 'H');
        $plain = QrRender::svg($q['modules'], 8);
        self::assertStringContainsString('fill="#FFFFFF"', $plain);
        self::assertStringContainsString('<path fill="#000000" d="M', $plain, 'defaults: black on white, square');
        self::assertStringContainsString('shape-rendering="crispEdges"', $plain);

        $svg = QrRender::svg($q['modules'], 8, 'data:image/png;base64,' . self::LOGO, 'โลโก้', ['fg' => '#2f5a7e', 'bg' => '#FFFBEA', 'eye' => '#0B1F44', 'module' => 'dot', 'eye_style' => 'circle', 'logo' => 0.44]);
        self::assertStringContainsString('xmlns:xlink="http://www.w3.org/1999/xlink"', $svg);
        self::assertMatchesRegularExpression('#<image [^>]*href="data:image/png;base64,[^"]+" xlink:href="data:image/png;base64,[^"]+"/>#', $svg, 'logo readable by viewers that only know xlink:href (0.38.0 fix)');
        self::assertStringContainsString('<g fill="#2F5A7E"><circle ', $svg, 'dot modules in the code colour (upper-cased)');
        self::assertSame(3, substr_count($svg, '<circle fill="#0B1F44"'), 'three round eye pupils in the eye colour');
        self::assertStringContainsString('fill="#FFFBEA"', $svg);
        self::assertStringNotContainsString('crispEdges', $svg, 'round shapes are anti-aliased');
        $rounded = QrRender::svg($q['modules'], 8, null, '', ['module' => 'rounded', 'eye_style' => 'rounded']);
        self::assertStringContainsString('rx="0.32"', $rounded);
        self::assertStringContainsString('fill-rule="evenodd" d="M6,4h3a2,2 0 0 1 2,2', $rounded, 'rounded eye ring');
    }

    public function testLogoPlateSizesStayClearOfFindersAndTiming(): void
    {
        foreach ([21, 25, 37, 57, 97] as $n) {
            foreach (QrRender::LOGO_SIZES as $share) {
                [$off, $plate] = QrRender::logoBox($n, $share);
                self::assertSame(1, (int) $plate % 2, 'odd number of modules');
                self::assertLessThanOrEqual(floor($n * $share), $plate);
                self::assertGreaterThanOrEqual(9.0, $off, "n={$n}: plate starts after the finder + separator + timing line");
            }
        }
        [, $old] = QrRender::logoBox(37);
        [, $xl] = QrRender::logoBox(37, QrRender::LOGO_SIZES['xl']);
        self::assertSame(7.0, $old);
        self::assertSame(15.0, $xl, 'extra large ≈ ×2 the former width');
        $s = QrRender::style(['fg' => 'red', 'module' => 'star', 'logo' => 0.9]);
        self::assertSame(['fg' => '#000000', 'bg' => '#FFFFFF', 'eye' => '#000000', 'module' => 'square', 'eye_style' => 'square', 'logo' => 0.44], $s, 'unknown values fall back; logo capped at ×2');
    }

    public function testPngCarriesColoursAndTheLogo(): void
    {
        if (!function_exists('imagecreatefromstring')) {
            self::markTestSkipped('GD not installed');
        }
        $q = QrEncoder::encode('https://blue.example.com/q/Ab3dE5fG7h', 'H');
        $star = (string) file_get_contents(dirname(__DIR__, 2) . '/ui/icons/star-blue.png');
        $png = QrRender::png($q['modules'], 8, $star, ['fg' => '#2F5A7E', 'bg' => '#FFFFFF', 'module' => 'rounded', 'eye_style' => 'rounded', 'logo' => 0.44]);
        $img = imagecreatefromstring($png);
        self::assertNotFalse($img);
        $w = imagesx($img);
        self::assertSame(($q['size'] + 8) * 8, $w, 'supersampled shapes come back at the requested size');
        // the centre is the logo: a blue star pixel (blue clearly above red), not the plain plate or a module
        $c = imagecolorsforindex($img, imagecolorat($img, intdiv($w, 2), intdiv($w, 2)));
        self::assertGreaterThan($c['red'] + 60, $c['blue'], 'logo drawn into the PNG');
        // a finder eye corner module is in the eye colour (= code colour by default)
        $e = imagecolorsforindex($img, imagecolorat($img, 4 * 8 + 12, 4 * 8 + 4));
        self::assertEqualsWithDelta(0x2F, $e['red'], 40);
        self::assertEqualsWithDelta(0x7E, $e['blue'], 40);
        self::assertTrue(QrRender::logoReadable($star));
        self::assertFalse(QrRender::logoReadable('not an image'));
        $this->expectException(\InvalidArgumentException::class);
        QrRender::png($q['modules'], 4, 'not an image'); // never dropped silently
    }

    public function testContentTypesBuildStandardPayloads(): void
    {
        self::assertSame(['content' => 'tel:+66812345678', 'errors' => []], QrPolicy::compose('tel', ['tel' => '+66 (81) 234-5678']));
        self::assertSame(['tel' => 'qr.error.tel'], QrPolicy::compose('tel', ['tel' => 'call me'])['errors']);
        self::assertSame('mailto:hr@example.com?subject=%E0%B8%A5%E0%B8%B2%20%E0%B8%9B%E0%B9%88%E0%B8%A7%E0%B8%A2', QrPolicy::compose('email', ['email_to' => 'hr@example.com', 'email_subject' => 'ลา ป่วย'])['content']);
        self::assertSame(['email_to' => 'qr.error.email'], QrPolicy::compose('email', ['email_to' => 'nope'])['errors']);
        self::assertSame('WIFI:T:WPA;S:Guest\\;5G;P:a\\\\b\\:c\\,d;H:true;;', QrPolicy::compose('wifi', ['wifi_ssid' => 'Guest;5G', 'wifi_pass' => 'a\\b:c,d', 'wifi_enc' => 'WPA', 'wifi_hidden' => '1'])['content'], 'special characters escaped');
        self::assertSame('WIFI:T:nopass;S:Lobby;;', QrPolicy::compose('wifi', ['wifi_ssid' => 'Lobby', 'wifi_enc' => 'nopass'])['content']);
        self::assertSame(['wifi_pass' => 'qr.error.wifi_pass'], QrPolicy::compose('wifi', ['wifi_ssid' => 'X', 'wifi_enc' => 'WPA'])['errors']);
        $vc = QrPolicy::compose('vcard', ['vc_name' => 'สมชาย, ใจดี', 'vc_org' => 'Blue Co', 'vc_tel' => '081-234-5678', 'vc_email' => 'somchai@example.com']);
        self::assertSame("BEGIN:VCARD\r\nVERSION:3.0\r\nN:สมชาย\\, ใจดี;;;;\r\nFN:สมชาย\\, ใจดี\r\nORG:Blue Co\r\nTEL;TYPE=CELL:0812345678\r\nEMAIL:somchai@example.com\r\nEND:VCARD", $vc['content']);
        self::assertSame(['vc_name' => 'qr.error.vc_name'], QrPolicy::compose('vcard', ['vc_name' => 'x'])['errors']);
        self::assertSame('https://a.example', QrPolicy::compose('url', ['content' => ' https://a.example '])['content']);
    }

    public function testCode128EncodesRequestNumbers(): void
    {
        $w = Code128::widths('RQ-2611-0001');
        self::assertSame(11 * (12 + 3) + 2, array_sum($w), 'start + 12 characters + check + stop (13)');
        self::assertSame([2, 1, 1, 2, 1, 4], array_slice($w, 0, 6), 'start code B');
        self::assertSame([2, 3, 3, 1, 1, 1, 2], array_slice($w, -7), 'stop pattern');
        // check symbol: (104 + Σ value × position) mod 103 — for "AB": (104 + 33×1 + 34×2) mod 103 = 102
        self::assertSame([4, 1, 1, 1, 3, 1], array_slice(Code128::widths('AB'), 18, 6));
        $svg = Code128::svg('RQ-2611-0001', 2, 50, true, 'บาร์โค้ด');
        self::assertStringContainsString('aria-label="บาร์โค้ด"', $svg);
        self::assertStringContainsString('>RQ-2611-0001</text>', $svg);
        foreach (['', 'ไทย', str_repeat('A', Code128::MAX + 1)] as $bad) {
            try {
                Code128::widths($bad);
                self::fail('accepted ' . $bad);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
