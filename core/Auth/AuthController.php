<?php

declare(strict_types=1);

namespace Blue\Core\Auth;

use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** Sign-in, activation, password reset, MFA and re-authentication pages (6.1–6.3, P0-04 §3). */
final class AuthController extends Controller
{
    public function loginForm(Request $r): Response
    {
        return $this->page('auth/login', [
            'title' => $this->app->translator()->get('auth.login.title'),
            'next' => $this->safeNext($r->query['next'] ?? '/'),
            'expired' => isset($r->query['expired']),
            'rightsChanged' => ($r->query['expired'] ?? '') === 'rights',
            'resetAvailable' => $this->app->auth()->resetAvailable(),
            'errors' => [], 'old' => [],
        ], 200, 'layouts/auth');
    }

    public function login(Request $r): Response
    {
        $identifier = $this->str($r, 'identifier', 160);
        $result = $this->app->auth()->login($identifier, (string) $r->input('password', ''), $r->ip, $r->header('user-agent'));
        if (!$result['ok']) {
            return $this->page('auth/login', [
                'title' => $this->app->translator()->get('auth.login.title'),
                'next' => $this->safeNext($r->input('next')),
                'expired' => false,
                'resetAvailable' => $this->app->auth()->resetAvailable(),
                'errors' => ['_form' => $this->app->translator()->get($result['error'] ?? 'auth.error.invalid', ['minutes' => $this->app->authSettings()->lockMinutes])],
                'old' => ['identifier' => $identifier],
            ], 422, 'layouts/auth');
        }
        $store = $this->store($r);
        $store->regenerate();
        $store->set('blue_st', $result['session']['token'] ?? '');
        $store->forget('ctx_le');
        return $this->redirect($r, $this->safeNext($r->input('next')));
    }

    public function logout(Request $r): Response
    {
        $ctx = $this->ctx($r);
        if (!$ctx->isGuest()) {
            $this->app->auth()->logout((int) $ctx->session['id'], $ctx->userId(), $r->input('everywhere') === '1');
        }
        $store = $this->store($r);
        $store->clear();
        $store->regenerate();
        $this->flash($r, 'success', 'auth.logout.done');
        return $this->redirect($r, '/login');
    }

    // ---- activation & admin reset link -------------------------------------------------------

    public function linkForm(Request $r): Response
    {
        [$purpose, $token] = $this->linkParams($r);
        $user = $this->app->auth()->userForLinkToken($token, $purpose);
        return $this->page('auth/set-password', [
            'title' => $this->app->translator()->get($purpose === 'activation' ? 'auth.activate.title' : 'auth.link_reset.title'),
            'purpose' => $purpose, 'token' => $token, 'user' => $user, 'errors' => [],
            'min' => $this->app->authSettings()->passwordMin,
        ], $user === null ? 410 : 200, 'layouts/auth');
    }

    public function linkSubmit(Request $r): Response
    {
        [$purpose, $token] = $this->linkParams($r);
        try {
            $this->app->auth()->completeLink($token, $purpose, (string) $r->input('password', ''), (string) $r->input('password_confirm', ''));
        } catch (ValidationException $e) {
            return $this->page('auth/set-password', [
                'title' => $this->app->translator()->get($purpose === 'activation' ? 'auth.activate.title' : 'auth.link_reset.title'),
                'purpose' => $purpose, 'token' => $token, 'user' => $this->app->auth()->userForLinkToken($token, $purpose),
                'errors' => $this->errors($e), 'min' => $this->app->authSettings()->passwordMin,
            ], 422, 'layouts/auth');
        }
        $this->flash($r, 'success', $purpose === 'activation' ? 'auth.activate.done' : 'auth.password.changed');
        return $this->redirect($r, '/login');
    }

    /** @return array{0:string, 1:string} */
    private function linkParams(Request $r): array
    {
        $purpose = str_starts_with($r->path, '/activate/') ? 'activation' : 'reset_link';
        return [$purpose, (string) ($r->params['token'] ?? '')];
    }

    // ---- self-service reset (OTP by e-mail) ----------------------------------------------------

    public function forgotForm(Request $r): Response
    {
        return $this->page('auth/forgot', [
            'title' => $this->app->translator()->get('auth.forgot.title'),
            'available' => $this->app->auth()->resetAvailable(), 'errors' => [],
        ], 200, 'layouts/auth');
    }

    public function forgot(Request $r): Response
    {
        $identifier = $this->str($r, 'identifier', 160);
        $this->app->auth()->requestResetOtp($identifier, $r->ip);
        $this->store($r)->set('reset_identifier', $identifier);
        $this->flash($r, 'info', 'auth.forgot.sent');
        return $this->redirect($r, '/password/reset');
    }

    public function resetForm(Request $r): Response
    {
        return $this->page('auth/reset', [
            'title' => $this->app->translator()->get('auth.reset.title'),
            'identifier' => (string) $this->store($r)->get('reset_identifier', ''), 'errors' => [],
            'min' => $this->app->authSettings()->passwordMin,
        ], 200, 'layouts/auth');
    }

