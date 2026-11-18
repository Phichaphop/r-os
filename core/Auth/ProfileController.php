<?php

declare(strict_types=1);

namespace Blue\Core\Auth;

use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Ui\AppHub;
use Blue\Core\Http\Response;

/** "My account": password, devices (sessions), MFA status, my roles (6.30.2). */
final class ProfileController extends Controller
{
    public function show(Request $r): Response
    {
        $ctx = $this->ctx($r);
        return $this->page('auth/profile', [
            'title' => $this->app->translator()->get('profile.title'),
            'user' => $ctx->user,
            'sessions' => $this->app->sessions()->active($ctx->userId()),
            'currentSessionId' => (int) $ctx->session['id'],
            'assignments' => $this->app->iam()->assignments($ctx->userId()),
            'mfaRequired' => $this->app->security()->mfaEnforced() && $ctx->az()->requiresMfa(),
            'mfaOn' => $this->app->security()->mfaEnforced(),
        ]);
    }

    // ---- my display settings (0.6.1): theme, highlight colour, font ---------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, string>|null $old
     */
    public function appearance(Request $r, array $errors = [], ?array $old = null, int $status = 200): Response
    {
        $ctx = $this->ctx($r);
        $look = \Blue\Core\Ui\UserLook::load($this->app, $ctx->userId());
        $org = $this->app->appearance()->current();
        return $this->page('auth/appearance', [
            'title' => $this->app->translator()->get('look.title'),
            'look' => $look, 'org' => $org, 'errors' => $errors,
            'form' => $old ?? ['theme' => $look['theme'] ?? 'org', 'accent' => $look['accent'] ?? '', 'font' => $look['font'] ?? 'org'],
            'accents' => \Blue\Core\Ui\UserLook::ACCENTS, 'fonts' => \Blue\Core\Ui\FontCatalog::FONTS,
            'orgPrimary' => \Blue\Core\Ui\ThemeBuilder::build($this->app->root, $org['preset'], $org['primary'])['colors']['primary'],
        ], $status);
    }

    public function saveAppearance(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $in = ['theme' => $this->str($r, 'theme', 10), 'accent' => strtoupper($this->str($r, 'accent', 7)), 'font' => $this->str($r, 'font', 20)];
        if ($r->input('reset') === '1') {
            $in = ['theme' => 'org', 'accent' => '', 'font' => 'org'];
        }
        $errors = [];
        if ($in['theme'] !== 'org' && !in_array($in['theme'], \Blue\Core\Ui\UserLook::THEMES, true)) {
            $errors['theme'] = 'look.error.theme';
        }
        if ($in['accent'] !== '') {
            if (preg_match('/^#[0-9A-F]{6}$/', $in['accent']) !== 1) {
                $errors['accent'] = 'look.error.accent';
            } else {
                $chk = \Blue\Core\Ui\UserLook::checkAccent($this->app, $in['accent']);
                if (!$chk['ok']) {
                    $errors['accent'] = $this->app->translator()->get('look.error.contrast', ['suggestion' => $chk['suggestion'] ?? '—']);
                }
            }
        }
        $orgFont = $this->app->appearance()->current()['font'];
        if ($in['font'] !== 'org' && !\Blue\Core\Ui\FontCatalog::valid($in['font']) && !($in['font'] === 'custom' && $orgFont === 'custom')) {
            $errors['font'] = 'look.error.font';
        }
        if ($errors !== []) {
            $t = $this->app->translator();
            return $this->appearance($r, array_map(static fn (string $k): string => str_starts_with($k, 'look.') ? $t->get($k) : $k, $errors), $in, 422);
        }
        $prefs = $this->app->preferences();
        $uid = $ctx->userId();
        $prefs->set($uid, 'ui.theme', $in['theme'] === 'org' ? '' : $in['theme']);
        $prefs->set($uid, 'ui.accent', $in['accent']);
        $prefs->set($uid, 'ui.font', $in['font'] === 'org' ? '' : $in['font']);
        $this->app->audit()->record('profile.appearance_changed', 'success', 'user', (string) $ctx->user['public_id'], null, null, ['theme' => $in['theme'], 'accent' => $in['accent'], 'font' => $in['font']]);
        $this->flash($r, 'success', 'look.saved');
        return $this->redirect($r, '/me/appearance');
    }

