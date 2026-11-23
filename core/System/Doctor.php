<?php

declare(strict_types=1);

namespace Blue\Core\System;

use Blue\Core\App;
use Blue\Core\Database\MigrationRunner;
use Blue\Core\Support\Crypto;
use Throwable;

/**
 * `php bin/blue doctor` (0.50.0 · R9, DEC-119 · Q-17/Q-18): is this installation fit to hold real data?
 * Read-only — it changes nothing. Each check is PASS, WARN or FAIL; any FAIL makes the command exit 1.
 * Written for the Staging notebook (Katana 17 B13V, XAMPP, this machine only) which holds real data until
 * a production server exists, so the checks are the ones that matter there: versions, secrets, the
 * database account, migrations, storage, the scheduler, backups, the off-site copy, the restore drill and
 * demo data. Nothing secret is printed (the APP_KEY appears only as the backup-key fingerprint).
 */
final class Doctor
{
    public const PASS = 'PASS';
    public const WARN = 'WARN';
    public const FAIL = 'FAIL';

    /** MariaDB LTS the CI runs on; anything older is no longer maintained upstream. */
    private const MARIADB_LTS = '10.11';

    public function __construct(private readonly App $app)
    {
    }

    /** @return list<array{status: string, check: string, detail: string}> */
    public function run(): array
    {
        $out = [];
        $add = static function (string $status, string $check, string $detail) use (&$out): void {
            $out[] = ['status' => $status, 'check' => $check, 'detail' => $detail];
        };
        $env = $this->app->config->env();
        $live = in_array($env, ['staging', 'production'], true);

        $add($live ? self::PASS : self::WARN, 'environment', 'APP_ENV=' . $env . ($live ? '' : ' — these checks are meant for staging/production'));
        $add(version_compare(PHP_VERSION, '8.1.0', '>=') ? self::PASS : self::FAIL, 'php', PHP_VERSION);
        $missing = array_values(array_filter(['pdo_mysql', 'mbstring', 'json'], static fn (string $e): bool => !extension_loaded($e)));
        if (Crypto::backends() === []) {
            $missing[] = 'openssl (aes-256-gcm) or sodium';
        }
        $add($missing === [] ? self::PASS : self::FAIL, 'php extensions', $missing === [] ? 'pdo_mysql, mbstring, json, ' . implode('/', Crypto::backends()) : 'missing: ' . implode(', ', $missing));
        $add(function_exists('imagecreatefromstring') ? self::PASS : self::WARN, 'php gd', function_exists('imagecreatefromstring') ? 'images are resized on the server' : 'extension=gd is off — images are resized in the browser only');

        $key = $this->app->config->string('APP_KEY');
        if ($key === '' || str_contains($key, 'CHANGE_ME')) {
            $add(self::FAIL, 'app key', 'APP_KEY is not set in config/env.php');
        } else {
            $add(self::PASS, 'app key', 'backup key fingerprint ' . $this->app->backups()->cipher()->fingerprint() . ' — keep a copy of APP_KEY away from this machine');
        }

        try {
            $db = $this->app->db();
            $version = (string) $db->scalar('SELECT VERSION()');
        } catch (Throwable $e) {
            $add(self::FAIL, 'database', 'cannot connect: ' . mb_substr($e->getMessage(), 0, 120));
            return $out;
        }
        $add(...$this->dbVersion($version));
        $user = $this->app->config->string('DB_USER');
        if ($user === 'root' && $this->app->config->string('DB_PASS') === '') {
            $add($live ? self::FAIL : self::WARN, 'database account', 'root without a password (the XAMPP default) — set a root password and use a separate account for Blue');
        } elseif ($user === 'root') {
            $add(self::WARN, 'database account', 'Blue connects as root — a separate account with rights on its own databases is safer');
        } else {
            $add(self::PASS, 'database account', $user);
        }

        $states = array_count_values(array_column((new MigrationRunner($db, $this->app->migrationPaths(), $this->app->clock, $this->app->logger))->status(), 'state'));
        if (($states['DRIFT'] ?? 0) > 0) {
            $add(self::FAIL, 'migrations', ($states['DRIFT']) . ' changed after they ran (DRIFT) — see php bin/blue migrate:status');
        } elseif (($states['pending'] ?? 0) > 0) {
            $add(self::FAIL, 'migrations', $states['pending'] . ' pending — run php bin/blue migrate --backup-confirmed');
        } else {
            $add(self::PASS, 'migrations', ($states['applied'] ?? 0) . ' applied');
        }

        $bad = [];
        foreach (['LOG_PATH' => 'storage/logs', 'BACKUP_PATH' => 'storage/backups', 'DOCUMENTS_PATH' => 'storage/documents', 'PDF_TEMP_PATH' => 'storage/pdf-temp'] as $k => $default) {
            $dir = rtrim($this->app->path($this->app->config->string($k, $default)), '/\\');
            if (!(is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir)))) {
                $bad[] = $k;
            }
        }
        if (!is_writable($this->app->root . '/storage/locks')) {
            $bad[] = 'storage/locks';
        }
        $add($bad === [] ? self::PASS : self::FAIL, 'storage', $bad === [] ? 'logs, backups, documents, pdf-temp and locks are writable' : 'not writable: ' . implode(', ', $bad));
        $add($this->app->install->isLocked() ? self::PASS : self::FAIL, 'installed', $this->app->install->isLocked() ? 'storage/install.lock present' : 'not installed');

        $scheduler = $this->app->config->string('SCHEDULER', 'piggyback');
        $beat = $db->one("SELECT info_value, updated_at FROM system_info WHERE info_key = 'scheduler_last_run'");
        $beatAge = $beat !== null ? $this->minutesSince((string) $beat['updated_at']) : null;
        if ($live && $scheduler !== 'cron') {
            $add(self::WARN, 'scheduler', 'SCHEDULER=' . $scheduler . ' — staging/production run jobs from cron (Task Scheduler on Windows)');
        } elseif ($beatAge === null) {
            $add(self::WARN, 'scheduler', 'scheduled jobs have never run — set up php bin/blue jobs:run every minute');
        } elseif ($beatAge > 15) {
            $add(self::WARN, 'scheduler', 'last run ' . $beatAge . ' min ago (' . $beat['info_value'] . ') — is the every-minute task running?');
        } else {
            $add(self::PASS, 'scheduler', 'last run ' . $beatAge . ' min ago (' . $beat['info_value'] . ')');
        }

        $health = $this->app->backups()->health();
        if ($health['last'] === null) {
            $add(self::WARN, 'backup', 'no verified backup yet — php bin/blue backup:run');
        } else {
            $age = (int) round((float) $health['age_hours']);
            $add($health['overdue'] ? self::WARN : self::PASS, 'backup', 'newest verified ' . $age . ' h ago (limit ' . $health['max_age_hours'] . ' h)' . ($health['failed_recent'] > 0 ? ', ' . $health['failed_recent'] . ' failed in 7 days' : ''));
        }
        $offsite = $this->app->backups()->offsitePath();
        if ($offsite === '') {
            $add(self::WARN, 'off-site copy', 'BACKUP_OFFSITE_PATH is empty — every backup sits on this one disk');
        } elseif (!is_dir($offsite) || !is_writable($offsite)) {
            $add(self::FAIL, 'off-site copy', 'BACKUP_OFFSITE_PATH is not a writable folder (is the drive plugged in?)');
        } else {
            $add(self::PASS, 'off-site copy', 'copies go to the off-site folder');
        }
        $drill = $health['last_drill'];
        if ($drill === null) {
            $add(self::WARN, 'restore drill', 'never done — open a verified backup and run the drill (docs/DR_RUNBOOK.md)');
        } elseif ($drill['status'] !== 'passed') {
            $add(self::WARN, 'restore drill', 'the latest drill ' . $drill['status'] . ' — fix and run it again');
        } else {
            $days = intdiv((int) $this->minutesSince((string) $drill['finished_at']), 1440);
            $add($days > 90 ? self::WARN : self::PASS, 'restore drill', 'passed ' . $days . ' day(s) ago' . ($days > 90 ? ' — repeat at least every 90 days' : ''));
        }

        $demo = (int) $db->scalar('SELECT COUNT(*) FROM organizations WHERE is_demo = 1');
        if ($demo === 0) {
            $add(self::PASS, 'demo data', 'none');
        } else {
            $add($env === 'production' ? self::FAIL : ($live ? self::WARN : self::PASS), 'demo data', 'present — the demo accounts share one password; never put real data in this installation');
        }
        return $out;
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function dbVersion(string $version): array
    {
        if (preg_match('/^(\d+\.\d+)(?:\.\d+)?/', $version, $m) !== 1) {
            return [self::WARN, 'database version', $version];
        }
        if (stripos($version, 'mariadb') !== false) {
            return version_compare($m[1], self::MARIADB_LTS, '>=')
                ? [self::PASS, 'database version', $version]
                : [self::WARN, 'database version', $version . ' — older than MariaDB ' . self::MARIADB_LTS . ' LTS, no longer maintained upstream (XAMPP ships 10.4); plan an upgrade'];
        }
        return version_compare($m[1], '8.0', '>=') ? [self::PASS, 'database version', 'MySQL ' . $version] : [self::WARN, 'database version', 'MySQL ' . $version . ' — use 8.0 or newer'];
    }

    private function minutesSince(string $utc): int
    {
        $t = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        return max(0, intdiv($this->app->clock->now()->getTimestamp() - $t->getTimestamp(), 60));
    }
}
