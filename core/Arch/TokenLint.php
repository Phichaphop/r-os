<?php

declare(strict_types=1);

namespace Blue\Core\Arch;

/**
 * P0-05 §1.1 / §8: views and components use only semantic/component tokens.
 * - no raw colour values (#hex, rgb(), hsl()) outside ui/tokens/
 * - no inline style="" attributes in views/components (also blocked by CSP)
 * - no inline <script> without the CSP nonce
 */
final class TokenLint
{
    private const COLOR = '/(?<![&\w])#[0-9a-fA-F]{3,8}\b(?![\w-])|\brgba?\(|\bhsla?\(/';

    public function __construct(private readonly string $root)
    {
    }

    /** @return list<string> problems */
    public function run(): array
    {
        $problems = [];
        $targets = array_merge(
            FileScan::files($this->root, 'ui', ['css', 'php', 'js']),
            FileScan::files($this->root, 'modules', ['css', 'php', 'js'])
        );
        foreach ($targets as $rel) {
            if (str_starts_with($rel, 'ui/tokens/') || str_starts_with($rel, 'ui/fonts/') || str_starts_with($rel, 'ui/icons/')) {
                continue;
            }
            $isView = str_ends_with($rel, '.php');
            if (!$isView && !str_ends_with($rel, '.css') && !str_ends_with($rel, '.js')) {
                continue;
            }
            if ($isView && !str_contains($rel, '/views/') && !str_contains($rel, '/Views/') && !str_starts_with($rel, 'ui/components/')) {
                continue;
            }
            $lines = file($this->root . '/' . $rel) ?: [];
            foreach ($lines as $i => $line) {
                $code = preg_replace('#//.*$|/\*.*?\*/#', '', $line) ?? $line;
                if (preg_match(self::COLOR, $code)) {
                    $problems[] = "{$rel}:" . ($i + 1) . ' raw colour — use a semantic token (var(--color-…))';
                }
                if ($isView && preg_match('/\sstyle\s*=\s*["\']/', $code)) {
                    $problems[] = "{$rel}:" . ($i + 1) . ' inline style attribute';
                }
                if ($isView && preg_match('/<script(?![^>]*\bnonce=)(?![^>]*\bsrc=)/i', $code)) {
                    $problems[] = "{$rel}:" . ($i + 1) . ' inline <script> without nonce';
                }
            }
        }
        return $problems;
    }
}
