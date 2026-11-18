<?php

declare(strict_types=1);

namespace Blue\Core\Http\Middleware;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Http\Middleware;
use Blue\Core\Http\Request;
use Blue\Core\Http\RequestContext;
use Blue\Core\Http\Response;
use Blue\Core\Http\SessionStore;
use Blue\Core\Ui\GuestAccess;
use Blue\Core\Ui\ShellData;

/**
 * Resolves the signed-in user and enforces the sign-in state machine (P0-04 §3, DEC-51):
 *   guest → only public pages · must change password → only that page · MFA required but not verified
 *   in this session → only MFA pages · otherwise everything (permissions are checked by each action).
 */
final class Authenticate implements Middleware
{
    /** Paths reachable without signing in. */
    private const PUBLIC = ['/', '/login', '/health', '/password/forgot', '/password/reset', '/theme.css', '/brand/logo', '/brand/favicon'];
    private const PUBLIC_PREFIX = ['/install', '/activate/', '/password/link/', '/assets/', '/m2m/', '/brand/font/'];
    private const MFA_PATHS = ['/mfa/setup', '/mfa/verify', '/logout', '/theme.css', '/brand/logo', '/brand/favicon'];

    public function __construct(private readonly App $app, private readonly SessionStore $store)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $user = null;
        $session = null;
        $az = null;
        if ($this->app->install->isLocked() && $this->app->hasDatabase()) {
            $token = (string) $this->store->get('blue_st', '');
            $session = $token !== '' ? $this->app->sessions()->current($token) : null;
            if ($session !== null) {
                $user = $this->app->db()->one('SELECT * FROM users WHERE id = ?', [$session['user_id']]);
                if ($user === null || $user['status'] !== 'active') {
                    $this->app->sessions()->revoke((int) $session['id'], 'user_inactive');
                    $user = $session = null;
                }
            }
            if ($session === null && $token !== '') {
                $this->store->forget('blue_st');
                // rights changed (role granted/revoked) ends every session: say so instead of "idle"
                $request->attributes['session_expired'] = $this->app->sessions()->endedReason() === 'rights_changed' ? 'rights' : true;
            }
            if ($user !== null) {
                $az = new Authorizer($this->app->db(), $this->app->clock, (int) $user['id']);
                $this->app->auditContext()->actorId = (int) $user['id'];
                $this->app->auditContext()->actorType = 'user';
                if (!$this->store->get('locale') && in_array($user['locale'] ?? '', ['th', 'en'], true)) {
                    $this->app->translator()->setLocale((string) $user['locale']);
                    $this->app->view()->share('locale', (string) $user['locale']);
                }
            }
        }
        // Two-step verification is an organization switch (DEC-52, default off); when on, DEC-51 decides who needs it.
        $needsMfa = $user !== null && $az !== null && $this->app->install->isLocked() && $this->app->security()->mfaEnforced()
            && ((int) $user['mfa_enabled'] === 1 || $az->requiresMfa());
        $fullyIn = $user !== null && $az !== null && (!$needsMfa || ($session['mfa_verified_at'] ?? null) !== null);
        $this->app->view()->share('shell', $fullyIn ? ShellData::build($this->app, $user, $az, $this->store) : null);
        // the person's own theme / highlight colour / font (0.6.1) — follows the organization where not set
        $this->app->view()->share('user_look', $fullyIn ? \Blue\Core\Ui\UserLook::load($this->app, (int) $user['id']) : null);
        $ctx = new RequestContext($user, $session, $az, $this->app->audit(), $this->app->authSettings()->reauthMinutes, $this->app->clock);
        $request->attributes['ctx'] = $ctx;
        $this->app->view()->share('ctx', $ctx);

