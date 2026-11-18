<?php

declare(strict_types=1);

namespace Blue\Core\Records;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\RequestContext;
use Blue\Core\Http\Response;

/** Records management (04 §6.17.7): retention schedule register, legal holds, disposition queue + certificates. */
final class RecordsController extends Controller
{
    public function index(Request $r): Response
    {
        $ctx = $this->guard($r);
        return $this->page('admin/records/index', [
            'title' => $this->app->translator()->get('records.title'), 'tab' => 'rules',
            'register' => $this->app->retention()->register(), 'canManage' => $ctx->az()->scope('core.records.manage')->all,
        ]);
    }

    public function ruleClass(Request $r): Response
    {
        return $this->classPage($r, (string) $r->params['class'], [], null);
    }

    public function rule(Request $r): Response
    {
        $this->guard($r);
        $rule = $this->app->retention()->get((string) $r->params['id']);
        return $this->redirect($r, '/admin/records/rules/' . $rule['record_class'] . '#v' . $rule['version_no']);
    }

    public function propose(Request $r): Response
    {
        $ctx = $this->guard($r);
        $class = (string) $r->params['class'];
        $in = [
            'trigger' => $this->str($r, 'trigger', 40), 'months' => $this->str($r, 'months', 5), 'action' => $this->str($r, 'action', 20),
            'note' => $this->str($r, 'note', 1000), 'source' => $this->str($r, 'source', 255), 'effective_from' => $this->str($r, 'effective_from', 10), 'reason' => $this->str($r, 'reason', 500),
        ];
        try {
            $pid = $this->app->retention()->propose($ctx->az(), $class, $in);
            if ($r->input('submit_now') === '1') {
                $this->app->retention()->submit($ctx->az(), $pid);
                $this->flash($r, 'success', 'records.flash.rule_submitted');
            } else {
                $this->flash($r, 'success', 'records.flash.rule_saved');
            }
        } catch (ValidationException $e) {
            return $this->classPage($r, $class, $this->errors($e), $in, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->classPage($r, $class, ['form' => $this->app->translator()->get($e->messageKey)], $in, 422);
        }
        return $this->redirect($r, '/admin/records/rules/' . $class);
    }

    public function submit(Request $r): Response
    {
        $ctx = $this->guard($r);
        $rule = $this->app->retention()->get((string) $r->params['id']);
        try {
            $this->app->retention()->submit($ctx->az(), (string) $rule['public_id']);
            $this->flash($r, 'success', 'records.flash.rule_submitted');
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/admin/records/rules/' . $rule['record_class']);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function holds(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $ctx = $this->guard($r);
        return $this->page('admin/records/holds', [
            'title' => $this->app->translator()->get('records.holds.title'), 'tab' => 'holds',
            'holds' => $this->app->holds()->list($ctx->az(), $this->str($r, 'status', 20)), 'canManage' => !$ctx->az()->scope('core.records.manage')->isEmpty(),
            'companies' => $this->app->org()->scopeOptions($ctx->az()->scope('core.records.manage'), $this->app->translator()->locale())['legal_entity'],
            'errors' => $errors, 'old' => $old,
        ], $status);
    }

    public function placeHold(Request $r): Response
    {
        $ctx = $this->guard($r);
        $le = $this->str($r, 'legal_entity', 20);
        $in = [
            'scope_type' => $this->str($r, 'scope_type', 20), 'related_type' => $this->str($r, 'related_type', 40), 'related_id' => $this->str($r, 'related_id', 40),
            'record_class' => $this->str($r, 'record_class', 60), 'legal_entity_id' => ctype_digit($le) ? (int) $le : null,
            'reference' => $this->str($r, 'reference', 60), 'reason' => $this->str($r, 'reason', 500), 'review_date' => $this->str($r, 'review_date', 10),
        ];
        try {
            $this->app->holds()->place($ctx->az(), $in);
        } catch (ValidationException $e) {
            return $this->holds($r, $this->errors($e), $in + ['legal_entity' => $le], 422);
        }
        $this->flash($r, 'success', 'records.flash.hold_placed');
        return $this->redirect($r, '/admin/records/holds');
    }

    public function releaseHold(Request $r): Response
    {
        $ctx = $this->guard($r);
        try {
            $this->app->holds()->requestRelease($ctx->az(), (string) $r->params['id'], $this->str($r, 'reason', 500));
            $this->flash($r, 'success', 'records.flash.release_requested');
        } catch (ValidationException) {
            $this->flash($r, 'danger', 'iam.error.reason_required');
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/admin/records/holds');
    }

    public function disposition(Request $r): Response
    {
        $ctx = $this->guard($r);
        return $this->page('admin/records/disposition', [
            'title' => $this->app->translator()->get('records.disposition.title'), 'tab' => 'disposition',
            'due' => $this->app->disposition()->due($ctx->az()), 'batches' => $this->app->disposition()->batches($ctx->az()),
            'canManage' => !$ctx->az()->scope('core.records.manage')->isEmpty(),
        ]);
    }

    public function requestDisposition(Request $r): Response
    {
        $ctx = $this->guard($r);
        $ids = array_values(array_filter((array) ($r->body['documents'] ?? []), static fn ($v): bool => is_string($v) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $v) === 1));
        try {
            $pid = $this->app->disposition()->request($ctx->az(), $ids, $this->str($r, 'reason', 500));
            $this->flash($r, 'success', 'records.flash.disposition_requested');
            return $this->redirect($r, '/admin/records/disposition/' . $pid);
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/admin/records/disposition');
    }

    public function batch(Request $r): Response
    {
        $ctx = $this->guard($r);
        $b = $this->app->disposition()->get($ctx->az(), (string) $r->params['id']);
        return $this->page('admin/records/batch', [
            'title' => $this->app->translator()->get('records.batch.title'), 'tab' => 'disposition', 'b' => $b['batch'], 'items' => $b['items'],
            'certificate' => $b['batch']['certificate_json'] !== null ? (array) json_decode((string) $b['batch']['certificate_json'], true) : null,
        ]);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string>|null $old
     */
    private function classPage(Request $r, string $class, array $errors, ?array $old, int $status = 200): Response
    {
        $ctx = $this->guard($r);
        if (!in_array($class, DocumentCatalog::RECORD_CLASSES, true)) {
            throw HttpException::notFound();
        }
        $history = $this->app->retention()->history($class);
        $latest = $history[0] ?? null;
        $old ??= [
            'trigger' => (string) ($latest['trigger_event'] ?? 'finalized'), 'months' => $latest !== null && $latest['duration_months'] !== null ? (string) $latest['duration_months'] : '',
            'action' => (string) ($latest['disposition_action'] ?? 'review'), 'note' => (string) ($latest['legal_basis_note'] ?? ''), 'source' => (string) ($latest['source_reference'] ?? ''),
            'effective_from' => $this->app->clock->now()->format('Y-m-d'), 'reason' => '',
        ];
        return $this->page('admin/records/class', [
            'title' => $this->app->translator()->get('records.class.' . $class), 'tab' => 'rules', 'class' => $class, 'history' => $history,
            'current' => $this->app->retention()->current($class), 'canManage' => $ctx->az()->scope('core.records.manage')->all, 'errors' => $errors, 'old' => $old,
            'userId' => $ctx->userId(),
        ], $status);
    }

    private function guard(Request $r): RequestContext
    {
        $ctx = $this->ctx($r);
        $az = $ctx->az();
        if ($az->scope('core.records.manage')->isEmpty() && $az->scope('core.records.approve')->isEmpty()) {
            throw HttpException::forbidden();
        }
        return $ctx;
    }
}
