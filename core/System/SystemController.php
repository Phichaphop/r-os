<?php

declare(strict_types=1);

namespace Blue\Core\System;

use Blue\Core\App;
use Blue\Core\Error\HttpException;
use Blue\Core\Http\Request;
use Blue\Core\I18n\DateFormat;
use Blue\Core\Http\Response;
use Blue\Core\Database\MigrationRunner;
use RuntimeException;
use Throwable;

/**
 * Platform pages for phase 1. Access control (System Admin only) is added in phase 2;
 * until then status/showcase are available only outside production and can be switched off.
 */
final class SystemController
{
    public function __construct(private readonly App $app)
    {
    }

    public function home(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $hub = ['groups' => [], 'locked' => [], 'disabled' => 0, 'total' => 0];
        $sections = [];
        // a visitor (0.8.1, DEC-68): the public launcher — apps the organization shows here; the ones not open to
        // visitors go through sign-in and then straight on to that app
        if (($ctx === null || $ctx->isGuest()) && $this->app->install->isLocked() && $this->app->hasDatabase()) {
            return Response::html($this->app->view()->render('system/home_guest', [
                'title' => $this->app->translator()->get('home.title'),
                'apps' => \Blue\Core\Ui\GuestAccess::homeApps($this->app),
            ]));
        }
        if ($ctx !== null && !$ctx->isGuest()) {
            $shell = (array) $this->app->view()->global('shell', []);
            $hub = \Blue\Core\Ui\AppHub::build($this->app, $ctx->az(), $ctx->userId(), $shell);
            $sections = $this->homeSections($ctx->az());
        }
        return Response::html($this->app->view()->render('system/home', [
            'title' => $this->app->translator()->get('home.title'),
            'hub' => $hub,
            'sections' => $sections,
        ]));
    }

    /**
     * Home sections contributed by switched-on modules (manifest "home"). A section that fails is skipped and logged —
     * one broken module must not take the home page down.
     *
     * @return list<array{key: string, title: string, items: list<array<string, mixed>>, empty?: string, more_href?: string}>
     */
    private function homeSections(\Blue\Core\Authz\Authorizer $az): array
    {
        $out = [];
        foreach ($this->app->modules()->enabled() as $code => $m) {
            $call = (string) ($m['home'] ?? '');
            if ($call === '' || !is_callable($call)) {
                continue;
            }
            try {
                $s = $call($this->app, $az);
            } catch (Throwable $e) {
                $this->app->logger->warning('home_section_failed', ['module' => $code, 'error' => $e->getMessage()]);
                continue;
            }
            if (is_array($s) && isset($s['key'], $s['title']) && is_array($s['items'] ?? null)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /** Liveness only — reveals nothing about versions or configuration. */
    public function health(Request $r): Response
    {
        $db = 'not_installed';
        if ($this->app->hasDatabase()) {
            try {
                $this->app->db()->scalar('SELECT 1');
                $db = 'ok';
            } catch (Throwable) {
                $db = 'error';
            }
        }
        return Response::json(['status' => $db === 'error' ? 'degraded' : 'ok', 'db' => $db], $db === 'error' ? 503 : 200);
    }

    public function status(Request $r): Response
    {
        $this->guardNonProduction();
        $this->ctx($r)?->require('core.system.status');
        $migrations = [];
        $dbError = null;
        try {
            $migrations = (new MigrationRunner($this->app->db(), $this->app->migrationPaths(), $this->app->clock, $this->app->logger))->status();
        } catch (Throwable $e) {
            $dbError = $this->app->translator()->get('status.db_unavailable');
            $this->app->logger->warning('status_db_unavailable', ['error' => $e->getMessage()]);
        }
        return Response::html($this->app->view()->render('system/status', [
            'title' => $this->app->translator()->get('status.title'),
            'migrations' => $migrations,
            'dbError' => $dbError,
            'scheduler' => $this->schedulerInfo(),
            'aiProvider' => $this->app->ai()->providerCode(),
            'release' => \Blue\Core\App::RELEASE,
            'env' => $this->app->config->env(),
        ]));
    }

    public function showcase(Request $r): Response
    {
        $this->guardShowcase();
        $this->ctx($r)?->require('core.system.showcase');
        return Response::html($this->app->view()->render('system/showcase', [
            'title' => $this->app->translator()->get('showcase.title'),
        ]));
    }

    /** Demonstrates the safe technical-error page (reference code, no stack trace). */
    public function errorDemo(Request $r): Response
    {
        $this->guardShowcase();
        $this->ctx($r)?->require('core.system.showcase');
        throw new RuntimeException('Showcase demo exception with secret-looking detail: DB_PASS=should-never-be-shown');
    }

    /** Development mailbox (MAIL_DRIVER=dev_mailbox): messages the system would e-mail. Never in production. */
    public function mailbox(Request $r): Response
    {
        $this->guardNonProduction();
        $this->ctx($r)?->require('core.system.mailbox');
        $rows = $this->app->db()->all('SELECT id, to_address, subject, body, created_at FROM mail_outbox WHERE expires_at > ? ORDER BY id DESC LIMIT 100', [$this->app->clock->now()->format('Y-m-d H:i:s.u')]);
        return Response::html($this->app->view()->render('system/mailbox', [
            'title' => $this->app->translator()->get('mailbox.title'),
            'rows' => $rows,
        ]));
    }

    private function ctx(Request $r): ?\Blue\Core\Http\RequestContext
    {
        $ctx = $r->attributes['ctx'] ?? null;
        return $ctx instanceof \Blue\Core\Http\RequestContext ? $ctx : null;
    }

    /** @return array{last_run:?string, trigger:?string} */
    private function schedulerInfo(): array
    {
        if (!$this->app->hasDatabase()) {
            return ['last_run' => null, 'trigger' => null];
        }
        try {
            $row = $this->app->db()->one("SELECT info_value, updated_at FROM system_info WHERE info_key = 'scheduler_last_run'");
            $last = isset($row['updated_at']) ? DateFormat::display((string) $row['updated_at'], $this->app->translator()->locale(), $this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok')) : null;
            return ['last_run' => $last, 'trigger' => $row['info_value'] ?? null];
        } catch (Throwable) {
            return ['last_run' => null, 'trigger' => null];
        }
    }

    private function guardShowcase(): void
    {
        if (!$this->app->config->bool('UI_SHOWCASE') || $this->app->config->isProduction()) {
            throw HttpException::notFound();
        }
    }

    private function guardNonProduction(): void
    {
        if ($this->app->config->isProduction()) {
            throw HttpException::notFound();
        }
    }
}
