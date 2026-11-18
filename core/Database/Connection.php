<?php

declare(strict_types=1);

namespace Blue\Core\Database;

use PDO;
use PDOStatement;
use Blue\Core\Config\AppConfig;
use Throwable;

/**
 * Thin PDO wrapper (lazy connect, UTC session, utf8mb4, exceptions, real prepares).
 * Application services own transactions (P0-02 §10) via transactional().
 */
final class Connection
{
    private ?PDO $pdo = null;

    private int $depth = 0;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
    ) {
    }

    public static function fromConfig(AppConfig $c): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c->string('DB_HOST'),
            $c->int('DB_PORT', 3306),
            $c->string('DB_NAME'),
            $c->string('DB_CHARSET', 'utf8mb4')
        );
        return new self($dsn, $c->string('DB_USER'), $c->string('DB_PASS'));
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO($this->dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            // Store and compare everything in UTC (P0-03 §0); strict mode prevents silent truncation.
            $this->pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        }
        return $this->pdo;
    }

    /** Drops the PDO handle (the next call reconnects). Long-running processes and tests use it to give the connection back. */
    public function close(): void
    {
        if ($this->depth === 0) {
            $this->pdo = null;
        }
    }

    /** @param array<int|string, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string, mixed> $params */
    public function scalar(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function exec(string $sql): void
    {
        $this->pdo()->exec($sql);
    }

    public function tableExists(string $table): bool
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    }

    /**
     * Runs $fn inside a transaction. Nested calls join the outer transaction.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transactional(callable $fn): mixed
    {
        $pdo = $this->pdo();
        if ($this->depth === 0) {
            $pdo->beginTransaction();
        }
        $this->depth++;
        try {
            $result = $fn($this);
            $this->depth--;
            if ($this->depth === 0) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            $this->depth--;
            if ($this->depth === 0 && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Named advisory lock (prevents concurrent migrations / scheduler runs). */
    public function acquireLock(string $name, int $timeoutSeconds = 0): bool
    {
        return (int) $this->scalar('SELECT GET_LOCK(?, ?)', [$name, $timeoutSeconds]) === 1;
    }

    public function releaseLock(string $name): void
    {
        $this->run('SELECT RELEASE_LOCK(?)', [$name]);
    }
}
