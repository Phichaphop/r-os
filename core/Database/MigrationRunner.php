<?php

declare(strict_types=1);

namespace Blue\Core\Database;

use Blue\Core\Log\Logger;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;
use RuntimeException;
use Throwable;

/**
 * Versioned migration runner — the ONLY way schema changes (DEC-16).
 *
 * Per migration: checksum drift check → pre-check → up() → post-check → record in schema_migrations.
 * Every step is written to migration_log (who/when/result/duration), so a failed run leaves evidence.
 * A named DB lock prevents two runners at once. Re-running with nothing pending is a no-op.
 */
final class MigrationRunner
{
    private const LOCK = 'blue_migrations';

    /** @param array<string, string> $paths module code => migration directory (core first) */
    public function __construct(
        private readonly Connection $db,
        private readonly array $paths,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /** @return list<array{version:string, module:string, file:string, checksum:string}> all migration files found, in run order */
    public function discover(): array
    {
        $found = [];
        foreach ($this->paths as $module => $dir) {
            foreach (glob(rtrim($dir, '/') . '/*.php') ?: [] as $file) {
                $base = basename($file, '.php');
                if (!preg_match('/^(\d{12})_[a-z0-9_]+$/', $base, $m)) {
                    throw new RuntimeException("Bad migration file name: {$base} (expected YYYYMMDDHHMM_desc)");
                }
                $found[] = [
                    'version' => $m[1] . '_' . substr($base, 13),
                    'module' => (string) $module,
                    'file' => $file,
                    'checksum' => hash_file('sha256', $file) ?: '',
                ];
            }
        }
        usort($found, static function (array $a, array $b): int {
            return [$a['version'], $a['module'] === 'core' ? 0 : 1, $a['module']]
                <=> [$b['version'], $b['module'] === 'core' ? 0 : 1, $b['module']];
        });
        return $found;
    }

    public function ensureBookkeeping(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(120) NOT NULL,
            module VARCHAR(40) NOT NULL,
            checksum CHAR(64) NOT NULL,
            recovery VARCHAR(20) NOT NULL,
            applied_at DATETIME(6) NOT NULL,
            applied_by VARCHAR(120) NOT NULL,
            duration_ms INT UNSIGNED NOT NULL,
            PRIMARY KEY (module, version)
        ) ' . Schema::TABLE_OPTIONS);
        $this->db->exec('CREATE TABLE IF NOT EXISTS migration_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id CHAR(26) NOT NULL,
            module VARCHAR(40) NOT NULL,
            version VARCHAR(120) NOT NULL,
            step VARCHAR(20) NOT NULL,
            status VARCHAR(10) NOT NULL,
            message TEXT NULL,
            actor VARCHAR(120) NOT NULL,
            logged_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_migration_log_run (run_id)
        ) ' . Schema::TABLE_OPTIONS);
    }

    /** @return array<string, array<string, mixed>> "module/version" => row */
    public function applied(): array
    {
        $this->ensureBookkeeping();
        $out = [];
        foreach ($this->db->all('SELECT * FROM schema_migrations ORDER BY applied_at') as $row) {
            $out[$row['module'] . '/' . $row['version']] = $row;
        }
        return $out;
    }

    /** @return list<array{version:string, module:string, file:string, checksum:string, state:string}> */
    public function status(): array
    {
        $applied = $this->applied();
        $out = [];
        foreach ($this->discover() as $m) {
            $key = $m['module'] . '/' . $m['version'];
            $state = 'pending';
            if (isset($applied[$key])) {
                $state = hash_equals((string) $applied[$key]['checksum'], $m['checksum']) ? 'applied' : 'DRIFT';
            }
            $out[] = $m + ['state' => $state];
        }
        return $out;
    }

    /**
     * @param bool $allowDestructive explicit approval for destructive migrations (6.30.11)
     * @return array{run_id:string, applied:list<string>, skipped:int}
     */
    public function migrate(string $actor, bool $allowDestructive = false): array
    {
        $this->ensureBookkeeping();
        if (!$this->db->acquireLock(self::LOCK, 5)) {
            throw new RuntimeException('Another migration run is in progress.');
        }
        $runId = Ulid::generate($this->clock);
        $done = [];
        $skipped = 0;
        try {
            $status = $this->status();
            foreach ($status as $m) {
                if ($m['state'] === 'DRIFT') {
                    $this->log($runId, $m, 'checksum', 'FAIL', 'Applied migration file was modified. Restore it and add a new migration instead.', $actor);
                    throw new RuntimeException("Checksum drift in {$m['module']}/{$m['version']} — refusing to continue.");
                }
            }
            foreach ($status as $m) {
                if ($m['state'] === 'applied') {
                    $skipped++;
                    continue;
                }
                $done[] = $this->runOne($runId, $m, $actor, $allowDestructive);
            }
            $this->logger->info('migrations_run', ['run_id' => $runId, 'applied' => $done, 'skipped' => $skipped]);
            return ['run_id' => $runId, 'applied' => $done, 'skipped' => $skipped];
        } finally {
            $this->db->releaseLock(self::LOCK);
        }
    }

    /** @param array{version:string, module:string, file:string, checksum:string, state:string} $m */
    private function runOne(string $runId, array $m, string $actor, bool $allowDestructive): string
    {
        $migration = require $m['file'];
        if (!$migration instanceof Migration) {
            throw new RuntimeException("{$m['file']} must return an instance of " . Migration::class);
        }
        if ($migration->destructive() && !$allowDestructive) {
            $this->log($runId, $m, 'approve', 'FAIL', 'Destructive migration requires approval (--approve-destructive).', $actor);
            throw new RuntimeException("{$m['module']}/{$m['version']} is destructive and was not approved.");
        }

        $problems = $migration->preCheck($this->db);
        if ($problems !== []) {
            $this->log($runId, $m, 'pre-check', 'FAIL', implode('; ', $problems), $actor);
            throw new RuntimeException("Pre-check failed for {$m['version']}: " . implode('; ', $problems));
        }
        $this->log($runId, $m, 'pre-check', 'PASS', null, $actor);

        $start = hrtime(true);
        try {
            $migration->up($this->db);
        } catch (Throwable $e) {
            $this->log($runId, $m, 'up', 'FAIL', $e->getMessage(), $actor);
            throw new RuntimeException("Migration {$m['version']} failed: " . $e->getMessage(), 0, $e);
        }
        $ms = (int) ((hrtime(true) - $start) / 1_000_000);

        $problems = $migration->postCheck($this->db);
        if ($problems !== []) {
            $this->log($runId, $m, 'post-check', 'FAIL', implode('; ', $problems), $actor);
            throw new RuntimeException("Post-check failed for {$m['version']}: " . implode('; ', $problems));
        }
        $this->log($runId, $m, 'post-check', 'PASS', null, $actor);

        $this->db->run(
            'INSERT INTO schema_migrations (version, module, checksum, recovery, applied_at, applied_by, duration_ms) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$m['version'], $m['module'], $m['checksum'], $migration->recovery(), $this->now(), $actor, $ms]
        );
        $this->log($runId, $m, 'record', 'PASS', $migration->description() . " ({$ms} ms)", $actor);
        return $m['module'] . '/' . $m['version'];
    }

    /** @param array{version:string, module:string} $m */
    private function log(string $runId, array $m, string $step, string $status, ?string $message, string $actor): void
    {
        $this->db->run(
            'INSERT INTO migration_log (run_id, module, version, step, status, message, actor, logged_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$runId, $m['module'], $m['version'], $step, $status, $message, $actor, $this->now()]
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
