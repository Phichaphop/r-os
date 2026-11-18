<?php

declare(strict_types=1);

namespace Blue\Modules\Welfare;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** Welfare staff screens: claims to review/approve · welfare types (HR) · payout (payroll) · usage report. */
final class WelfareAdminController extends Controller
{
    /**
     * Companies the person covers with one of the permissions, and the chosen one.
     *
     * @param list<string> $perms
     * @return array{0: array<string, string>, 1: string, 2: int}
     */
    private function company(Request $r, array $perms): array
    {
        $az = $this->ctx($r)->az();
        $ids = [];
        foreach ($perms as $p) {
            $ids = array_merge($ids, TypeService::of($this->app)->companies($az, $p));
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            throw HttpException::forbidden();
        }
        $companies = [];
        foreach ($this->app->db()->all('SELECT id, public_id, code FROM legal_entities WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY code') as $row) {
            $companies[(string) $row['public_id']] = (string) $row['code'];
        }
        $pid = (string) ($r->query['le'] ?? $r->body['le'] ?? '');
        if (!isset($companies[$pid])) {
            $pid = (string) array_key_first($companies);
        }
        return [$companies, $pid, (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$pid])];
    }

    private function back(Request $r, string $to, BusinessException $e): Response
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
        $this->flash($r, 'danger', $e->messageKey, $e->params);
        return $this->redirect($r, $to);
    }

    private function year(Request $r): int
    {
        $y = (string) ($r->query['year'] ?? '');
        $now = (int) substr(TypeService::of($this->app)->today(), 0, 4);
        return ctype_digit($y) && (int) $y >= $now - 5 && (int) $y <= $now ? (int) $y : $now;
    }

    /** @return array<string, bool> which staff tabs the person sees for a company */
    private function tabs(Request $r, int $le): array
    {
        $az = $this->ctx($r)->az();
        $c = static fn (string $p): bool => $az->scope($p)->coversLegalEntity($le);
        return ['queue' => $c('welfare.review') || $c('welfare.approve'), 'types' => $c('welfare.manage'), 'payout' => $c('welfare.pay'), 'report' => $c('welfare.report')];
    }

    // ---- review queue -------------------------------------------------------------------------------------------------------

    public function queue(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r, ['welfare.review', 'welfare.approve']);
        $filter = (string) ($r->query['f'] ?? 'open');
        $svc = ClaimService::of($this->app);
        return $this->page('@welfare/admin', ['title' => $this->app->translator()->get('welfare.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'filter' => $filter,
            'claims' => $svc->queue($this->ctx($r)->az(), $le, $filter), 'counts' => $svc->counts($le), 'tabs' => $this->tabs($r, $le), 'here' => '/welfare/admin'])->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    // ---- types ------------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function types(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r, ['welfare.manage']);
        $svc = TypeService::of($this->app);
        $basePid = (string) ($old['base'] ?? $r->query['edit'] ?? '');
        $base = null;
        if ($basePid !== '') {
            $base = $this->app->db()->one("SELECT * FROM wf_types WHERE public_id = ? AND legal_entity_id = ? AND status IN ('active','draft')", [$basePid, $le]);
        }
        return $this->page('@welfare/types', ['title' => $this->app->translator()->get('welfare.types_title'), 'companies' => $companies, 'lePid' => $lePid,
            'overview' => $svc->overview($le), 'components' => $svc->components($le), 'base' => $base, 'me' => $this->ctx($r)->userId(), 'today' => $svc->today(),
            'errors' => $errors, 'old' => $old, 'tabs' => $this->tabs($r, $le), 'here' => '/welfare/types'], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function saveType(Request $r): Response
    {
        [, $lePid, $le] = $this->company($r, ['welfare.manage']);
        $in = [];
        foreach (
            ['code' => 20, 'name_th' => 120, 'name_en' => 120, 'category' => 20, 'description' => 1000, 'limit_year' => 20, 'limit_event' => 20, 'max_claims' => 5, 'window_days' => 5,
            'min_service_days' => 5, 'payout' => 10, 'component_code' => 20, 'policy_ref' => 300, 'effective_from' => 10, 'change_note' => 500, 'base' => 26] as $k => $max
        ) {
            $in[$k] = $this->str($r, $k, $max);
        }
        $in['employment_types'] = array_values(array_filter(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', (array) ($r->body['employment_types'] ?? []))));
        $in['receipt_required'] = !empty($r->body['receipt_required']);
        try {
            TypeService::of($this->app)->save($this->ctx($r)->az(), $le, $in, (string) $in['base']);
        } catch (ValidationException $e) {
            return $this->types($r, $this->errors($e), $in, 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/welfare/types?le=' . rawurlencode($lePid), $e);
        }
        $this->flash($r, 'success', 'welfare.flash.type_saved');
        return $this->redirect($r, '/welfare/types?le=' . rawurlencode($lePid));
    }

    public function typeAction(Request $r): Response
    {
        [, $lePid] = $this->company($r, ['welfare.manage']);
        $to = '/welfare/types?le=' . rawurlencode($lePid);
        $action = (string) $r->params['action'];
        $svc = TypeService::of($this->app);
        $az = $this->ctx($r)->az();
        try {
            match ($action) {
                'activate' => $svc->activate($az, (string) $r->params['id']),
                'discard' => $svc->discard($az, (string) $r->params['id'], $this->str($r, 'reason', 300)),
                default => $svc->retire($az, (string) $r->params['id'], $this->str($r, 'last_day', 10), $this->str($r, 'reason', 300)),
            };
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, $to);
        } catch (BusinessException $e) {
            return $this->back($r, $to, $e);
        }
        $this->flash($r, 'success', 'welfare.flash.type_' . $action);
        return $this->redirect($r, $to);
    }

    // ---- payout -----------------------------------------------------------------------------------------------------------------

    /** @param array<string, string> $errors */
    public function payout(Request $r, array $errors = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r, ['welfare.pay']);
        $svc = PayoutService::of($this->app);
        $az = $this->ctx($r)->az();
        $batches = $svc->batches($az, $le);
        return $this->page('@welfare/payout', ['title' => $this->app->translator()->get('welfare.payout_title'), 'companies' => $companies, 'lePid' => $lePid,
            'ready' => $svc->ready($az, $le), 'batches' => $batches, 'today' => TypeService::of($this->app)->today(), 'errors' => $errors,
            'canPayment' => $az->scope('core.payment.prepare')->coversLegalEntity($le), 'tabs' => $this->tabs($r, $le), 'here' => '/welfare/payout'], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function createBatch(Request $r): Response
    {
        [, $lePid, $le] = $this->company($r, ['welfare.pay']);
        $method = $this->str($r, 'method', 10);
        $claims = array_values(array_filter(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', (array) ($r->body['claims'] ?? []))));
        try {
            $pid = PayoutService::of($this->app)->create($this->ctx($r)->az(), $le, $method, $claims, $this->str($r, 'pay_date', 10), $this->str($r, 'note', 300));
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, '/welfare/payout?le=' . rawurlencode($lePid));
        } catch (BusinessException $e) {
            return $this->back($r, '/welfare/payout?le=' . rawurlencode($lePid), $e);
        }
        $no = (string) $this->app->db()->scalar('SELECT batch_no FROM wf_batches WHERE public_id = ?', [$pid]);
        $this->flash($r, 'success', 'welfare.flash.batch_' . ($method === 'payment' ? 'payment' : 'payroll'), ['no' => $no]);
        return $this->redirect($r, '/welfare/payout?le=' . rawurlencode($lePid) . '#batches');
    }

    public function cancelBatch(Request $r): Response
    {
        [, $lePid] = $this->company($r, ['welfare.pay']);
        $to = '/welfare/payout?le=' . rawurlencode($lePid) . '#batches';
        try {
            PayoutService::of($this->app)->cancel($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'reason', 300));
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, $to);
        } catch (BusinessException $e) {
            return $this->back($r, $to, $e);
        }
        $this->flash($r, 'success', 'welfare.flash.batch_cancelled');
        return $this->redirect($r, $to);
    }

    // ---- report ------------------------------------------------------------------------------------------------------------------

    public function report(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r, ['welfare.report']);
        $year = $this->year($r);
        return $this->page('@welfare/report', ['title' => $this->app->translator()->get('welfare.report_title'), 'companies' => $companies, 'lePid' => $lePid, 'year' => $year,
            'rows' => PayoutService::of($this->app)->usage($this->ctx($r)->az(), $le, $year), 'tabs' => $this->tabs($r, $le), 'here' => '/welfare/report'])->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function export(Request $r): Response
    {
        [, , $le] = $this->company($r, ['welfare.report']);
        $this->ctx($r)->requireFreshReauth();
        $f = PayoutService::of($this->app)->export($this->ctx($r)->az(), $le, $this->year($r));
        return DocumentsController::fileResponse($f['bytes'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $f['name'], false)->withHeader('Cache-Control', 'no-store, max-age=0');
    }
}
