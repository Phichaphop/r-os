<?php

declare(strict_types=1);

namespace Blue\Core\Files;

/**
 * Decides what a file really is from its bytes (6.17.2 step 3, 6.30.12) — the extension and the browser's MIME type
 * are never trusted. Also flags content that Blue OS refuses to keep: macros in Office files, scripts/launch actions
 * inside PDFs, legacy binary Office files (cannot be checked), and SVG (may carry script).
 */
final class FileType
{
    /** type => [mime, extension] */
    public const TYPES = [
        'pdf' => ['application/pdf', 'pdf'],
        'png' => ['image/png', 'png'],
        'jpeg' => ['image/jpeg', 'jpg'],
        'gif' => ['image/gif', 'gif'],
        'webp' => ['image/webp', 'webp'],
        'ico' => ['image/x-icon', 'ico'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'pptx'],
        'csv' => ['text/csv', 'csv'],
        'txt' => ['text/plain', 'txt'],
        'html' => ['text/html', 'html'],
        // phase 3U-B — company fonts (M30)
        'ttf' => ['font/ttf', 'ttf'],
        'otf' => ['font/otf', 'otf'],
        'woff' => ['font/woff', 'woff'],
        'woff2' => ['font/woff2', 'woff2'],
    ];

    /**
     * @return array{type: ?string, mime: ?string, ext: ?string, problem: ?string} problem = translation key when refused
     */
    public static function sniff(string $bytes, string $clientName = ''): array
    {
        $r = static fn (?string $type, ?string $problem = null): array => [
            'type' => $type, 'mime' => $type !== null ? self::TYPES[$type][0] : null, 'ext' => $type !== null ? self::TYPES[$type][1] : null, 'problem' => $problem,
        ];
        if ($bytes === '') {
            return $r(null, 'file.error.empty');
        }
        $head = substr($bytes, 0, 16);
        if (str_starts_with($bytes, '%PDF-')) {
            return $r('pdf', PdfInspector::activeContent($bytes) ? 'file.error.pdf_active' : null);
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return $r('png');
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return $r('jpeg');
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return $r('gif');
        }
        if (str_starts_with($head, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return $r('webp');
        }
        if (str_starts_with($head, "\x00\x00\x01\x00")) {
            return $r('ico');
        }
        $font = FontInfo::kind($bytes);
        if ($font !== null) {
            return FontInfo::sane($bytes) ? $r($font) : $r(null, 'file.error.font_corrupt');
        }
        if (str_starts_with($head, 'ttcf')) {
            return $r(null, 'file.error.type_not_allowed'); // font collections: upload each font on its own
        }
        if (str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            return $r(null, 'file.error.legacy_office');
        }
        if (Zip::looksLikeZip($bytes)) {
            try {
                $zip = Zip::open($bytes);
            } catch (\Throwable) {
                return $r(null, 'file.error.archive_corrupt');
            }
            if (!$zip->has('[Content_Types].xml')) {
                return $r(null, 'file.error.type_not_allowed');
            }
            foreach ($zip->names() as $n) {
                if (preg_match('#(^|/)vbaProject\.bin$|/activeX/#i', $n) === 1) {
                    return $r(null, 'file.error.macro');
                }
            }
            $ct = (string) $zip->get('[Content_Types].xml', 1024 * 1024);
            if (stripos($ct, 'macroEnabled') !== false) {
                return $r(null, 'file.error.macro');
            }
            return match (true) {
                $zip->has('xl/workbook.xml') => $r('xlsx'),
                $zip->has('word/document.xml') => $r('docx'),
                $zip->has('ppt/presentation.xml') => $r('pptx'),
                default => $r(null, 'file.error.type_not_allowed'),
            };
        }
        // text formats: must be valid text (UTF-8, or Thai Windows-874 which Excel often writes)
        if (str_contains(substr($bytes, 0, 8192), "\x00")) {
            return $r(null, 'file.error.type_not_allowed');
        }
        $sample = ltrim(substr($bytes, 0, 2048), "\xEF\xBB\xBF \t\r\n");
        if (preg_match('/^<(\?xml|svg|!doctype svg)/i', $sample) === 1 && stripos($sample, '<svg') !== false) {
            return $r(null, 'file.error.svg');
        }
        if (preg_match('/^<(!doctype html|html|head|body|script)/i', $sample) === 1) {
            return $r('html');
        }
        $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        return $r($ext === 'csv' || self::looksLikeCsv($sample) ? 'csv' : 'txt');
    }

    private static function looksLikeCsv(string $sample): bool
    {
        $first = strtok($sample, "\n");
        return is_string($first) && (substr_count($first, ',') >= 1 || substr_count($first, "\t") >= 1 || substr_count($first, ';') >= 1);
    }

    /** Converts Thai legacy encodings to UTF-8 and removes a BOM. */
    public static function toUtf8(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $converted = function_exists('iconv') ? @iconv('CP874', 'UTF-8//IGNORE', $text) : false;
        return is_string($converted) && $converted !== '' ? $converted : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-11');
    }
}
