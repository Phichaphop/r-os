<?php

declare(strict_types=1);

namespace Blue\Core\Files;

use Blue\Core\Error\BusinessException;

/**
 * Small Excel (.xlsx) writer and reader for import/export (6.10) — no vendor library at runtime.
 *
 * Writer: one or more sheets, every cell written as TEXT unless it is a real number column, so account numbers,
 * codes with leading zeros and long IDs never get mangled (DEC-31 lesson). Header row bold + frozen.
 * Strings in an .xlsx are never evaluated as formulas, so no injection guard is needed here (CSV has one).
 *
 * Reader: first sheet (or a named one) → rows of strings. Shared strings, inline strings, numbers, booleans.
 * Dates stay as Excel serial numbers; importers convert them (Xlsx::serialToDate).
 */
final class Xlsx
{
    /**
     * Optional per sheet: numeric (column indexes written as numbers), money (numbers shown with 2 decimals "0.00"),
     * bold_rows (row indexes in rows[] drawn like the header), freeze (default true: the header row stays visible).
     *
     * @param array<string, array{headers: list<string>, rows: list<list<scalar|null>>, numeric?: list<int>, money?: list<int>, bold_rows?: list<int>, freeze?: bool, widths?: list<int>}> $sheets
     */
    public static function write(array $sheets, string $title = 'Blue OS export'): string
    {
        $files = [];
        $sheetEntries = '';
        $rels = '';
        $ct = '';
        $i = 0;
        foreach ($sheets as $name => $sheet) {
            $i++;
            $files["xl/worksheets/sheet{$i}.xml"] = self::sheetXml($sheet);
            $sheetEntries .= '<sheet name="' . self::x(self::sheetName((string) $name)) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $n = $i + 1;
        $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $all = [
            '[Content_Types].xml' => $head . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
                . $ct . '</Types>',
            '_rels/.rels' => $head . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
            'docProps/core.xml' => $head . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/">'
                . '<dc:title>' . self::x($title) . '</dc:title><dc:creator>Blue OS</dc:creator></cp:coreProperties>',
            'xl/workbook.xml' => $head . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets>' . $sheetEntries . '</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => $head . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>',
            'xl/styles.xml' => $head . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<numFmts count="1"><numFmt numFmtId="164" formatCode="@"/></numFmts>'
                . '<fonts count="2"><font><sz val="11"/><name val="Tahoma"/></font><font><b/><sz val="11"/><name val="Tahoma"/></font></fonts>'
                . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8EEF6"/><bgColor indexed="64"/></patternFill></fill></fills>'
                . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="4"><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
                . '<xf numFmtId="164" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/>'
                . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                . '<xf numFmtId="2" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
                . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        ];
        return Zip::build($all + $files);
    }

    /** @param array{headers: list<string>, rows: list<list<scalar|null>>, numeric?: list<int>, money?: list<int>, bold_rows?: list<int>, freeze?: bool, widths?: list<int>} $sheet */
    private static function sheetXml(array $sheet): string
    {
        $numeric = array_flip($sheet['numeric'] ?? []);
        $money = array_flip($sheet['money'] ?? []);
        $bold = array_flip($sheet['bold_rows'] ?? []);
        $cols = '';
        foreach ($sheet['headers'] as $c => $h) {
            $w = $sheet['widths'][$c] ?? max(10, min(60, mb_strlen($h) + 4));
            $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . (($sheet['freeze'] ?? true) ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' : '')
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '') . '<sheetData>';
        $xml .= self::rowXml(1, $sheet['headers'], [], 1);
        foreach ($sheet['rows'] as $i => $row) {
            $xml .= isset($bold[$i]) ? self::rowXml($i + 2, $row, [], 1) : self::rowXml($i + 2, $row, $numeric, 0, $money);
        }
        return $xml . '</sheetData></worksheet>';
    }

    /**
     * @param list<scalar|null> $row
     * @param array<int, int> $numeric
     * @param array<int, int> $money
     */
    private static function rowXml(int $r, array $row, array $numeric, int $style, array $money = []): string
    {
        $out = '<row r="' . $r . '">';
        foreach (array_values($row) as $c => $v) {
            $ref = self::col($c) . $r;
            if ($v === null || $v === '') {
                continue;
            }
            if (isset($money[$c]) && is_numeric($v)) {
                $out .= '<c r="' . $ref . '" s="3"><v>' . self::x((string) $v) . '</v></c>';
            } elseif (isset($numeric[$c]) && is_numeric($v)) {
                $out .= '<c r="' . $ref . '" s="2"><v>' . self::x((string) $v) . '</v></c>';
            } else {
                $out .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::x(self::clean((string) $v)) . '</t></is></c>';
            }
        }
        return $out . '</row>';
    }

    // ---- reading --------------------------------------------------------------------------------

    /**
     * @return list<list<string>> rows (trailing empty rows removed); the first row is usually the header
     */
    public static function read(string $bytes, ?string $sheetName = null, int $maxRows = 20000): array
    {
        $zip = Zip::open($bytes);
        $prev = libxml_use_internal_errors(true);
        try {
            $wb = self::xml((string) $zip->get('xl/workbook.xml'));
            $rels = self::xml((string) $zip->get('xl/_rels/workbook.xml.rels'));
            $target = null;
            foreach ($wb->sheets->sheet ?? [] as $s) {
                if ($sheetName === null || (string) $s['name'] === $sheetName) {
                    $rid = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                    foreach ($rels->Relationship as $rel) {
                        if ((string) $rel['Id'] === $rid) {
                            $target = ltrim(str_replace('/xl/', '', (string) $rel['Target']), '/');
                        }
                    }
                    break;
                }
            }
            if ($target === null) {
                throw new BusinessException('import.error.no_sheet', [], 422);
            }
            $shared = [];
            if ($zip->has('xl/sharedStrings.xml')) {
                foreach (self::xml((string) $zip->get('xl/sharedStrings.xml'))->si as $si) {
                    $shared[] = self::siText($si);
                }
            }
            $sheet = self::xml((string) $zip->get('xl/' . $target));
            $rows = [];
            foreach ($sheet->sheetData->row ?? [] as $row) {
                $rIndex = (int) $row['r'] > 0 ? (int) $row['r'] - 1 : count($rows);
                if ($rIndex >= $maxRows) {
                    throw new BusinessException('import.error.too_many_rows', ['max' => $maxRows], 422);
                }
                $cells = [];
                foreach ($row->c as $c) {
                    $col = preg_match('/^([A-Z]+)/', (string) $c['r'], $m) === 1 ? self::colIndex($m[1]) : count($cells);
                    $type = (string) $c['t'];
                    $val = match ($type) {
                        's' => $shared[(int) $c->v] ?? '',
                        'inlineStr' => self::siText($c->is),
                        'b' => ((string) $c->v) === '1' ? 'TRUE' : 'FALSE',
                        default => (string) $c->v,
                    };
                    $cells[$col] = $val;
                }
                $line = [];
                $max = $cells === [] ? -1 : max(array_keys($cells));
                for ($i = 0; $i <= $max; $i++) {
                    $line[] = trim((string) ($cells[$i] ?? ''));
                }
                $rows[$rIndex] = $line;
            }
            if ($rows === []) {
                return [];
            }
            $out = [];
            for ($i = 0; $i <= max(array_keys($rows)); $i++) {
                $out[] = $rows[$i] ?? [];
            }
            while ($out !== [] && implode('', end($out)) === '') {
                array_pop($out);
            }
            return $out;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /** Excel serial date (1900 system) → Y-m-d, or null. */
    public static function serialToDate(string $v): ?string
    {
        if (!preg_match('/^\d{4,6}(\.\d+)?$/', $v)) {
            return null;
        }
        $days = (int) floor((float) $v);
        if ($days < 1 || $days > 2958465) {
            return null;
        }
        return (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC')))->modify("+{$days} days")->format('Y-m-d');
    }

    private static function xml(string $s): \SimpleXMLElement
    {
        if ($s === '' || stripos($s, '<!DOCTYPE') !== false) {
            throw new BusinessException('file.error.archive_corrupt', [], 422); // no DTDs → no XXE / entity expansion
        }
        $x = simplexml_load_string($s, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if ($x === false) {
            throw new BusinessException('file.error.archive_corrupt', [], 422);
        }
        return $x;
    }

    private static function siText(?\SimpleXMLElement $si): string
    {
        if ($si === null) {
            return '';
        }
        if (isset($si->t)) {
            return (string) $si->t;
        }
        $s = '';
        foreach ($si->r ?? [] as $run) {
            $s .= (string) $run->t;
        }
        return $s;
    }

    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    private static function sheetName(string $n): string
    {
        $n = (string) preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $n);
        return mb_substr($n === '' ? 'Sheet1' : $n, 0, 31);
    }

    /** Removes characters XML 1.0 cannot carry. */
    private static function clean(string $s): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
    }

    private static function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
