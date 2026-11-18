<?php

declare(strict_types=1);

namespace Blue\Core\Audit;

use Blue\Core\Database\Connection;
use Blue\Core\Log\Logger;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * Append-only audit trail (6.30.5, P0-04 §7). This class only INSERTs — there is no update/delete API.
 * Never pass passwords, OTPs, tokens, full ID/account numbers or salary amounts in $diff or $reason;
 * values whose keys look sensitive are redacted defensively.
 */
final class AuditLogger
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditContext $ctx,
    ) {
    }

    /**
     * @param 'success'|'denied'|'failure' $outcome
     * @param array<string, mixed> $diff before/after values that are needed to understand the change
     */
    public function record(
        string $action,
        string $outcome = 'success',
        ?string $targetType = null,
        string|int|null $targetId = null,
        ?int $legalEntityId = null,
        ?string $reason = null,
        array $diff = [],
        ?int $actorIdOverride = null,
    ): string {
        $id = Ulid::generate($this->clock);
        $actor = $actorIdOverride ?? $this->ctx->actorId;
        $this->db->run(
            'INSERT INTO audit_events (public_id, occurred_at, actor_type, actor_id, on_behalf_of_id, action, target_type, target_id,
                legal_entity_id, outcome, reason, correlation_id, ip_hash, diff_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $this->clock->now()->format('Y-m-d H:i:s.u'),
                $actor !== null ? 'user' : ($this->ctx->actorType === 'system' ? 'system' : 'anonymous'),
                $actor,
                $this->ctx->onBehalfOfId,
                $action,
                $targetType,
                $targetId !== null ? (string) $targetId : null,
                $legalEntityId,
                $outcome,
                $reason !== null ? mb_substr($reason, 0, 255) : null,
                $this->ctx->correlationId,
                $this->ctx->ipHash,
                $diff === [] ? null : json_encode(Logger::redact($diff), JSON_UNESCAPED_UNICODE),
            ]
        );
        return $id;
    }
}
