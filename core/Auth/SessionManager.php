<?php

declare(strict_types=1);

namespace Blue\Core\Auth;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Crypto;
use Blue\Core\Support\Ulid;

/**
 * Server-side sessions (DEC-51: idle 30 min, absolute 12 h; "sign out everywhere").
 * The browser holds only a random token inside the PHP session; the database stores its keyed hash.
 */
final class SessionManager
{
    private ?string $endedReason = null;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Crypto $crypto,
        private readonly int $idleMinutes,
        private readonly int $absoluteHours,
    ) {
    }

    /** @return array{token:string, id:int, public_id:string} */
    public function start(int $userId, string $ip, string $userAgent): array
    {
        $token = Crypto::token();
        $now = $this->clock->now();
        $publicId = Ulid::generate($this->clock);
        $this->db->run(
            'INSERT INTO user_sessions (public_id, user_id, token_hash, created_at, last_seen_at, expires_at, ip_hash, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $publicId, $userId, $this->crypto->keyedHash($token), $this->fmt($now), $this->fmt($now),
                $this->fmt($now->modify("+{$this->absoluteHours} hours")), $this->crypto->keyedHash($ip), mb_substr($userAgent, 0, 255),
            ]
        );
        return ['token' => $token, 'id' => (int) $this->db->pdo()->lastInsertId(), 'public_id' => $publicId];
    }

    /**
     * Valid session row for the token, or null (expired/idle/revoked sessions are revoked on the spot).
     *
     * @return array<string, mixed>|null
     */
    public function current(string $token): ?array
    {
        $this->endedReason = null;
        if ($token === '') {
            return null;
        }
        $row = $this->db->one('SELECT * FROM user_sessions WHERE token_hash = ?', [$this->crypto->keyedHash($token)]);
        if ($row === null || $row['revoked_at'] !== null) {
            $this->endedReason = $row !== null ? (string) $row['revoked_reason'] : null;
            return null;
        }
        $now = $this->clock->now();
        $nowStr = $this->fmt($now);
        if ($nowStr >= (string) $row['expires_at']) {
            $this->revoke((int) $row['id'], 'absolute_timeout');
            $this->endedReason = 'absolute_timeout';
            return null;
        }
        $idleLimit = $this->fmt($now->modify("-{$this->idleMinutes} minutes"));
        if ((string) $row['last_seen_at'] < $idleLimit) {
            $this->revoke((int) $row['id'], 'idle_timeout');
            $this->endedReason = 'idle_timeout';
            return null;
        }
        if ((string) $row['last_seen_at'] < $this->fmt($now->modify('-60 seconds'))) {
            $this->db->run('UPDATE user_sessions SET last_seen_at = ? WHERE id = ?', [$nowStr, $row['id']]);
            $row['last_seen_at'] = $nowStr;
        }
        return $row;
    }

    /** Why the last current() call found no valid session (revoked_reason), e.g. rights_changed — null when unknown. */
    public function endedReason(): ?string
    {
        return $this->endedReason;
    }

    public function revoke(int $sessionId, string $reason): void
    {
        $this->db->run(
            'UPDATE user_sessions SET revoked_at = ?, revoked_reason = ? WHERE id = ? AND revoked_at IS NULL',
            [$this->fmt($this->clock->now()), $reason, $sessionId]
        );
    }

    /** @return int number of sessions revoked */
    public function revokeAll(int $userId, string $reason, ?int $exceptSessionId = null): int
    {
        $sql = 'UPDATE user_sessions SET revoked_at = ?, revoked_reason = ? WHERE user_id = ? AND revoked_at IS NULL';
        $params = [$this->fmt($this->clock->now()), $reason, $userId];
        if ($exceptSessionId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptSessionId;
        }
        return $this->db->run($sql, $params)->rowCount();
    }

    public function markMfa(int $sessionId): void
    {
        $this->db->run('UPDATE user_sessions SET mfa_verified_at = ? WHERE id = ?', [$this->fmt($this->clock->now()), $sessionId]);
    }

    public function markReauth(int $sessionId): void
    {
        $this->db->run('UPDATE user_sessions SET reauth_at = ? WHERE id = ?', [$this->fmt($this->clock->now()), $sessionId]);
    }

    /** @return list<array<string, mixed>> active sessions of a user, newest first */
    public function active(int $userId): array
    {
        return $this->db->all(
            'SELECT id, public_id, created_at, last_seen_at, expires_at, user_agent FROM user_sessions
              WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ? ORDER BY last_seen_at DESC',
            [$userId, $this->fmt($this->clock->now())]
        );
    }

    public function minutesIdle(): int
    {
        return $this->idleMinutes;
    }

    private function fmt(\DateTimeImmutable $d): string
    {
        return $d->format('Y-m-d H:i:s.u');
    }
}
