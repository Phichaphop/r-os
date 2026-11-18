<?php

declare(strict_types=1);

namespace Blue\Tests\Support;

use PHPUnit\Framework\TestCase;
use Blue\Core\App;
use Blue\Core\Authz\RbacSync;
use Blue\Core\Config\AppConfig;
use Blue\Core\Database\MigrationRunner;
use Blue\Core\Http\Kernel;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\I18n\MessageSync;
use Blue\Core\Install\Bootstrap;
use Blue\Core\Install\InstallState;
use Blue\Core\Log\Logger;
use Blue\Core\Support\FrozenClock;

/**
 * A fully installed Blue OS on the test database: migrations, RBAC catalogue, first admin and DEC-34 demo data.
 * Time is frozen so session timeouts, lockouts and TOTP can be tested deterministically.
 */
final class TestApp
{
    public const ADMIN = 'admin.test';
    public const ADMIN_PASSWORD = 'Correct-Horse-Battery-77';

    public App $app;
    public FrozenClock $clock;
    public string $demoPassword;
    public string $tmp;
    private ?\Blue\Core\Database\Connection $setupDb = null;

    /** @param bool $mfa two-step verification switch (installs default to off — DEC-52; most security tests run with it on) */
    public static function create(TestCase $test, string $env = 'ci', bool $mfa = true): self
    {
        $self = new self();
        $db = TestDb::fresh($test);
        $self->setupDb = $db;
        $self->clock = new FrozenClock('2026-11-09T02:00:00Z');
        $self->tmp = TempDir::make('blue-app');
        mkdir($self->tmp . '/storage/locks', 0777, true);
        file_put_contents($self->tmp . '/storage/install.lock', '{}');
        $root = (string) realpath(__DIR__ . '/../..');
        $logger = new Logger($self->tmp . '/logs', $self->clock);
        $migrations = ['core' => $root . '/core/migrations'];
        $seeds = ['core' => $root . '/core/I18n/seed'];
        foreach (glob($root . '/modules/*/migrations', GLOB_ONLYDIR) ?: [] as $dir) {
            $migrations[basename(dirname($dir))] = $dir;
        }
        foreach (glob($root . '/modules/*/lang', GLOB_ONLYDIR) ?: [] as $dir) {
            $seeds[basename(dirname($dir))] = $dir;
        }
        (new MigrationRunner($db, $migrations, $self->clock, $logger))->migrate('test');
        (new MessageSync($db, $self->clock))->sync($seeds, ['en', 'th']);
        (new RbacSync($db, $self->clock, \Blue\Core\Modules\ModuleRegistry::discover($root)))->sync();
        $self->demoPassword = (string) (new Bootstrap($db, $self->clock))->run([
            'org_name_th' => 'กลุ่มทดสอบ', 'org_name_en' => 'Test Group',
            'admin_username' => self::ADMIN, 'admin_name' => 'Admin Test', 'admin_email' => 'admin@test.invalid',
            'admin_password' => self::ADMIN_PASSWORD, 'admin_password_confirm' => self::ADMIN_PASSWORD,
        ], true, true); // + the second demo company for cross-company checks (a real install has one — Q-20)
        \Blue\Core\Install\PlatformSync::run($db, $self->clock, $root);
        if ($mfa) {
            $db->run("INSERT INTO system_info (info_key, info_value, updated_at) VALUES ('mfa_enforced', '1', UTC_TIMESTAMP(6))");
        }

        $c = TestDb::creds();
        $config = AppConfig::load($root, [
            'APP_ENV' => $env, 'INSTALLED' => true, 'APP_DEBUG' => $env === 'ci',
            'APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'DB_HOST' => $c['DB_HOST'], 'DB_PORT' => $c['DB_PORT'], 'DB_NAME' => $c['DB_NAME'], 'DB_USER' => $c['DB_USER'], 'DB_PASS' => $c['DB_PASS'],
            'MAIL_DRIVER' => 'dev_mailbox',
            'DOCUMENTS_PATH' => $self->tmp . '/documents',
            'PDF_TEMP_PATH' => $self->tmp . '/pdf-temp',
            'BACKUP_PATH' => $self->tmp . '/backups',
            'BACKUP_DRILL_DB' => $c['DB_NAME'] . '_drill',
        ]);
        $self->app = new App($root, $config, $self->clock, new InstallState($self->tmp), $self->tmp . '/logs');
        return $self;
    }

    public function cleanup(): void
    {
        $this->setupDb?->close(); // PHPUnit keeps every test object until the end — give the connections back now
        $this->app->db()->close();
        TempDir::remove($this->tmp);
    }

    /** @return array<string, mixed> */
    public function user(string $username): array
    {
        return $this->app->db()->one('SELECT * FROM users WHERE username = ?', [$username]) ?? [];
    }

    public function id(string $table, string $code, ?string $leCode = null): string
    {
        $sql = $leCode === null
            ? "SELECT public_id FROM `{$table}` WHERE code = ?"
            : "SELECT t.public_id FROM `{$table}` t JOIN legal_entities l ON l.id = t.legal_entity_id WHERE t.code = ? AND l.code = ?";
        return (string) $this->app->db()->scalar($sql, $leCode === null ? [$code] : [$code, $leCode]);
    }

    public function browser(): TestBrowser
    {
        return new TestBrowser(new Kernel($this->app), $this);
    }
}
