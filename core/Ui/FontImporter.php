<?php

declare(strict_types=1);

namespace Blue\Core\Ui;

use Blue\Core\Error\ValidationException;
use Blue\Core\Integrations\HttpTransport;

/**
 * Imports an organization font from a link (0.6.1) — a Google Fonts CSS link (fonts.googleapis.com/css2?family=…) or a
 * direct .woff2/.woff/.ttf/.otf file. The SERVER downloads the files once and serves them itself from /brand/font/…,
 * so the strict CSP (font-src 'self', no CDN — P0-02 §11) stays as it is and visitors' browsers never call the font host.
 *
 * Safety: https only, no user/password or odd port in the link, the host must resolve to public addresses only (no
 * internal network), sizes are capped, every file must really be a font (magic bytes), and the stylesheet we serve is
 * REBUILT from a few parsed properties — the downloaded CSS is never passed through.
 */
final class FontImporter
{
    public const MAX_CSS_BYTES = 200_000;
    public const MAX_FONT_BYTES = 5_000_000;
    public const MAX_FACES = 40;
    public const MAX_TOTAL_BYTES = 25_000_000;
    /** placeholder for the base path in the stored stylesheet (filled in when served) */
    public const BASE = '__BLUE_FONT_BASE__';
    /** Google Fonts picks the format from the browser; ask like a current browser so it answers with WOFF2 */
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36 BlueOS-font-import';

    /** @var callable(string): list<string> host → IP addresses */
    private $resolve;

    public function __construct(
        private readonly HttpTransport $http,
        private readonly string $dir,
        ?callable $resolve = null,
    ) {
        $this->resolve = $resolve ?? static fn (string $host): array => array_values(array_unique(array_merge(
            gethostbynamel($host) ?: [],
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')
        )));
    }

