<?php

declare(strict_types=1);

namespace Blue\Core\Files;

/**
 * One file sent by the browser (6.30.12). The client's name and MIME type are kept for reference only —
 * the real type is decided from the content (FileType::sniff), never from the extension or the browser.
 */
final class UploadedFile
{
    public function __construct(
        public readonly string $clientName,
        public readonly string $clientType,
        public readonly string $path,
        public readonly int $size,
        public readonly int $error = UPLOAD_ERR_OK,
        private readonly bool $trusted = false,
    ) {
    }

    /**
     * Normalizes $_FILES (single and multiple inputs) into field => list<UploadedFile>.
     *
     * @param array<string, mixed> $files
     * @return array<string, list<self>>
     */
    public static function fromGlobals(array $files): array
    {
        $out = [];
        foreach ($files as $field => $f) {
            if (!is_array($f) || !isset($f['name'])) {
                continue;
            }
            $names = (array) $f['name'];
            foreach (array_keys($names) as $i) {
                $tmp = (string) (((array) $f['tmp_name'])[$i] ?? '');
                $out[(string) $field][] = new self(
                    (string) $names[$i],
                    (string) (((array) $f['type'])[$i] ?? ''),
                    $tmp,
                    (int) (((array) $f['size'])[$i] ?? 0),
                    (int) (((array) $f['error'])[$i] ?? UPLOAD_ERR_NO_FILE),
                    $tmp !== '' && is_uploaded_file($tmp),
                );
            }
        }
        return $out;
    }

    /** For tests and server-side producers: a file that did not come through the browser. */
    public static function fromBytes(string $name, string $bytes, string $dir): self
    {
        $path = $dir . '/up-' . bin2hex(random_bytes(8));
        file_put_contents($path, $bytes);
        return new self($name, '', $path, strlen($bytes), UPLOAD_ERR_OK, true);
    }

    public function ok(): bool
    {
        return $this->error === UPLOAD_ERR_OK && $this->trusted && is_file($this->path);
    }

    /** Translation key describing why the upload itself failed (before any content check). */
    public function errorKey(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'file.error.too_large',
            UPLOAD_ERR_NO_FILE => 'file.error.missing',
            UPLOAD_ERR_PARTIAL => 'file.error.partial',
            default => 'file.error.upload_failed',
        };
    }

    public function bytes(): string
    {
        return (string) file_get_contents($this->path);
    }

    /** A safe display name: no path, no control characters, at most 150 characters (6.30.12 filename normalization). */
    public function safeName(): string
    {
        return self::normalizeName($this->clientName);
    }

    public static function normalizeName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', '_', $name);
        $name = trim($name, " .\t");
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = 'file';
        }
        return $name === '' ? 'file' : mb_substr($name, -150);
    }
}
