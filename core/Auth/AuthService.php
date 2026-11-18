<?php

declare(strict_types=1);

namespace Blue\Core\Auth;

use Blue\Core\Audit\AuditLogger;
use Blue\Core\Database\Connection;
use Blue\Core\Error\ValidationException;
use Blue\Core\Mail\Mailer;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Crypto;

/**
 * Sign-in, sign-out, first activation, password reset and password change (6.1–6.3, P0-04 §3, DEC-51).
 *
 * Failure messages never reveal whether an account exists. Lockout: N failures in M minutes locks the
 * account for L minutes (config). Every outcome is audited without passwords, OTPs or tokens.
 */
final class AuthService
{
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Crypto $crypto,
        private readonly SessionManager $sessions,
        private readonly LoginThrottle $throttle,
        private readonly PasswordPolicy $policy,
        private readonly AuditLogger $audit,
        private readonly Mailer $mailer,
        private readonly AuthSettings $s,
    ) {
    }

    /**
     * @return array{ok:bool, error?:string, user?:array<string,mixed>, session?:array{token:string, id:int, public_id:string}}
     */
    public function login(string $identifier, string $password, string $ip, string $userAgent): array
    {
        $identifier = trim($identifier);
        if ($this->throttle->failures('login_ip', $ip, $this->s->lockWindowMinutes) >= $this->s->ipFailures) {
            $this->audit->record('auth.login', 'denied', null, null, null, 'ip_rate_limited');
            return ['ok' => false, 'error' => 'auth.error.too_many'];
        }
        $user = $this->findByIdentifier($identifier);
        $now = $this->now();

        if ($user === null) {
            // Verify against a throw-away hash so response time does not reveal whether the account exists.
            password_verify($password, self::$dummyHash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT));
            $this->throttle->hit('login_ip', $ip, false);
            $this->audit->record('auth.login', 'failure', null, null, null, 'unknown_identifier');
            return ['ok' => false, 'error' => 'auth.error.invalid'];
        }
        $uid = (int) $user['id'];
        $locked = $user['locked_until'] !== null && (string) $user['locked_until'] > $now;
        $validPassword = $user['password_hash'] !== null && password_verify($password, (string) $user['password_hash']);

        if ($locked || $user['status'] !== 'active' || !$validPassword) {
            $this->throttle->hit('login_ip', $ip, false);
            $reason = $locked ? 'temporarily_locked' : ($user['status'] !== 'active' ? 'status_' . $user['status'] : 'bad_password');
            if (!$locked && $user['status'] === 'active') {
                $this->throttle->hit('login_user', (string) $uid, false);
                if ($this->throttle->failures('login_user', (string) $uid, $this->s->lockWindowMinutes) >= $this->s->lockFailures) {
                    $until = $this->clock->now()->modify("+{$this->s->lockMinutes} minutes")->format('Y-m-d H:i:s.u');
                    $this->db->run('UPDATE users SET locked_until = ? WHERE id = ?', [$until, $uid]);
                    $this->audit->record('auth.lockout', 'success', 'user', $user['public_id'], null, "{$this->s->lockFailures} failures", [], $uid);
                }
            }
            $this->audit->record('auth.login', 'failure', 'user', $user['public_id'], null, $reason, [], null);
            return ['ok' => false, 'error' => 'auth.error.invalid'];
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $uid]);
        }
        $this->throttle->hit('login_user', (string) $uid, true);
        $this->throttle->hit('login_ip', $ip, true);
        $this->db->run('UPDATE users SET locked_until = NULL, last_login_at = ? WHERE id = ?', [$now, $uid]);
        $session = $this->sessions->start($uid, $ip, $userAgent);
        $this->audit->record('auth.login', 'success', 'user', $user['public_id'], null, null, [], $uid);
        return ['ok' => true, 'user' => $user, 'session' => $session];
    }

    public function logout(int $sessionId, int $userId, bool $everywhere = false): void
    {
        if ($everywhere) {
            $n = $this->sessions->revokeAll($userId, 'logout_all');
            $this->audit->record('auth.logout_all', 'success', 'user', (string) $userId, null, "{$n} sessions");
            return;
        }
        $this->sessions->revoke($sessionId, 'logout');
        $this->audit->record('auth.logout', 'success');
    }

    // ---- activation (6.3, M14) ---------------------------------------------------------------

    /** @return array<string, mixed>|null user row for a valid, unused activation/reset-link token */
    public function userForLinkToken(string $token, string $purpose): ?array
    {
        $row = $this->tokenRow($token, $purpose);
        return $row === null ? null : $this->db->one('SELECT * FROM users WHERE id = ?', [$row['user_id']]);
    }

    /** Sets the first password (activation) or a new one from an admin-issued reset link. */
    public function completeLink(string $token, string $purpose, string $password, string $confirm): void
    {
        $row = $this->tokenRow($token, $purpose);
        if ($row === null) {
            $this->audit->record("auth.{$purpose}", 'failure', null, null, null, 'invalid_or_expired_token');
            throw new ValidationException(['token' => 'auth.error.link_invalid']);
        }
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$row['user_id']]) ?? [];
        $this->assertNewPassword($password, $confirm, $user);
        $this->db->transactional(function () use ($row, $user, $password, $purpose): void {
            $this->db->run('UPDATE user_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [$this->now(), $row['id']]);
            $status = $purpose === 'activation' ? 'active' : (string) $user['status'];
            $this->db->run(
                'UPDATE users SET password_hash = ?, status = ?, must_change_password = 0, password_changed_at = ?, locked_until = NULL, row_version = row_version + 1 WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), $status, $this->now(), $user['id']]
            );
            $this->sessions->revokeAll((int) $user['id'], 'password_set');
            $this->audit->record("auth.{$purpose}", 'success', 'user', $user['public_id'], null, null, [], (int) $user['id']);
        });
    }

    /** Creates a single-use link token (activation or admin reset). Returns the raw token for the URL. */
    public function issueLinkToken(int $userId, string $purpose, ?int $createdBy): string
    {
        $hours = $purpose === 'activation' ? $this->s->activationHours : 24;
        $this->db->run('UPDATE user_tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [$this->now(), $userId, $purpose]);
        $token = Crypto::token();
        $this->db->run(
            'INSERT INTO user_tokens (user_id, purpose, token_hash, created_at, expires_at, created_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $purpose, $this->crypto->keyedHash($token), $this->now(), $this->clock->now()->modify("+{$hours} hours")->format('Y-m-d H:i:s.u'), $createdBy]
        );
        return $token;
    }

    // ---- self-service reset with OTP (6.2) -----------------------------------------------------

    public function resetAvailable(): bool
    {
        return $this->mailer->available();
    }

    /** Always behaves the same whether or not the account exists. */
    public function requestResetOtp(string $identifier, string $ip): void
    {
        if (!$this->mailer->available()) {
            return;
        }
        if ($this->throttle->failures('reset_req', $ip, 15) >= 5) {
            $this->audit->record('auth.reset_request', 'denied', null, null, null, 'rate_limited');
            return;
        }
        $this->throttle->hit('reset_req', $ip, false); // counts requests, not failures
        $user = $this->findByIdentifier(trim($identifier));
        if ($user === null || $user['status'] !== 'active' || ($user['email'] ?? '') === '') {
            $this->audit->record('auth.reset_request', 'failure', null, null, null, 'no_eligible_account');
            return;
        }
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->db->run('UPDATE user_tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [$this->now(), $user['id'], 'reset_otp']);
        $this->db->run(
            'INSERT INTO user_tokens (user_id, purpose, token_hash, created_at, expires_at) VALUES (?, ?, ?, ?, ?)',
            [$user['id'], 'reset_otp', $this->crypto->keyedHash($user['id'] . '|' . $otp), $this->now(),
                $this->clock->now()->modify("+{$this->s->resetOtpMinutes} minutes")->format('Y-m-d H:i:s.u')]
        );
        $this->mailer->send(
            (string) $user['email'],
            'Blue OS: รหัสตั้งรหัสผ่านใหม่ / Password reset code',
            "รหัส OTP: {$otp}\nใช้ได้ {$this->s->resetOtpMinutes} นาที — ถ้าคุณไม่ได้ขอ ไม่ต้องทำอะไร\n\nCode: {$otp} (valid {$this->s->resetOtpMinutes} minutes)"
        );
        $this->audit->record('auth.reset_request', 'success', 'user', $user['public_id'], null, null, [], (int) $user['id']);
    }

    public function completeResetOtp(string $identifier, string $otp, string $password, string $confirm): void
    {
        $user = $this->findByIdentifier(trim($identifier));
        $row = $user === null ? null : $this->db->one(
            "SELECT * FROM user_tokens WHERE user_id = ? AND purpose = 'reset_otp' AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1",
            [$user['id'], $this->now()]
        );
        if ($user === null || $row === null || (int) $row['attempts'] >= $this->s->otpAttempts) {
            $this->audit->record('auth.reset_complete', 'failure', null, null, null, 'no_valid_otp');
            throw new ValidationException(['otp' => 'auth.error.otp_invalid']);
        }
        if (!hash_equals((string) $row['token_hash'], $this->crypto->keyedHash($user['id'] . '|' . preg_replace('/\D/', '', $otp)))) {
            $this->db->run('UPDATE user_tokens SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            $this->audit->record('auth.reset_complete', 'failure', 'user', $user['public_id'], null, 'wrong_otp', [], (int) $user['id']);
            throw new ValidationException(['otp' => 'auth.error.otp_invalid']);
        }
        $this->assertNewPassword($password, $confirm, $user);
        $this->db->transactional(function () use ($row, $user, $password): void {
            $this->db->run('UPDATE user_tokens SET used_at = ? WHERE id = ?', [$this->now(), $row['id']]);
            $this->db->run(
                'UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = ?, locked_until = NULL, row_version = row_version + 1 WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), $this->now(), $user['id']]
            );
            $this->sessions->revokeAll((int) $user['id'], 'password_reset');
            $this->audit->record('auth.reset_complete', 'success', 'user', $user['public_id'], null, null, [], (int) $user['id']);
        });
    }

    // ---- change password / re-auth ------------------------------------------------------------

    /** @param array<string, mixed> $user */
    public function changePassword(array $user, int $sessionId, string $current, string $new, string $confirm): void
    {
        if (!password_verify($current, (string) $user['password_hash'])) {
            $this->audit->record('auth.password_change', 'failure', 'user', $user['public_id'], null, 'bad_current');
            throw new ValidationException(['current_password' => 'auth.error.current_password']);
        }
        $this->assertNewPassword($new, $confirm, $user);
        $this->db->run(
            'UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = ?, row_version = row_version + 1 WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), $this->now(), $user['id']]
        );
        $n = $this->sessions->revokeAll((int) $user['id'], 'password_changed', $sessionId);
        $this->audit->record('auth.password_change', 'success', 'user', $user['public_id'], null, "other sessions revoked: {$n}");
    }

    /** @param array<string, mixed> $user */
    public function verifyPassword(array $user, string $password): bool
    {
        return $user['password_hash'] !== null && password_verify($password, (string) $user['password_hash']);
    }

    /** @param array<string, mixed> $user */
    private function assertNewPassword(string $password, string $confirm, array $user): void
    {
        $errors = [];
        $problems = $this->policy->check($password, (string) ($user['username'] ?? ''), (string) ($user['email'] ?? ''));
        if ($problems !== []) {
            $errors['password'] = $problems[0];
        } elseif (!hash_equals($password, $confirm)) {
            $errors['password_confirm'] = 'auth.pw.mismatch';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /** @return array<string, mixed>|null */
    private function tokenRow(string $token, string $purpose): ?array
    {
        if ($token === '' || strlen($token) > 100) {
            return null;
        }
        return $this->db->one(
            'SELECT * FROM user_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?',
            [$this->crypto->keyedHash($token), $purpose, $this->now()]
        );
    }

    /** @return array<string, mixed>|null */
    public function findByIdentifier(string $identifier): ?array
    {
        if ($identifier === '' || mb_strlen($identifier) > 160) {
            return null;
        }
        return $this->db->one('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1', [mb_strtolower($identifier), mb_strtolower($identifier)]);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
