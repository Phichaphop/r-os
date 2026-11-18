<?php

declare(strict_types=1);

namespace Blue\Core\Authz;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** Administration of users, roles and scopes (SYS) — P0-04 §4, 6.4, 6.30.2–3. */
final class UsersController extends Controller
{
    public function index(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $paging = \Blue\Core\Ui\Paging::from($r, $this->app->preferences(), $ctx->userId(), 'users');
        $q = $this->str($r, 'q', 60);
        $status = $this->str($r, 'status', 20);
        $list = $this->app->iam()->listUsers($ctx->az(), $q, $status, $paging->page, $paging->per);
        if ($paging->clamp($list['total'])) {
            $list = $this->app->iam()->listUsers($ctx->az(), $q, $status, $paging->page, $paging->per);
        }
        [$page, $per] = [$paging->page, $paging->per];
        return $this->page('admin/users/index', [
            'title' => $this->app->translator()->get('iam.users.title'),
            'list' => $list, 'q' => $q, 'status' => $status, 'page' => $page, 'per' => $per,
            'canManage' => $ctx->az()->scope('core.user.manage')->all,
            'pending' => $ctx->can('core.user.grant') ? $this->app->iam()->pendingApprovals() : [],
            'sod' => $ctx->can('core.user.grant') ? $this->app->iam()->sodReport() : [],
        ]);
    }

    /** Users list as Excel/CSV — same filter as the screen; needs core.user.export (organization-wide) + password confirmation. */
    public function export(Request $r): Response
    {
        $ctx = $this->ctx($r);
        if (!$ctx->az()->scope('core.user.export')->all) {
            $ctx->require('core.user.export');
            throw \Blue\Core\Error\HttpException::forbidden();
        }
        $ctx->requireFreshReauth();
        $q = $this->str($r, 'q', 60);
        $status = $this->str($r, 'status', 20);
        $rows = [];
        for ($page = 1; $page <= 200; $page++) {
            $list = $this->app->iam()->listUsers($ctx->az(), $q, $status, $page, 300);
            foreach ($list['rows'] as $u) {
                $rows[] = [$u['username'], $u['display_name'], $u['email'], $u['status'], (string) $u['roles'], (int) $u['mfa_enabled'] === 1 ? 'yes' : 'no', $u['last_login_at']];
            }
            if ($page * 300 >= $list['total']) {
                break;
            }
        }
        $t = $this->app->translator();
        $this->app->audit()->record('user.list_exported', 'success', null, null, null, http_build_query(array_filter(['q' => $q, 'status' => $status])), ['rows' => count($rows)]);
        return \Blue\Core\Files\TableExport::response(\Blue\Core\Files\TableExport::format($r->query['format'] ?? 'xlsx'), 'users-' . $this->app->clock->now()->format('Ymd-His'), [
            $t->get('iam.field.username'), $t->get('iam.field.display_name'), $t->get('iam.field.email'), $t->get('iam.field.status'), $t->get('iam.field.roles'), 'MFA', $t->get('iam.field.last_login') . ' (UTC)',
        ], $rows);
    }

    public function createForm(Request $r): Response
    {
        $this->ctx($r)->require('core.user.manage');
        return $this->page('admin/users/create', ['title' => $this->app->translator()->get('iam.users.invite'), 'errors' => [], 'old' => []]);
    }

    public function create(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $in = ['username' => $this->str($r, 'username', 60), 'display_name' => $this->str($r, 'display_name', 120), 'email' => $this->str($r, 'email', 160), 'locale' => $this->str($r, 'locale', 5)];
        try {
            $res = $this->app->iam()->invite($ctx->az(), $in);
        } catch (ValidationException $e) {
            return $this->page('admin/users/create', ['title' => $this->app->translator()->get('iam.users.invite'), 'errors' => $this->errors($e), 'old' => $in], 422);
        }
        $this->store($r)->set('one_time_link', ['user' => $res['public_id'], 'url' => $this->origin($r) . '/activate/' . $res['token'], 'kind' => 'activation']);
        $this->flash($r, 'success', 'iam.users.invited');
        return $this->redirect($r, '/admin/users/' . $res['public_id']);
    }

    public function show(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $user = $this->app->iam()->user($ctx->az(), (string) $r->params['id']);
        $link = $this->store($r)->get('one_time_link');
        if (is_array($link) && ($link['user'] ?? '') === $user['public_id']) {
            $this->store($r)->forget('one_time_link'); // shown exactly once
        } else {
            $link = null;
        }
        $grantScope = $ctx->az()->scope('core.user.grant');
        return $this->page('admin/users/show', [
            'title' => (string) $user['display_name'],
            'user' => $user,
            'assignments' => $this->app->iam()->assignments((int) $user['id']),
            'roles' => array_keys(Catalog::ROLES),
            'scopeOptions' => $grantScope->isEmpty() ? null : $this->app->org()->scopeOptions($grantScope, $this->app->translator()->locale()),
            'orgId' => (int) ($this->app->org()->organization()['id'] ?? 0),
            'canManage' => $ctx->az()->scope('core.user.manage')->all,
            'canGrant' => !$grantScope->isEmpty(),
            'isSelf' => (int) $user['id'] === $ctx->userId(),
            'link' => $link,
            'errors' => $this->store($r)->get('form_errors') ?? [],
            'today' => $this->app->clock->now()->format('Y-m-d'),
        ]);
    }

