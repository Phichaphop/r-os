<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Database\Connection;
use Blue\Core\Log\Logger;
use Blue\Core\Database\MigrationRunner;
use Blue\Core\Support\FrozenClock;
use Blue\Tests\Support\TempDir;
use Blue\Tests\Support\TestDb;
use RuntimeException;

/** DEC-16 + phase-1 gate "migration runner re-runs without breaking" (TC-RES). */
final class MigrationRunnerTest extends TestCase
{
    private Connection $db;
    private string $tmp;
    private Logger $logger;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->db = TestDb::fresh($this);
        $this->tmp = TempDir::make();
        mkdir($this->tmp . '/mig');
        $this->clock = new FrozenClock();
        $this->logger = new Logger($this->tmp . '/logs', $this->clock);
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    private function runner(array $extra = []): MigrationRunner
    {
        return new MigrationRunner($this->db, ['core' => __DIR__ . '/../../core/migrations'] + $extra, $this->clock, $this->logger);
    }

    /** @return list<string> */
    private function coreMigrations(): array
    {
        return array_map(static fn (string $f): string => 'core/' . basename($f, '.php'), glob(__DIR__ . '/../../core/migrations/*.php') ?: []);
    }

    private function writeMigration(string $name, string $body, string $extraMethods = ''): void
    {
        file_put_contents("{$this->tmp}/mig/{$name}.php", "<?php\nuse Blue\\Core\\Database\\Connection;\nuse Blue\\Core\\Database\\Migration;\n"
            . "return new class extends Migration {\n public function description(): string { return 'test'; }\n"
            . " public function up(Connection \$db): void { {$body} }\n{$extraMethods}\n};\n");
    }

    public function testFreshInstallThenRerunIsANoOp(): void
    {
        $first = $this->runner()->migrate('test');
        $all = $this->coreMigrations();
        self::assertSame($all, $first['applied']);
        self::assertTrue($this->db->tableExists('i18n_messages'));

        $second = $this->runner()->migrate('test');
        self::assertSame([], $second['applied']);
        self::assertSame(count($all), $second['skipped']);
        self::assertSame(count($all), (int) $this->db->scalar('SELECT COUNT(*) FROM schema_migrations'));
        self::assertSame(3 * count($all), (int) $this->db->scalar("SELECT COUNT(*) FROM migration_log WHERE status = 'PASS'"), 'pre-check, post-check, record');
    }

    public function testUpIsSafeToReRunAfterAPartialFailure(): void
    {
        // Simulate a crash after DDL but before bookkeeping: tables exist, no schema_migrations row.
        $this->runner()->migrate('test');
        $this->db->exec('DELETE FROM schema_migrations');
        $again = $this->runner()->migrate('test');
        self::assertSame($this->coreMigrations(), $again['applied']);
    }

    public function testModuleMigrationsRunAfterCoreInVersionOrder(): void
    {
        $this->writeMigration('202611020002_create_demo', 'Blue\\Core\\Database\\Schema::class; $db->exec("CREATE TABLE IF NOT EXISTS t_demo (id INT PRIMARY KEY) ENGINE=InnoDB");');
        $res = $this->runner(['demo' => $this->tmp . '/mig'])->migrate('test');
        self::assertSame(['core/202611020001_platform_base', 'demo/202611020002_create_demo'], array_slice($res['applied'], 0, 2), 'version order, core first on ties');
    }

    public function testEditedAppliedMigrationIsDetectedAndRefused(): void
    {
        $this->writeMigration('202611020002_create_demo', '$db->exec("CREATE TABLE IF NOT EXISTS t_demo (id INT PRIMARY KEY)");');
        $this->runner(['demo' => $this->tmp . '/mig'])->migrate('test');
        $this->writeMigration('202611020002_create_demo', '$db->exec("CREATE TABLE IF NOT EXISTS t_demo (id BIGINT PRIMARY KEY)");');

        $states = array_column($this->runner(['demo' => $this->tmp . '/mig'])->status(), 'state', 'version');
        self::assertSame('DRIFT', $states['202611020002_create_demo']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum drift');
        $this->runner(['demo' => $this->tmp . '/mig'])->migrate('test');
    }

    public function testFailedPreCheckStopsAndLeavesEvidence(): void
    {
        $this->writeMigration('202611020002_needs_table', '$db->exec("SELECT 1");', 'public function preCheck(Connection $db): array { return ["required table x missing"]; }');
        try {
            $this->runner(['demo' => $this->tmp . '/mig'])->migrate('test');
            self::fail('expected failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Pre-check failed', $e->getMessage());
        }
        self::assertSame(0, (int) $this->db->scalar("SELECT COUNT(*) FROM schema_migrations WHERE module = 'demo'"));
        self::assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM migration_log WHERE module = 'demo' AND step = 'pre-check' AND status = 'FAIL'"));
    }

    public function testDestructiveMigrationNeedsExplicitApproval(): void
    {
        $this->writeMigration('202611020002_drop_old', '$db->exec("DROP TABLE IF EXISTS t_old");', 'public function destructive(): bool { return true; }');
        try {
            $this->runner(['demo' => $this->tmp . '/mig'])->migrate('test');
            self::fail('expected refusal');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not approved', $e->getMessage());
        }
        $ok = $this->runner(['demo' => $this->tmp . '/mig'])->migrate('test', true);
        self::assertContains('demo/202611020002_drop_old', $ok['applied']);
    }

    public function testBadFileNameIsRejected(): void
    {
        file_put_contents($this->tmp . '/mig/create_stuff.php', '<?php return null;');
        $this->expectException(RuntimeException::class);
        $this->runner(['demo' => $this->tmp . '/mig'])->discover();
    }

    public function testConcurrentRunIsBlockedByLock(): void
    {
        $other = TestDb::connection();
        self::assertTrue($other->acquireLock('blue_migrations', 0));
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('in progress');
            (new MigrationRunner($this->db, ['core' => __DIR__ . '/../../core/migrations'], $this->clock, $this->logger))->migrate('test');
        } finally {
            $other->releaseLock('blue_migrations');
        }
    }

    public function testThaiTextRoundTripsThroughUtf8mb4(): void
    {
        $this->runner()->migrate('test');
        $text = 'ลาพักร้อน ๖ วัน — ทดสอบสระ ำ ั้ ๊ และ emoji 🙂';
        $this->db->run("INSERT INTO i18n_messages (locale, message_key, message, updated_at) VALUES ('th', 't.k', ?, NOW(6))", [$text]);
        self::assertSame($text, $this->db->scalar("SELECT message FROM i18n_messages WHERE message_key = 't.k'"));
    }
}