    /** /me/theme.css — this person's overrides (cached per version). */
    public function themeCss(Request $r): Response
    {
        $look = \Blue\Core\Ui\UserLook::load($this->app, $this->ctx($r)->userId());
        $fresh = ($r->query['v'] ?? '') === \Blue\Core\Ui\UserLook::version($this->app, $look);
        return (new Response(\Blue\Core\Ui\UserLook::css($this->app, $look, $r->basePath()), 200))
            ->withHeader('Content-Type', 'text/css; charset=utf-8')
            ->withHeader('Cache-Control', $fresh ? 'private, max-age=31536000, immutable' : 'no-cache');
    }

    /** Live preview on the settings page (nothing saved). */
    public function previewCss(Request $r): Response
    {
        $this->ctx($r);
        $accent = strtoupper(mb_substr(trim((string) ($r->query['accent'] ?? '')), 0, 7));
        $font = (string) ($r->query['font'] ?? '');
        $look = ['theme' => null, 'accent' => preg_match('/^#[0-9A-F]{6}$/', $accent) === 1 ? $accent : null,
            'font' => \Blue\Core\Ui\FontCatalog::valid($font) || $font === 'custom' ? $font : null];
        $check = $look['accent'] !== null ? \Blue\Core\Ui\UserLook::checkAccent($this->app, $look['accent']) : ['ok' => true, 'suggestion' => null];
        if (($r->query['format'] ?? '') === 'json') {
            return Response::json(['ok' => $check['ok'], 'message' => $check['ok'] ? '' : $this->app->translator()->get('look.error.contrast', ['suggestion' => $check['suggestion'] ?? '—'])]);
        }
        return (new Response(\Blue\Core\Ui\UserLook::css($this->app, $look, $r->basePath()), 200))
            ->withHeader('Content-Type', 'text/css; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }

    public function passwordForm(Request $r): Response
    {
        return $this->page('auth/change-password', [
            'title' => $this->app->translator()->get('profile.password.title'),
            'forced' => (int) ($this->ctx($r)->user['must_change_password'] ?? 0) === 1,
            'errors' => [], 'min' => $this->app->authSettings()->passwordMin,
        ]);
    }

    public function changePassword(Request $r): Response
    {
        $ctx = $this->ctx($r);
        try {
            $this->app->auth()->changePassword($ctx->user ?? [], (int) $ctx->session['id'], (string) $r->input('current_password', ''), (string) $r->input('password', ''), (string) $r->input('password_confirm', ''));
        } catch (ValidationException $e) {
            return $this->page('auth/change-password', [
                'title' => $this->app->translator()->get('profile.password.title'),
                'forced' => (int) ($ctx->user['must_change_password'] ?? 0) === 1,
                'errors' => $this->errors($e), 'min' => $this->app->authSettings()->passwordMin,
            ], 422);
        }
        $this->flash($r, 'success', 'auth.password.changed');
        return $this->redirect($r, '/me');
    }

    public function revokeSession(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $id = (string) ($r->params['id'] ?? '');
        $own = array_filter($this->app->sessions()->active($ctx->userId()), static fn ($s) => $s['public_id'] === $id);
        if ($own === []) {
            throw HttpException::notFound();
        }
        $row = array_values($own)[0];
        $this->app->sessions()->revoke((int) $row['id'], 'revoked_by_user');
        $this->app->audit()->record('auth.session_revoke', 'success', 'session', $id);
        $this->flash($r, 'success', 'profile.sessions.revoked');
        return $this->redirect($r, '/me');
    }

    public function revokeOthers(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $n = $this->app->sessions()->revokeAll($ctx->userId(), 'revoked_by_user', (int) $ctx->session['id']);
        $this->app->audit()->record('auth.session_revoke_others', 'success', 'user', (string) $ctx->user['public_id'], null, "{$n} sessions");
        $this->flash($r, 'success', 'profile.sessions.revoked_others', ['n' => $n]);
        return $this->redirect($r, '/me');
    }

    /** Context switcher (DEC-21): remembers the company the user is working in. */
    public function switchContext(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $le = (int) $r->input('legal_entity', 0);
        $options = $this->app->org()->scopeOptions($ctx->az()->reachableScope(), $this->app->translator()->locale())['legal_entity'];
        if ($le !== 0 && !isset($options[$le])) {
            throw HttpException::forbidden();
        }
        $this->store($r)->set('ctx_le', $le === 0 ? null : $le);
        return $this->redirect($r, $this->safeNext($r->input('_back')));
    }

    /**
     * "Request access" form (6.4): role + data scope + reason → central approval inbox.
     * From a locked app on Home (?app=<key>) only the roles that open that app are offered, smallest first (REQ-IAM-003).
     */
    public function accessForm(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $key = $this->str($r, 'app', 60);
        $appInfo = null;
        if ($key !== '') {
            $appInfo = AppHub::lockedApp($this->app, $ctx->az(), $ctx->userId(), (array) $this->app->view()->global('shell', []), $key);
            if ($appInfo === null && $this->isOpenApp($key)) {
                $this->flash($r, 'info', 'access.error.app_open');
                return $this->redirect($r, '/');
            }
        }
        $old = [];
        if ($appInfo !== null) {
            $old = ['role' => (string) $appInfo['roles'][0], 'scope' => $this->defaultScope()];
        }
        return $this->accessPage($appInfo, [], $old);
    }

    public function accessSubmit(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $old = ['role' => $this->str($r, 'role', 20), 'scope' => $this->str($r, 'scope', 40), 'reason' => $this->str($r, 'reason', 255)];
        $key = $this->str($r, 'app', 60);
        $appInfo = $key !== '' ? AppHub::lockedApp($this->app, $ctx->az(), $ctx->userId(), (array) $this->app->view()->global('shell', []), $key) : null;
        if ($key !== '' && $appInfo === null) {
            $this->flash($r, 'info', $this->isOpenApp($key) ? 'access.error.app_open' : 'form.invalid_choice');
            return $this->redirect($r, '/');
        }
        [$level, $id] = preg_match('/^(organization|legal_entity|site|department):(\d+)$/', $old['scope'], $m) ? [$m[1], (int) $m[2]] : ['', 0];
        try {
            if ($appInfo !== null && !in_array($old['role'], $appInfo['roles'], true)) {
                throw new ValidationException(['role' => 'form.invalid_choice']);
            }
            $this->app->accessRequests()->create($ctx->userId(), $old['role'], $level, $id, $old['reason'], $appInfo !== null ? (string) $appInfo['key'] : null);
        } catch (ValidationException $e) {
            return $this->accessPage($appInfo, $this->errors($e), $old, 422);
        } catch (\Blue\Core\Error\BusinessException $e) {
            $this->flash($r, 'warning', $e->messageKey);
            return $this->redirect($r, '/requests');
        }
        $this->flash($r, 'success', 'access.request.sent');
        return $this->redirect($r, $appInfo !== null ? '/' : '/requests');
    }

    /**
     * @param array<string, mixed>|null $appInfo
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function accessPage(?array $appInfo, array $errors, array $old, int $status = 200): Response
    {
        return $this->page('auth/access-request', [
            'title' => $this->app->translator()->get('access.request.title'),
            'roles' => $appInfo !== null ? $appInfo['roles'] : array_keys(\Blue\Core\Authz\Catalog::ROLES),
            'appInfo' => $appInfo,
            'scopeOptions' => $this->app->org()->scopeOptions(new \Blue\Core\Authz\ScopeSet(true), $this->app->translator()->locale()),
            'orgId' => (int) ($this->app->org()->organization()['id'] ?? 0),
            'errors' => $errors, 'old' => $old,
        ], $status);
    }

    /** The company chosen in the top bar, or the only company there is — the usual scope of a request. */
    private function defaultScope(): string
    {
        $shell = (array) $this->app->view()->global('shell', []);
        if (is_int($shell['company'] ?? null)) {
            return 'legal_entity:' . $shell['company'];
        }
        $all = $this->app->org()->scopeOptions(new \Blue\Core\Authz\ScopeSet(true), $this->app->translator()->locale())['legal_entity'] ?? [];
        return count($all) === 1 ? 'legal_entity:' . (int) array_key_first($all) : '';
    }

    private function isOpenApp(string $key): bool
    {
        $shell = (array) $this->app->view()->global('shell', []);
        $hrefs = [];
        foreach ((array) ($shell['groups'] ?? []) as $g) {
            foreach ((array) $g['items'] as $i) {
                $hrefs[(string) $i['href']] = true;
            }
        }
        foreach (AppHub::CORE_APPS as $href => $c) {
            if ($c[0] === $key) {
                return isset($hrefs[$href]);
            }
        }
        $m = $this->app->modules()->enabled()[$key] ?? null;
        foreach ((array) ($m['menu'] ?? []) as $i) {
            if (isset($hrefs[(string) $i['href']])) {
                return true;
            }
        }
        return false;
    }
}