    public function status(Request $r): Response
    {
        $id = (string) $r->params['id'];
        try {
            $this->app->iam()->changeStatus($this->ctx($r)->az(), $id, (string) $r->params['action'], $this->str($r, 'reason', 255));
            $this->flash($r, 'success', 'iam.users.status_changed');
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', 'iam.error.reason_required');
        } catch (BusinessException $e) {
            if ($e->httpStatus === 404 || $e->httpStatus === 403) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, $e->params);
        }
        return $this->redirect($r, '/admin/users/' . $id);
    }

    public function resetLink(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $ctx->requireFreshReauth();
        $id = (string) $r->params['id'];
        $user = $this->app->iam()->user($ctx->az(), $id);
        $token = $this->app->iam()->issueResetLink($ctx->az(), $id);
        $kind = $user['status'] === 'invited' ? 'activation' : 'reset_link';
        $this->store($r)->set('one_time_link', ['user' => $id, 'url' => $this->origin($r) . ($kind === 'activation' ? '/activate/' : '/password/link/') . $token, 'kind' => $kind]);
        return $this->redirect($r, '/admin/users/' . $id);
    }

    public function mfaReset(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $ctx->require('core.user.manage');
        $ctx->requireFreshReauth();
        $id = (string) $r->params['id'];
        $user = $this->app->iam()->user($ctx->az(), $id);
        if ((int) $user['id'] === $ctx->userId()) {
            throw new BusinessException('iam.error.self_reset', [], 403);
        }
        $reason = $this->str($r, 'reason', 255);
        if ($reason === '') {
            $this->flash($r, 'danger', 'iam.error.reason_required');
            return $this->redirect($r, '/admin/users/' . $id);
        }
        $this->app->mfa()->reset((int) $user['id'], $id, $reason);
        $this->app->sessions()->revokeAll((int) $user['id'], 'mfa_reset');
        $this->flash($r, 'success', 'iam.users.mfa_reset_done');
        return $this->redirect($r, '/admin/users/' . $id);
    }

    public function grant(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $id = (string) $r->params['id'];
        $scopes = [];
        foreach ((array) ($r->body['scopes'] ?? []) as $s) {
            if (is_string($s) && preg_match('/^(organization|legal_entity|site|department):(\d+)$/', $s, $m)) {
                $scopes[] = [$m[1], (int) $m[2]];
            }
        }
        try {
            $res = $this->app->iam()->grant($ctx->az(), $id, $this->str($r, 'role', 20), $scopes, $this->str($r, 'valid_from', 10), $this->str($r, 'valid_to', 10), $this->str($r, 'reason', 255));
        } catch (ValidationException $e) {
            $this->store($r)->set('form_errors', $this->errors($e));
            $this->flash($r, 'danger', 'form.has_errors');
            return $this->redirect($r, '/admin/users/' . $id . '#grant');
        } catch (BusinessException $e) {
            if ($e->httpStatus === 404) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/admin/users/' . $id);
        }
        $this->store($r)->forget('form_errors');
        if ($res['sod_conflicts'] !== []) {
            $this->flash($r, 'warning', 'iam.grant.sod_warning', ['pairs' => implode(', ', $res['sod_conflicts'])]);
        } else {
            $this->flash($r, 'success', $res['status'] === 'pending_approval' ? 'iam.grant.pending' : 'iam.grant.done');
        }
        return $this->redirect($r, '/admin/users/' . $id);
    }

    public function revoke(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $back = $this->safeNext($r->input('_back'));
        try {
            $this->app->iam()->revoke($ctx->az(), (string) $r->params['aid'], $this->str($r, 'reason', 255));
            $this->flash($r, 'success', 'iam.grant.revoked');
        } catch (ValidationException) {
            $this->flash($r, 'danger', 'iam.error.reason_required');
        }
        return $this->redirect($r, $back);
    }

    public function decide(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $ctx->requireFreshReauth();
        $approve = (string) $r->params['decision'] === 'approve';
        try {
            $this->app->iam()->decide($ctx->az(), (string) $r->params['aid'], $approve, $this->str($r, 'comment', 255));
            $this->flash($r, 'success', $approve ? 'iam.grant.approved' : 'iam.grant.rejected');
        } catch (BusinessException $e) {
            if ($e->httpStatus === 404) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/admin/users');
    }

    /** Absolute base URL for links that are copied and sent to someone else. */
    private function origin(Request $r): string
    {
        $configured = rtrim($this->app->config->string('APP_URL'), '/');
        return $configured !== '' ? $configured : ($r->secure ? 'https' : 'http') . '://' . $r->host . $r->basePath();
    }

    public function roles(Request $r): Response
    {
        $this->ctx($r)->require('core.role.view');
        return $this->page('admin/roles', [
            'title' => $this->app->translator()->get('iam.roles.title'),
            'roles' => RbacSync::roles($this->app->modules()->all()), 'permissions' => array_map(static fn (array $p): string => $p['risk'], RbacSync::permissions($this->app->modules()->all())), 'conflicts' => Catalog::CONFLICTING_ROLES,
        ]);
    }
}
