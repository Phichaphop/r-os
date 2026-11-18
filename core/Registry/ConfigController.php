<?php

declare(strict_types=1);

namespace Blue\Core\Registry;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** Configuration Registry screens (6.25.2): catalogue, versions timeline, propose, submit, impact, cancel, resolve. */
final class ConfigController extends Controller
{
    /** Definitions + the organization-level value in force now (Excel/CSV) — for review/audit, not for re-import. */
    public function export(Request $r): Response
    {
        $ctx = $this->ctx($r);
        if ($ctx->az()->scope('core.config.view')->isEmpty()) {
            throw HttpException::forbidden();
        }
        $t = $this->app->translator();
        $rows = [];
        foreach ($this->app->registry()->definitions($this->str($r, 'category', 40), $this->str($r, 'q', 60)) as $d) {
            $cur = $d['current'];
            $rows[] = [$d['config_key'], $d['category'], $d['data_type'], (string) $d['unit'], $d['source_type'], $d['risk_level'], (int) $d['is_required'] === 1 ? 'yes' : 'no',
                $cur === null ? '' : ConfigValue::display($cur->value), $cur?->sourceScope ?? '', $cur?->effectiveFrom ?? '', (int) $d['pending'], (int) $d['scheduled']];
        }
        $this->app->audit()->record('config.exported', 'success', null, null, null, null, ['rows' => count($rows)]);
        return \Blue\Core\Files\TableExport::response(\Blue\Core\Files\TableExport::format($r->query['format'] ?? 'xlsx'), 'config-' . $this->app->clock->now()->format('Ymd-His'), [
            $t->get('config.field.key'), $t->get('config.field.category'), $t->get('config.field.type'), 'unit', $t->get('config.field.source'), $t->get('config.field.risk'),
            $t->get('config.field.required'), $t->get('config.field.current'), $t->get('config.field.scope'), $t->get('config.field.effective') . ' (UTC)', $t->get('config.field.pending'), 'scheduled',
        ], $rows);
    }

    public function index(Request $r): Response
    {
        $ctx = $this->ctx($r);
        if ($ctx->az()->scope('core.config.view')->isEmpty()) {
            throw HttpException::forbidden();
        }
        $cat = $this->str($r, 'category', 40);
        $q = $this->str($r, 'q', 60);
        $defs = $this->app->registry()->definitions($cat, $q);
        $cats = array_values(array_unique(array_column($this->app->registry()->definitions(), 'category')));
        return $this->page('admin/config/index', [
            'title' => $this->app->translator()->get('config.title'),
            'defs' => $defs, 'categories' => $cats, 'category' => $cat, 'q' => $q,
        ]);
    }

    public function show(Request $r): Response
    {
        $ctx = $this->ctx($r);
        if ($ctx->az()->scope('core.config.view')->isEmpty()) {
            throw HttpException::forbidden();
        }
        $key = (string) $r->params['key'];
        $reg = $this->app->registry();
        $def = $reg->definition($key);
        // "what applies to …?" — resolve for a chosen company/site at a chosen date
        $le = (int) ($r->query['le'] ?? 0) ?: null;
        $site = (int) ($r->query['site'] ?? 0) ?: null;
        $at = $this->date((string) ($r->query['at'] ?? ''), '00:00') ?? $this->app->clock->now();
        $resolved = $reg->resolveOrNull($key, $le, $site, $at);
        $canPropose = !$ctx->az()->scope('core.config.propose')->isEmpty();
        return $this->page('admin/config/show', [
            'title' => $key,
            'def' => $def,
            'versions' => $reg->versions($key),
            'resolved' => $resolved, 'resolveFor' => ['le' => $le, 'site' => $site, 'at' => $at],
            'scopeOptions' => $this->app->org()->scopeOptions($ctx->az()->reachableScope(), $this->app->translator()->locale()),
            'orgId' => (int) ($this->app->org()->organization()['id'] ?? 0),
            'canPropose' => $canPropose,
            'errors' => $this->store($r)->get('config_errors') ?? [],
            'old' => $this->store($r)->get('config_old') ?? [],
            'tz' => $this->tz(),
        ]);
    }

