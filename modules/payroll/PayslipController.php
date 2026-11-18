<?php

declare(strict_types=1);

namespace Blue\Modules\Payroll;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** Phase 6B screens: my payslips (password first) · publish payslips of a run · opening year-to-date amounts. */
final class PayslipController extends Controller
{
    private function svc(): PayslipService
    {
        return PayslipService::of($this->app);
    }

    public function mine(Request $r): Response
    {
        $ctx = $this->ctx($r);
        if (!$ctx->can('payroll.self')) {
            throw HttpException::forbidden();
        }
        return $this->page('@payroll/my_payslips', ['title' => $this->app->translator()->get('payroll.my_title'), 'rows' => $this->svc()->mine($ctx->userId())])
            ->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function show(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $pid = (string) $r->params['id'];
        $this->svc()->mineOne($ctx->userId(), $pid, false); // someone else's → 404 before asking for a password
        $ctx->requireFreshReauth(); // figures only after the password (M36: hidden until the person confirms)
        $s = $this->svc()->mineOne($ctx->userId(), $pid);
        return $this->page('@payroll/my_payslip', ['title' => (string) $s['label'], 'slip' => $s])->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function pdf(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $ctx->requireFreshReauth();
        try {
            $f = $this->svc()->file($ctx->az(), (string) $r->params['id']);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/me/payslips');
        }
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], true)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function publish(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $res = $this->svc()->publish($this->ctx($r)->az(), $pid);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, $e->params);
            return $this->redirect($r, '/payroll/runs/' . $pid);
        }
        $this->flash($r, 'success', 'payroll.flash.published', ['n' => (string) $res['published'], 'm' => (string) $res['notified']]);
        return $this->redirect($r, '/payroll/runs/' . $pid . '#slips');
    }

    public function opening(Request $r): Response
    {
        $emp = (string) $r->params['emp'];
        $in = [];
        foreach (['tax_year' => 4, 'as_of' => 10, 'gross' => 20, 'taxable_income' => 20, 'wht' => 20, 'sso_employee' => 20, 'note' => 500] as $k => $n) {
            $in[$k] = $this->str($r, $k, $n);
        }
        try {
            $this->svc()->proposeOpening($this->ctx($r)->az(), $emp, $in);
        } catch (ValidationException $e) {
            return (new PayrollController($this->app))->person($r, $this->errors($e), $in, 422, 'opening');
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/payroll/people/' . $emp . '#ytd');
        }
        $this->flash($r, 'success', 'payroll.flash.opening');
        return $this->redirect($r, '/payroll/people/' . $emp . '#ytd');
    }

    public function decideOpening(Request $r): Response
    {
        $emp = (string) $r->params['emp'];
        try {
            $this->svc()->decideOpening($this->ctx($r)->az(), (string) $r->params['id'], $r->params['action'] === 'approve', $this->str($r, 'decision_note', 300));
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', 'payroll.error.reject_note');
            return $this->redirect($r, '/payroll/people/' . $emp . '#ytd');
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/payroll/people/' . $emp . '#ytd');
        }
        $this->flash($r, 'success', 'payroll.flash.opening_' . $r->params['action']);
        return $this->redirect($r, '/payroll/people/' . $emp . '#ytd');
    }

    public function staffPdf(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $ctx->requireFreshReauth();
        $f = $this->svc()->file($ctx->az(), (string) $r->params['id']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], true)->withHeader('Cache-Control', 'no-store, max-age=0');
    }
}
