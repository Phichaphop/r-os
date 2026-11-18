<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\Pdf\Dict;
use Blue\Core\Pdf\Filters;
use Blue\Core\Pdf\Name;
use Blue\Core\Pdf\Parser;
use Blue\Core\Pdf\PdfError;
use Blue\Core\Pdf\Reader;
use Blue\Core\Pdf\Security;
use Blue\Core\Pdf\Stream;
use Blue\Core\Pdf\Toolkit;
use Blue\Core\Pdf\TrueTypeFont;
use Blue\Modules\Draw\DrawEngine;

/** Phase 3U-C building blocks: the pure-PHP PDF engine (S01) and the verifiable draw (M29). */
final class UtilitiesCUnitTest extends TestCase
{
    private static function fx(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/' . $name);
    }

    /** Independent check with qpdf when it is installed (CI without it skips this part). */
    private static function qpdfOk(string $bytes, string $password = ''): ?bool
    {
        $bin = trim((string) shell_exec('command -v qpdf 2>/dev/null'));
        if ($bin === '') {
            return null;
        }
        $f = tempnam(sys_get_temp_dir(), 'rosq');
        file_put_contents((string) $f, $bytes);
        exec(escapeshellarg($bin) . ' --check ' . ($password !== '' ? '--password=' . escapeshellarg($password) . ' ' : '') . escapeshellarg((string) $f) . ' 2>&1', $out, $code);
        @unlink((string) $f);
        return $code === 0;
    }

    public function testParserHandlesSyntaxAndRefusesHostileNesting(): void
    {
        $p = new Parser('<< /Type /Page /Kids [1 0 R 2 0 R] /N 3.5 /S (a\\(b\\)\\101\\n) /H <4142> /J#61 true /X null >>');
        $d = $p->value();
        self::assertInstanceOf(Dict::class, $d);
        self::assertSame('Page', $d->name('Type'));
        self::assertSame(2, $d->get('Kids')[1]->num);
        self::assertSame(3.5, $d->get('N'));
        self::assertSame("a(b)A\n", $d->get('S')->bytes);
        self::assertSame('AB', $d->get('H')->bytes);
        self::assertTrue($d->get('Ja'), '#61 decodes to a');
        $this->expectException(PdfError::class);
        (new Parser(str_repeat('[', 500)))->value();
    }

    public function testFiltersDecodeAndStopBombs(): void
    {
        $raw = str_repeat('ABCD', 1000);
        self::assertSame($raw, Filters::decode(new Stream(new Dict(['Filter' => new Name('FlateDecode')]), gzcompress($raw)), 100000));
        self::assertSame('Hello', Filters::decode(new Stream(new Dict(['Filter' => new Name('ASCIIHexDecode')]), '48656C6C6F>'), 100));
        self::assertSame('Man ', Filters::decode(new Stream(new Dict(['Filter' => new Name('ASCII85Decode')]), '9jqo^~>'), 100));
        // PNG predictor (Up) on 2 rows of 3 bytes
        $pred = "\x00\x01\x02\x03\x02\x01\x01\x01";
        self::assertSame("\x01\x02\x03\x02\x03\x04", Filters::decode(new Stream(new Dict(['Filter' => new Name('FlateDecode'), 'DecodeParms' => new Dict(['Predictor' => 12, 'Columns' => 3])]), gzcompress($pred)), 100));
        try {
            Filters::decode(new Stream(new Dict(['Filter' => new Name('FlateDecode')]), gzcompress(str_repeat("\0", 5_000_000))), 1_000_000);
            self::fail('bomb not stopped');
        } catch (PdfError $e) {
            self::assertSame('pdf.error.too_big', $e->messageKey);
        }
    }

    public function testReaderOpensEveryVariantOnlyWithThePassword(): void
    {
        foreach (['a.pdf' => '', 'a_objstm.pdf' => ''] as $f => $pw) {
            $r = new Reader(self::fx($f), $pw);
            self::assertCount(3, $r->pages(), $f);
            self::assertFalse($r->repaired, $f);
        }
        foreach (['a_aes256.pdf' => 'AES-256', 'a_aes128.pdf' => 'AES-128', 'a_rc4.pdf' => 'RC4 128-bit', 'a_rc4_40.pdf' => 'RC4 40-bit'] as $f => $method) {
            foreach (['user1', 'owner1'] as $pw) {
                $r = new Reader(self::fx($f), $pw);
                self::assertSame($method, $r->security?->method(), $f);
                self::assertCount(3, $r->pages());
                self::assertSame('Alpha title', $r->info()?->get('Title')->text(), "{$f} strings decrypted with {$pw}");
            }
            try {
                new Reader(self::fx($f), 'wrong');
                self::fail("{$f} opened with a wrong password");
            } catch (PdfError $e) {
                self::assertSame('pdf.error.password', $e->messageKey);
            }
        }
        foreach (['a_broken.pdf', 'a_objstm_broken.pdf'] as $f) {
            $r = new Reader(self::fx($f));
            self::assertTrue($r->repaired, $f);
            self::assertCount(3, $r->pages());
            self::assertContains('pdf.warn.repaired', $r->warnings());
        }
        self::assertContains('pdf.warn.javascript', (new Reader(self::fx('js.pdf')))->warnings());
        foreach (['', 'not a pdf', "%PDF-1.4\n garbage garbage", random_bytes(3000)] as $junk) {
            try {
                new Reader($junk);
                self::fail('junk accepted');
            } catch (PdfError $e) {
                self::assertContains($e->messageKey, ['pdf.error.not_pdf', 'pdf.error.corrupt']);
            }
        }
    }

