<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\Auth\PasswordPolicy;
use Blue\Core\Auth\Totp;
use Blue\Core\Support\Crypto;

/** RFC 6238 test vectors (SHA-1), replay protection, password policy (DEC-51), field encryption. */
final class TotpTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32("12345678901234567890")

    /** @return array<string, array{0:int, 1:string}> */
    public static function vectors(): array
    {
        // RFC 6238 Appendix B (8 digits) → last 6 digits for our 6-digit codes
        return [
            't=59' => [59, '287082'],
            't=1111111109' => [1111111109, '081804'],
            't=1111111111' => [1111111111, '050471'],
            't=1234567890' => [1234567890, '005924'],
            't=2000000000' => [2000000000, '279037'],
        ];
    }

    /** @dataProvider vectors */
    public function testRfc6238Vectors(int $time, string $code): void
    {
        self::assertSame($code, Totp::code(self::SECRET, Totp::stepAt($time)));
        self::assertSame(Totp::stepAt($time), Totp::verify(self::SECRET, $code, $time));
    }

    public function testBase32RoundTripAndWindow(): void
    {
        self::assertSame('12345678901234567890', Totp::base32Decode(self::SECRET));
        $secret = Totp::newSecret();
        self::assertSame(32, strlen($secret));
        $now = 1_800_000_000;
        $prev = Totp::code($secret, Totp::stepAt($now) - 1);
        self::assertNotNull(Totp::verify($secret, $prev, $now), 'one step of clock drift is accepted');
        self::assertNull(Totp::verify($secret, Totp::code($secret, Totp::stepAt($now) - 3), $now), 'older codes are refused');
        self::assertNull(Totp::verify($secret, 'abcdef', $now));
    }

    public function testReplayOfAUsedStepIsRefused(): void
    {
        $now = 1_800_000_000;
        $code = Totp::code(self::SECRET, Totp::stepAt($now));
        $step = Totp::verify(self::SECRET, $code, $now);
        self::assertNotNull($step);
        self::assertNull(Totp::verify(self::SECRET, $code, $now, $step), 'same code cannot be used twice');
    }

    public function testPasswordPolicy(): void
    {
        $p = new PasswordPolicy(12);
        self::assertSame(['auth.pw.too_short'], $p->check('Short-pass1'));
        self::assertContains('auth.pw.common', $p->check('password1234'));
        self::assertContains('auth.pw.common', $p->check('aaaaaaaaaaaaaaaa'));
        self::assertContains('auth.pw.contains_name', $p->check('somchai-likes-tea-2026', 'somchai'));
        self::assertSame([], $p->check('ช้างเผือกกินกล้วย 3 ลูก', 'somchai'), 'Thai passphrases are fine');
        self::assertSame([], $p->check('correct horse battery staple'));
    }

    public function testFieldEncryptionAndKeyedHash(): void
    {
        $c = new Crypto('base64:' . base64_encode(random_bytes(32)));
        $blob = $c->encrypt('JBSWY3DPEHPK3PXP');
        self::assertNotSame('JBSWY3DPEHPK3PXP', $blob);
        self::assertSame('JBSWY3DPEHPK3PXP', $c->decrypt($blob));
        self::assertSame($c->keyedHash('x'), $c->keyedHash('x'));
        $other = new Crypto('base64:' . base64_encode(random_bytes(32)));
        self::assertNotSame($c->keyedHash('x'), $other->keyedHash('x'));
        $this->expectException(\RuntimeException::class);
        $other->decrypt($blob);
    }

    /** XAMPP on Windows has OpenSSL but no libsodium; each available backend must work alone and reject tampering. */
    public function testEveryAvailableCryptoBackendRoundTripsAndDetectsTampering(): void
    {
        $key = 'base64:' . base64_encode(random_bytes(32));
        self::assertNotSame([], Crypto::backends());
        foreach (Crypto::backends() as $backend) {
            $c = new Crypto($key, $backend);
            $blob = $c->encrypt('ข้อมูลลับ secret');
            self::assertSame('ข้อมูลลับ secret', (new Crypto($key))->decrypt($blob), $backend);
            $tampered = substr($blob, 0, -1) . chr(ord(substr($blob, -1)) ^ 1);
            try {
                $c->decrypt($tampered);
                self::fail("{$backend}: tampered value was accepted");
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }
        }
        // keyed hashes must not depend on the backend (sessions/tokens are looked up by them)
        self::assertSame((new Crypto($key, Crypto::backends()[0]))->keyedHash('t'), (new Crypto($key, Crypto::backends()[count(Crypto::backends()) - 1]))->keyedHash('t'));
    }

    /** Optional extensions may only be touched behind Crypto's availability checks (regression: sodium on XAMPP). */
    public function testNoDirectSodiumCallsOutsideCrypto(): void
    {
        $root = dirname(__DIR__, 2);
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/core', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = str_replace('\\', '/', (string) $f);
            if (str_ends_with($path, '.php') && !str_ends_with($path, '/Support/Crypto.php') && preg_match('/\bsodium_\w+\s*\(/', (string) file_get_contents($path))) {
                $hits[] = $path;
            }
        }
        self::assertSame([], $hits);
    }
}