    public function propose(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $key = (string) $r->params['key'];
        [$level, $id] = $this->scope($this->str($r, 'scope', 60));
        $from = $this->date($this->str($r, 'from_date', 10), $this->str($r, 'from_time', 5) ?: '00:00');
        $toRaw = $this->str($r, 'to_date', 10);
        $to = $toRaw === '' ? null : $this->date($toRaw, '00:00');
        $old = ['value' => $this->str($r, 'value', 2000), 'scope' => $this->str($r, 'scope', 60), 'from_date' => $this->str($r, 'from_date', 10),
            'from_time' => $this->str($r, 'from_time', 5), 'to_date' => $toRaw, 'reason' => $this->str($r, 'reason', 255), 'source_reference' => $this->str($r, 'source_reference', 255)];
        try {
            if ($from === null || ($toRaw !== '' && $to === null)) {
                throw new ValidationException($from === null ? ['effective_from' => 'form.invalid_date'] : ['effective_to' => 'form.invalid_date']);
            }
            $pid = $this->app->registry()->propose($ctx->az(), $key, $level, $id, $old['value'], $from, $to, $old['reason'], $old['source_reference']);
        } catch (ValidationException $e) {
            $this->store($r)->set('config_errors', $this->errors($e));
            $this->store($r)->set('config_old', $old);
            $this->flash($r, 'danger', 'form.has_errors');
            return $this->redirect($r, '/admin/config/key/' . $key . '#propose');
        }
        $this->store($r)->forget('config_errors');
        $this->store($r)->forget('config_old');
        $this->flash($r, 'success', 'config.proposed');
        return $this->redirect($r, '/admin/config/version/' . $pid);
    }

    public function version(Request $r): Response
    {
        $ctx = $this->ctx($r);
        if ($ctx->az()->scope('core.config.view')->isEmpty()) {
            throw HttpException::forbidden();
        }
        $reg = $this->app->registry();
        $v = $reg->version((string) $r->params['id']);
        $v['display_status'] = $reg->displayStatus($v, (int) $v['definition_id']);
        return $this->page('admin/config/version', [
            'title' => (string) $v['config_key'],
            'ver' => $v,
            'impact' => $reg->impact($v),
            'scopeLabel' => $this->scopeLabel((string) $v['scope_level'], (int) $v['scope_id']),
            'isProposer' => (int) $v['proposed_by'] === $ctx->userId(),
            'canApprove' => !$ctx->az()->scope('core.config.approve')->isEmpty(),
            'tz' => $this->tz(),
        ]);
    }

    public function submit(Request $r): Response
    {
        $id = (string) $r->params['id'];
        try {
            $inst = $this->app->registry()->submit($this->ctx($r)->az(), $id);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/admin/config/version/' . $id);
        }
        $status = (string) $this->app->workflow()->instanceRow($inst)['status'];
        $this->flash($r, $status === 'needs_attention' ? 'warning' : 'success', $status === 'needs_attention' ? 'config.submitted_no_approver' : 'config.submitted');
        return $this->redirect($r, '/admin/config/version/' . $id);
    }

    public function cancel(Request $r): Response
    {
        $id = (string) $r->params['id'];
        try {
            $this->app->registry()->cancel($this->ctx($r)->az(), $id, $this->str($r, 'reason', 255));
            $this->flash($r, 'success', 'config.cancelled');
        } catch (ValidationException) {
            $this->flash($r, 'danger', 'iam.error.reason_required');
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/admin/config/version/' . $id);
    }

    /** @return array{0: string, 1: int} */
    private function scope(string $raw): array
    {
        if (preg_match('/^(organization|legal_entity|site):(\d+)$/', $raw, $m)) {
            return [$m[1], (int) $m[2]];
        }
        return ['organization', (int) ($this->app->org()->organization()['id'] ?? 0)];
    }

    private function scopeLabel(string $level, int $id): string
    {
        $t = $this->app->translator();
        if ($level === 'organization') {
            return $t->get('config.scope.organization');
        }
        $table = $level === 'legal_entity' ? 'legal_entities' : 'sites';
        $code = (string) ($this->app->db()->scalar("SELECT code FROM {$table} WHERE id = ?", [$id]) ?? '?');
        return $t->get('config.scope.' . $level) . ' ' . $code;
    }

    private function tz(): DateTimeZone
    {
        try {
            return new DateTimeZone((string) ($this->app->org()->organization()['default_timezone'] ?? 'Asia/Bangkok'));
        } catch (\Exception) {
            return new DateTimeZone('Asia/Bangkok');
        }
    }

    /** Date (+ time) entered in the organization's timezone → UTC instant. */
    private function date(string $date, string $time): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $this->tz());
        return $d === false ? null : $d->setTimezone(new DateTimeZone('UTC'));
    }
}