    /**
     * @return array{id: string, family: string, faces: int, bytes: int}
     */
    public function import(string $url, string $family): array
    {
        $url = trim($url);
        $this->checkUrl($url);
        $family = FontCatalog::cleanFamily($family);
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if (preg_match('/\.(woff2|woff|ttf|otf)$/', $path) === 1) {
            if ($family === '') {
                $family = FontCatalog::cleanFamily(ucwords(str_replace(['-', '_'], ' ', pathinfo($path, PATHINFO_FILENAME))));
            }
            [$file, $fmt, $size] = $this->fetchFont($url);
            $css = $this->face($family, $file, $fmt, '100 900', 'normal', null);
            return $this->store($css, $family, 1, $size);
        }
        $res = $this->http->get($url, ['User-Agent' => self::UA, 'Accept' => 'text/css,*/*;q=0.1'], 15, self::MAX_CSS_BYTES);
        if ($res['status'] !== 200 || $res['body'] === '') {
            throw new ValidationException(['font_url' => $res['error'] === 'response_too_large' ? 'font.error.too_large' : 'font.error.fetch']);
        }
        if (preg_match_all('/@font-face\s*\{([^{}]*)\}/i', $res['body'], $m) < 1) {
            throw new ValidationException(['font_url' => 'font.error.not_css']);
        }
        $css = '';
        $faces = 0;
        $total = 0;
        $seen = [];
        foreach ($m[1] as $block) {
            $src = preg_match('/url\(\s*[\'"]?(https:\/\/[^\'")\s]+)[\'"]?\s*\)/i', $block, $u) === 1 ? $u[1] : null;
            if ($src === null) {
                continue;
            }
            if ($faces >= self::MAX_FACES) {
                throw new ValidationException(['font_url' => 'font.error.too_many']);
            }
            $fam = preg_match('/font-family\s*:\s*[\'"]?([^;\'"]+)[\'"]?\s*;/i', $block, $f) === 1 ? FontCatalog::cleanFamily($f[1]) : '';
            if ($family === '') {
                $family = $fam;
            }
            $weight = preg_match('/font-weight\s*:\s*([0-9]{3}(?:\s+[0-9]{3})?)\s*;/i', $block, $w) === 1 ? $w[1] : '400';
            $style = preg_match('/font-style\s*:\s*(normal|italic)\s*;/i', $block, $s) === 1 ? strtolower($s[1]) : 'normal';
            $range = preg_match('/unicode-range\s*:\s*([U+0-9A-Fa-f?,\s-]+);/', $block, $r) === 1 ? (string) preg_replace('/\s+/', '', $r[1]) : null;
            $this->checkUrl($src);
            if (!isset($seen[$src])) {
                [$file, $fmt, $size] = $this->fetchFont($src);
                $seen[$src] = [$file, $fmt];
                $total += $size;
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new ValidationException(['font_url' => 'font.error.too_large']);
                }
            }
            [$file, $fmt] = $seen[$src];
            $css .= $this->face($family, $file, $fmt, $weight, $style, $range);
            $faces++;
        }
        if ($faces === 0 || $family === '') {
            throw new ValidationException(['font_url' => 'font.error.not_css']);
        }
        return $this->store($css, $family, $faces, $total);
    }

    /** The stored stylesheet of an imported font, with links under $basePath; null when missing. */
    public function css(string $id, string $basePath): ?string
    {
        if (preg_match('/^[a-f0-9]{40}$/', $id) !== 1 || !is_file($this->dir . '/bundle-' . $id . '.css')) {
            return null;
        }
        return str_replace(self::BASE, $basePath, (string) file_get_contents($this->dir . '/bundle-' . $id . '.css'));
    }

    /** @return array{0: string, 1: string}|null path + MIME type of a stored font file */
    public function file(string $name): ?array
    {
        if (preg_match('/^[a-f0-9]{64}\.(woff2|woff|ttf|otf)$/', $name, $m) !== 1 || !is_file($this->dir . '/' . $name)) {
            return null;
        }
        return [$this->dir . '/' . $name, ['woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf'][$m[1]]];
    }

    private function checkUrl(string $url): void
    {
        $p = parse_url($url);
        if (
            !is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || ($p['host'] ?? '') === '' || isset($p['user']) || isset($p['pass'])
            || (isset($p['port']) && (int) $p['port'] !== 443) || strlen($url) > 500
        ) {
            throw new ValidationException(['font_url' => 'font.error.url']);
        }
        $host = trim((string) $p['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve)($host);
        if ($ips === []) {
            throw new ValidationException(['font_url' => 'font.error.fetch']);
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new ValidationException(['font_url' => 'font.error.private']);
            }
        }
    }

    /** @return array{0: string, 1: string, 2: int} stored file name, CSS format, size */
    private function fetchFont(string $url): array
    {
        $res = $this->http->get($url, ['User-Agent' => self::UA, 'Accept' => 'font/woff2,font/*;q=0.9,*/*;q=0.1'], 20, self::MAX_FONT_BYTES);
        if ($res['status'] !== 200 || $res['body'] === '') {
            throw new ValidationException(['font_url' => $res['error'] === 'response_too_large' ? 'font.error.too_large' : 'font.error.fetch']);
        }
        $bytes = $res['body'];
        $head = substr($bytes, 0, 4);
        [$ext, $fmt] = match (true) {
            $head === 'wOF2' => ['woff2', 'woff2'],
            $head === 'wOFF' => ['woff', 'woff'],
            $head === 'OTTO' => ['otf', 'opentype'],
            $head === "\x00\x01\x00\x00" || $head === 'true' => ['ttf', 'truetype'],
            default => throw new ValidationException(['font_url' => 'font.error.not_font']),
        };
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('font storage is not writable');
        }
        $name = hash('sha256', $bytes) . '.' . $ext;
        if (!is_file($this->dir . '/' . $name)) {
            file_put_contents($this->dir . '/' . $name, $bytes, LOCK_EX);
        }
        return [$name, $fmt, strlen($bytes)];
    }

    private function face(string $family, string $file, string $fmt, string $weight, string $style, ?string $range): string
    {
        return "@font-face {\n  font-family: '" . $family . "';\n  font-style: {$style};\n  font-weight: {$weight};\n  font-display: swap;\n"
            . '  src: url(\'' . self::BASE . '/brand/font/' . $file . "') format('{$fmt}');\n"
            . ($range !== null && $range !== '' ? "  unicode-range: {$range};\n" : '') . "}\n";
    }

    /** @return array{id: string, family: string, faces: int, bytes: int} */
    private function store(string $css, string $family, int $faces, int $bytes): array
    {
        $css = "/* imported font: {$family} ({$faces} faces) */\n" . $css;
        $id = substr(hash('sha256', $css), 0, 40);
        file_put_contents($this->dir . '/bundle-' . $id . '.css', $css, LOCK_EX);
        return ['id' => $id, 'family' => $family, 'faces' => $faces, 'bytes' => $bytes];
    }
}
