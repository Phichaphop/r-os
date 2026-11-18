<?php

declare(strict_types=1);

namespace Blue\Core\Backup;

use PDO;
use RuntimeException;

/**
 * Database export/import in plain PHP (M48 item 1: "PHP export อ่านทีละตาราง เขียน chunk กัน timeout" — works where
 * mysqldump is not available, e.g. shared hosting). One gzip file per table: DROP + CREATE, then multi-row INSERTs.
 * Every statement is written on ONE line (CREATE statements are folded; values are escaped so they never contain a raw
 * line break), which lets the loader stream any file size. Binary columns are written as hex. Rows are read in primary
 * key order so a second dump of a restored copy gives byte-identical data lines — the restore drill compares them.
 * Triggers (append-only guards) go to their own file and are created after all data is loaded.
 */
final class SqlDumper
{
    private const BATCH_ROWS = 500;
    private const BATCH_BYTES = 1 << 20;
    private const BINARY = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'];
    private const INTEGER = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];

    /** @return list<string> base tables of the current database */
    public static function tables(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) ?? [] as $r) {
            $out[] = (string) $r[0];
        }
        sort($out);
        return $out;
    }

    /**
     * Dumps (part of) a table. $cursor = null starts the file; returns the cursor to continue with, or null when done.
     *
     * @param array{last?: int|string, offset?: int}|null $cursor
     * @return array{cursor: array{last?: int|string, offset?: int}|null, rows: int}
     */
    public static function dumpTable(PDO $pdo, string $table, string $gzPath, bool $withData, ?array $cursor, float $deadline): array
    {
        self::assertName($table);
        $gz = gzopen($gzPath, $cursor === null ? 'wb6' : 'ab6');
        if ($gz === false) {
            throw new RuntimeException('Cannot write ' . basename($gzPath));
        }
        try {
            if ($cursor === null) {
                $create = (string) ($pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1] ?? '');
                $create = (string) preg_replace('/\s+AUTO_INCREMENT=\d+/', '', $create);
                gzwrite($gz, 'DROP TABLE IF EXISTS `' . $table . "`;\n" . self::oneLine($create) . ";\n");
                $cursor = [];
            }
            if (!$withData) {
                return ['cursor' => null, 'rows' => 0];
            }
            [$columns, $binary, $pk, $intPk] = self::columns($pdo, $table);
            $colList = '`' . implode('`,`', $columns) . '`';
            $order = $pk !== [] ? ' ORDER BY `' . implode('`,`', $pk) . '`' : '';
            $rows = 0;
            while (true) {
                if ($intPk) {
                    $stmt = $pdo->prepare('SELECT ' . $colList . ' FROM `' . $table . '` WHERE `' . $pk[0] . '` > ?' . $order . ' LIMIT ' . self::BATCH_ROWS);
                    $stmt->execute([$cursor['last'] ?? PHP_INT_MIN]);
                } else {
                    $stmt = $pdo->query('SELECT ' . $colList . ' FROM `' . $table . '`' . $order . ' LIMIT ' . self::BATCH_ROWS . ' OFFSET ' . (int) ($cursor['offset'] ?? 0));
                }
                $batch = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_NUM) : [];
                if ($batch === []) {
                    return ['cursor' => null, 'rows' => $rows];
                }
                $values = [];
                $bytes = 0;
                foreach ($batch as $row) {
                    $v = [];
                    foreach ($row as $i => $cell) {
                        if ($cell === null) {
                            $v[] = 'NULL';
                        } elseif (isset($binary[$i])) {
                            $v[] = $cell === '' ? "''" : '0x' . bin2hex((string) $cell);
                        } else {
                            $v[] = $pdo->quote((string) $cell);
                        }
                    }
                    $tuple = '(' . implode(',', $v) . ')';
                    $values[] = $tuple;
                    $bytes += strlen($tuple);
                    if ($bytes >= self::BATCH_BYTES) {
                        gzwrite($gz, 'INSERT INTO `' . $table . '` (' . $colList . ') VALUES ' . implode(',', $values) . ";\n");
                        $values = [];
                        $bytes = 0;
                    }
                }
                if ($values !== []) {
                    gzwrite($gz, 'INSERT INTO `' . $table . '` (' . $colList . ') VALUES ' . implode(',', $values) . ";\n");
                }
                $rows += count($batch);
                if ($intPk) {
                    $cursor = ['last' => $batch[count($batch) - 1][array_search($pk[0], $columns, true)]];
                } else {
                    $cursor = ['offset' => (int) ($cursor['offset'] ?? 0) + count($batch)];
                }
                if (count($batch) < self::BATCH_ROWS) {
                    return ['cursor' => null, 'rows' => $rows];
                }
                if (microtime(true) >= $deadline) {
                    return ['cursor' => $cursor, 'rows' => $rows];
                }
            }
        } finally {
            gzclose($gz);
        }
    }

    /** Triggers of the current database (append-only guards) — created after all data on restore. */
    public static function dumpTriggers(PDO $pdo, string $gzPath): int
    {
        $gz = gzopen($gzPath, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('Cannot write triggers');
        }
        $n = 0;
        try {
            $names = $pdo->query('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME')->fetchAll(PDO::FETCH_COLUMN) ?? [];
            foreach ($names as $name) {
                self::assertName((string) $name);
                $row = $pdo->query('SHOW CREATE TRIGGER `' . $name . '`')->fetch(PDO::FETCH_ASSOC) ?: [];
                $sql = (string) ($row['SQL Original Statement'] ?? '');
                $sql = (string) preg_replace('/^CREATE\s+DEFINER=`[^`]*`@`[^`]*`\s+/i', 'CREATE ', $sql); // no definer: restorable by another DB user
                gzwrite($gz, 'DROP TRIGGER IF EXISTS `' . $name . "`;\n" . self::oneLine($sql) . ";\n");
                $n++;
            }
        } finally {
            gzclose($gz);
        }
        return $n;
    }

    /**
     * Executes a dump file statement by statement.
     *
     * @return int statements run
     */
    public static function load(PDO $pdo, string $gzPath): int
    {
        $fh = fopen('compress.zlib://' . $gzPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Cannot read ' . basename($gzPath));
        }
        $n = 0;
        try {
            while (($line = fgets($fh)) !== false) {
                $sql = rtrim($line, "\r\n");
                if ($sql === '') {
                    continue;
                }
                if (str_ends_with($sql, ';')) {
                    $sql = substr($sql, 0, -1);
                }
                $pdo->exec($sql);
                $n++;
            }
        } finally {
            fclose($fh);
        }
        return $n;
    }

    /** SHA-256 over the INSERT lines only (structure text may differ between servers; data must not). */
    public static function dataHash(string $gzPath): string
    {
        $fh = fopen('compress.zlib://' . $gzPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Cannot read ' . basename($gzPath));
        }
        $h = hash_init('sha256');
        try {
            while (($line = fgets($fh)) !== false) {
                if (str_starts_with($line, 'INSERT INTO ')) {
                    hash_update($h, $line);
                }
            }
        } finally {
            fclose($fh);
        }
        return hash_final($h);
    }

    /** @return array{0: list<string>, 1: array<int, true>, 2: list<string>, 3: bool} columns, binary column indexes, primary key, single integer key */
    private static function columns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $stmt->execute([$table]);
        $cols = [];
        $binary = [];
        $types = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i => $c) {
            $cols[] = (string) $c['COLUMN_NAME'];
            $types[(string) $c['COLUMN_NAME']] = strtolower((string) $c['DATA_TYPE']);
            if (in_array(strtolower((string) $c['DATA_TYPE']), self::BINARY, true)) {
                $binary[$i] = true;
            }
        }
        $pk = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION");
        $pk->execute([$table]);
        $keys = array_map('strval', $pk->fetchAll(PDO::FETCH_COLUMN));
        $intPk = count($keys) === 1 && in_array($types[$keys[0]] ?? '', self::INTEGER, true);
        return [$cols, $binary, $keys, $intPk];
    }

    private static function oneLine(string $sql): string
    {
        return trim((string) preg_replace('/\s*\R\s*/', ' ', $sql));
    }

    private static function assertName(string $name): void
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new RuntimeException('Unexpected object name');
        }
    }
}
