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

/** Phase 6C screens: my tax allowances + certificates · payroll: declarations to check, certificates of a year. */
final class TaxController extends Controller
{
    private function svc(): TaxService
    {
        return TaxService::of($this->app);
    }

    private function year(Request $r): int
    {
        $y = (string) ($r->query['year'] ?? $r->body['tax_year'] ?? '');
        $now = (int) substr(PayrollService::of($this->app)->today(), 0, 4);
        return ctype_digit($y) && (int) $y >= $now - 5 && (int) $y <= $now + 1 ? (int) $y : $now;
    }

    private function back(Request $r, string $to, BusinessException $e): Response
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
        $this->flash($r, 'danger', $e->messageKey, $e->params);
        return $this->redirect($r, $to);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function mine(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $ctx = $this->ctx($r);
        if (!$ctx->can('payroll.self')) {
            throw HttpException::forbidden();
        }
        $year = $this->year($r);
        return $this->page('@payroll/my_tax', ['title' => $this->app->translator()->get('payroll.my_tax_title'), 'p' => $this->svc()->myPage($ctx->userId(), $year), 'year' => $year,
            'errors' => $errors, 'old' => $old], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function submit(Request $r): Response
    {
        $year = $this->year($r);
        $values = [];
        foreach ((array) ($r->body['v'] ?? []) as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $values[mb_substr($k, 0, 20)] = mb_substr((string) $v, 0, 20);
            }
        }
        try {
            $this->svc()->submit($this->ctx($r)->userId(), $year, $values);
        } catch (ValidationException $e) {
            return $this->mine($r, $this->errors($e), ['v' => $values], 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/me/tax?year=' . $year, $e);
        }
        $this->flash($r, 'success', 'payroll.flash.declared');
        return $this->redirect($r, '/me/tax?year=' . $year);
    }

    public function myPdf(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $ctx->requireFreshReauth();
        try {
            $f = $this->svc()->file($ctx->az(), (string) $r->params['id']);
        } catch (BusinessException $e) {
            return $this->back($r, '/me/tax', $e);
        }
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], true)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function index(Request $r): Response
    {
        $les = PayrollService::of($this->app)->companies($this->ctx($r)->az());
        if ($les === []) {
            throw HttpException::forbidden();
        }
        $companies = [];
        foreach ($les as $id) {
            $row = $this->app->db()->one('SELECT public_id, code FROM legal_entities WHERE id = ?', [$id]);
            $companies[(string) $row['public_id']] = (string) $row['code'];
        }
        $lePid = (string) ($r->query['le'] ?? '');
        if (!isset($companies[$lePid])) {
            $lePid = (string) array_key_first($companies);
        }
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        $az = $this->ctx($r)->az();
        $year = $this->year($r);
        return $this->page('@payroll/tax', ['title' => $this->app->translator()->get('payroll.tax_title'), 'companies' => $companies, 'lePid' => $lePid, 'year' => $year,
            'declarations' => $this->svc()->declarations($le, $year), 'types' => $this->svc()->allowanceTypes($le, $year), 'candidates' => $this->svc()->candidates($le, $year),
            'certs' => $this->svc()->certificates($le, $year), 'payer' => $this->svc()->payer($le), 'me' => $az->userId,
            'canPrepare' => $az->scope('payroll.prepare')->coversLegalEntity($le), 'canApprove' => $az->scope('payroll.approve')->coversLegalEntity($le)])->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function decide(Request $r): Response
    {
        $to = '/payroll/tax?le=' . rawurlencode($this->str($r, 'le', 26)) . '&year=' . $this->year($r);
        try {
            $this->svc()->decide($this->ctx($r)->az(), (string) $r->params['id'], $r->params['action'] === 'approve', $this->str($r, 'decision_note', 500));
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', 'payroll.error.reject_note');
            return $this->redirect($r, $to);
        } catch (BusinessException $e) {
            return $this->back($r, $to, $e);
        }
        $this->flash($r, 'success', 'payroll.flash.declaration_' . $r->params['action']);
        return $this->redirect($r, $to);
    }

    public function issue(Request $r): Response
    {
        $lePid = $this->str($r, 'le', 26);
        $year = $this->year($r);
        $to = '/payroll/tax?le=' . rawurlencode($lePid) . '&year=' . $year;
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        try {
            $res = $this->svc()->issue($this->ctx($r)->az(), $le, $year);
        } catch (BusinessException $e) {
            return $this->back($r, $to, $e);
        }
        $this->flash($r, 'success', 'payroll.flash.issued', ['n' => (string) $res['issued'], 'no_id' => (string) $res['no_id'], 'outdated' => (string) $res['outdated']]);
        return $this->redirect($r, $to . '#certs');
    }

    public function reissue(Request $r): Response
    {
        $to = '/payroll/tax?le=' . rawurlencode($this->str($r, 'le', 26)) . '&year=' . $this->year($r);
        try {
            $this->svc()->reissue($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'reason', 500));
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', 'payroll.error.reissue_reason');
            return $this->redirect($r, $to . '#certs');
        } catch (BusinessException $e) {
            return $this->back($r, $to, $e);
        }
        $this->flash($r, 'success', 'payroll.flash.reissued');
        return $this->redirect($r, $to . '#certs');
    }

    public function staffPdf(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $ctx->requireFreshReauth();
        $f = $this->svc()->file($ctx->az(), (string) $r->params['id']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], true)->withHeader('Cache-Control', 'no-store, max-age=0');
    }
}
