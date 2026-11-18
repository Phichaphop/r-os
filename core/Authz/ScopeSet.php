<?php

declare(strict_types=1);

namespace Blue\Core\Authz;

/**
 * The data a user may touch for ONE permission (DEC-10/21): union of the access_scopes of every
 * active assignment whose role grants that permission. Empty = nothing (deny-by-default).
 */
final class ScopeSet
{
    /**
     * @param list<int> $legalEntityIds
     * @param list<int> $siteIds
     * @param list<int> $departmentIds
     */
    public function __construct(
        public readonly bool $all = false,
        public readonly array $legalEntityIds = [],
        public readonly array $siteIds = [],
        public readonly array $departmentIds = [],
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return !$this->all && $this->legalEntityIds === [] && $this->siteIds === [] && $this->departmentIds === [];
    }

    /** Distinct legal entities reachable — more than one means "cross-company" (MFA required, company column shown). */
    public function crossCompany(): bool
    {
        return $this->all || count($this->legalEntityIds) > 1;
    }

    /** Whole-company coverage (company-level business data such as journals and payment batches). */
    public function coversLegalEntity(?int $legalEntityId): bool
    {
        return $this->all || ($legalEntityId !== null && in_array($legalEntityId, $this->legalEntityIds, true));
    }

    /** @return list<int>|null legal entities covered (null = all) */
    public function legalEntities(): ?array
    {
        return $this->all ? null : $this->legalEntityIds;
    }

    /**
     * SQL condition + params for a table. Columns that a table does not have are passed as null.
     *
     * @return array{0:string, 1:list<int>}
     */
    public function sql(?string $leColumn, ?string $siteColumn = null, ?string $deptColumn = null): array
    {
        if ($this->all) {
            return ['1=1', []];
        }
        $parts = [];
        $params = [];
        foreach ([[$leColumn, $this->legalEntityIds], [$siteColumn, $this->siteIds], [$deptColumn, $this->departmentIds]] as [$col, $ids]) {
            if ($col !== null && $ids !== []) {
                $parts[] = $col . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                array_push($params, ...$ids);
            }
        }
        return $parts === [] ? ['1=0', []] : ['(' . implode(' OR ', $parts) . ')', $params];
    }
}
