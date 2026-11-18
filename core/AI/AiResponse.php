<?php

declare(strict_types=1);

namespace Blue\Core\AI;

final class AiResponse
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $messageKey,
        public readonly ?string $text = null,
        public readonly string $provider = 'disabled',
    ) {
    }
}
