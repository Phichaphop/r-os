<?php

declare(strict_types=1);

namespace Blue\Core\Database;

/** Small helpers so migrations stay re-runnable and checks stay readable. */
final class Schema
{
    public const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public static function columnExists(Connection $db, string $table, string $column): bool
    {
        return (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    }

    public static function indexExists(Connection $db, string $table, string $index): bool
    {
        return (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        ) > 0;
    }

    /**
     * @param list<string> $tables
     * @return list<string> problems
     */
    public static function expectTables(Connection $db, array $tables): array
    {
        $problems = [];
        foreach ($tables as $t) {
            if (!$db->tableExists($t)) {
                $problems[] = "missing table {$t}";
            }
        }
        return $problems;
    }

    /** Standard columns from P0-03 §0 for editable tables. */
    public static function standardColumns(): string
    {
        return "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_at DATETIME(6) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            row_version INT UNSIGNED NOT NULL DEFAULT 1";
    }
}
