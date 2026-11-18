<?php

declare(strict_types=1);

namespace Blue\Core\Support;

use RuntimeException;

/**
 * Field-level encryption + keyed hashes (6.11, P0-02 §10).
 * Works with whichever crypto extension the host has — XAMPP on Windows ships OpenSSL but not
 * libsodium, some shared hosts the other way round:
 * - key derivation: HKDF-SHA256 (PHP core) from APP_KEY
 * - keyedHash: HMAC-SHA256 (PHP core) — lets us look values up (tokens, IPs) without storing them
 * - encrypt: AES-256-GCM via OpenSSL (version byte 0x02); without OpenSSL, XChaCha20-Poly1305 via
 *   libsodium (0x03). decrypt reads every version whose extension is present.
 * The master key is APP_KEY (config/env.php, never in the repository or the database).
 */
final class Crypto
{
    private const V_GCM = "\x02";
    private const V_XCHACHA = "\x03";

    private string $encKey;
    private string $hashKey;
    private string $prefer;
    private string $master;

    /** @param ''|'openssl'|'sodium' $backend force a backend (tests); '' = best available */
    public function __construct(string $appKey, string $backend = '')
    {
        $raw = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
        if (!is_string($raw) || strlen($raw) < 32) {
            throw new RuntimeException('APP_KEY is missing or too short — run the installer or set APP_KEY.');
        }
        $this->master = $raw;
        $this->encKey = hash_hkdf('sha256', $raw, 32, 'blue-enc-v2');
        $this->hashKey = hash_hkdf('sha256', $raw, 32, 'blue-hash-v2');
        $available = self::backends();
        if ($backend !== '' && !in_array($backend, $available, true)) {
            throw new RuntimeException("Crypto backend '{$backend}' is not available on this PHP.");
        }
        $this->prefer = $backend !== '' ? $backend : ($available[0] ?? '');
    }

    /** @return list<string> crypto backends usable on this PHP, preferred first */
    public static function backends(): array
    {
        $list = [];
        if (extension_loaded('openssl') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            $list[] = 'openssl';
        }
        if (extension_loaded('sodium') && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $list[] = 'sodium';
        }
        return $list;
    }

    public function encrypt(string $plain): string
    {
        if ($this->prefer === 'openssl') {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->encKey, OPENSSL_RAW_DATA, $iv, $tag, self::V_GCM, 16);
            if ($cipher === false) {
                throw new RuntimeException('Encryption failed');
            }
            return self::V_GCM . $iv . $tag . $cipher;
        }
        if ($this->prefer === 'sodium') {
            $nonce = random_bytes(24);
            return self::V_XCHACHA . $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, self::V_XCHACHA, $nonce, $this->encKey);
        }
        throw new RuntimeException('No crypto extension: enable openssl (or sodium) in php.ini.');
    }

    public function decrypt(string $blob): string
    {
        $plain = false;
        $version = $blob[0] ?? '';
        if ($version === self::V_GCM && in_array('openssl', self::backends(), true) && strlen($blob) >= 29) {
            $plain = openssl_decrypt(substr($blob, 29), 'aes-256-gcm', $this->encKey, OPENSSL_RAW_DATA, substr($blob, 1, 12), substr($blob, 13, 16), self::V_GCM);
        } elseif ($version === self::V_XCHACHA && in_array('sodium', self::backends(), true) && strlen($blob) >= 25) {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($blob, 25), self::V_XCHACHA, substr($blob, 1, 24), $this->encKey);
        } elseif ($version !== self::V_GCM && $version !== self::V_XCHACHA) {
            throw new RuntimeException('Unknown ciphertext version');
        } else {
            throw new RuntimeException('The crypto extension needed to read this value is not enabled.');
        }
        if ($plain === false) {
            throw new RuntimeException('Decryption failed (wrong key or tampered value)');
        }
        return $plain;
    }

    /** A separate 32-byte key for one purpose (e.g. backup files), derived from APP_KEY with HKDF and its own label. */
    public function deriveKey(string $label): string
    {
        return hash_hkdf('sha256', $this->master, 32, $label);
    }

    /**
     * Raw AEAD with a caller-supplied 32-byte key and associated data (used by the backup container):
     * 'aes-256-gcm' → iv(12) · tag(16) · ciphertext; 'xchacha20poly1305' → nonce(24) · ciphertext+tag.
     */
    public static function aeadSeal(string $cipher, string $key, string $plain, string $aad): string
    {
        if ($cipher === 'xchacha20poly1305') {
            if (!in_array('sodium', self::backends(), true)) {
                throw new RuntimeException('The sodium extension is not enabled.');
            }
            $nonce = random_bytes(24);
            return $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $aad, $nonce, $key);
        }
        if (!in_array('openssl', self::backends(), true)) {
            throw new RuntimeException('The openssl extension is not enabled.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $c = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($c === false) {
            throw new RuntimeException('Encryption failed');
        }
        return $iv . $tag . $c;
    }

    /** Opposite of aeadSeal(); null when the data or associated data was changed. */
    public static function aeadOpen(string $cipher, string $key, string $sealed, string $aad): ?string
    {
        if ($cipher === 'xchacha20poly1305') {
            if (!in_array('sodium', self::backends(), true)) {
                throw new RuntimeException('This data needs the sodium extension.');
            }
            $p = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($sealed, 24), $aad, substr($sealed, 0, 24), $key);
            return $p === false ? null : $p;
        }
        if (!in_array('openssl', self::backends(), true)) {
            throw new RuntimeException('This data needs the openssl extension.');
        }
        $p = openssl_decrypt(substr($sealed, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($sealed, 0, 12), substr($sealed, 12, 16), $aad);
        return $p === false ? null : $p;
    }

    /** Name of the backend used for new ciphertext ('openssl' | 'sodium'). */
    public function backend(): string
    {
        return $this->prefer;
    }

    /** 32-byte keyed hash (binary) for exact-match lookups of secrets. */
    public function keyedHash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->hashKey, true);
    }

    /** URL-safe random token (default 32 bytes of entropy). */
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
