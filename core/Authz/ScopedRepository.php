<?php

declare(strict_types=1);

namespace Blue\Core\Authz;

use Blue\Core\Database\Repository;

/**
 * The ONLY way business data is read or changed (DEC-10, P0-02 §4 rule 4).
 * Every query carries the caller's ScopeSet; a row outside the scope behaves exactly like a
 * row that does not exist (404, never 403) so IDs cannot be probed across companies.
 */
abstract class ScopedRepository extends Repository
{
    /** Column holding the legal entity of a row (for legal_entities itself: 'id'). */
    abstract protected function legalEntityColumn(): ?string;

    protected function siteColumn(): ?string
    {
        return null;
    }

    protected function departmentColumn(): ?string
    {
        return null;
    }

    /** @return array{0:string, 1:list<int>} */
    protected function scopeSql(ScopeSet $scope, string $alias = 't'): array
    {
        $q = static fn (?string $c): ?string => $c === null ? null : "{$alias}.`{$c}`";
        return $scope->sql($q($this->legalEntityColumn()), $q($this->siteColumn()), $q($this->departmentColumn()));
    }

    /** @return array<string, mixed>|null */
    public function findInScope(string $publicId, ScopeSet $scope): ?array
    {
        $row = $this->findByPublicId($publicId);
        if ($row === null) {
            return null;
        }
        [$where, $params] = $this->scopeSql($scope);
        $ok = $this->db->scalar("SELECT 1 FROM `{$this->table()}` t WHERE t.id = ? AND {$where}", [(int) $row['id'], ...$params]);
        return $ok ? $row : null;
    }

    /**
     * @param array<string, scalar|null> $filters exact-match filters on own columns
     * @return array{rows:list<array<string,mixed>>, total:int}
     */
    public function listInScope(ScopeSet $scope, array $filters = [], int $page = 1, int $perPage = 20, string $orderBy = 't.id'): array
    {
        [$where, $params] = $this->scopeSql($scope);
        foreach ($filters as $col => $val) {
            if (!preg_match('/^[a-z_]+$/', (string) $col)) {
                throw new \InvalidArgumentException('Bad filter column');
            }
            $where .= " AND t.`{$col}` " . ($val === null ? 'IS NULL' : '= ?');
            if ($val !== null) {
                $params[] = $val;
            }
        }
        $perPage = max(1, min(300, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM `{$this->table()}` t WHERE {$where}", $params);
        $rows = $this->db->all("SELECT t.* FROM `{$this->table()}` t WHERE {$where} ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}", $params);
        return ['rows' => $rows, 'total' => $total];
    }
}