        $path = $request->path;
        $isPublic = in_array($path, self::PUBLIC, true);
        foreach (self::PUBLIC_PREFIX as $p) {
            $isPublic = $isPublic || str_starts_with($path . '/', $p) || str_starts_with($path, $p);
        }
        // enabled modules may declare public GET prefixes (e.g. M27 managed QR redirect "/q/") — GET/HEAD only — and, for
        // forms a person without an account must send (0.44.0: following an anonymous complaint), public POST prefixes
        if (!$isPublic) {
            foreach ($this->app->modules()->enabled() as $m) {
                foreach ((array) ($m[$request->isSafeMethod() ? 'public_paths' : 'public_forms'] ?? []) as $p) {
                    $isPublic = $isPublic || (is_string($p) && strlen($p) > 2 && str_starts_with($path, $p));
                }
            }
        }
        $base = $request->basePath();

        if ($user === null) {
            $this->app->view()->share('guest_shell', $this->app->install->isLocked() && $this->app->hasDatabase() ? $this->guestShell() : null);
            // a session that just ended still says why on the sign-in page, even though home is public now (0.8.1)
            $justEnded = ($request->attributes['session_expired'] ?? false) !== false && $path === '/' && $request->method === 'GET';
            if (($isPublic && !$justEnded) || !$this->app->install->isLocked()) {
                return $next($request);
            }
            // a tool the organization opened to visitors (0.8.1): only the requests its manifest lists, with a stand-in
            // authorizer that covers no company data; everything else still asks to sign in
            $rule = !$justEnded && $this->app->hasDatabase() ? GuestAccess::match($this->app, $request->method, $path) : null;
            if ($rule !== null) {
                $key = (string) $this->store->get('guest_key', '');
                if (preg_match('/^[0-9a-f]{32}$/', $key) !== 1) {
                    $key = bin2hex(random_bytes(16));
                    $this->store->set('guest_key', $key);
                }
                $this->app->auditContext()->actorType = 'anonymous';
                $guest = GuestAccess::authorizer($this->app, $rule['permissions'], $key);
                $ctx = new RequestContext(null, null, $guest, $this->app->audit(), $this->app->authSettings()->reauthMinutes, $this->app->clock);
                $request->attributes['ctx'] = $ctx;
                $request->attributes['guest_module'] = $rule['module'];
                $this->app->view()->share('ctx', $ctx);
                return $next($request);
            }
            if ($request->wantsJson()) {
                return Response::json(['error' => ['code' => 'auth.required']], 401);
            }
            $nextUrl = $request->method === 'GET' && $path !== '/' ? '?next=' . rawurlencode($path) : '';
            $expired = ($request->attributes['session_expired'] ?? false) ? ($nextUrl === '' ? '?' : '&') . 'expired=' . ($request->attributes['session_expired'] === 'rights' ? 'rights' : '1') : '';
            return Response::redirect($base . '/login' . $nextUrl . $expired);
        }

        if ((int) $user['must_change_password'] === 1 && !in_array($path, ['/me/password', '/logout', '/theme.css', '/brand/logo', '/brand/favicon'], true)) {
            return Response::redirect($base . '/me/password');
        }
        if ($needsMfa && $session['mfa_verified_at'] === null && !in_array($path, self::MFA_PATHS, true)) {
            $enrolled = $this->app->mfa()->enrolled($user);
            return Response::redirect($base . ($enrolled ? '/mfa/verify' : '/mfa/setup'));
        }
        if (!$needsMfa && in_array($path, ['/mfa/setup', '/mfa/verify'], true) && !$this->app->security()->mfaEnforced()) {
            return Response::redirect($base . '/');
        }
        if (in_array($path, ['/login'], true)) {
            return Response::redirect($base . '/');
        }
        return $next($request);
    }

    /**
     * Side-bar items for a visitor: the tools open to the public (each module's guest "href" or first tool page).
     *
     * @return list<array{label: string, icon: string, href: string}>
     */
    private function guestShell(): array
    {
        try {
            $tools = GuestAccess::tools($this->app);
            if ($tools === []) {
                return [];
            }
            $cands = GuestAccess::candidates($this->app);
            $mods = $this->app->modules()->enabled();
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($tools as $code) {
            if (isset($cands[$code])) {
                $out[] = ['label' => $this->app->translator()->get($cands[$code]['label']), 'icon' => $cands[$code]['icon'],
                    'href' => (string) ($mods[$code]['guest']['href'] ?? $cands[$code]['href'])];
            }
        }
        return $out;
    }
}
