<?php

declare(strict_types=1);

namespace Blue\Core\Org;

use Blue\Core\Authz\ScopedRepository;

final class SiteRepository extends ScopedRepository
{
    protected function table(): string
    {
        return 'sites';
    }

    protected function legalEntityColumn(): ?string
    {
        return 'legal_entity_id';
    }

    protected function siteColumn(): ?string
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
