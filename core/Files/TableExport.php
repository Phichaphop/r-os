<?php

declare(strict_types=1);

namespace Blue\Core\Files;

use Blue\Core\Http\Response;

/**
 * One way to hand a table to the user as Excel or CSV (6.10 item 2). Callers apply permission + data scope + the
 * current filter BEFORE building the rows, cap the size, and audit the export (6.30.14).
 */
final class TableExport
{
    public const FORMATS = ['xlsx', 'csv'];

    /**
     * @param list<string> $headers
     * @param list<list<scalar|null>> $rows
     * @param list<int> $numeric columns written as numbers in Excel (amounts) — everything else stays text
     */
    public static function response(string $format, string $baseName, array $headers, array $rows, array $numeric = []): Response
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $baseName) ?: 'export';
        if ($format === 'csv') {
            return (new Response(Csv::write($headers, $rows), 200, ['Content-Type' => 'text/csv; charset=utf-8']))
                ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '.csv"')
                ->withHeader('Cache-Control', 'no-store');
        }
        $bytes = Xlsx::write([$baseName => ['headers' => $headers, 'rows' => $rows, 'numeric' => $numeric]], $baseName);
        return (new Response($bytes, 200, ['Content-Type' => FileType::TYPES['xlsx'][0]]))
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '.xlsx"')
            ->withHeader('Cache-Control', 'no-store');
    }

    public static function format(mixed $v): string
    {
        return is_string($v) && in_array($v, self::FORMATS, true) ? $v : 'xlsx';
    }
}
