<?php

declare(strict_types=1);

namespace Blue\Tests\Support;

use PHPUnit\Framework\TestCase;
use Blue\Core\Database\Connection;
use Throwable;

/**
 * Integration tests run against a real MariaDB/MySQL test database (P0-06 §6.1).
 * Configure with BLUE_TEST_DB_HOST / _PORT / _NAME / _USER / _PASS (CI sets them for the service container).
 */
final class TestDb
{
    /** @return array{DB_HOST:string, DB_PORT:int, DB_NAME:string, DB_USER:string, DB_PASS:string} */
    public static function creds(): array
    {
        return [
            'DB_HOST' => getenv('BLUE_TEST_DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => (int) (getenv('BLUE_TEST_DB_PORT') ?: 3306),
            'DB_NAME' => getenv('BLUE_TEST_DB_NAME') ?: 'blue_test',
            'DB_USER' => getenv('BLUE_TEST_DB_USER') ?: 'blue',
            'DB_PASS' => getenv('BLUE_TEST_DB_PASS') ?: 'blue_dev_pw',
        ];
    }

    public static function connection(): Connection
    {
        $c = self::creds();
        return new Connection(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['DB_HOST'], $c['DB_PORT'], $c['DB_NAME']),
            $c['DB_USER'],
            $c['DB_PASS']
        );
    }

    /** Fresh, empty test database — or fail loudly (never silently skip: P0-06 reporting rule). */
    public static function fresh(TestCase $test): Connection
    {
        try {
            $db = self::connection();
            $db->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($db->all('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $row) {
                $db->exec('DROP TABLE IF EXISTS `' . $row['TABLE_NAME'] . '`');
            }
            $db->exec('SET FOREIGN_KEY_CHECKS = 1');
            return $db;
        } catch (Throwable $e) {
            $test->fail('Test database unavailable: ' . $e->getMessage());
        }
    }
}
