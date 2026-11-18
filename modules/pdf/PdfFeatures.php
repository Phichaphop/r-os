<?php

declare(strict_types=1);

namespace Blue\Modules\Pdf;

use Blue\Core\App;

/**
 * Feature registry (S01 6.24.2): what this installation can really do. A feature is
 *   available   — built in, its PHP extensions are present and an administrator has not switched it off;
 *   disabled    — switched off by an administrator (config pdf.disabled_features);
 *   unavailable — needs something this host does not have (an extension, a production worker, an engine we do not ship).
 * Nothing is sent to an outside service.
 */
final class PdfFeatures
{
    /** code => [input: pdf|images, multiple files, required PHP functions/extensions] */
    public const BUILT_IN = [
        'merge' => ['pdf', true, ['zlib']],
        'split' => ['pdf', false, ['zlib']],
        'extract' => ['pdf', false, ['zlib']],
        'organize' => ['pdf', false, ['zlib']],
        'rotate' => ['pdf', false, ['zlib']],
        'compress' => ['pdf', false, ['zlib']],
        'watermark' => ['pdf', false, ['zlib', 'mbstring']],
        'page_numbers' => ['pdf', false, ['zlib', 'mbstring']],
        'images' => ['images', true, ['zlib']],
        'protect' => ['pdf', false, ['zlib', 'openssl']],
        'unlock' => ['pdf', false, ['zlib', 'openssl']],
        'metadata' => ['pdf', false, ['zlib']],
        'repair' => ['pdf', false, ['zlib']],
    ];

    /**
     * Tools that run only in the person's browser (0.39.0): nothing is uploaded, so nothing is kept or audited on the
     * server. code => [input, multiple, required extensions (none)]
     */
    public const CLIENT = [
        'sign' => ['pdf', false, []],
    ];

    /** ILovePDF-style groups on the tool page (0.39.0). group => codes */
    public const GROUPS = [
        'organize' => ['merge', 'split', 'organize', 'extract', 'rotate'],
        'edit' => ['sign', 'watermark', 'page_numbers', 'compress', 'repair'],
        'convert' => ['images', 'office', 'to_images', 'ocr', 'pdfa'],
        'security' => ['protect', 'unlock', 'metadata', 'redact', 'compare', 'batch'],
    ];

    /** Not offered on this stack — shown with the reason, never faked (6.24.1). code => reason key */
    public const NOT_PROVIDED = [
        'office' => 'pdf.unavailable.worker',
        'to_images' => 'pdf.unavailable.renderer',
        'ocr' => 'pdf.unavailable.ocr',
        'redact' => 'pdf.unavailable.redact',
        'compare' => 'pdf.unavailable.compare',
        'pdfa' => 'pdf.unavailable.pdfa',
        'batch' => 'pdf.unavailable.worker',
    ];

    public const ICONS = [
        'merge' => 'copy', 'split' => 'components', 'extract' => 'file-text', 'organize' => 'arrows-sort', 'rotate' => 'refresh', 'compress' => 'download',
        'watermark' => 'eye', 'page_numbers' => 'list-check', 'images' => 'upload', 'protect' => 'lock', 'unlock' => 'lock-open', 'metadata' => 'info-circle',
        'repair' => 'tool', 'office' => 'file-text', 'to_images' => 'eye', 'ocr' => 'search', 'redact' => 'eye-off', 'compare' => 'checks', 'pdfa' => 'shield-check',
        'batch' => 'history', 'sign' => 'pencil',
    ];

    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string, array{state: string, reason: ?string, input: string, multiple: bool}> */
    public function all(): array
    {
        $off = $this->disabled();
        $out = [];
        foreach (self::BUILT_IN as $code => [$input, $multi, $needs]) {
            $missing = array_values(array_filter($needs, static fn (string $e): bool => !extension_loaded($e)));
            $state = $missing !== [] ? 'unavailable' : (in_array($code, $off, true) ? 'disabled' : 'available');
            $out[$code] = ['state' => $state, 'reason' => $missing !== [] ? 'pdf.unavailable.extension' : null, 'missing' => implode(', ', $missing), 'input' => $input, 'multiple' => $multi];
        }
        foreach (self::CLIENT as $code => [$input, $multi]) {
            $out[$code] = ['state' => in_array($code, $off, true) ? 'disabled' : 'available', 'reason' => null, 'missing' => '', 'input' => $input, 'multiple' => $multi, 'client' => true];
        }
        foreach (self::NOT_PROVIDED as $code => $reason) {
            $out[$code] = ['state' => 'unavailable', 'reason' => $reason, 'missing' => '', 'input' => 'pdf', 'multiple' => false];
        }
        return $out;
    }

    public function usable(string $code): bool
    {
        return ($this->all()[$code]['state'] ?? '') === 'available';
    }

    /** @return list<string> */
    public function disabled(): array
    {
        $v = $this->app->registry()->resolveOrNull('pdf.disabled_features')?->value;
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }

    /**
     * Codes an administrator can switch on or off (server tools and browser-only tools).
     * @return list<string>
     */
    public static function switchable(): array
    {
        return array_merge(array_keys(self::BUILT_IN), array_keys(self::CLIENT));
    }

    /** @return array{max_mb: int, max_pages: int, max_files: int, temp_hours: int, result_minutes: int} */
    public function limits(): array
    {
        $get = function (string $key, int $default): int {
            $v = $this->app->registry()->resolveOrNull($key)?->value;
            return is_int($v) ? $v : $default;
        };
        $mb = $get('pdf.max_mb', 20);
        $upload = self::iniBytes((string) ini_get('upload_max_filesize'));
        if ($upload > 0) {
            $mb = max(1, min($mb, intdiv($upload, 1024 * 1024))); // never promise more than the host accepts
        }
        return ['max_mb' => $mb, 'max_pages' => $get('pdf.max_pages', 500), 'max_files' => $get('pdf.max_files', 20), 'temp_hours' => $get('pdf.temp_hours', 24),
            'result_minutes' => $get('pdf.result_minutes', 60)];
    }

    private static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
