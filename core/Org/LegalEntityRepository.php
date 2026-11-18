<?php

declare(strict_types=1);

namespace Blue\Core\Org;

use Blue\Core\Authz\ScopedRepository;

/** legal_entities: the legal entity column is the row itself. */
final class LegalEntityRepository extends ScopedRepository
{
    protected function table(): string
    {
        return 'legal_entities';
    }

    protected function legalEntityColumn(): ?string
    {
        return 'id';
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, ?int $actor): string
    {
        return $this->insertRow($data, $actor);
    }

    /** @param array<string, mixed> $changes */
    public function update(string $publicId, int $version, array $changes, ?int $actor): void
    {
        $this->updateRow($publicId, $version, $changes, $actor);
    }
}
