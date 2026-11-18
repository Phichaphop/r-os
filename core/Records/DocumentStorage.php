<?php

declare(strict_types=1);

namespace Blue\Core\Records;

use Blue\Core\Support\Crypto;
use RuntimeException;

/**
 * Private file store for the Central Document Service (6.17.1, 6.30.12):
 * - outside the public web root (storage/ is denied by .htaccess and never published)
 * - random, non-guessable object keys; the original file name is metadata only
 * - optional encryption at rest (AES-256-GCM / XChaCha20 via Crypto) for Confidential/Restricted classes
 * - files are written to a temp name and renamed, so a half-written file is never visible
 * Nothing here is ever executed or included.
 */
final class DocumentStorage
{
    public function __construct(private readonly string $dir, private readonly Crypto $crypto)
    {
    }

    public function put(string $bytes, bool $encrypt): string
    {
        $key = bin2hex(random_bytes(20));
        $path = $this->path($key);
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0770, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('document storage is not writable');
        }
        $data = $encrypt ? $this->crypto->encrypt($bytes) : $bytes;
        $tmp = $path . '.part';
        if (file_put_contents($tmp, $data, LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('document storage write failed');
        }
        return $key;
    }

    public function get(string $key, bool $encrypted): ?string
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        $data = (string) file_get_contents($path);
        return $encrypted ? $this->crypto->decrypt($data) : $data;
    }

    /** Physical deletion (disposition). Returns false when the file was already gone. */
    public function delete(string $key): bool
    {
        $path = $this->path($key);
        return is_file($path) && unlink($path);
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function writable(): bool
    {
        return (is_dir($this->dir) || @mkdir($this->dir, 0770, true)) && is_writable($this->dir);
    }

    private function path(string $key): string
    {
        if (preg_match('/^[a-f0-9]{40}$/', $key) !== 1) {
            throw new RuntimeException('bad storage key'); // never let a key become a path (traversal)
        }
        return $this->dir . '/' . substr($key, 0, 2) . '/' . $key;
    }
}
