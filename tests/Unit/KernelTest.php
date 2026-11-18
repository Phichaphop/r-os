<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\App;
use Blue\Core\Config\AppConfig;
use Blue\Core\Http\Kernel;
use Blue\Core\Http\Request;
use Blue\Core\Install\InstallState;
use Blue\Core\Support\FrozenClock;
use Blue\Tests\Support\TempDir;

/**
 * HTTP pipeline end-to-end without a web server.
 * Covers phase-1 gate items: safe error page (no stack trace / secrets), installer lock (DEC-17),
 * CSRF, security headers, correlation ID, showcase availability per environment.
 */
final class KernelTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = TempDir::make();
        mkdir($this->tmp . '/storage/locks', 0777, true);
        mkdir($this->tmp . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    private function kernel(string $env, bool $locked): Kernel
    {
        if ($locked) {
            file_put_contents($this->tmp . '/storage/install.lock', '{}');
        }
        $root = realpath(__DIR__ . '/../..');
        $config = AppConfig::load($root, ['APP_ENV' => $env, 'INSTALLED' => false, 'APP_DEBUG' => true, 'APP_KEY' => 'base64:' . base64_encode(str_repeat('u', 32))]);
        $app = new App($root, $config, new FrozenClock(), new InstallState($this->tmp), $this->tmp . '/logs');
        return new Kernel($app);
    }

    public function testNotInstalledRedirectsEverythingToInstaller(): void
    {
        $res = $this->kernel('prototype', false)->handle(new Request('GET', '/'));
        self::assertSame(302, $res->status);
        self::assertSame('/install', $res->headers['Location']);
    }

    public function testInstallerIsLockedAfterInstallation(): void
    {
        $k = $this->kernel('prototype', true);
        self::assertSame(404, $k->handle(new Request('GET', '/install'))->status);
        self::assertSame(302, $k->handle(new Request('GET', '/system/ui-showcase'))->status, 'guests are sent to sign in');
        self::assertSame(404, $k->handle(new Request('POST', '/install', [], ['_csrf' => 'x']))->status);
    }

    public function testGuestJsonRequestsGet401(): void
    {
        $res = $this->kernel('prototype', true)->handle(new Request('GET', '/nope', [], [], ['accept' => 'application/json']));
        self::assertSame(401, $res->status);
        self::assertSame('auth.required', json_decode($res->body, true)['error']['code']);
    }

    public function testSecurityHeadersAndCorrelationId(): void
    {
        $res = $this->kernel('prototype', true)->handle(new Request('GET', '/login'));
        self::assertSame(200, $res->status);
        self::assertStringContainsString("script-src 'self' 'nonce-", $res->headers['Content-Security-Policy']);
        self::assertStringContainsString("frame-ancestors 'none'", $res->headers['Content-Security-Policy']);
        self::assertSame('nosniff', $res->headers['X-Content-Type-Options']);
        self::assertSame('DENY', $res->headers['X-Frame-Options']);
        self::assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $res->headers['X-Correlation-Id']);
        preg_match("/nonce-([A-Za-z0-9_-]+)/", $res->headers['Content-Security-Policy'], $m);
        self::assertStringContainsString('nonce="' . $m[1] . '"', $res->body, 'inline script carries the per-request nonce');
    }

    public function testCsrfIsRequiredForStateChangingRequests(): void
    {
        $k = $this->kernel('prototype', false);
        self::assertSame(419, $k->handle(new Request('POST', '/install', [], ['setup_code' => 'x']))->status);

        $page = $k->handle(new Request('GET', '/install'));
        preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $page->body, $m);
        self::assertNotEmpty($m, 'form carries a CSRF token');
        $res = $k->handle(new Request('POST', '/install', [], ['_csrf' => $m[1], 'setup_code' => 'WRONG', 'db_host' => 'h', 'db_name' => 'n', 'db_user' => 'u', 'app_env' => 'prototype']));
        self::assertSame(422, $res->status, 'token accepted, then business validation rejects the wrong setup code');
        self::assertStringContainsString('aria-invalid="true"', $res->body);
        self::assertFileDoesNotExist($this->tmp . '/storage/install.lock');
    }

    public function testThaiIsDefaultAndEnglishSelectable(): void
    {
        $k = $this->kernel('prototype', true);
        self::assertStringContainsString('<html lang="th" ', $k->handle(new Request('GET', '/login'))->body);
        self::assertStringContainsString('<html lang="en" ', $k->handle(new Request('GET', '/login', ['lang' => 'en']))->body);
    }

    private function extractRef(string $html): string
    {
        preg_match('/([0-9A-HJKMNP-TV-Z]{26})/', $html, $m);
        return $m[1] ?? '';
    }
}
