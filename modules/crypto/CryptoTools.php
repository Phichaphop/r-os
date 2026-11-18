<?php

declare(strict_types=1);

namespace Blue\Modules\Crypto;

use Blue\Core\Error\ValidationException;

/**
 * M28 tools — pure functions, no storage, no logging (7C: never persist plaintext, passwords or keys).
 * Uses PHP's own password_hash / hash / hash_hmac / random_bytes (standard library, salts handled by PHP).
 * Every result: ['value' => string, 'facts' => array<string, string|int>, 'warnings' => list<string>] (facts/warnings are translation keys or values).
 */
final class CryptoTools
{
    public const MAX_INPUT = 1_048_576; // 1 MB of text
    public const DIGESTS = ['sha256', 'sha384', 'sha512', 'sha3-256', 'sha3-512', 'sha1', 'md5'];
    public const LEGACY = ['sha1', 'md5'];
    public const HMACS = ['sha256', 'sha384', 'sha512'];
    public const CODECS = ['base64', 'base64url', 'hex', 'url'];

    public static function argon2Available(): bool
    {
        return defined('PASSWORD_ARGON2ID');
    }

    /** @return array{value: string, facts: array<string, string|int>, warnings: list<string>} */
    public static function passwordHash(string $password, string $algo, int $cost): array
    {
        self::text($password, 'password', 1, 1024);
        $warnings = [];
        if ($algo === 'argon2id' && self::argon2Available()) {
            $hash = password_hash($password, PASSWORD_ARGON2ID);
        } else {
            if ($algo === 'argon2id') {
                $warnings[] = 'crypto.warn.no_argon2';
            }
            $cost = max(10, min(13, $cost));
            if (strlen($password) > 72) {
                $warnings[] = 'crypto.warn.bcrypt72';
            }
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
        }
        $info = password_get_info($hash);
        return ['value' => $hash, 'facts' => ['crypto.fact.algorithm' => (string) $info['algoName'], 'crypto.fact.length' => strlen($hash)], 'warnings' => $warnings];
    }

