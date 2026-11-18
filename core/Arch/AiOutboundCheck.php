<?php

declare(strict_types=1);

namespace Blue\Core\Arch;

/**
 * TC-AI-00 / AIP-06 (P0-07): rollout 1 makes no AI calls.
 * 1. No source file outside core/AI references a known AI provider endpoint.
 * 2. core/AI itself contains no provider endpoint either (only DisabledProvider exists).
 * 3. DisabledProvider is the only class implementing ProviderInterface, and the gateway allow-list is ['disabled'].
 */
final class AiOutboundCheck
{
    /** Provider hosts/SDK markers — extend when evaluating providers in phase 10. */
    public const AI_MARKERS = [
        'api.openai.com', 'openai.azure.com', 'api.anthropic.com', 'generativelanguage.googleapis.com',
        'aiplatform.googleapis.com', 'api.groq.com', 'openrouter.ai', 'api.mistral.ai', 'api.cohere.',
        'api-inference.huggingface.co', 'router.huggingface.co', 'api.deepseek.com', 'api.together.',
        'api.perplexity.ai', 'api.x.ai', 'bedrock-runtime.', 'ollama', 'localhost:11434',
    ];

    public function __construct(private readonly string $root)
    {
    }

    /** @return list<string> problems */
    public function run(): array
    {
        $problems = [];
        foreach (FileScan::files($this->root, '', ['php', 'js', 'json', 'yml', 'yaml']) as $rel) {
            if (str_starts_with($rel, 'core/Arch/') || str_starts_with($rel, 'tests/')) {
                continue; // this check and its tests necessarily mention the markers
            }
            $src = strtolower((string) file_get_contents($this->root . '/' . $rel));
            foreach (self::AI_MARKERS as $marker) {
                if (str_contains($src, $marker)) {
                    $problems[] = "{$rel}: references AI endpoint marker '{$marker}'";
                }
            }
        }

        $implementors = [];
        foreach (FileScan::files($this->root, '', ['php']) as $rel) {
            if (str_starts_with($rel, 'tests/') || str_starts_with($rel, 'core/Arch/')) {
                continue;
            }
            $src = (string) file_get_contents($this->root . '/' . $rel);
            if (preg_match('/implements\s+[^{]*\bProviderInterface\b/', $src)) {
                $implementors[] = $rel;
            }
        }
        if ($implementors !== ['core/AI/DisabledProvider.php']) {
            $problems[] = 'Only core/AI/DisabledProvider.php may implement ProviderInterface in rollout 1; found: ' . implode(', ', $implementors);
        }
        $gateway = (string) @file_get_contents($this->root . '/core/AI/AiGateway.php');
        if ($gateway !== '' && !preg_match("/ALLOWED_PROVIDERS\\s*=\\s*\\['disabled'\\]\\s*;/", $gateway)) {
            $problems[] = "AiGateway::ALLOWED_PROVIDERS must be ['disabled'] in rollout 1";
        }
        return $problems;
    }
}
