<?php

declare(strict_types=1);

namespace Blue\Core\Install;

use PDO;
use Blue\Core\Database\Connection;
use Blue\Core\Authz\RbacSync;
use Blue\Core\I18n\MessageSync;
use Blue\Core\Log\Logger;
use Blue\Core\Database\MigrationRunner;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;
use RuntimeException;
use Throwable;

/**
 * Installer (spec 03 §6.0, DEC-16/17). Phase 1 scope:
 *   1 requirement check · 2 DB credentials + connection test (no CREATE DATABASE)
 *   3 run versioned migrations · 4 sync translations · 5 write config/env.php (APP_KEY generated)
 *   6 lock permanently.
 * First admin account + company profile + optional demo data are added in phase 2 (needs Auth).
 */
final class Installer
{
    public const MIN_PHP = '8.1.0';

    public function __construct(
        private readonly string $root,
        private readonly InstallState $state,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /** @return list<array{key:string, ok:bool, detail:string}> */
    public function requirements(): array
    {
        $checks = [
            ['req.php', version_compare(PHP_VERSION, self::MIN_PHP, '>='), PHP_VERSION],
            ['req.pdo_mysql', extension_loaded('pdo_mysql'), ''],
            ['req.mbstring', extension_loaded('mbstring'), ''],
            ['req.json', extension_loaded('json'), ''],
            ['req.openssl', \Blue\Core\Support\Crypto::backends() !== [], implode(', ', \Blue\Core\Support\Crypto::backends())],
            ['req.storage_writable', is_writable($this->root . '/storage'), 'storage/'],
            ['req.config_writable', is_writable($this->root . '/config'), 'config/ (optional — manual copy allowed)'],
        ];
        return array_map(static fn (array $c): array => ['key' => $c[0], 'ok' => (bool) $c[1], 'detail' => (string) $c[2]], $checks);
    }

    public function requirementsMet(): bool
    {
        foreach ($this->requirements() as $r) {
            if (!$r['ok'] && $r['key'] !== 'req.config_writable') {
                return false;
            }
        }
        return true;
    }

    /** @param array{DB_HOST:string, DB_PORT:int, DB_NAME:string, DB_USER:string, DB_PASS:string} $db */
    public function testConnection(array $db): ?string
    {
        try {
            $c = $this->connection($db);
            $version = (string) $c->scalar('SELECT VERSION()');
            $charset = (string) $c->scalar("SELECT @@character_set_database");
            if (!str_starts_with($charset, 'utf8mb4')) {
                return "connected (server {$version}) but database charset is {$charset}; utf8mb4 required for Thai text";
            }
            return null;
        } catch (Throwable $e) {
            // Never echo the password; PDO messages do not contain it.
            return $e->getMessage();
        }
    }

    /**
     * @param array{DB_HOST:string, DB_PORT:int, DB_NAME:string, DB_USER:string, DB_PASS:string} $db
     * @param array<string, string> $migrationPaths
     * @param array<string, string> $seedDirs
     * @param array{org_name_th:string, org_name_en:string, admin_username:string, admin_name:string, admin_email:string, admin_password:string, admin_password_confirm:string}|null $bootstrap
     * @return array{env_written:bool, env_php:string, migrations:list<string>, demo_password:?string}
     */
    public function install(array $db, string $appEnv, array $migrationPaths, array $seedDirs, string $actor, ?array $bootstrap = null, bool $demo = false): array
    {
        if ($this->state->isLocked()) {
            throw new RuntimeException('Already installed — installer is locked (DEC-17).');
        }
        @mkdir($this->root . '/storage/locks', 0750, true);
        $lock = fopen($this->root . '/storage/locks/install.lck', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another installation is running.');
        }
        try {
            $conn = $this->connection($db);
            $runner = new MigrationRunner($conn, $migrationPaths, $this->clock, $this->logger);
            $result = $runner->migrate($actor);
            (new MessageSync($conn, $this->clock))->sync($seedDirs, ['en', 'th']);
            (new RbacSync($conn, $this->clock, \Blue\Core\Modules\ModuleRegistry::discover($this->root)))->sync();
            $demoPassword = $bootstrap !== null ? (new Bootstrap($conn, $this->clock))->run($bootstrap, $demo && $appEnv !== 'production') : null;
            PlatformSync::run($conn, $this->clock, $this->root); // config + workflow definitions (after demo data so demo keys come along)

            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            foreach (['installed_at' => $now, 'install_id' => Ulid::generate($this->clock), 'app_env' => $appEnv] as $k => $v) {
                $conn->run(
                    'INSERT INTO system_info (info_key, info_value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE info_value = VALUES(info_value), updated_at = VALUES(updated_at)',
                    [$k, $v, $now]
                );
            }

            $envPhp = $this->renderEnv($db, $appEnv);
            $written = @file_put_contents($this->state->envFile(), $envPhp, LOCK_EX) !== false;
            if ($written) {
                @chmod($this->state->envFile(), 0640);
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($this->state->envFile(), true); // never serve a stale cached config
                }
            }
            file_put_contents($this->state->lockFile(), json_encode(['installed_at' => $now, 'by' => $actor]) . "\n", LOCK_EX);
            @unlink($this->state->setupCodeFile());
            $this->logger->info('installed', ['env' => $appEnv, 'migrations' => count($result['applied']), 'env_written' => $written]);

            return ['env_written' => $written, 'env_php' => $written ? '' : $envPhp, 'migrations' => $result['applied'], 'demo_password' => $demoPassword];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array{DB_HOST:string, DB_PORT:int, DB_NAME:string, DB_USER:string, DB_PASS:string} $db */
    public function renderEnv(array $db, string $appEnv): string
    {
        $values = [
            'APP_ENV' => $appEnv,
            'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
            'DB_HOST' => $db['DB_HOST'],
            'DB_PORT' => (int) $db['DB_PORT'],
            'DB_NAME' => $db['DB_NAME'],
            'DB_USER' => $db['DB_USER'],
            'DB_PASS' => $db['DB_PASS'],
        ];
        return "<?php\n\n// Generated by the Blue OS installer on " . $this->clock->now()->format('Y-m-d H:i') . " UTC.\n"
            . "// Contains secrets — never commit, never share. (6.30.11)\n\nreturn " . var_export($values, true) . ";\n";
    }

    /** @param array{DB_HOST:string, DB_PORT:int, DB_NAME:string, DB_USER:string, DB_PASS:string} $db */
    private function connection(array $db): Connection
    {
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $db['DB_NAME'])) {
            throw new RuntimeException('Invalid database name.');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['DB_HOST'], (int) $db['DB_PORT'], $db['DB_NAME']);
        $c = new Connection($dsn, $db['DB_USER'], $db['DB_PASS']);
        $c->pdo()->setAttribute(PDO::ATTR_TIMEOUT, 5);
        return $c;
    }
}
