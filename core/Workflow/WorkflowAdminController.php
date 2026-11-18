<?php

declare(strict_types=1);

namespace Blue\Core\Workflow;

use Blue\Core\Authz\Catalog;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** Approval routes (6.9 item 1, 6.30.4): view routes; publish a new version (running requests keep theirs). */
final class WorkflowAdminController extends Controller
{
    public function index(Request $r): Response
    {
        $this->guard($r);
        return $this->page('admin/workflows', [
            'title' => $this->app->translator()->get('workflow.admin.title'),
            'defs' => $this->app->workflow()->definitions(),
            'roles' => array_keys(Catalog::ROLES),
            'permissions' => array_keys(Catalog::PERMISSIONS),
            'errors' => $this->store($r)->get('wf_errors') ?? [],
        ]);
    }

    public function publish(Request $r): Response
    {
        $ctx = $this->guard($r);
        $code = (string) $r->params['code'];
        $steps = [];
        foreach ((array) ($r->body['steps'] ?? []) as $s) {
            if (!is_array($s) || ($s['approver'] ?? '') === '') {
                continue;
            }
            [$type, $value] = array_pad(explode(':', (string) $s['approver'], 2), 2, '');
            [$etype, $evalue] = array_pad(explode(':', (string) ($s['escalate_to'] ?? ''), 2), 2, '');
            $sla = trim((string) ($s['sla_hours'] ?? ''));
            $steps[] = [
                'approver' => ['type' => $type, 'value' => $value],
                'scope' => ($s['scope'] ?? 'subject') === 'any' ? 'any' : 'subject',
                'sla_hours' => $sla === '' ? null : max(1, min(720, (int) $sla)),
                'escalate_to' => $etype !== '' ? ['type' => $etype, 'value' => $evalue] : null,
            ];
        }
        try {
            $no = $this->app->workflow()->publishVersion($code, $steps, $this->str($r, 'reason', 255), $ctx->userId());
        } catch (ValidationException $e) {
            $this->store($r)->set('wf_errors', [$code => $this->errors($e)]);
            $this->flash($r, 'danger', 'form.has_errors');
            return $this->redirect($r, '/admin/workflows#' . str_replace('.', '-', $code));
        }
        $this->store($r)->forget('wf_errors');
        $this->flash($r, 'success', 'workflow.admin.published', ['n' => $no]);
        return $this->redirect($r, '/admin/workflows#' . str_replace('.', '-', $code));
    }

    private function guard(Request $r): \Blue\Core\Http\RequestContext
    {
        $ctx = $this->ctx($r);
        if (!$ctx->az()->scope('core.workflow.manage')->all) {
            throw HttpException::forbidden();
        }
        return $ctx;
    }
}
