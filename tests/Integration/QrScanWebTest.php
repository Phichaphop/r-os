<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Tests\Support\TestApp;

/**
 * 0.41.1: QR reader (/qr/scan). The page reads in the browser only — no form, no request, camera allowed on this page
 * only — and the bundled decoder (jsQR) reads the codes this system makes (default style: primary colour, ×2 logo).
 */
final class QrScanWebTest extends TestCase
{
    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    public function testReaderPageIsBrowserOnlyAndCameraIsAllowedThereOnly(): void
    {
        $b = $this->t->browser();
        $b->login('employee.demo1');
        $gen = $b->get('/qr');
        self::assertStringContainsString('href="/qr/scan"', $gen->body, 'the generator links to the reader');
        self::assertStringContainsString('camera=()', $gen->headers['Permissions-Policy'] ?? '');

        $page = $b->get('/qr/scan');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('camera=(self)', $page->headers['Permissions-Policy'] ?? '', 'camera only on the reader');
        self::assertStringContainsString('ui/js/vendor/jsqr/jsQR.js', $page->body);
        self::assertStringContainsString('ui/js/qr-scan.js', $page->body);
        self::assertStringContainsString('data-qr-scan-start', $page->body);
        $main = (string) preg_replace('#^.*<main[^>]*>|</main>.*$#s', '', $page->body);
        self::assertStringNotContainsString('<form', $main, 'nothing on the reader is posted to the server');
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/ui/js/qr-scan.js');
        foreach (['fetch(', 'XMLHttpRequest', 'sendBeacon', 'localStorage', 'innerHTML', 'eval('] as $no) {
            self::assertStringNotContainsString($no, $js, 'qr-scan.js must not use ' . $no);
        }
        $lib = (string) file_get_contents(dirname(__DIR__, 2) . '/ui/js/vendor/jsqr/jsQR.js');
        self::assertStringNotContainsString('eval(', $lib);
        self::assertDoesNotMatchRegularExpression('/new Function/', $lib, 'no new Function in the decoder (CSP)');
    }

    public function testVisitorCanUseTheReaderWhenQrIsOpen(): void
    {
        $g = $this->t->browser();
        self::assertSame(302, $g->get('/qr/scan')->status, 'closed to visitors until the QR tool is opened');
        $sys = $this->t->browser();
        $sys->login(TestApp::ADMIN);
        $sys->get('/admin/settings/guest');
        self::assertSame(303, $sys->post('/admin/settings/guest', ['home' => [], 'tools' => ['qr']])->status);
        self::assertSame(200, $g->get('/qr/scan')->status);
    }

    public function testBundledDecoderReadsCodesMadeHere(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '' || !function_exists('imagecreatefromstring')) {
            self::markTestSkipped('node or GD is not installed');
        }
        $b = $this->t->browser();
        $b->login('employee.demo1');
        $b->get('/qr');
        $cases = [
            ['content_type' => 'url', 'content' => 'https://example.com/a?b=1&c=ไทย'],
            ['content_type' => 'text', 'content' => 'ทดสอบอ่าน QR ภาษาไทย 123'],
            ['content_type' => 'wifi', 'wifi_ssid' => 'Guest;5G', 'wifi_pass' => 'p:a\\ss', 'wifi_enc' => 'WPA'],
            ['content_type' => 'tel', 'tel' => '+66812345678'],
        ];
        $dir = sys_get_temp_dir() . '/blue-qrscan-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $lib = dirname(__DIR__, 2) . '/ui/js/vendor/jsqr/jsQR.js';
        $script = $dir . '/read.js';
        file_put_contents($script, "const jsQR=require(process.argv[2]);const fs=require('fs');const [w,h]=process.argv[4].split('x').map(Number);"
            . "const r=jsQR(new Uint8ClampedArray(fs.readFileSync(process.argv[3])),w,h,{inversionAttempts:'attemptBoth'});process.stdout.write(r?r.data:'<none>');");
        try {
            foreach ($cases as $i => $c) {
                foreach (['s', 'm'] as $size) {
                    $png = $b->post('/qr/download', ['kind' => 'static', 'level' => 'M', 'name' => '', 'format' => 'png', 'size' => $size] + $c);
                    self::assertSame(200, $png->status, $png->body);
                    $im = imagecreatefromstring($png->body);
                    self::assertNotFalse($im);
                    $w = imagesx($im);
                    $h = imagesy($im);
                    $raw = '';
                    for ($y = 0; $y < $h; $y++) {
                        for ($x = 0; $x < $w; $x++) {
                            $px = imagecolorat($im, $x, $y);
                            $a = ($px >> 24) & 0x7F; // GD alpha 0 = opaque; composite on white
                            $k = (127 - $a) / 127;
                            $raw .= chr((int) round((($px >> 16) & 0xFF) * $k + 255 * (1 - $k))) . chr((int) round((($px >> 8) & 0xFF) * $k + 255 * (1 - $k)))
                                . chr((int) round(($px & 0xFF) * $k + 255 * (1 - $k))) . "\xFF";
                        }
                    }
                    file_put_contents($dir . '/p.raw', $raw);
                    $got = (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($lib) . ' ' . escapeshellarg($dir . '/p.raw') . ' ' . $w . 'x' . $h . ' 2>&1');
                    $want = match ($c['content_type']) {
                        'wifi' => 'WIFI:T:WPA;S:Guest\\;5G;P:p\\:a\\\\ss;;',
                        'tel' => 'tel:+66812345678',
                        default => $c['content'],
                    };
                    self::assertSame($want, $got, 'case ' . $i . ' size ' . $size);
                }
            }
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }
}
