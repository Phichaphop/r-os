<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\Log\Logger;
use Blue\Core\Support\FrozenClock;
use Blue\Tests\Support\TempDir;

/** 6.30.6 — logs carry the correlation ID and never secrets/personal identifiers. */
final class LoggerTest extends TestCase
{
    public function testWritesJsonLineWithCorrelationIdAndRedactsSecrets(): void
    {
        $dir = TempDir::make();
        $log = new Logger($dir, new FrozenClock('2026-11-02T00:00:00Z'), 'info');
        $log->setCorrelationId('01M4PQDJ7XWG9M9ZHH7D6DKCC9');
        $log->info('test', ['db_pass' => 'hunter2', 'nested' => ['api_key' => 'k', 'national_id' => '1234567890123', 'ok' => 'visible']]);
        $log->debug('below level is dropped');
        $lines = file($dir . '/blue-2026-11-02.log') ?: [];
        self::assertCount(1, $lines);
        $row = json_decode($lines[0], true);
        self::assertSame('01M4PQDJ7XWG9M9ZHH7D6DKCC9', $row['cid']);
        self::assertSame('[REDACTED]', $row['ctx']['db_pass']);
        self::assertSame('[REDACTED]', $row['ctx']['nested']['api_key']);
        self::assertSame('[REDACTED]', $row['ctx']['nested']['national_id']);
        self::assertSame('visible', $row['ctx']['nested']['ok']);
        self::assertStringNotContainsString('hunter2', $lines[0]);
        TempDir::remove($dir);
    }
}