    public function reset(Request $r): Response
    {
        $identifier = $this->str($r, 'identifier', 160);
        try {
            $this->app->auth()->completeResetOtp($identifier, $this->str($r, 'otp', 10), (string) $r->input('password', ''), (string) $r->input('password_confirm', ''));
        } catch (ValidationException $e) {
            return $this->page('auth/reset', [
                'title' => $this->app->translator()->get('auth.reset.title'),
                'identifier' => $identifier, 'errors' => $this->errors($e), 'min' => $this->app->authSettings()->passwordMin,
            ], 422, 'layouts/auth');
        }
        $this->store($r)->forget('reset_identifier');
        $this->flash($r, 'success', 'auth.password.changed');
        return $this->redirect($r, '/login');
    }

    // ---- MFA ------------------------------------------------------------------------------------

    public function mfaSetup(Request $r): Response
    {
        $user = $this->ctx($r)->user ?? [];
        $mfa = $this->app->mfa();
        $pending = $mfa->pending($user) ?? $mfa->begin($user);
        return $this->page('auth/mfa-setup', [
            'title' => $this->app->translator()->get('auth.mfa.setup_title'),
            'secret' => $pending['secret'], 'uri' => $pending['uri'], 'errors' => [],
            'required' => $this->ctx($r)->authorizer?->requiresMfa() ?? false,
        ], 200, 'layouts/minimal');
    }

    public function mfaSetupConfirm(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $user = $ctx->user ?? [];
        try {
            $this->app->mfa()->confirm($user, $this->str($r, 'code', 10));
        } catch (ValidationException $e) {
            $pending = $this->app->mfa()->pending($user) ?? $this->app->mfa()->begin($user);
            return $this->page('auth/mfa-setup', [
                'title' => $this->app->translator()->get('auth.mfa.setup_title'),
                'secret' => $pending['secret'], 'uri' => $pending['uri'], 'errors' => $this->errors($e),
                'required' => $ctx->authorizer?->requiresMfa() ?? false,
            ], 422, 'layouts/minimal');
        }
        $this->app->sessions()->markMfa((int) $ctx->session['id']);
        $this->flash($r, 'success', 'auth.mfa.enrolled');
        return $this->redirect($r, '/');
    }

    public function mfaVerifyForm(Request $r): Response
    {
        return $this->page('auth/mfa-verify', ['title' => $this->app->translator()->get('auth.mfa.verify_title'), 'errors' => []], 200, 'layouts/auth');
    }

    public function mfaVerify(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $user = $ctx->user ?? [];
        $key = 'mfa|' . $ctx->userId();
        $throttle = $this->app->throttle();
        $s = $this->app->authSettings();
        if ($throttle->failures('mfa', $key, $s->lockWindowMinutes) >= $s->lockFailures) {
            $this->app->auth()->logout((int) $ctx->session['id'], $ctx->userId());
            $this->store($r)->clear();
            $this->flash($r, 'danger', 'auth.error.mfa_locked');
            return $this->redirect($r, '/login');
        }
        if (!$this->app->mfa()->verify($user, $this->str($r, 'code', 10))) {
            $throttle->hit('mfa', $key, false);
            return $this->page('auth/mfa-verify', [
                'title' => $this->app->translator()->get('auth.mfa.verify_title'),
                'errors' => ['code' => $this->app->translator()->get('auth.error.totp_invalid')],
            ], 422, 'layouts/auth');
        }
        $throttle->hit('mfa', $key, true);
        $this->app->sessions()->markMfa((int) $ctx->session['id']);
        return $this->redirect($r, '/');
    }

    // ---- re-authentication (P0-04 §3, P0-05 §3.5 level 3) --------------------------------------

    public function reauthForm(Request $r): Response
    {
        return $this->page('auth/reauth', [
            'title' => $this->app->translator()->get('auth.reauth.title'),
            'next' => $this->safeNext($r->query['next'] ?? '/'),
            'useTotp' => $this->app->security()->mfaEnforced() && (int) ($this->ctx($r)->user['mfa_enabled'] ?? 0) === 1,
            'errors' => [],
        ]);
    }

    public function reauth(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $user = $ctx->user ?? [];
        $useTotp = $this->app->security()->mfaEnforced() && (int) ($user['mfa_enabled'] ?? 0) === 1;
        $ok = $useTotp
            ? $this->app->mfa()->verify($user, $this->str($r, 'code', 10))
            : $this->app->auth()->verifyPassword($user, (string) $r->input('password', ''));
        $this->app->audit()->record('auth.reauth', $ok ? 'success' : 'failure', 'user', (string) $user['public_id']);
        if (!$ok) {
            return $this->page('auth/reauth', [
                'title' => $this->app->translator()->get('auth.reauth.title'),
                'next' => $this->safeNext($r->input('next')), 'useTotp' => $useTotp,
                'errors' => [$useTotp ? 'code' : 'password' => $this->app->translator()->get($useTotp ? 'auth.error.totp_invalid' : 'auth.error.current_password')],
            ], 422);
        }
        $this->app->sessions()->markReauth((int) $ctx->session['id']);
        $this->flash($r, 'success', 'auth.reauth.done');
        return $this->redirect($r, $this->safeNext($r->input('next')));
    }
}
