<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\Error\BusinessException;
use Blue\Core\Files\Csv;
use Blue\Core\Files\FileType;
use Blue\Core\Files\PdfInspector;
use Blue\Core\Files\Xlsx;
use Blue\Core\Files\Zip;
use Blue\Core\Support\HtmlSanitizer;
use Blue\Core\Support\LineDiff;

/** File helpers written without vendor libraries (6.10, 6.30.12, S01 inspection). */
final class FilesTest extends TestCase
{
    public function testXlsxRoundTripKeepsTextAndLeadingZeros(): void
    {
        $bytes = Xlsx::write(['ข้อมูล' => ['headers' => ['บัญชี', 'ชื่อ', 'ยอด'], 'rows' => [['0012345678', 'ไทย <&>', '1500.50'], ['=1+1', null, '7']], 'numeric' => [2]]]);
        self::assertSame('xlsx', FileType::sniff($bytes)['type']);
        self::assertSame([['บัญชี', 'ชื่อ', 'ยอด'], ['0012345678', 'ไทย <&>', '1500.50'], ['=1+1', '', '7']], Xlsx::read($bytes));
        if (class_exists(\ZipArchive::class)) {
            $f = tempnam(sys_get_temp_dir(), 'x');
            file_put_contents((string) $f, $bytes);
            $z = new \ZipArchive();
            self::assertTrue($z->open((string) $f) === true, 'a standard ZIP reader opens our file');
            self::assertNotFalse($z->getFromName('xl/worksheets/sheet1.xml'));
            $z->close();
            unlink((string) $f);
        }
    }

    public function testZipRefusesTraversalAndBombs(): void
    {
        $evil = Zip::build(['../evil.php' => 'x']);
        $this->expectException(BusinessException::class);
        Zip::open($evil);
    }

    public function testZipBombRatioIsRefused(): void
    {
        $bomb = Zip::build(['a.xml' => str_repeat('A', 3 * 1024 * 1024)]);
        try {
            Zip::open($bomb);
            self::fail('extreme compression ratio accepted');
        } catch (BusinessException $e) {
            self::assertSame('file.error.archive_too_large', $e->messageKey);
        }
    }

    public function testCsvGuardBomAndThaiLegacyEncoding(): void
    {
        $csv = Csv::write(['a'], [['=HYPERLINK("x")'], ['+1'], ['-2'], ['@SUM(A1)'], ['ok']]);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertSame([["a"], ["'=HYPERLINK(\"x\")"], ["'+1"], ["'-2"], ["'@SUM(A1)"], ['ok']], Csv::read($csv));
        $tis = (string) iconv('UTF-8', 'CP874', "วันที่;ชื่อ\n2026-12-05;วันพ่อ\n");
        self::assertSame([['วันที่', 'ชื่อ'], ['2026-12-05', 'วันพ่อ']], Csv::read($tis), 'Windows-874 + semicolon detected');
    }

    public function testPdfInspectionFindsPagesAndActiveContentInsideObjectStreams(): void
    {
        $objstm = gzcompress('<</Type/Catalog/OpenAction<</S/JavaScript/JS(x)>>>>');
        $pdf = "%PDF-1.7\n5 0 obj<</Type/ObjStm/N 1/First 4/Filter/FlateDecode/Length " . strlen($objstm) . ">>stream\n{$objstm}\nendstream\nendobj\n"
            . "2 0 obj<</Type/Pages/Count 12/Kids[]>>endobj\n%%EOF";
        $info = PdfInspector::inspect($pdf);
        self::assertSame('1.7', $info['version']);
        self::assertSame(12, $info['pages']);
        self::assertTrue($info['active'], 'JavaScript hidden in a compressed object stream is found');
        self::assertFalse(PdfInspector::inspect("%PDF-1.4\n1 0 obj<</Type/Page>>endobj")['active']);
    }

    public function testSanitizerKeepsStructureAndDropsActiveContent(): void
    {
        $in = '<h1 onclick="x()" style="color:red">หัวเรื่อง</h1><script>alert(1)</script><p class="text-center evil">ย่อหน้า <a href="javascript:x">ลิงก์</a> {{name}}</p><img src=x onerror=alert(1)><table><tr><td colspan="2">A</td></tr></table>';
        self::assertSame('<h1>หัวเรื่อง</h1><p class="text-center">ย่อหน้า ลิงก์ {{name}}</p><table><tr><td colspan="2">A</td></tr></table>', HtmlSanitizer::clean($in));
    }

    public function testLineDiff(): void
    {
        $d = LineDiff::compare('<p>a</p><p>b</p>', '<p>a</p><p>c</p>');
        self::assertSame([['=', '<p>a</p>'], ['-', '<p>b</p>'], ['+', '<p>c</p>']], $d);
    }
}
