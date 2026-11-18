<?php

declare(strict_types=1);

namespace Blue\Core\Http;

use Blue\Core\Audit\AuditLogger;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Authz\ScopeSet;
use Blue\Core\Error\HttpException;

/** The signed-in user for this request (null = guest) — handed to controllers by the Authenticate middleware. */
final class RequestContext
{
    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed>|null $session
     */
    public function __construct(
        public readonly ?array $user,
        public readonly ?array $session,
        public readonly ?Authorizer $authorizer,
        private readonly AuditLogger $audit,
        private readonly int $reauthMinutes,
        private readonly \Blue\Core\Support\Clock $clock,
    ) {
    }

    public function isGuest(): bool
    {
        return $this->user === null;
    }

    public function userId(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    public function az(): Authorizer
    {
        if ($this->authorizer === null) {
            throw HttpException::forbidden();
        }
        return $this->authorizer;
    }

    public function can(string $permission): bool
    {
        return $this->authorizer !== null && $this->authorizer->can($permission);
    }

    /** Throws 403 (and audits) when the permission is missing in every scope. */
    public function require(string $permission): ScopeSet
    {
        $scope = $this->authorizer?->scope($permission) ?? ScopeSet::none();
        if ($scope->isEmpty()) {
            $this->audit->record('authz.denied', 'denied', null, null, null, $permission);
            throw HttpException::forbidden();
        }
        return $scope;
    }

    /** High-risk actions need a password/TOTP confirmation within the last N minutes (P0-04 §3, P0-05 §3.5). */
    public function requireFreshReauth(): void
    {
        $at = $this->session['reauth_at'] ?? null;
        $limit = $this->clock->now()->modify("-{$this->reauthMinutes} minutes")->format('Y-m-d H:i:s.u');
        if (!is_string($at) || $at < $limit) {
            throw new ReauthRequired();
        }
    }
}
