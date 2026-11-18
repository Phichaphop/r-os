<?php

declare(strict_types=1);

namespace Blue\Core\Integrations;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Ui\Paging;

/** Integration registry screens (6.30.15) + the machine endpoint for an external scheduler (M48 item 2). */
final class IntegrationsController extends Controller
{
    private const FIELDS = ['code', 'name', 'direction', 'system_type', 'owner', 'auth_method', 'scopes', 'data_classification', 'schema_version', 'endpoint',
        'rate_limit_per_min', 'timeout_seconds', 'retry_policy', 'idempotency', 'monitoring', 'incident_contact', 'cost_note', 'privacy_review', 'privacy_ref', 'status', 'notes'];

    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        return $this->page('admin/integrations/index', [
            'title' => $this->app->translator()->get('integration.title'), 'rows' => $this->app->integrations()->list($az),
            'canManage' => $az->scope('core.integration.manage')->all,
        ]);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    public function form(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->scope('core.integration.manage')->all) {
            throw HttpException::forbidden();
        }
        $pid = $r->params['id'] ?? null;
        if ($pid !== null && $old === []) {
            $i = $this->app->integrations()->get($az, (string) $pid);
            foreach (self::FIELDS as $f) {
                $old[$f] = (string) ($i[$f] ?? '');
            }
            $old['owner'] = (string) ($this->app->db()->scalar('SELECT username FROM users WHERE id = ?', [$i['owner_user_id']]) ?? '');
        }
        $old += ['direction' => 'outbound', 'auth_method' => 'api_key', 'data_classification' => 'I', 'privacy_review' => 'pending', 'status' => 'planned'];
        return $this->page('admin/integrations/form', [
            'title' => $this->app->translator()->get($pid === null ? 'integration.new' : 'integration.edit'), 'pid' => $pid, 'old' => $old, 'errors' => $errors,
        ], $status);
    }

    public function save(Request $r): Response
    {
        $pid = isset($r->params['id']) ? (string) $r->params['id'] : null;
        $in = [];
        foreach (self::FIELDS as $f) {
            $in[$f] = $this->str($r, $f, $f === 'notes' ? 1000 : 300);
        }
        try {
            $pid = $this->app->integrations()->save($this->ctx($r)->az(), $pid, $in);
        } catch (ValidationException $e) {
            return $this->form($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'integration.flash.saved');
        return $this->redirect($r, '/admin/integrations/' . $pid);
    }

    public function show(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $pid = (string) $r->params['id'];
        $i = $this->app->integrations()->get($az, $pid);
        $paging = Paging::from($r, $this->app->preferences(), $this->ctx($r)->userId(), 'integration_logs');
        $status = $this->str($r, 'status', 10);
        $secret = $this->store($r)->get('integration_secret');
        $this->store($r)->forget('integration_secret');
        return $this->page('admin/integrations/show', [
            'title' => (string) $i['name'], 'i' => $i, 'logs' => $this->app->integrations()->logs($az, $pid, $status, $paging->page, $paging->per),
            'status' => $status, 'page' => $paging->page, 'per' => $paging->per, 'canManage' => $az->scope('core.integration.manage')->all,
            'canKeys' => $az->scope('core.integration.credentials')->all,
            'secret' => is_array($secret) && ($secret['integration'] ?? '') === $pid ? $secret : null,
        ]);
    }

    public function issueKey(Request $r): Response
    {
        $this->ctx($r)->requireFreshReauth();
        $pid = (string) $r->params['id'];
        $days = $this->str($r, 'valid_days', 5);
        try {
            $c = $this->app->integrations()->issueCredential($this->ctx($r)->az(), $pid, $days === '' ? null : (ctype_digit($days) ? (int) $days : -1));
            // shown once on the next page, then gone (never stored in clear)
            $this->store($r)->set('integration_secret', ['integration' => $pid, 'key_id' => $c['key_id'], 'secret' => $c['secret']]);
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/admin/integrations/' . $pid);
    }

    public function revokeKey(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->app->integrations()->revokeCredential($this->ctx($r)->az(), (string) $r->params['key'], $this->str($r, 'reason', 255));
            $this->flash($r, 'success', 'integration.flash.revoked');
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/admin/integrations/' . $pid);
    }

    /**
     * POST /m2m/scheduler/run — an external scheduler (cron-job.org, OS cron with curl) runs due background jobs.
     * Authenticated by the integration's credential (api_key or HMAC), never by a session; every call is logged.
     */
    public function schedulerRun(Request $r): Response
    {
        $reg = $this->app->integrations();
        $i = $reg->byCode('blue.scheduler');
        if ($i === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $t0 = hrtime(true);
        $auth = $this->app->integrationAuth()->verify($r, $i);
        $cid = (string) ($r->attributes['correlation_id'] ?? '');
        if (!$auth['ok']) {
            $code = $auth['error'] === 'rate_limited' ? 429 : ($auth['error'] === 'integration_not_active' ? 403 : 401);
            $reg->log((int) $i['id'], 'inbound', ['operation' => 'scheduler.run', 'status' => 'rejected', 'correlation_id' => $cid, 'key_id' => $auth['key_id'],
                'http_status' => $code, 'error_code' => $auth['error'], 'duration_ms' => (int) ((hrtime(true) - $t0) / 1_000_000)]);
            return Response::json(['error' => $auth['error']], $code);
        }
        $budget = max(1000, min(55000, $this->app->config->int('SCHEDULER_CRON_BUDGET_MS', 25000)));
        $res = $this->app->scheduler()->runDue('cron', $budget, 20);
        $ran = array_map(static fn (array $x): array => ['job' => $x['type'], 'status' => $x['status']], $res['ran']);
        $failed = count(array_filter($ran, static fn (array $x): bool => $x['status'] !== 'succeeded'));
        $reg->log((int) $i['id'], 'inbound', ['operation' => 'scheduler.run', 'status' => $failed > 0 ? 'failed' : 'success', 'correlation_id' => $cid, 'key_id' => $auth['key_id'],
            'http_status' => 200, 'records' => count($ran), 'error_code' => $failed > 0 ? 'job_failed' : ($res['skipped'] ?? null),
            'duration_ms' => (int) ((hrtime(true) - $t0) / 1_000_000)]);
        return Response::json(['ran' => $ran, 'skipped' => $res['skipped']]);
    }
}
