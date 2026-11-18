<?php

declare(strict_types=1);

namespace Blue\Core\Privacy;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** Phase 5 gate (DEC-82): my data (export, requests) · the privacy officer's request desk. */
final class PrivacyController extends Controller
{
    private function svc(): PrivacyService
    {
        return PrivacyService::of($this->app);
    }

    private function t(string $key): string
    {
        return $this->app->translator()->get($key);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function mine(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $uid = $this->ctx($r)->userId();
        return $this->page('privacy/mine', ['title' => $this->t('privacy.my_title'), 'coverage' => $this->svc()->coverage($uid), 'requests' => $this->svc()->mine($uid),
            'errors' => $errors, 'old' => $old], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function export(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $ctx->requireFreshReauth(); // the package contains restricted data (e.g. GPS positions)
        $json = $this->svc()->package($ctx->userId(), 'self');
        $this->app->audit()->record('privacy.self_export', 'success', 'user', (string) ($ctx->user['public_id'] ?? ''), null);
        return (new Response($json, 200, ['Content-Type' => 'application/json; charset=utf-8']))
            ->withHeader('Content-Disposition', 'attachment; filename="my-data-' . substr($this->app->clock->now()->format('Y-m-d'), 0, 10) . '.json"')
            ->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function submit(Request $r): Response
    {
        $in = ['type' => $this->str($r, 'type', 20), 'details' => mb_substr(is_scalar($r->body['details'] ?? null) ? (string) $r->body['details'] : '', 0, 2000)];
        try {
            $pid = $this->svc()->submit($this->ctx($r)->userId(), $in['type'], $in['details']);
        } catch (ValidationException $e) {
            return $this->mine($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'privacy.flash.submitted');
        return $this->redirect($r, '/me/privacy/' . $pid);
    }

    public function myRequest(Request $r): Response
    {
        $req = $this->svc()->request($this->ctx($r)->az(), (string) $r->params['id']);
        if ((int) $req['user_id'] !== $this->ctx($r)->userId()) {
            throw HttpException::notFound();
        }
        return $this->page('privacy/my_request', ['title' => (string) $req['request_no'], 'req' => $req]);
    }

    public function desk(Request $r): Response
    {
        $status = (string) ($r->query['status'] ?? '');
        $status = in_array($status, ['received', 'verified', 'erasure_pending', 'erasure_done', 'completed', 'rejected'], true) ? $status : '';
        return $this->page('privacy/desk', ['title' => $this->t('privacy.desk_title'), 'rows' => $this->svc()->all($this->ctx($r)->az(), $status), 'status' => $status,
            'dueSet' => $this->app->registry()->resolveOrNull('privacy.dsr_response_days') !== null]);
    }

    /** @param array<string, string> $errors */
    public function show(Request $r, array $errors = [], string $form = '', int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $req = $this->svc()->request($az, (string) $r->params['id']);
        if (!$req['is_officer']) {
            throw HttpException::forbidden();
        }
        $loc = $this->app->translator()->locale();
        $names = [];
        foreach ($this->app->modules()->all() as $code => $m) {
            $names[(string) $code] = (string) ($m['name'][$loc] ?? $m['name']['en'] ?? $code);
        }
        return $this->page('privacy/request', ['title' => (string) $req['request_no'], 'req' => $req, 'coverage' => $this->svc()->coverage((int) $req['user_id']), 'me' => $az->userId, 'moduleNames' => $names,
            'errors' => $errors, 'form' => $form, 'old' => $r->method === 'POST' ? $r->body : []], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function act(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        $text = static fn (string $k): string => is_scalar($r->body[$k] ?? null) ? mb_substr((string) $r->body[$k], 0, 2000) : '';
        try {
            match ($action) {
                'verify' => $this->svc()->verify($az, $pid, $text('identity_note')),
                'prepare' => $this->svc()->prepareErasure($az, $pid),
                'approve' => $this->svc()->approveErasure($az, $pid),
                default => $this->svc()->close($az, $pid, $text('outcome') === 'completed' ? 'completed' : 'rejected', $text('answer')),
            };
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $action, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/privacy/requests/' . $pid);
        }
        $this->flash($r, 'success', 'privacy.flash.' . $action);
        return $this->redirect($r, '/privacy/requests/' . $pid);
    }

    public function package(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $ctx->requireFreshReauth();
        $json = $this->svc()->accessPackage($ctx->az(), (string) $r->params['id']);
        return (new Response($json, 200, ['Content-Type' => 'application/json; charset=utf-8']))
            ->withHeader('Content-Disposition', 'attachment; filename="dsr-package.json"')->withHeader('Cache-Control', 'no-store, max-age=0');
    }
}
