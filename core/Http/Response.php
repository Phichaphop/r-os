<?php

declare(strict_types=1);

namespace Blue\Core\Http;

final class Response
{
    /** File streamed from disk by send() instead of $body (large downloads such as backups). */
    public ?string $file = null;

    /** @param array<string, string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    /** Streams a private file in chunks (never loaded into memory) as an attachment. */
    public static function download(string $path, string $mime, string $filename): self
    {
        $r = new self('', 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) (filesize($path) ?: 0),
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $r->file = $path;
        return $r;
    }

    /** In-memory file download; the UTF-8 name goes in filename* (Thai names survive), an ASCII fallback in filename. */
    public static function attachment(string $bytes, string $mime, string $filename): self
    {
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename), '_');
        if (!preg_match('/[A-Za-z0-9]/', (string) pathinfo($ascii, PATHINFO_FILENAME))) {
            $ascii = 'download' . ($ascii !== '' && str_contains($ascii, '.') ? '.' . pathinfo($ascii, PATHINFO_EXTENSION) : '');
        }
        return new self($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v);
            }
        }
        if ($this->file !== null) {
            $fh = fopen($this->file, 'rb');
            if ($fh !== false) {
                while (!feof($fh)) {
                    echo fread($fh, 1 << 20);
                    flush();
                }
                fclose($fh);
            }
            return;
        }
        echo $this->body;
    }
}
