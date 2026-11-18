<?php

declare(strict_types=1);

namespace Blue\Core\Http;

/** Immutable HTTP request snapshot. */
final class Request
{
    /** @var array<string, string> route parameters, filled by Router */
    public array $params = [];

    /** @var array<string, mixed> per-request attributes set by middleware (correlation id, nonce, …) */
    public array $attributes = [];

    /** @var array<string, list<\Blue\Core\Files\UploadedFile>> uploaded files by field name (multipart forms) */
    public array $files = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers lower-case names
     * @param array<string, string> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly string $ip = '0.0.0.0',
        public readonly bool $secure = false,
        public readonly string $host = 'localhost',
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        [$base, $path] = self::resolveBase((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'), $uri);

        $body = $_POST;
        if (str_contains($headers['content-type'] ?? '', 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            $body = is_array($decoded) ? $decoded : [];
        }

        $request = new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $body,
            $headers,
            array_map('strval', $_COOKIE),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($headers['x-forwarded-proto'] ?? '') === 'https',
            (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        );
        $request->attributes['base_path'] = $base;
        $request->files = \Blue\Core\Files\UploadedFile::fromGlobals($_FILES);
        return $request;
    }

    /**
     * Works out the URL prefix the app is installed under and the route path inside it.
     * Handles: web root = public/ · app in a sub-folder (XAMPP http://localhost/blue/) where the
     * root .htaccess rewrites into public/ · non-ASCII folder names (REQUEST_URI is percent-encoded).
     *
     * @return array{0:string, 1:string} [basePath without trailing slash ('' at the domain root), route path]
     */
    public static function resolveBase(string $scriptName, string $requestUri): array
    {
        $path = '/' . trim(rawurldecode((string) parse_url($requestUri, PHP_URL_PATH)), '/');
        $scriptDir = rtrim(dirname(str_replace('\\', '/', $scriptName)), '/');

        $candidates = [$scriptDir];
        if (str_ends_with($scriptDir, '/public')) {
            $candidates[] = substr($scriptDir, 0, -strlen('/public')); // reached via root .htaccess → public/
        }
        foreach ($candidates as $base) {
            if ($base === '' || $base === '/') {
                return ['', $path];
            }
            if ($path === $base || str_starts_with($path, $base . '/')) {
                return [$base, '/' . ltrim(substr($path, strlen($base)), '/')];
            }
        }
        return ['', $path];
    }

    public function basePath(): string
    {
        return (string) ($this->attributes['base_path'] ?? '');
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    /** First file of a field, or null when none was sent. */
    public function file(string $field): ?\Blue\Core\Files\UploadedFile
    {
        $f = $this->files[$field][0] ?? null;
        return $f !== null && $f->error !== UPLOAD_ERR_NO_FILE ? $f : null;
    }

    public function wantsJson(): bool
    {
        return str_contains($this->header('accept'), 'application/json')
            || str_starts_with($this->path, '/api/');
    }

    public function isSafeMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
