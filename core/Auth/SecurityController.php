<?php

declare(strict_types=1);

namespace Blue\Core\Auth;

use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** Organization security settings (SYS): two-step verification switch (DEC-52). */
final class SecurityController extends Controller
{
    public function show(Request $r): Response
    {
        $this->ctx($r)->require('core.user.manage');
        return $this->page('admin/security', [
            'title' => $this->app->translator()->get('security.title'),
            'mfaOn' => $this->app->security()->mfaEnforced(),
            'errors' => [],
        ]);
    }

    public function save(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $ctx->require('core.user.manage');
        $ctx->requireFreshReauth();
        $on = (string) $r->input('mfa_enforced', '0') === '1';
        $reason = $this->str($r, 'reason', 255);
        if ($reason === '') {
            return $this->page('admin/security', [
                'title' => $this->app->translator()->get('security.title'),
                'mfaOn' => $this->app->security()->mfaEnforced(),
                'errors' => ['reason' => $this->app->translator()->get('iam.error.reason_required')],
            ], 422);
        }
        $this->app->security()->setMfaEnforced($on, $reason);
        $this->flash($r, 'success', $on ? 'security.mfa.turned_on' : 'security.mfa.turned_off');
        return $this->redirect($r, '/admin/security');
    }
}