    public function testPageOperationsProduceValidFilesAndKeepSources(): void
    {
        $a = self::fx('a.pdf');
        $b = self::fx('b.pdf');
        $hash = hash('sha256', $a);
        $m = Toolkit::merge([[$a, ''], [$b, ''], [self::fx('a_objstm.pdf'), '']]);
        self::assertSame(8, $m['pages']);
        self::assertCount(8, (new Reader($m['bytes']))->pages());
        self::assertNotFalse(self::qpdfOk($m['bytes']));
        self::assertSame($hash, hash('sha256', $a), 'source unchanged');

        $s = Toolkit::split($a, '', 'every');
        self::assertSame(['p1', 'p2', 'p3'], array_column($s['parts'], 'name'));
        self::assertSame(['p1-2', 'p3'], array_column(Toolkit::split($a, '', 'ranges', '1-2, 3')['parts'], 'name'));
        self::assertSame([2, 1], array_column(Toolkit::split($a, '', 'chunk', '', 2)['parts'], 'pages'));
        self::assertSame(4, Toolkit::extract($a, '', '3,1-2,3')['pages'], 'repeats allowed');

        $o = Toolkit::organize($a, '', [[2, 0], [0, 90]]);
        $pages = (new Reader($o['bytes']))->pages();
        self::assertCount(2, $pages);
        self::assertSame(90, $pages[1][1]->get('Rotate'));

        $r = (new Reader(Toolkit::rotate($a, '', 270, '2-')['bytes']))->pages();
        self::assertSame([0, 270, 270], array_map(static fn (array $p): mixed => $p[1]->get('Rotate'), $r));

        self::assertSame([0, 1, 2], Toolkit::pageList('', 3));
        self::assertSame([1, 2, 0], Toolkit::pageList('2-, 1', 3));
        foreach (['0', '4', '3-2', 'a', '1;2'] as $bad) {
            try {
                Toolkit::pageList($bad, 3);
                self::fail("range {$bad} accepted");
            } catch (PdfError $e) {
                self::assertSame('pdf.error.page_range', $e->messageKey);
            }
        }
    }

    public function testProtectUnlockMetadataRepairCompress(): void
    {
        $a = self::fx('a.pdf');
        $p = Toolkit::protect($a, '', 'รหัส-ทดสอบ9', '', Security::PERM_PRINT);
        self::assertStringNotContainsString('Alpha', $p['bytes'], 'content encrypted');
        $r = new Reader($p['bytes'], 'รหัส-ทดสอบ9');
        self::assertSame('AES-256', $r->security?->method());
        self::assertSame(6, $r->resolve($r->trailer->get('Encrypt'))->get('R'), 'AES-256 revision 6');
        self::assertNotFalse(self::qpdfOk($p['bytes'], 'รหัส-ทดสอบ9'));
        try {
            new Reader($p['bytes'], 'x');
            self::fail('opened without the password');
        } catch (PdfError $e) {
            self::assertSame('pdf.error.password', $e->messageKey);
        }
        $this->assertThrowsKey('pdf.error.password_short', static fn () => Toolkit::protect($a, '', '123', '', 0));

        $u = Toolkit::unlock(self::fx('a_aes128.pdf'), 'user1');
        self::assertFalse((new Reader($u['bytes']))->encrypted);
        $this->assertThrowsKey('pdf.error.not_encrypted', static fn () => Toolkit::unlock($a, ''));

        self::assertSame('Secret Author', Toolkit::info($a)['metadata']['Author']);
        $clean = Toolkit::stripMetadata($a, '');
        self::assertSame(['Producer' => 'Blue OS PDF Toolkit'], Toolkit::info($clean['bytes'])['metadata']);
        self::assertStringNotContainsString('Secret Author', $clean['bytes']);

        $fixed = Toolkit::repair(self::fx('a_broken.pdf'), '');
        self::assertSame('pdf.note.repaired', $fixed['notes'][0][0]);
        self::assertFalse((new Reader($fixed['bytes']))->repaired, 'the output is sound');

        $c = Toolkit::compress($a, '', true);
        self::assertSame('pdf.note.size', $c['notes'][0][0]);
        self::assertSame(3, $c['pages']);
    }

