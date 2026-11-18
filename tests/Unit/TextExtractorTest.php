<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\Pdf\PdfError;
use Blue\Core\Pdf\SimpleDocument;
use Blue\Core\Pdf\TextExtractor;
use Blue\Core\Pdf\Toolkit;

/**
 * Phase 9D-3 (DEC-99): text out of PDF pages for the library search — our own Thai PDFs (Identity-H + ToUnicode), a
 * LibreOffice PDF whose Thai clusters use /ActualText, a ReportLab PDF with a standard font (WinAnsi), CMaps with ranges,
 * scanned (image-only) pages and broken files.
 */
final class TextExtractorTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/library/' . $name);
    }

    public function testOwnThaiPdfGivesTextPerPage(): void
    {
        $d = (new SimpleDocument('ท้ายกระดาษ'))->title('คู่มือความปลอดภัย', 'Safety Manual')->text('พนักงานต้องสวมหมวกนิรภัย Hard hats are required.');
        for ($i = 0; $i < 70; $i++) {
            $d->text('บรรทัดที่ ' . $i);
        }
        $d->text('คำสุดท้าย xylophone');
        $pages = TextExtractor::pages($d->output());
        self::assertCount(2, $pages);
        self::assertStringContainsString('คู่มือความปลอดภัย', $pages[0]);
        self::assertStringContainsString('พนักงานต้องสวมหมวกนิรภัย Hard hats are required.', $pages[0]);
        self::assertStringNotContainsString('xylophone', $pages[0]);
        self::assertStringContainsString('คำสุดท้าย xylophone', $pages[1]);
        self::assertCount(1, TextExtractor::pages($d->output(), 1)); // page cap
    }

    public function testLibreOfficeThaiUsesActualTextAndReportLabUsesWinAnsi(): void
    {
        $lo = TextExtractor::pages(self::fixture('lo.pdf'));
        self::assertCount(2, $lo);
        $flat = static fn (string $s): string => (string) preg_replace('/\s+/u', '', $s);
        // the cluster glyphs (นั, มี, รั) carry /ActualText — without it the text comes out with stray or missing marks
        self::assertStringContainsString('พนักงานมีสิทธิลาพักร้อนตามอายุงาน', $flat($lo[0]));
        self::assertStringContainsString('Annual leave policy.', $lo[0]);
        self::assertStringContainsString('การลาป่วยต้องแนบใบรับรองแพทย์', $flat($lo[1]));
        $rl = TextExtractor::pages(self::fixture('rl.pdf'));
        self::assertSame(["Forklift operation manual\nCheck brakes daily (fees & costs)", 'Page two: emergency stop'], $rl);
    }

    public function testCmapRangesAndScannedAndBrokenFiles(): void
    {
        $cmap = TextExtractor::parseCmap("1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n2 beginbfrange\n<0010> <0012> <0E01>\n<0020> <0021> [<0041> <00420043>]\nendbfrange\n1 beginbfchar\n<0005> <0E31>\nendbfchar");
        self::assertSame("\u{0E01}", $cmap['map']["\x00\x10"]);
        self::assertSame("\u{0E03}", $cmap['map']["\x00\x12"]);
        self::assertSame('BC', $cmap['map']["\x00\x21"]);
        self::assertSame("\u{0E31}", $cmap['map']["\x00\x05"]);
        self::assertSame(2, strlen($cmap['ranges'][0][0]));
        // a page that is only a picture (a scan) has no text — there is no OCR
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        $scan = Toolkit::imagesToPdf([$png], 'A4', 10)['bytes'];
        self::assertSame([''], TextExtractor::pages($scan));
        $this->expectException(PdfError::class);
        TextExtractor::pages("%PDF-1.4\nthis is not a pdf at all");
    }

    public function testTidyCollapsesWhitespaceAndCaps(): void
    {
        self::assertSame("a b\nc", TextExtractor::tidy("  a \t  b \n\n  c  \x01"));
        self::assertSame(TextExtractor::MAX_PAGE_CHARS, mb_strlen(TextExtractor::tidy(str_repeat('ก', TextExtractor::MAX_PAGE_CHARS + 50))));
    }
}
