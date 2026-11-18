<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\Backup\BackupCipher;
use Blue\Core\Backup\KeyMismatch;
use Blue\Core\Files\Xlsx;
use Blue\Core\Files\ZipStream;
use Blue\Core\Http\Request;
use Blue\Core\Integrations\IntegrationAuth;
use Blue\Core\Payments\BankFile;
use Blue\Core\Payments\PaymentBatches;
use Blue\Core\Support\Crypto;
use Blue\Core\Support\Money;
use RuntimeException;

/** Phase 3C building blocks: exact money, streaming ZIP, encrypted backup container, the standard bank file, HMAC signing. */
final class MoneyFilesCipherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/blue-3c-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testMoneyIsExactWithoutFloats(): void
    {
        self::assertSame(15005000, Money::parse('1,500.50'));
        self::assertNull(Money::parse('1.005'), 'three decimals refused for baht');
        self::assertSame(10050, Money::parse('1.005', 4));
        self::assertNull(Money::parse('1e5'));
        self::assertNull(Money::parse('12.5', 0));
        self::assertSame(120000, Money::parse('12', 0));
        self::assertSame('1500.5000', Money::toDb(Money::fromDb('1500.5000')));
        self::assertSame('-0.0100', Money::toDb(-100));
        // 0.1 + 0.2 problem does not exist
        self::assertSame('0.30', Money::format((int) Money::parse('0.1') + (int) Money::parse('0.2')));
        self::assertSame('1,234,567.89', Money::format((int) Money::parse('1234567.89')));
        self::assertSame('1.01', Money::format(10050), 'half up when shown with 2 decimals');
        self::assertTrue(Money::hasAtMostDecimals(15005000, 2));
        self::assertFalse(Money::hasAtMostDecimals(10050, 2));
    }

    public function testStreamingZipRoundTripAndChecks(): void
    {
        file_put_contents($this->dir . '/big.bin', random_bytes(3 * 1024 * 1024 + 7));
        $z = ZipStream::create($this->dir . '/a.zip');
        $z->addString('manifest.json', '{"ok":true}');
        $z->addFile('files/ab/big', $this->dir . '/big.bin');
        $z->finish();
        $r = ZipStream::open($this->dir . '/a.zip');
        self::assertSame(['manifest.json', 'files/ab/big'], $r->names());
        self::assertSame('{"ok":true}', $r->read('manifest.json'));
        self::assertSame(hash_file('sha256', $this->dir . '/big.bin'), $r->extractTo('files/ab/big', $this->dir . '/out.bin'));
        if (class_exists(\ZipArchive::class)) {
            $za = new \ZipArchive();
            self::assertTrue($za->open($this->dir . '/a.zip') === true, 'a standard reader opens it');
            $za->close();
        }
        // a changed byte inside an entry is caught by the CRC
        $bytes = (string) file_get_contents($this->dir . '/a.zip');
        $pos = strpos($bytes, '{"ok":true}');
        $bytes[(int) $pos + 2] = 'X';
        file_put_contents($this->dir . '/bad.zip', $bytes);
        $this->expectException(RuntimeException::class);
        ZipStream::open($this->dir . '/bad.zip')->read('manifest.json');
    }

    public function testZipRefusesNamesThatEscape(): void
    {
        $z = ZipStream::create($this->dir . '/evil.zip');
        $z->addString('../x.php', '<?php');
        $z->finish();
        $this->expectException(RuntimeException::class);
        ZipStream::open($this->dir . '/evil.zip');
    }

    public function testBackupContainerEncryptsAuthenticatesAndNeedsTheSameKey(): void
    {
        foreach (Crypto::backends() as $backend) {
            $plain = random_bytes(2 * 1048576 + 100) . 'SECRET-MARKER';
            file_put_contents($this->dir . '/p', $plain);
            $c = new BackupCipher(str_repeat('a', 32), $backend);
            $c->encryptFile($this->dir . '/p', $this->dir . '/e', ['backup_id' => 'X']);
            self::assertStringNotContainsString('SECRET-MARKER', (string) file_get_contents($this->dir . '/e'));
            self::assertSame($c->fingerprint(), BackupCipher::header($this->dir . '/e')['key'], 'the header tells which key, not the key');
            $c->decryptFile($this->dir . '/e', $this->dir . '/d');
            self::assertSame(hash('sha256', $plain), hash_file('sha256', $this->dir . '/d'), $backend);

            $enc = (string) file_get_contents($this->dir . '/e');
            // truncated (last chunk removed) → refused
            file_put_contents($this->dir . '/t', substr($enc, 0, strlen($enc) - 200));
            try {
                $c->decryptFile($this->dir . '/t', $this->dir . '/d');
                self::fail('truncated backup opened');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
            // one flipped byte → authentication fails
            $flip = $enc;
            $flip[strlen($flip) - 50] = chr(ord($flip[strlen($flip) - 50]) ^ 1);
            file_put_contents($this->dir . '/f', $flip);
            try {
                $c->decryptFile($this->dir . '/f', $this->dir . '/d');
                self::fail('changed backup opened');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
            // another APP_KEY → key mismatch, nothing decrypted
            try {
                (new BackupCipher(str_repeat('b', 32), $backend))->decryptFile($this->dir . '/e', $this->dir . '/d');
                self::fail('opened with another key');
            } catch (KeyMismatch $e) {
                self::assertSame($c->fingerprint(), $e->fileFingerprint);
            }
        }
    }

    public function testStandardBankFileKeepsLeadingZerosAndPlainText(): void
    {
        $items = [
            ['seq' => 1, 'payee_code' => 'E001', 'payee_name' => 'นาย ก (DEMO)', 'site' => 'HQ', 'bank' => 'BANK-A', 'account' => '0012345678', 'amount' => (int) Money::parse('1500.50')],
            ['seq' => 2, 'payee_code' => 'E002', 'payee_name' => '=HYPERLINK("http://x")', 'site' => '', 'bank' => 'BANK-B', 'account' => '0000098765', 'amount' => (int) Money::parse('20')],
        ];
        $bytes = BankFile::standard(['company' => 'DEMO1', 'batch_no' => 'PAY-202612-0001', 'payment_type' => 'claim', 'period' => '2026-12', 'pay_date' => '2026-12-25',
            'prepared' => 'A', 'approved' => 'B', 'generated_at' => 'now', 'currency' => 'THB'], $items);
        $read = BankFile::readTotals($bytes);
        self::assertSame(2, $read['count']);
        self::assertSame((int) Money::parse('1520.50'), $read['total']);
        self::assertSame('0012345678', $read['items'][0]['account'], 'account stays text with its leading zeros');
        $rows = Xlsx::read($bytes, BankFile::SHEET);
        self::assertSame(BankFile::COLUMNS, $rows[10]);
        self::assertSame('=HYPERLINK("http://x")', $rows[12][2], 'stored as text, never as a formula');
        self::assertStringNotContainsString('<f>', (string) \Blue\Core\Files\Zip::open($bytes)->get('xl/worksheets/sheet1.xml'));
        self::assertSame('1500.50', $rows[11][6]);
    }

    public function testAccountAndPayeeNormalization(): void
    {
        self::assertSame('0012345678', PaymentBatches::normalizeAccount('001-2-34567-8'));
        self::assertNull(PaymentBatches::normalizeAccount('1.23457E+11'), 'Excel scientific notation refused');
        self::assertNull(PaymentBatches::normalizeAccount('1234'));
        self::assertSame('vendor', PaymentBatches::payeeType('ผู้ขาย'));
        self::assertNull(PaymentBatches::payeeType('boss'));
    }

    public function testHmacSignatureCoversMethodPathTimeAndNonce(): void
    {
        $h = IntegrationAuth::signHeaders('rk_0011223344556677', 'secret', 'POST', '/m2m/scheduler/run', 1_800_000_000);
        self::assertSame(IntegrationAuth::signature('secret', 'POST', '/m2m/scheduler/run', $h['X-Blue-Timestamp'], $h['X-Blue-Nonce']), $h['X-Blue-Signature']);
        self::assertNotSame($h['X-Blue-Signature'], IntegrationAuth::signature('secret', 'GET', '/m2m/scheduler/run', $h['X-Blue-Timestamp'], $h['X-Blue-Nonce']));
        self::assertNotSame($h['X-Blue-Signature'], IntegrationAuth::signature('secret', 'POST', '/m2m/scheduler/run', (string) (1_800_000_001), $h['X-Blue-Nonce']));
        self::assertSame('/x?a=1', IntegrationAuth::pathWithQuery(new Request('GET', '/x', ['a' => '1'])));
    }
}
