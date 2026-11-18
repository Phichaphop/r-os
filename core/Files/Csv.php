<?php

declare(strict_types=1);

namespace Blue\Core\Files;

/**
 * CSV for export/import (6.10). Export: UTF-8 with BOM (Excel shows Thai correctly) and the formula-injection guard
 * (6.30.12). Import: BOM / Windows-874 aware, delimiter detected from the header line (, ; or tab).
 */
final class Csv
{
    /**
     * @param list<string> $headers
     * @param iterable<list<scalar|null>> $rows
     */
    public static function write(array $headers, iterable $rows): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map([self::class, 'cell'], $headers), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'cell'], array_values($row)), ',', '"', '');
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    /** Formula-injection guard: a cell starting with = + - @ tab or CR gets a leading apostrophe. */
    public static function cell(mixed $v): string
    {
        $s = (string) $v;
        return preg_match('/^[=+\-@\t\r]/', $s) === 1 ? "'" . $s : $s;
    }

    /** @return list<list<string>> */
    public static function read(string $bytes, int $maxRows = 20000): array
    {
        $text = FileType::toUtf8($bytes);
        $first = (string) strtok($text, "\n");
        $delim = ',';
        $best = substr_count($first, ',');
        foreach ([';', "\t"] as $d) {
            if (substr_count($first, $d) > $best) {
                $delim = $d;
                $best = substr_count($first, $d);
            }
        }
        $h = fopen('php://temp', 'r+');
        if ($h === false) {
            return [];
        }
        fwrite($h, $text);
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, 0, $delim, '"', '')) !== false) {
            if (count($rows) >= $maxRows) {
                throw new \Blue\Core\Error\BusinessException('import.error.too_many_rows', ['max' => $maxRows], 422);
            }
            $rows[] = array_map(static fn ($c): string => trim((string) $c), $r);
        }
        while ($rows !== [] && implode('', end($rows)) === '') {
            array_pop($rows);
        }
        return $rows;
    }
}
