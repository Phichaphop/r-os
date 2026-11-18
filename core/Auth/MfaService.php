<?php

declare(strict_types=1);

namespace Blue\Core\Auth;

use Blue\Core\Audit\AuditLogger;
use Blue\Core\Database\Connection;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Crypto;

/** TOTP enrolment / verification / admin reset (6.30.2, DEC-51). Secrets are encrypted with APP_KEY. */
final class MfaService
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Crypto $crypto,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Starts (or restarts) enrolment and returns the secret to show once.
     *
     * @param array<string, mixed> $user
     * @return array{secret:string, uri:string}
     */
    public function begin(array $user): array
    {
        $this->db->run('DELETE FROM user_mfa_factors WHERE user_id = ? AND confirmed_at IS NULL', [$user['id']]);
        $secret = Totp::newSecret();
        $this->db->run(
            "INSERT INTO user_mfa_factors (user_id, type, secret_enc, created_at) VALUES (?, 'totp', ?, ?)",
            [$user['id'], $this->crypto->encrypt($secret), $this->now()]
        );
        return ['secret' => $secret, 'uri' => Totp::uri($secret, (string) $user['username'])];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{secret:string, uri:string}|null the pending (unconfirmed) secret, to show again
     */
    public function pending(array $user): ?array
    {
        $row = $this->db->one('SELECT secret_enc FROM user_mfa_factors WHERE user_id = ? AND confirmed_at IS NULL ORDER BY id DESC LIMIT 1', [$user['id']]);
        if ($row === null) {
            return null;
        }
        $secret = $this->crypto->decrypt((string) $row['secret_enc']);
        return ['secret' => $secret, 'uri' => Totp::uri($secret, (string) $user['username'])];
    }

    /** @param array<string, mixed> $user */
    public function confirm(array $user, string $code): void
    {
        $row = $this->db->one('SELECT * FROM user_mfa_factors WHERE user_id = ? AND confirmed_at IS NULL ORDER BY id DESC LIMIT 1', [$user['id']]);
        $step = $row === null ? null : Totp::verify($this->crypto->decrypt((string) $row['secret_enc']), $code, $this->clock->now()->getTimestamp());
        if ($row === null || $step === null) {
            $this->audit->record('auth.mfa_enroll', 'failure', 'user', $user['public_id'], null, 'bad_code');
            throw new ValidationException(['code' => 'auth.error.totp_invalid']);
        }
        $this->db->transactional(function () use ($user, $row, $step): void {
            $this->db->run('DELETE FROM user_mfa_factors WHERE user_id = ? AND id <> ?', [$user['id'], $row['id']]);
            $this->db->run('UPDATE user_mfa_factors SET confirmed_at = ?, last_used_step = ? WHERE id = ?', [$this->now(), $step, $row['id']]);
            $this->db->run('UPDATE users SET mfa_enabled = 1, row_version = row_version + 1 WHERE id = ?', [$user['id']]);
            $this->audit->record('auth.mfa_enroll', 'success', 'user', $user['public_id']);
        });
    }

    /** @param array<string, mixed> $user */
    public function verify(array $user, string $code): bool
    {
        $row = $this->db->one('SELECT * FROM user_mfa_factors WHERE user_id = ? AND confirmed_at IS NOT NULL ORDER BY id DESC LIMIT 1', [$user['id']]);
        if ($row === null) {
            return false;
        }
        $last = $row['last_used_step'] === null ? null : (int) $row['last_used_step'];
        $step = Totp::verify($this->crypto->decrypt((string) $row['secret_enc']), $code, $this->clock->now()->getTimestamp(), $last);
        if ($step === null) {
            $this->audit->record('auth.mfa_verify', 'failure', 'user', $user['public_id']);
            return false;
        }
        $this->db->run('UPDATE user_mfa_factors SET last_used_step = ? WHERE id = ?', [$step, $row['id']]);
        $this->audit->record('auth.mfa_verify', 'success', 'user', $user['public_id']);
        return true;
    }

    /** @param array<string, mixed> $user */
    public function enrolled(array $user): bool
    {
        return (bool) $this->db->scalar('SELECT 1 FROM user_mfa_factors WHERE user_id = ? AND confirmed_at IS NOT NULL', [$user['id']]);
    }

    /** Admin removes a lost authenticator (requires re-auth in the controller); the user must enrol again. */
    public function reset(int $userId, string $publicId, string $reason): void
    {
        $this->db->transactional(function () use ($userId, $publicId, $reason): void {
            $this->db->run('DELETE FROM user_mfa_factors WHERE user_id = ?', [$userId]);
            $this->db->run('UPDATE users SET mfa_enabled = 0, row_version = row_version + 1 WHERE id = ?', [$userId]);
            $this->audit->record('auth.mfa_reset', 'success', 'user', $publicId, null, $reason);
        });
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