    public function testStampingWritesSearchableThaiAndConvertsPictures(): void
    {
        $w = Toolkit::watermark(self::fx('a.pdf'), '', 'ลับเฉพาะ', 60, 0.3, 45, '');
        self::assertSame(3, $w['pages']);
        self::assertNotFalse(self::qpdfOk($w['bytes']));
        $n = Toolkit::pageNumbers(self::fx('b.pdf'), '', 'หน้า {n} จาก {total}', 'bottom-right', 1, 10, '');
        $txt = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
        if ($txt !== '') {
            $f = tempnam(sys_get_temp_dir(), 'rost');
            file_put_contents((string) $f, $n['bytes']);
            $text = (string) shell_exec(escapeshellarg($txt) . ' ' . escapeshellarg((string) $f) . ' - 2>/dev/null');
            @unlink((string) $f);
            self::assertStringContainsString('หน้า 2 จาก 2', $text, 'ToUnicode makes the Thai text extractable');
        }
        $this->assertThrowsKey('pdf.error.font_chars', static fn () => Toolkit::watermark(self::fx('a.pdf'), '', '漢字', 40, 0.3, 45, ''));
        $this->assertThrowsKey('pdf.error.position', static fn () => Toolkit::pageNumbers(self::fx('a.pdf'), '', '{n}', 'middle', 1, 10, ''));

        $font = TrueTypeFont::thai();
        $plain = array_column($font->shape('ที'), 0);
        $raised = array_column($font->shape('ที่'), 0);
        $low = array_column($font->shape('ท่'), 0);
        self::assertNotSame($low[1], $raised[2], 'a tone mark above an upper vowel uses the raised (.small) glyph');
        self::assertSame($plain, array_slice($raised, 0, 2));
        self::assertSame(170, $font->shape('ป่')[1][2], 'marks over ป are shifted clear of the tall stroke');

        $img = Toolkit::imagesToPdf([self::fx('img.jpg'), self::fx('pal.png'), self::fx('alpha.png')], 'A4', 20);
        $pages = (new Reader($img['bytes']))->pages();
        self::assertCount(3, $pages);
        self::assertSame([0, 0, 841.89, 595.28], $pages[0][1]->get('MediaBox'), 'landscape picture → landscape A4');
        self::assertNotFalse(self::qpdfOk($img['bytes']));
    }

    public function testDrawIsDeterministicFairAndVerifiable(): void
    {
        $seed = str_repeat("\x07", 32);
        $cands = range(1, 50);
        $a = DrawEngine::pick($seed, 1, $cands, 10);
        self::assertSame($a, DrawEngine::pick($seed, 1, $cands, 10), 'same seed + round → same winners');
        self::assertCount(10, array_unique($a));
        self::assertNotSame($a, DrawEngine::pick($seed, 2, $cands, 10), 'another round → another draw');
        self::assertSame(array_values(array_intersect($a, $cands)), $a);
        self::assertSame(hash('sha256', $seed), DrawEngine::commitment($seed));
        self::assertSame([3], DrawEngine::pick($seed, 9, [3], 5), 'never more than the candidates');
        // rough uniformity: 6 candidates × 6000 single picks
        $hits = array_fill(1, 6, 0);
        for ($r = 1; $r <= 6000; $r++) {
            $hits[DrawEngine::pick(random_bytes(32), $r, range(1, 6), 1)[0]]++;
        }
        $chi = array_sum(array_map(static fn (int $h): float => (($h - 1000) ** 2) / 1000, $hits));
        self::assertLessThan(25.0, $chi, 'chi-square (5 d.f.) far below an implausible value');
        $s1 = DrawEngine::snapshotHash([['position' => 1, 'code' => 'E1', 'name' => 'ก', 'grp' => null]], [['sort' => 1, 'name' => 'x', 'quantity' => 1]], true);
        self::assertNotSame($s1, DrawEngine::snapshotHash([['position' => 1, 'code' => 'E1', 'name' => 'ข', 'grp' => null]], [['sort' => 1, 'name' => 'x', 'quantity' => 1]], true));
    }

    private function assertThrowsKey(string $key, callable $fn): void
    {
        try {
            $fn();
            self::fail("expected {$key}");
        } catch (PdfError $e) {
            self::assertSame($key, $e->messageKey);
        }
    }
}
