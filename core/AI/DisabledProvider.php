<?php

declare(strict_types=1);

namespace Blue\Core\AI;

/**
 * Rollout 1 has no AI features (P0-07 §2). This is the ONLY registered provider:
 * it never opens a network connection and always answers "not enabled".
 */
final class DisabledProvider implements ProviderInterface
{
    public function code(): string
    {
        return 'disabled';
    }

    public function isFree(): bool
    {
        return true;
    }

    public function complete(AiRequest $request): AiResponse
    {
        return new AiResponse(false, 'ai.disabled');
    }
}
