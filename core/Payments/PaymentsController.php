<?php

declare(strict_types=1);

namespace Blue\Core\Payments;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;
use Blue\Core\Ui\Paging;

/** Payment batches and bank files (DEC-26/31). */
final class PaymentsController extends Controller
{
    public function index(Request $r): Response
    {
        $az = $this->guard($r);
        $paging = Paging::from($r, $this->app->preferences(), $this->ctx($r)->userId(), 'pmt_batches');
        $le = $this->str($r, 'legal_entity', 20);
        $f = ['legal_entity' => ctype_digit($le) ? (int) $le : 0, 'status' => $this->str($r, 'status', 20), 'type' => $this->str($r, 'type', 10), 'q' => $this->str($r, 'q', 60)];
        $companies = $this->app->org()->scopeOptions($az->scope('core.payment.view'), $this->app->translator()->locale())['legal_entity'];
        return $this->page('payments/index', [
            'title' => $this->app->translator()->get('payment.title'), 'list' => $this->app->payments()->list($az, $f, $paging->page, $paging->per),
            'f' => $f, 'page' => $paging->page, 'per' => $paging->per, 'companies' => $companies, 'canPrepare' => $az->can('core.payment.prepare'),
        ]);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    public function createForm(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('core.payment.prepare')) {
            throw HttpException::forbidden();
        }
        $companies = $this->app->org()->scopeOptions($az->scope('core.payment.prepare'), $this->app->translator()->locale())['legal_entity'];
        $types = [];
        foreach (array_keys($companies) as $le) {
            foreach ($this->app->payments()->manualTypes((int) $le) as $tp) {
                $types[$tp] = $this->app->translator()->get('payment.type.' . $tp);
            }
        }
        return $this->page('payments/new', [
            'title' => $this->app->translator()->get('payment.new.title'), 'companies' => $companies, 'types' => $types,
            'errors' => $errors, 'old' => $old + ['legal_entity' => (string) array_key_first($companies), 'type' => (string) array_key_first($types), 'pay_date' => '', 'period' => '', 'note' => ''],
        ], $status);
    }

    public function create(Request $r): Response
    {
        $in = ['legal_entity' => $this->str($r, 'legal_entity', 20), 'type' => $this->str($r, 'type', 10), 'pay_date' => $this->str($r, 'pay_date', 10),
            'period' => $this->str($r, 'period', 40), 'note' => $this->str($r, 'note', 500)];
        try {
            $pid = $this->app->payments()->createManual($this->ctx($r)->az(), ctype_digit($in['legal_entity']) ? (int) $in['legal_entity'] : 0, $in['type'], $in['pay_date'], $in['period'], $in['note']);
        } catch (ValidationException $e) {
            return $this->createForm($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'payment.flash.created');
        return $this->redirect($r, '/payments/' . $pid);
    }

    public function show(Request $r): Response
    {
        $az = $this->guard($r);
        $pid = (string) $r->params['id'];
        $b = $this->app->payments()->get($az, $pid);
        $le = (int) $b['legal_entity_id'];
        $paging = Paging::from($r, $this->app->preferences(), $this->ctx($r)->userId(), 'pmt_items');
        $status = $this->str($r, 'status', 10);
        return $this->page('payments/show', [
            'title' => $this->app->translator()->get('payment.batch') . ' ' . $b['batch_no'], 'b' => $b,
            'items' => $this->app->payments()->items($az, $pid, $status, $paging->page, $paging->per), 'status' => $status, 'page' => $paging->page, 'per' => $paging->per,
            'can' => [
                'prepare' => $az->scope('core.payment.prepare')->coversLegalEntity($le), 'approve' => $az->scope('core.payment.approve')->coversLegalEntity($le),
                'download' => $az->scope('core.payment.download')->coversLegalEntity($le), 'reconcile' => $az->scope('core.payment.reconcile')->coversLegalEntity($le),
                'finance' => $az->scope('core.finance.view')->coversLegalEntity($le),
            ],
            'formats' => $this->app->bankFormats()->active($le),
            'wf' => $b['workflow_instance_id'] !== null ? $this->app->db()->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$b['workflow_instance_id']]) : null,
        ]);
    }

    public function submit(Request $r): Response
    {
        return $this->act($r, fn (string $pid) => $this->app->payments()->submit($this->ctx($r)->az(), $pid), 'payment.flash.submitted');
    }

    public function resubmit(Request $r): Response
    {
        return $this->act($r, fn (string $pid) => $this->app->payments()->resubmit($this->ctx($r)->az(), $pid), 'payment.flash.submitted');
    }

    public function cancel(Request $r): Response
    {
        return $this->act($r, fn (string $pid) => $this->app->payments()->cancel($this->ctx($r)->az(), $pid, $this->str($r, 'reason', 500)), 'payment.flash.cancelled');
    }

    public function clear(Request $r): Response
    {
        return $this->act($r, fn (string $pid) => $this->app->payments()->clearItems($this->ctx($r)->az(), $pid), 'payment.flash.cleared');
    }

    public function generate(Request $r): Response
    {
        $this->ctx($r)->requireFreshReauth();
        return $this->act($r, fn (string $pid) => $this->app->payments()->generateFile($this->ctx($r)->az(), $pid, $this->str($r, 'format', 26)), 'payment.flash.generated');
    }

    public function supersede(Request $r): Response
    {
        $this->ctx($r)->requireFreshReauth();
        return $this->act($r, fn (string $pid) => $this->app->payments()->supersedeFile($this->ctx($r)->az(), $pid, $this->str($r, 'reason', 500)), 'payment.flash.superseded');
    }

    public function sent(Request $r): Response
    {
        return $this->act($r, fn (string $pid) => $this->app->payments()->markSent($this->ctx($r)->az(), $pid), 'payment.flash.sent');
    }

    public function reissue(Request $r): Response
    {
        $new = null;
        $pidOld = (string) $r->params['id'];
        $res = $this->act($r, function (string $pid) use ($r, &$new): void {
            $new = $this->app->payments()->reissueFailed($this->ctx($r)->az(), $pid, $this->str($r, 'pay_date', 10));
        }, 'payment.flash.reissued');
        unset($pidOld);
        return $new !== null ? $this->redirect($r, '/payments/' . $new) : $res;
    }

    /** Restricted file: password again (re-authentication) every time; audited and counted. */
    public function file(Request $r): Response
    {
        $this->ctx($r)->requireFreshReauth();
        $f = $this->app->payments()->downloadFile($this->ctx($r)->az(), (string) $r->params['id']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], false);
    }

    private function act(Request $r, callable $fn, string $okKey): Response
    {
        $this->guard($r);
        $pid = (string) $r->params['id'];
        try {
            $fn($pid);
            $this->flash($r, 'success', $okKey);
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, array_map('strval', $e->params));
        }
        return $this->redirect($r, '/payments/' . $pid);
    }

    private function guard(Request $r): \Blue\Core\Authz\Authorizer
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('core.payment.view')) {
            throw HttpException::forbidden();
        }
        return $az;
    }
}