    /** @return array{value: string, facts: array<string, string|int>, warnings: list<string>} */
    public static function passwordVerify(string $password, string $hash): array
    {
        self::text($password, 'password', 1, 1024);
        $hash = trim($hash);
        $info = password_get_info($hash);
        if ($info['algo'] === null || $info['algo'] === 0 || $info['algoName'] === 'unknown') {
            throw new ValidationException(['hash' => 'crypto.error.not_a_hash']);
        }
        $ok = password_verify($password, $hash);
        return ['value' => $ok ? 'crypto.verify.match' : 'crypto.verify.no_match', 'facts' => ['crypto.fact.algorithm' => (string) $info['algoName']],
            'warnings' => $ok && password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]) && $info['algoName'] === 'bcrypt' ? ['crypto.warn.rehash'] : []];
    }

    /** @return array{value: string, facts: array<string, string|int>, warnings: list<string>} */
    public static function digest(string $data, string $algo, bool $fromFile = false): array
    {
        if (!in_array($algo, self::DIGESTS, true)) {
            throw new ValidationException(['algo' => 'crypto.error.algo']);
        }
        if (!$fromFile) {
            self::text($data, 'text', 0, self::MAX_INPUT);
        }
        $raw = hash($algo, $data, true);
        return ['value' => bin2hex($raw), 'facts' => ['crypto.fact.algorithm' => strtoupper($algo), 'crypto.fact.input_bytes' => strlen($data), 'crypto.fact.base64' => base64_encode($raw)],
            'warnings' => in_array($algo, self::LEGACY, true) ? ['crypto.warn.legacy'] : []];
    }

    /** @return array{value: string, facts: array<string, string|int>, warnings: list<string>} */
    public static function hmac(string $data, string $key, string $algo): array
    {
        if (!in_array($algo, self::HMACS, true)) {
            throw new ValidationException(['algo' => 'crypto.error.algo']);
        }
        self::text($data, 'text', 0, self::MAX_INPUT);
        self::text($key, 'key', 1, 4096);
        $raw = hash_hmac($algo, $data, $key, true);
        return ['value' => bin2hex($raw), 'facts' => ['crypto.fact.algorithm' => 'HMAC-' . strtoupper($algo), 'crypto.fact.base64' => base64_encode($raw)],
            'warnings' => strlen($key) < 32 ? ['crypto.warn.short_key'] : []];
    }

    /** @return array{value: string, facts: array<string, string|int>, warnings: list<string>} */
    public static function codec(string $data, string $codec, string $direction): array
    {
        if (!in_array($codec, self::CODECS, true) || !in_array($direction, ['encode', 'decode'], true)) {
            throw new ValidationException(['codec' => 'crypto.error.algo']);
        }
        if (strlen($data) > self::MAX_INPUT) {
            throw new ValidationException(['text' => 'crypto.error.too_long']);
        }
        if ($direction === 'encode') {
            self::text($data, 'text', 0, self::MAX_INPUT);
            $out = match ($codec) {
                'base64' => base64_encode($data),
                'base64url' => rtrim(strtr(base64_encode($data), '+/', '-_'), '='),
                'hex' => bin2hex($data),
                default => rawurlencode($data),
            };
            return ['value' => $out, 'facts' => ['crypto.fact.input_bytes' => strlen($data), 'crypto.fact.output_chars' => strlen($out)], 'warnings' => []];
        }
        $clean = $codec === 'url' ? $data : (string) preg_replace('/\s+/', '', $data);
        $out = match ($codec) {
            'base64' => base64_decode($clean, true),
            'base64url' => base64_decode(strtr($clean, '-_', '+/') . str_repeat('=', (4 - strlen($clean) % 4) % 4), true),
            'hex' => (strlen($clean) % 2 === 0 && ctype_xdigit($clean)) || $clean === '' ? hex2bin($clean) : false,
            default => rawurldecode($clean),
        };
        if ($out === false) {
            throw new ValidationException(['text' => 'crypto.error.decode']);
        }
        $binary = !mb_check_encoding($out, 'UTF-8') || preg_match('/[\x00-\x08\x0E-\x1F]/', $out) === 1;
        return ['value' => $binary ? bin2hex($out) : $out, 'facts' => ['crypto.fact.output_bytes' => strlen($out)], 'warnings' => $binary ? ['crypto.warn.binary'] : []];
    }

    /**
     * Random secrets from the operating system's CSPRNG (random_bytes / random_int).
     *
     * @param list<string> $sets lower|upper|digits|symbols (password)
     * @return array{value: string, facts: array<string, string|int>, warnings: list<string>}
     */
    public static function random(string $kind, int $length, array $sets = []): array
    {
        switch ($kind) {
            case 'password':
                $length = max(8, min(128, $length));
                $pools = ['lower' => 'abcdefghijkmnopqrstuvwxyz', 'upper' => 'ABCDEFGHJKLMNPQRSTUVWXYZ', 'digits' => '23456789', 'symbols' => '!@#$%^&*()-_=+[]{};:,.?'];
                $use = array_values(array_intersect(array_keys($pools), $sets)) ?: ['lower', 'upper', 'digits'];
                // at least one character from every chosen set, then shuffle with random_int
                $chars = array_map(static fn (string $s): string => $pools[$s][random_int(0, strlen($pools[$s]) - 1)], $use);
                $all = implode('', array_map(static fn (string $s): string => $pools[$s], $use));
                while (count($chars) < $length) {
                    $chars[] = $all[random_int(0, strlen($all) - 1)];
                }
                for ($i = count($chars) - 1; $i > 0; $i--) {
                    $j = random_int(0, $i);
                    [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
                }
                $value = implode('', $chars);
                $bits = (int) floor($length * log(strlen($all), 2));
                return ['value' => $value, 'facts' => ['crypto.fact.length' => $length, 'crypto.fact.entropy' => $bits], 'warnings' => $bits < 64 ? ['crypto.warn.weak'] : []];
            case 'hex':
            case 'base64url':
                $bytes = max(16, min(64, $length));
                $raw = random_bytes($bytes);
                $value = $kind === 'hex' ? bin2hex($raw) : rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
                return ['value' => $value, 'facts' => ['crypto.fact.bytes' => $bytes, 'crypto.fact.entropy' => $bytes * 8], 'warnings' => []];
            case 'uuid':
                $b = random_bytes(16);
                $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
                $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
                $h = bin2hex($b);
                return ['value' => sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20)),
                    'facts' => ['crypto.fact.version' => 'UUID v4'], 'warnings' => []];
            default:
                throw new ValidationException(['kind' => 'crypto.error.algo']);
        }
    }

    private static function text(string $v, string $field, int $min, int $max): void
    {
        if (!mb_check_encoding($v, 'UTF-8')) {
            throw new ValidationException([$field => 'crypto.error.encoding']);
        }
        if (strlen($v) < $min) {
            throw new ValidationException([$field => 'crypto.error.required']);
        }
        if (strlen($v) > $max) {
            throw new ValidationException([$field => 'crypto.error.too_long']);
        }
    }
}
