<?php

declare(strict_types=1);

namespace Blue\Core\Kpi;

/** One company, optionally narrowed to sites / departments, over a date range (inclusive). */
final class MeasureQuery
{
    /**
     * @param list<int> $siteIds
     * @param list<int> $departmentIds
     */
    public function __construct(
        public readonly int $legalEntityId,
        public readonly string $from,
        public readonly string $to,
        public readonly array $siteIds = [],
        public readonly array $departmentIds = [],
    ) {
    }

    /**
     * SQL condition for a table's site / department columns (null = the table has no such column).
     *
     * @return array{0: string, 1: list<int>}
     */
    public function where(?string $siteColumn, ?string $deptColumn): array
    {
        $parts = [];
        $params = [];
        foreach ([[$siteColumn, $this->siteIds], [$deptColumn, $this->departmentIds]] as [$col, $ids]) {
            if ($col !== null && $ids !== []) {
                $parts[] = $col . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                array_push($params, ...$ids);
            }
        }
        return [$parts === [] ? '1=1' : implode(' AND ', $parts), $params];
    }
}
