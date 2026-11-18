<?php

declare(strict_types=1);

namespace Blue\Core\AI;

final class AiRequest
{
    public function __construct(
        public readonly string $purpose,
        public readonly string $prompt,
        public readonly string $correlationId,
    ) {
    }
}
