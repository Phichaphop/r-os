<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\Error\ValidationException;
use Blue\Core\Modules\ModuleRegistry;
use Blue\Core\Support\Qr\QrEncoder;
use Blue\Core\Support\Qr\QrRender;
use Blue\Core\Support\UrlSafety;
use Blue\Modules\Crypto\CryptoTools;
use Blue\Modules\Qr\QrPolicy;

/** Phase 3U-A building blocks: QR encoder, QR content policy, URL safety, crypto tools, module manifests. */
final class UtilitiesUnitTest extends TestCase
{
    public function testQrStructureFollowsTheStandard(): void
    {
        $alignment = new \ReflectionMethod(QrEncoder::class, 'alignmentPositions');
        $alignment->setAccessible(true);
        // ISO/IEC 18004 Annex E (row/column coordinates of alignment pattern centres)
        $expected = [2 => [6, 18], 7 => [6, 22, 38], 14 => [6, 26, 46, 66], 32 => [6, 34, 60, 86, 112, 138], 40 => [6, 30, 58, 86, 114, 142, 170]];
        foreach ($expected as $v => $pos) {
            $q = QrEncoder::encode('x', 'L', $v);
            $obj = (new \ReflectionClass(QrEncoder::class))->newInstanceWithoutConstructor();
            $size = new \ReflectionProperty(QrEncoder::class, 'size');
            $size->setAccessible(true);
            $size->setValue($obj, $q['size']);
            self::assertSame($pos, $alignment->invoke($obj, $v), "version {$v}");
            self::assertSame($v * 4 + 17, $q['size']);
        }
        $q = QrEncoder::encode('HELLO WORLD', 'Q');
        self::assertSame(['alnum', 1], [$q['mode'], $q['version']], 'HELLO WORLD fits version 1-Q in alphanumeric mode');
        self::assertSame('numeric', QrEncoder::encode('01234567', 'M')['mode']);
        self::assertSame('byte', QrEncoder::encode('สวัสดี', 'M')['mode']);
        // finder pattern: 7×7 dark ring at the top-left corner
        $m = $q['modules'];
        self::assertTrue($m[0][0] && $m[0][6] && $m[6][0] && $m[6][6] && $m[3][3]);
        self::assertFalse($m[1][1] || $m[5][5] || $m[7][7]);
        self::assertTrue($m[$q['size'] - 8][8], 'dark module');
        // capacities (byte mode, version 40)
        self::assertSame([2953, 2331, 1663, 1273], array_map([QrEncoder::class, 'maxBytes'], QrEncoder::LEVELS));
        try {
            QrEncoder::encode(str_repeat('a', 2954), 'L');
            self::fail('too long accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('too_long', $e->getMessage());
        }
    }

    public function testQrImagesAreProduced(): void
    {
        $q = QrEncoder::encode('https://example.com/', 'H');
        $svg = QrRender::svg($q['modules'], 8, 'data:image/png;base64,AAAA', 'ทดสอบ <x>');
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        self::assertStringContainsString('aria-label="ทดสอบ &lt;x&gt;"', $svg);
        self::assertStringNotContainsString('<script', $svg);
        [, $plate] = QrRender::logoBox($q['size']);
        self::assertLessThanOrEqual(QrRender::LOGO_MAX * $q['size'], $plate, 'the logo plate stays within the limit');
        self::assertStringStartsWith("\x89PNG", QrRender::png($q['modules'], 4));
    }

    /**
     * Independent check: every symbol is decoded by ZXing (C++) — runs only where python3 + zxing-cpp are installed
     * (the development machine); elsewhere the test is reported as skipped, not passed.
     */
    public function testSymbolsDecodeWithAnIndependentReader(): void
    {
        exec('python3 -c "import zxingcpp, cv2" 2>&1', $o, $rc);
        if ($rc !== 0) {
            self::markTestSkipped('python3 with zxing-cpp + opencv not installed');
        }
        $dir = sys_get_temp_dir() . '/blueqr-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $cases = [];
        $texts = ['https://intranet.example.co.th/forms/leave?openExternalBrowser=1', 'ห้องประชุม ชั้น 3', '0812345678', 'TABLE-12', str_repeat('ข้อความยาว ', 40)];
        foreach ($texts as $i => $t) {
            foreach (QrEncoder::LEVELS as $l) {
                $q = QrEncoder::encode($t, $l);
                $f = "{$dir}/{$i}{$l}.png";
                file_put_contents($f, QrRender::png($q['modules'], 4));
                $cases[$f] = $t;
            }
        }
        file_put_contents("{$dir}/cases.json", json_encode($cases, JSON_UNESCAPED_UNICODE));
        $py = 'import json,sys,cv2,zxingcpp' . "\n" . 'c=json.load(open(sys.argv[1]))' . "\n"
            . 'bad=[f for f,t in c.items() if not (lambda r: r and r[0].text==t)(zxingcpp.read_barcodes(cv2.imread(f)))]' . "\n" . 'print(len(bad))';
        file_put_contents("{$dir}/d.py", $py);
        $out = trim((string) shell_exec('python3 ' . escapeshellarg("{$dir}/d.py") . ' ' . escapeshellarg("{$dir}/cases.json") . ' 2>&1'));
        array_map('unlink', glob("{$dir}/*") ?: []);
        rmdir($dir);
        self::assertSame('0', $out, 'symbols that did not decode');
    }

    public function testQrPolicyBlocksIdsCardsAndSecrets(): void
    {
        self::assertTrue(QrPolicy::hasThaiId('เลข 1-1037-02071-81-1 ครับ')); // valid check digit
        self::assertFalse(QrPolicy::hasThaiId('1103702071812'), 'wrong check digit is not an ID');
        self::assertTrue(QrPolicy::hasCardNumber('4111 1111 1111 1111'));
        self::assertFalse(QrPolicy::hasCardNumber('4111 1111 1111 1112'));
        self::assertSame(['qr.error.national_id'], QrPolicy::check('id 1103702071811', false)['errors']);
        self::assertSame(['qr.error.secret'], QrPolicy::check('password=hunter2', false)['errors']);
        self::assertContains('url.error.secret', QrPolicy::check('https://a.example/x?token=abc', true)['errors']);
        self::assertSame(['url.error.scheme'], QrPolicy::check('javascript:alert(1)', false)['errors']);
        self::assertContains('qr.warn.personal', QrPolicy::check('call 081-234-5678', false)['warnings']);
        self::assertSame([], QrPolicy::check('https://example.com/menu?table=12', true)['errors']);
        self::assertSame('https://a.example/p?openExternalBrowser=1', QrPolicy::withExternalBrowser('https://a.example/p'));
        self::assertSame('https://a.example/p?x=1&openExternalBrowser=1#top', QrPolicy::withExternalBrowser('https://a.example/p?x=1#top'));
        self::assertSame('https://a.example/?x=1&openExternalBrowser=1', QrPolicy::withExternalBrowser('https://a.example/?openExternalBrowser=0&x=1'), 'replaces, never doubles');
    }

    public function testUrlSafety(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,x', 'vbscript:x', 'file:///etc/passwd', '/relative', 'ftp://a.example'] as $bad) {
            self::assertSame(['url.error.scheme'], UrlSafety::check($bad)['errors'], $bad);
        }
        self::assertContains('url.error.credentials', UrlSafety::check('https://user:pw@a.example/')['errors']);
        self::assertContains('url.error.secret', UrlSafety::check('https://a.example/?api_key=1')['errors']);
        self::assertContains('url.error.secret', UrlSafety::check('https://a.example/#access_token=1')['errors']);
        self::assertSame(['url.error.spaces'], UrlSafety::check("https://a.example/a b")['errors']);
        self::assertSame(['url.warn.http'], UrlSafety::check('http://a.example/')['warnings']);
        self::assertSame(['url.warn.private_ip'], UrlSafety::check('https://192.168.1.10/')['warnings']);
        self::assertSame(['url.warn.punycode'], UrlSafety::check('https://xn--80ak6aa92e.com/')['warnings']);
        $ok = UrlSafety::check('https://Translate.Google.com/?sl=th&tl=en');
        self::assertSame([[], [], 'translate.google.com'], [$ok['errors'], $ok['warnings'], $ok['host']]);
    }

