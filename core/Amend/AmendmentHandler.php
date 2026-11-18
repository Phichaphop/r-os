<?php

declare(strict_types=1);

namespace Blue\Core\Amend;

/**
 * Domain side of "request to amend an approved record" (6.28). The central service handles the request,
 * approval and status; the owning domain decides what can change and HOW the change is applied
 * (new revision, reversal + replacement, adjustment …).
 */
interface AmendmentHandler
{
    public function targetType(): string;

    /**
     * Current state of the record as the requester sees it — null when it does not exist or is outside their scope.
     *
     * @return array{revision: int, legal_entity_id: ?int, values: array<string, scalar|null>, amendable_fields: list<string>}|null
     */
    public function load(string $targetId, int $actorId): ?array;

    /**
     * What the change would affect (shown to the approver).
     *
     * @param array<string, string> $changes field => new value
     * @return array<string, mixed>
     */
    public function impact(string $targetId, array $changes): array;

    /**
     * Applies the approved change. Must be idempotent for $idempotencyKey and must refuse when the record's
     * revision is no longer $expectedRevision (someone changed it meanwhile).
     *
     * @param array<string, string> $changes
     * @return string reference of what was created (revision id, reversal document …)
     */
    public function execute(string $targetId, int $expectedRevision, string $action, array $changes, string $idempotencyKey, int $approverId): string;
}
