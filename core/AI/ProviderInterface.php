<?php

declare(strict_types=1);

namespace Blue\Core\AI;

/**
 * The single doorway to any AI provider (P0-07 AIP-01, S02 6.22.2).
 * Modules never call AI APIs directly — they ask AiGateway, which picks the provider.
 */
interface ProviderInterface
{
    public function code(): string;

    /** True only for providers that cost nothing (FREE_API_ONLY is locked — AIP-02). */
    public function isFree(): bool;

    public function complete(AiRequest $request): AiResponse;
}
