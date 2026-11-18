<?php

declare(strict_types=1);

namespace Blue\Core\Files;

/**
 * Best-effort PDF inspection without third-party libraries (S01 6.24.1 — "assess what the stack can really do"):
 * version, encryption, page count, and active content (JavaScript, launch actions, rich media) which Blue OS refuses.
 * Compressed object streams are inflated (zlib) with a size cap so a "PDF bomb" cannot exhaust memory.
 * It does NOT render, repair, merge or validate PDF/A — those need a PDF engine (S01 full, phase 3U).
 */
final class PdfInspector
{
    public const MAX_INFLATE = 20 * 1024 * 1024;

    /** @return array{version: ?string, encrypted: bool, pages: ?int, active: bool, attachments: bool} */
    public static function inspect(string $bytes): array
    {
        $text = self::searchable($bytes);
        $version = preg_match('/^%PDF-(\d\.\d)/', $bytes, $m) === 1 ? $m[1] : null;
        $pages = null;
        if (preg_match_all('#/Type\s*/Pages\b(?:(?!>>).){0,400}?/Count\s+(\d+)|/Count\s+(\d+)(?:(?!>>).){0,400}?/Type\s*/Pages\b#s', $text, $mm, PREG_SET_ORDER) > 0) {
            foreach ($mm as $row) {
                $pages = max($pages ?? 0, (int) (($row[1] ?? '') !== '' ? $row[1] : ($row[2] ?? 0)));
            }
        }
        if ($pages === null || $pages === 0) {
            $n = preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $text);
            $pages = $n > 0 ? $n : null;
        }
        return [
            'version' => $version,
            'encrypted' => preg_match('#/Encrypt\b#', $text) === 1,
            'pages' => $pages,
            'active' => self::isActive($text),
            'attachments' => preg_match('#/EmbeddedFiles?\b#', $text) === 1,
        ];
    }

    public static function activeContent(string $bytes): bool
    {
        return self::isActive(self::searchable($bytes));
    }

    private static function isActive(string $text): bool
    {
        return preg_match('#/(JavaScript|JS|Launch|RichMedia|XFA)\b#', $text) === 1;
    }

    /** Raw bytes + inflated object streams, with #xx escapes in names decoded (/J#61vaScript → /JavaScript). */
    private static function searchable(string $bytes): string
    {
        $out = $bytes;
        $budget = self::MAX_INFLATE;
        $offset = 0;
        while ($budget > 0 && ($pos = strpos($bytes, 'stream', $offset)) !== false) {
            $offset = $pos + 6;
            if (substr($bytes, $pos - 3, 3) === 'end') {
                continue;
            }
            $dictStart = strrpos(substr($bytes, max(0, $pos - 1000), min(1000, $pos)), '<<');
            $dict = $dictStart === false ? '' : substr($bytes, max(0, $pos - 1000) + $dictStart, 1000 - $dictStart);
            $start = $pos + 6 + (substr($bytes, $pos + 6, 2) === "\r\n" ? 2 : 1);
            $end = strpos($bytes, 'endstream', $start);
            if ($end === false) {
                break;
            }
            if (str_contains($dict, '/FlateDecode') && (str_contains($dict, '/ObjStm') || str_contains($dict, '/XRef'))) {
                $data = @gzuncompress(substr($bytes, $start, $end - $start), $budget);
                if (is_string($data)) {
                    $budget -= strlen($data);
                    $out .= "\n" . $data;
                }
            }
            $offset = $end + 9;
        }
        return (string) preg_replace_callback('#/[A-Za-z0-9\#]*\#[0-9A-Fa-f]{2}[A-Za-z0-9\#]*#', static fn (array $m): string => (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $h): string => chr((int) hexdec($h[1])), $m[0]), $out);
    }
}
