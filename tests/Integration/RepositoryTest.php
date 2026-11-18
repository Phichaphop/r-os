<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Database\ConcurrencyException;
use Blue\Core\Database\Connection;
use Blue\Core\Database\Repository;
use Blue\Core\Database\Schema;
use Blue\Core\Support\FrozenClock;
use Blue\Core\Support\Ulid;
use Blue\Tests\Support\TestDb;

/** P0-02 §10: public_id in URLs, optimistic locking with row_version, transactions. */
final class RepositoryTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = TestDb::fresh($this);
        $this->db->exec('CREATE TABLE demo_items (' . Schema::standardColumns() . ', name VARCHAR(100) NOT NULL, PRIMARY KEY (id), UNIQUE KEY uq_demo_items_public (public_id)) ' . Schema::TABLE_OPTIONS);
    }

    private function repo(): Repository
    {
        return new class ($this->db, new FrozenClock()) extends Repository {
            protected function table(): string
            {
                return 'demo_items';
            }

            public function create(string $name): string
            {
                return $this->insertRow(['name' => $name], 7);
            }

            public function rename(string $id, int $version, string $name): void
            {
                $this->updateRow($id, $version, ['name' => $name], 7);
            }
        };
    }

    public function testInsertAndFindByPublicId(): void
    {
        $repo = $this->repo();
        $id = $repo->create('สมชาย');
        self::assertTrue(Ulid::isValid($id));
        $row = $repo->findByPublicId($id);
        self::assertSame('สมชาย', $row['name']);
        self::assertSame(1, (int) $row['row_version']);
        self::assertNull($repo->findByPublicId("1' OR '1'='1"), 'invalid ids never reach SQL');
    }

    public function testStaleUpdateIsRejected(): void
    {
        $repo = $this->repo();
        $id = $repo->create('A');
        $repo->rename($id, 1, 'B');
        self::assertSame(2, (int) $repo->findByPublicId($id)['row_version']);
        $this->expectException(ConcurrencyException::class);
        $repo->rename($id, 1, 'C');
    }

    public function testTransactionRollsBackOnError(): void
    {
        $repo = $this->repo();
        try {
            $this->db->transactional(function () use ($repo): void {
                $repo->create('will be rolled back');
                throw new \RuntimeException('fail');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM demo_items'));
    }
}
