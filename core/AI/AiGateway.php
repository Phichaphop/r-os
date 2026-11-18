<?php

declare(strict_types=1);

namespace Blue\Core\AI;

use Blue\Core\Config\AppConfig;
use Blue\Core\Log\Logger;

/**
 * Server-side guard for every AI request (P0-07 AIP-01/02, S02 6.22.2).
 * - AI_ENABLED=false            → DisabledProvider, always
 * - AI_FREE_API_ONLY (locked)   → non-free providers are refused even if registered
 * - AI_MAX_BILLABLE_COST (0, locked)
 * - ALLOWED_PROVIDERS: rollout 1 allows only "disabled"; adding a real provider is a
 *   code change that must pass gates G-01–G-12 (P0-07 §3) and the TC-AI-00 test update.
 * Audit events ai.query / ai.disabled are reserved (AIP-05) and wired in phase 2.
 */
final class AiGateway
{
    public const ALLOWED_PROVIDERS = ['disabled'];

    private ProviderInterface $provider;

    public function __construct(private readonly AppConfig $config, private readonly Logger $logger)
    {
        $this->provider = new DisabledProvider();
    }

    public function register(ProviderInterface $provider): void
    {
        if (!in_array($provider->code(), self::ALLOWED_PROVIDERS, true)) {
            throw new \DomainException('AI provider not allowed in this rollout: ' . $provider->code());
        }
        if ($this->config->bool('AI_FREE_API_ONLY') && !$provider->isFree()) {
            throw new \DomainException('Only free AI providers are allowed (AI_FREE_API_ONLY is locked).');
        }
        $this->provider = $provider;
    }

    public function complete(AiRequest $request): AiResponse
    {
        if (!$this->config->bool('AI_ENABLED')) {
            $this->logger->info('ai.disabled', ['purpose' => $request->purpose]);
            return new AiResponse(false, 'ai.disabled');
        }
        return $this->provider->complete($request);
    }

    public function providerCode(): string
    {
        return $this->config->bool('AI_ENABLED') ? $this->provider->code() : 'disabled';
    }
}
