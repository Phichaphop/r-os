<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Install\Installer;
use Blue\Core\Install\InstallState;
use Blue\Core\Log\Logger;
use Blue\Core\Support\FrozenClock;
use Blue\Tests\Support\TempDir;
use Blue\Tests\Support\TestDb;
use RuntimeException;

/** Spec 03 §6.0 + DEC-17: install once, write config, lock forever (TC-SEC installer). */
final class InstallerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        TestDb::fresh($this);
        $this->tmp = TempDir::make();
        mkdir($this->tmp . '/storage/locks', 0777, true);
        mkdir($this->tmp . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    public function testInstallWritesConfigAndLocks(): void
    {
        $state = new InstallState($this->tmp);
        $code = $state->setupCode();
        self::assertMatchesRegularExpression('/^[0-9A-F]{10}$/', $code);
        $clock = new FrozenClock();
        $installer = new Installer($this->tmp, $state, $clock, new Logger($this->tmp . '/logs', $clock));

        self::assertNull($installer->testConnection(TestDb::creds()));
        $bad = TestDb::creds();
        $bad['DB_PASS'] = 'wrong-password';
        $error = $installer->testConnection($bad);
        self::assertNotNull($error);
        self::assertStringNotContainsString('wrong-password', $error, 'password never echoed');

        $root = realpath(__DIR__ . '/../..');
        $res = $installer->install(TestDb::creds(), 'prototype', ['core' => $root . '/core/migrations'], ['core' => $root . '/core/I18n/seed'], 'test');

        self::assertTrue($res['env_written']);
        self::assertTrue($state->isLocked());
        self::assertFileDoesNotExist($state->setupCodeFile(), 'one-time setup code is removed');
        $env = require $this->tmp . '/config/env.php';
        self::assertSame('prototype', $env['APP_ENV']);
        self::assertMatchesRegularExpression('#^base64:[A-Za-z0-9+/]{43}=$#', $env['APP_KEY']);
        self::assertGreaterThan(100, (int) TestDb::connection()->scalar("SELECT COUNT(*) FROM i18n_messages WHERE locale = 'th'"));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('locked');
        $installer->install(TestDb::creds(), 'prototype', ['core' => $root . '/core/migrations'], [], 'again');
    }

    public function testRejectsSuspiciousDatabaseName(): void
    {
        $clock = new FrozenClock();
        $installer = new Installer($this->tmp, new InstallState($this->tmp), $clock, new Logger($this->tmp . '/logs', $clock));
        $c = TestDb::creds();
        $c['DB_NAME'] = 'x;DROP DATABASE y';
        self::assertNotNull($installer->testConnection($c));
    }
}
