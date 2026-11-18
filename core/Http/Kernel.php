<?php

declare(strict_types=1);

namespace Blue\Core\Http;

use Blue\Core\App;
use Blue\Core\Error\ErrorRenderer;
use Blue\Core\Http\Middleware\Authenticate;
use Blue\Core\Http\Middleware\CorrelationId;
use Blue\Core\Http\Middleware\Csrf;
use Blue\Core\Http\Middleware\ErrorBoundary;
use Blue\Core\Http\Middleware\InstallGuard;
use Blue\Core\Http\Middleware\SecurityHeaders;
use Blue\Core\Http\Middleware\Session;
use Blue\Core\Log\Logger;
use Throwable;

/**
 * Front controller pipeline (P0-02 §7):
 * correlation ID → security headers → error boundary → session → view globals → installer guard
 * → CSRF → authenticate (+ MFA / must-change-password gates) → route handler.
 */
final class Kernel
{
    public readonly Router $router;

    /** In-memory session for CLI/tests (one Kernel = one browser). Web requests use the native session. */
    private SessionStore $memoryStore;

    public function __construct(private readonly App $app, ?Router $router = null)
    {
        $this->router = $router ?? new Router();
        $this->memoryStore = new SessionStore();
        (require $app->root . '/core/routes.php')($this->router, $app);
        foreach ($app->modules()->enabled() as $m) { // switched-off modules register no routes (P0-02 §5)
            if (!empty($m['routes'])) {
                (require $m['dir'] . '/' . $m['routes'])($this->router, $app);
            }
        }
    }

    public function handle(Request $request): Response
    {
        $view = $this->app->view();
        $view->share('base_path', $request->basePath());
        $actx = $this->app->auditContext();
        $actx->actorId = null;
        $actx->onBehalfOfId = null;
        $actx->actorType = 'anonymous';

        $store = fn (): SessionStore => PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE ? new NativeSessionStore() : $this->memoryStore;

        $stack = [
            new CorrelationId($this->app->logger, $this->app->clock),
            new SecurityHeaders(),
            new ErrorBoundary(fn (): ErrorRenderer => new ErrorRenderer(
                $this->app->logger,
                $this->app->translator(),
                $view,
                $this->app->config->debug(),
            )),
            new Session(),
            function (Request $r, callable $next) use ($store, $actx): Response {
                $r->attributes['session_store'] = $store();
                $actx->correlationId = (string) ($r->attributes['correlation_id'] ?? '-');
                $actx->ipHash = null;
                if ($this->app->install->isLocked() && $this->app->config->string('APP_KEY') !== '') {
                    $actx->ipHash = $this->app->crypto()->keyedHash($r->ip);
                }
                return $this->prepareView($r, $next);
            },
            new InstallGuard($this->app->install),
            fn (Request $r, callable $next): Response => (new Csrf($r->attributes['session_store']))->process($r, $next),
            fn (Request $r, callable $next): Response => (new Authenticate($this->app, $r->attributes['session_store']))->process($r, $next),
            fn (Request $r, callable $next): Response => $this->schemaGuard($r, $next),
        ];

        $handler = function (Request $r): Response {
            $this->app->view()->share('csrf', (string) ($r->attributes['csrf_token'] ?? ''));
            $route = $this->router->match($r);
            try {
                return $route($r);
            } catch (ReauthRequired) {
                $back = $r->method === 'GET'
                    ? $r->path . ($r->query !== [] ? '?' . http_build_query($r->query) : '')
                    : (string) ($r->body['_back'] ?? '/');
                return Response::redirect($r->basePath() . '/reauth?next=' . rawurlencode($back));
            }
        };
        foreach (array_reverse($stack) as $mw) {
            $next = $handler;
            $handler = $mw instanceof Middleware
                ? static fn (Request $r): Response => $mw->process($r, $next)
                : static fn (Request $r): Response => $mw($r, $next);
        }

        try {
            return $handler($request);
        } catch (Throwable $e) {
            // Should be unreachable (ErrorBoundary) — last resort without templates or details.
            $this->app->logger->error('kernel_failure', Logger::exceptionContext($e));
            return Response::html('<!doctype html><meta charset="utf-8"><title>Error</title><p>Unexpected error. Reference: '
                . htmlspecialchars($this->app->logger->correlationId(), ENT_QUOTES) . '</p>', 500);
        }
    }

    /** @param callable(Request): Response $next */
    private function prepareView(Request $r, callable $next): Response
    {
        /** @var SessionStore $store */
        $store = $r->attributes['session_store'];
        $t = $this->app->translator();
        $lang = (string) ($r->query['lang'] ?? '');
        if (in_array($lang, ['th', 'en'], true)) {
            $store->set('locale', $lang);
        }
        $t->setLocale((string) ($store->get('locale') ?? $this->app->config->string('I18N_DEFAULT', 'th')));

        $view = $this->app->view();
        $view->share('locale', $t->locale());
        $view->share('nonce', (string) ($r->attributes['csp_nonce'] ?? ''));
        $look = $this->app->appearance()->current();
        $view->share('app_name', $look['app_name']);
        $view->share('theme', $look);
        $view->share('app_env', $this->app->config->env());
        $view->share('path', $r->path);
        $view->share('method', $r->method);
        $view->share('now_utc', $this->app->clock->now()->format('Y-m-d H:i:s.u'));
        $view->share('flash', $store->get('flash'));
        $store->forget('flash');
        return $next($r);
    }

    /**
     * A newer release was copied over an installed one: until "update database" (DEC-16) runs, administrators are
     * taken to that page and everyone else sees a maintenance notice — never half-working pages.
     *
     * @param callable(Request): Response $next
     */
    private function schemaGuard(Request $r, callable $next): Response
    {
        $ctx = $r->attributes['ctx'] ?? null;
        if (
            !$this->app->install->isLocked() || !$ctx instanceof RequestContext || $ctx->isGuest()
            || in_array($r->path, ['/system/update', '/logout', '/reauth', '/health', '/theme.css', '/brand/logo', '/brand/favicon'], true)
            || $this->app->schemaPending() === 0
        ) {
            return $next($r);
        }
        $az = $ctx->az();
        if (in_array('SYS', $az->roleCodes(), true) || $az->scope('core.system.update')->all) {
            return Response::redirect($r->basePath() . '/system/update', 303);
        }
        return Response::html($this->app->view()->render('system/maintenance', ['title' => $this->app->translator()->get('update.maintenance.title')], 'layouts/minimal'), 503);
    }

    public function app(): App
    {
        return $this->app;
    }
}
