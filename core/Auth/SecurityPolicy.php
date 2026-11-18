<?php

declare(strict_types=1);

namespace Blue\Core\Auth;

use Blue\Core\Audit\AuditLogger;
use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;

/**
 * Organization-wide security switches kept in system_info (phase 3 moves them to the Config Registry).
 * mfa_enforced: two-step verification (TOTP) on/off — OFF by default (user decision 2026-10-12, DEC-52).
 * When ON, the DEC-51 rule applies: SYS/PYA/FNC/POSS and cross-company scopes must use it.
 */
final class SecurityPolicy
{
    private const KEY_MFA = 'mfa_enforced';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
    ) {
    }

    public function mfaEnforced(): bool
    {
        return (string) ($this->db->scalar('SELECT info_value FROM system_info WHERE info_key = ?', [self::KEY_MFA]) ?? '0') === '1';
    }

    public function setMfaEnforced(bool $on, string $reason): void
    {
        $before = $this->mfaEnforced();
        if ($before === $on) {
            return;
        }
        $this->db->run(
            'INSERT INTO system_info (info_key, info_value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE info_value = VALUES(info_value), updated_at = VALUES(updated_at)',
            [self::KEY_MFA, $on ? '1' : '0', $this->clock->now()->format('Y-m-d H:i:s.u')]
        );
        $this->audit->record('security.mfa_policy_changed', 'success', 'system', self::KEY_MFA, null, $reason, ['before' => $before, 'after' => $on]);
    }
}