    public function testCryptoTools(): void
    {
        $h = CryptoTools::passwordHash('รหัสผ่าน-ทดสอบ', 'bcrypt', 10);
        self::assertStringStartsWith('$2y$10$', $h['value']);
        self::assertSame('crypto.verify.match', CryptoTools::passwordVerify('รหัสผ่าน-ทดสอบ', $h['value'])['value']);
        self::assertSame('crypto.verify.no_match', CryptoTools::passwordVerify('wrong', $h['value'])['value']);
        self::assertContains('crypto.warn.bcrypt72', CryptoTools::passwordHash(str_repeat('a', 80), 'bcrypt', 10)['warnings']);
        if (CryptoTools::argon2Available()) {
            self::assertStringStartsWith('$argon2id$', CryptoTools::passwordHash('x', 'argon2id', 12)['value']);
        }
        self::assertSame('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', CryptoTools::digest('abc', 'sha256')['value']); // FIPS 180-2
        self::assertContains('crypto.warn.legacy', CryptoTools::digest('abc', 'md5')['warnings']);
        // RFC 4231 test case 2
        self::assertSame('5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843', CryptoTools::hmac('what do ya want for nothing?', 'Jefe', 'sha256')['value']);
        foreach (CryptoTools::CODECS as $c) {
            $enc = CryptoTools::codec('ภาษาไทย & ?/=', $c, 'encode')['value'];
            self::assertSame('ภาษาไทย & ?/=', CryptoTools::codec($enc, $c, 'decode')['value'], $c);
        }
        self::assertContains('crypto.warn.binary', CryptoTools::codec(base64_encode("\x00\xFF\x10"), 'base64', 'decode')['warnings']);
        foreach ([['%%%', 'base64'], ['zz', 'hex'], ['abc', 'hex']] as [$in, $c]) {
            try {
                CryptoTools::codec($in, $c, 'decode');
                self::fail("{$c} decoded {$in}");
            } catch (ValidationException $e) {
                self::assertSame('crypto.error.decode', $e->errors['text']);
            }
        }
        $p = CryptoTools::random('password', 24, ['lower', 'digits']);
        self::assertMatchesRegularExpression('/^[a-z0-9]{24}$/', $p['value']);
        self::assertMatchesRegularExpression('/[0-9]/', $p['value'], 'at least one character from each chosen set');
        self::assertNotSame($p['value'], CryptoTools::random('password', 24, ['lower', 'digits'])['value']);
        self::assertSame(64, strlen(CryptoTools::random('hex', 32)['value']));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', CryptoTools::random('uuid', 0)['value']);
        $this->expectException(ValidationException::class);
        CryptoTools::digest("\xFF\xFE", 'sha256'); // not UTF-8
    }

    public function testModuleManifestsAreValidAndBadOnesAreRefused(): void
    {
        $all = ModuleRegistry::discover(dirname(__DIR__, 2));
        foreach (['links', 'qr', 'crypto'] as $code) {
            self::assertArrayHasKey($code, $all);
        }
        $bad = ['code' => 'Core', 'version' => '', 'pack' => 'x', 'name' => ['th' => 'x'], 'permissions' => ['other.use' => 'super'],
            'grants' => ['SYS' => ['x.nope']], 'menu' => [['group' => 'nowhere', 'href' => 'https://evil.example', 'permission' => 'x']],
            'config' => ['other.key' => []], 'public_paths' => ['/admin/'], 'routes' => '../evil.php'];
        $problems = ModuleRegistry::validate($bad, 'core');
        self::assertGreaterThanOrEqual(9, count($problems), implode("\n", $problems));
    }
}
