<?php

declare(strict_types=1);

namespace Blue\Core\Database;

use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * Repository base for tables that follow the P0-03 §0 standard
 * (id, public_id, created_*, updated_*, row_version).
 *
 * Phase 1 provides the mechanics only. From phase 2 every BUSINESS table must be
 * accessed through ScopedRepository (DEC-10) which extends this class and adds
 * the mandatory legal_entity/site scope filter; this base is allowed directly
 * only for core platform tables (P0-02 §4 rule 4).
 */
abstract class Repository
{
    public function __construct(
        protected readonly Connection $db,
        protected readonly Clock $clock,
    ) {
    }

    abstract protected function table(): string;

    /** @return array<string, mixed>|null */
    public function findByPublicId(string $publicId): ?array
    {
        if (!Ulid::isValid($publicId)) {
            return null;
        }
        return $this->db->one("SELECT * FROM `{$this->table()}` WHERE public_id = ?", [$publicId]);
    }

    /**
     * @param array<string, mixed> $data
     * @return string public_id of the new row
     */
    protected function insertRow(array $data, ?int $actorId = null): string
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $data += [
            'public_id' => Ulid::generate($this->clock),
            'created_at' => $now,
            'created_by' => $actorId,
            'updated_at' => $now,
            'updated_by' => $actorId,
            'row_version' => 1,
        ];
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $this->table(),
            implode('`, `', $cols),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        $this->db->run($sql, array_values($data));
        return (string) $data['public_id'];
    }

    /**
     * Optimistic-lock update (P0-02 §10 Concurrency).
     *
     * @param array<string, mixed> $changes
     * @throws ConcurrencyException when the row was changed by someone else
     */
    protected function updateRow(string $publicId, int $expectedVersion, array $changes, ?int $actorId = null): void
    {
        $changes['updated_at'] = $this->clock->now()->format('Y-m-d H:i:s.u');
        $changes['updated_by'] = $actorId;
        $set = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($changes)));
        $stmt = $this->db->run(
            "UPDATE `{$this->table()}` SET {$set}, row_version = row_version + 1 WHERE public_id = ? AND row_version = ?",
            [...array_values($changes), $publicId, $expectedVersion]
        );
        if ($stmt->rowCount() !== 1) {
            throw new ConcurrencyException($this->table(), $publicId);
        }
    }
}
