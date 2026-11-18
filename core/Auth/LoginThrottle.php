<?php

declare(strict_types=1);

namespace Blue\Core\Auth;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Crypto;

/**
 * Rate limiting per "point" (login, otp, activation) and key (account or IP) — 6.8, DEC-51.
 * Only keyed hashes are stored, never the username or IP itself.
 */
final class LoginThrottle
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Crypto $crypto,
    ) {
    }

    public function hit(string $point, string $key, bool $success): void
    {
        $this->db->run(
            'INSERT INTO auth_attempts (point, key_hash, success, attempted_at) VALUES (?, ?, ?, ?)',
            [$point, $this->crypto->keyedHash($point . '|' . mb_strtolower($key)), (int) $success, $this->clock->now()->format('Y-m-d H:i:s.u')]
        );
    }

    public function failures(string $point, string $key, int $windowMinutes): int
    {
        $since = $this->clock->now()->modify("-{$windowMinutes} minutes")->format('Y-m-d H:i:s.u');
        $hash = $this->crypto->keyedHash($point . '|' . mb_strtolower($key));
        $lastSuccess = $this->db->scalar('SELECT MAX(attempted_at) FROM auth_attempts WHERE point = ? AND key_hash = ? AND success = 1', [$point, $hash]);
        if (is_string($lastSuccess) && $lastSuccess > $since) {
            $since = $lastSuccess; // a success resets the counter
        }
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM auth_attempts WHERE point = ? AND key_hash = ? AND success = 0 AND attempted_at > ?',
            [$point, $hash, $since]
        );
    }

    /** Housekeeping: attempts older than a day are useless. */
    public function prune(): void
    {
        $this->db->run('DELETE FROM auth_attempts WHERE attempted_at < ?', [$this->clock->now()->modify('-1 day')->format('Y-m-d H:i:s.u')]);
    }
}
