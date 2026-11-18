<?php

declare(strict_types=1);

namespace Blue\Core\Backup;

use Blue\Core\Support\Crypto;
use RuntimeException;

/**
 * Encrypted backup container "ROSBAK1" (M48, 8D "backup ต้องเข้ารหัส").
 *
 *   "ROSBAK1\n" · uint32 header length · header JSON (format, cipher, key fingerprint, backup id, created at)
 *   then chunks: uint32 length · nonce · ciphertext+tag, each chunk 1 MiB of plaintext
 *
 * Every chunk is authenticated with the header hash, its index and a "last chunk" flag as associated data, so a
 * reordered, truncated or edited file fails to open. The key is derived from APP_KEY (HKDF label "blue-backup-v1") —
 * the same key that protects encrypted fields and documents, so restoring needs the ORIGINAL APP_KEY (kept apart from
 * the backups — P0-06 §3 "Keys"). The fingerprint tells which key a file needs without revealing it.
 */
final class BackupCipher
{
    public const MAGIC = "ROSBAK1\n";
    private const CHUNK = 1048576;

    private string $cipher;

    public function __construct(private readonly string $key, string $backend)
    {
        if (strlen($key) !== 32) {
            throw new RuntimeException('Backup key must be 32 bytes');
        }
        $this->cipher = $backend === 'sodium' ? 'xchacha20poly1305' : 'aes-256-gcm';
    }

    public function fingerprint(): string
    {
        return substr(hash_hmac('sha256', 'blue-backup-fingerprint', $this->key), 0, 16);
    }

    /** @param array<string, scalar> $meta */
    public function encryptFile(string $in, string $out, array $meta): void
    {
        $header = (string) json_encode(['format' => 'ROSBAK1', 'cipher' => $this->cipher, 'key' => $this->fingerprint()] + $meta, JSON_UNESCAPED_SLASHES);
        $hh = hash('sha256', $header, true);
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        if ($src === false || $dst === false) {
            throw new RuntimeException('Cannot open backup files');
        }
        try {
            fwrite($dst, self::MAGIC . pack('N', strlen($header)) . $header);
            $index = 0;
            $next = (string) fread($src, self::CHUNK);
            do {
                $chunk = $next;
                $next = feof($src) ? '' : (string) fread($src, self::CHUNK);
                $last = $next === '';
                $sealed = $this->seal($chunk, $hh . pack('J', $index) . ($last ? "\x01" : "\x00"));
                fwrite($dst, pack('N', strlen($sealed)) . $sealed);
                $index++;
            } while (!$last);
        } finally {
            fclose($src);
            fclose($dst);
        }
    }

    /** @return array<string, mixed> header of a backup file (no key needed) */
    public static function header(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Cannot open backup file');
        }
        try {
            return self::readHeader($fh)[0];
        } finally {
            fclose($fh);
        }
    }

    /** Decrypts to $out. Throws when the key is different or anything was changed. */
    public function decryptFile(string $in, string $out): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        if ($src === false || $dst === false) {
            throw new RuntimeException('Cannot open backup files');
        }
        try {
            [$header, $raw] = self::readHeader($src);
            if (($header['key'] ?? '') !== $this->fingerprint()) {
                throw new KeyMismatch((string) ($header['key'] ?? ''));
            }
            $cipher = (string) ($header['cipher'] ?? '');
            $hh = hash('sha256', $raw, true);
            $index = 0;
            $sawLast = false;
            while (true) {
                $lenBytes = (string) fread($src, 4);
                if ($lenBytes === '') {
                    break;
                }
                if ($sawLast || strlen($lenBytes) !== 4) {
                    throw new RuntimeException('Backup file is damaged (extra data)');
                }
                $len = (int) unpack('N', $lenBytes)[1];
                if ($len < 17 || $len > self::CHUNK + 64) {
                    throw new RuntimeException('Backup file is damaged (chunk size)');
                }
                $sealed = '';
                while (strlen($sealed) < $len && !feof($src)) {
                    $sealed .= (string) fread($src, $len - strlen($sealed));
                }
                if (strlen($sealed) !== $len) {
                    throw new RuntimeException('Backup file is truncated');
                }
                $plain = $this->open($cipher, $sealed, $hh . pack('J', $index) . "\x00");
                if ($plain === null) {
                    $plain = $this->open($cipher, $sealed, $hh . pack('J', $index) . "\x01");
                    $sawLast = $plain !== null;
                }
                if ($plain === null) {
                    throw new RuntimeException('Backup file failed authentication (changed or damaged)');
                }
                fwrite($dst, $plain);
                $index++;
            }
            if (!$sawLast) {
                throw new RuntimeException('Backup file is truncated');
            }
        } finally {
            fclose($src);
            fclose($dst);
        }
    }

    /**
     * @param resource $fh
     * @return array{0: array<string, mixed>, 1: string}
     */
    private static function readHeader($fh): array
    {
        $magic = (string) fread($fh, 8);
        if ($magic !== self::MAGIC) {
            throw new RuntimeException('Not a Blue OS backup file');
        }
        $len = (int) (unpack('N', (string) fread($fh, 4))[1] ?? 0);
        if ($len < 2 || $len > 65536) {
            throw new RuntimeException('Not a Blue OS backup file');
        }
        $raw = (string) fread($fh, $len);
        $h = json_decode($raw, true);
        if (!is_array($h) || ($h['format'] ?? '') !== 'ROSBAK1') {
            throw new RuntimeException('Not a Blue OS backup file');
        }
        return [$h, $raw];
    }

    private function seal(string $plain, string $aad): string
    {
        return Crypto::aeadSeal($this->cipher, $this->key, $plain, $aad);
    }

    private function open(string $cipher, string $sealed, string $aad): ?string
    {
        return Crypto::aeadOpen($cipher, $this->key, $sealed, $aad);
    }
}
