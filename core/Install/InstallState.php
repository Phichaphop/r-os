<?php

declare(strict_types=1);

namespace Blue\Core\Install;

/**
 * DEC-17: once storage/install.lock exists the installer is permanently closed.
 * The lock is written last, after migrations and config succeeded.
 */
final class InstallState
{
    /** @param string $root directory holding storage/ and config/ (the app root in production) */
    public function __construct(private readonly string $root)
    {
    }

    public function lockFile(): string
    {
        return $this->root . '/storage/install.lock';
    }

    public function envFile(): string
    {
        return $this->root . '/config/env.php';
    }

    public function isLocked(): bool
    {
        return is_file($this->lockFile());
    }

    /** Present while a database restore runs (M48): every page answers "maintenance" until it is removed. */
    public function restoreFlag(): string
    {
        return $this->root . '/storage/locks/restore.flag';
    }

    public function isRestoring(): bool
    {
        return is_file($this->restoreFlag());
    }

    public function setRestoring(bool $on, string $note = ''): void
    {
        if ($on) {
            @mkdir(dirname($this->restoreFlag()), 0750, true);
            file_put_contents($this->restoreFlag(), $note, LOCK_EX);
        } else {
            @unlink($this->restoreFlag());
        }
    }

    public function setupCodeFile(): string
    {
        return $this->root . '/storage/locks/setup-code.txt';
    }

    /**
     * One-time setup code that must be read from the server's file system (FTP / file manager)
     * — stops a stranger from installing the system onto their own database first.
     */
    public function setupCode(): string
    {
        $file = $this->setupCodeFile();
        if (!is_file($file)) {
            @mkdir(dirname($file), 0750, true);
            $code = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
            file_put_contents($file, $code . "\n", LOCK_EX);
            @chmod($file, 0600);
        }
        return trim((string) file_get_contents($file));
    }
}
