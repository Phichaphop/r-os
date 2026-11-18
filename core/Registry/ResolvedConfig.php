<?php

declare(strict_types=1);

namespace Blue\Core\Registry;

/** Result of resolving a configuration key (6.25.2): value + where it came from, for snapshots and explanations. */
final class ResolvedConfig
{
    /** @param list<string> $path inheritance path that was searched, most specific first */
    public function __construct(
        public readonly string $key,
        public readonly mixed $value,
        public readonly ?string $unit,
        public readonly int $versionId,
        public readonly string $versionPublicId,
        public readonly string $sourceScope,
        public readonly int $sourceScopeId,
        public readonly string $effectiveFrom,
        public readonly string $resolvedAt,
        public readonly array $path,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'config_key' => $this->key, 'resolved_value' => $this->value, 'unit' => $this->unit,
            'version_id' => $this->versionPublicId, 'source_scope' => $this->sourceScope . ':' . $this->sourceScopeId,
            'effective_from' => $this->effectiveFrom, 'effective_at' => $this->resolvedAt, 'path' => $this->path,
        ];
    }
}
