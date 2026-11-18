<?php

declare(strict_types=1);

namespace Blue\Modules\Welfare;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** Welfare screens for everyone: my limits and claims, write / send / withdraw a claim, and the claim page (where reviewers decide). */
final class WelfareController extends Controller
{
    private function svc(): ClaimService
    {
        return ClaimService::of($this->app);
    }

    private function year(Request $r): int
    {
        $y = (string) ($r->query['year'] ?? '');
        $now = (int) substr(TypeService::of($this->app)->today(), 0, 4);
        return ctype_digit($y) && (int) $y >= $now - 5 && (int) $y <= $now ? (int) $y : $now;
    }

    private function back(Request $r, string $to, BusinessException $e): Response
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
        $this->flash($r, 'danger', $e->messageKey, $e->params);
        return $this->redirect($r, $to);
    }

    /** @return list<UploadedFile> */
    private function uploads(Request $r): array
    {
        return array_values(array_filter((array) ($r->files['files'] ?? []), static fn (mixed $f): bool => $f instanceof UploadedFile && $f->error !== UPLOAD_ERR_NO_FILE));
    }

    /** @return array<string, string> */
    private function input(Request $r): array
    {
        $in = [];
        foreach (['type' => 20, 'expense_date' => 10, 'description' => 500, 'amount' => 20, 'receipt_no' => 60, 'receipt_from' => 160, 'payee_name' => 160] as $k => $max) {
            $in[$k] = $this->str($r, $k, $max);
        }
        return $in;
    }

    private function guard(Request $r): void
    {
        if (!$this->ctx($r)->can('welfare.use')) {
            throw HttpException::forbidden();
        }
    }

    public function mine(Request $r): Response
    {
        $this->guard($r);
        $ctx = $this->ctx($r);
        $year = $this->year($r);
        $me = $this->svc()->me($ctx->userId());
        return $this->page('@welfare/index', ['title' => $this->app->translator()->get('welfare.title'), 'me' => $me, 'year' => $year,
            'balances' => $me !== null ? $this->svc()->balances((int) $me['employee']['id'], $me['le'], $year) : [],
            'claims' => $me !== null ? $this->svc()->mine((int) $me['employee']['id'], $year) : [],
            'today' => TypeService::of($this->app)->today(), 'staff' => $ctx->can('welfare.review') || $ctx->can('welfare.manage') || $ctx->can('welfare.pay') || $ctx->can('welfare.report')]);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     * @param array<string, mixed>|null $claim
     */
    public function form(Request $r, array $errors = [], array $old = [], ?array $claim = null, int $status = 200): Response
    {
        $this->guard($r);
        $me = $this->svc()->me($this->ctx($r)->userId());
        if ($me === null) {
            $this->flash($r, 'danger', 'welfare.error.no_employee');
            return $this->redirect($r, '/welfare');
        }
        $types = TypeService::of($this->app)->inForce($me['le'], TypeService::of($this->app)->today());
        $year = (int) substr(TypeService::of($this->app)->today(), 0, 4);
        $bal = [];
        foreach ($this->svc()->balances((int) $me['employee']['id'], $me['le'], $year) as $b) {
            $bal[(string) $b['type']['code']] = $b;
        }
        if ($old === [] && $claim !== null) {
            $old = ['type' => $claim['type']['code'] ?? '', 'expense_date' => $claim['expense_date'], 'description' => $claim['description'], 'amount' => \Blue\Core\Support\Money::format(\Blue\Core\Support\Money::fromDb($claim['amount_claimed']), 2, false),
                'receipt_no' => $claim['receipt_no'], 'receipt_from' => $claim['receipt_from'], 'payee_name' => $claim['payee_name']];
        } elseif ($old === []) {
            $old = ['type' => strtoupper((string) ($r->query['type'] ?? ''))];
        }
        return $this->page('@welfare/form', ['title' => $this->app->translator()->get($claim !== null ? 'welfare.edit_title' : 'welfare.new_title'), 'types' => $types, 'balances' => $bal,
            'claim' => $claim, 'errors' => $errors, 'old' => $old, 'today' => TypeService::of($this->app)->today()], $status);
    }

    public function create(Request $r): Response
    {
        $this->guard($r);
        try {
            $pid = $this->svc()->create($this->ctx($r)->az(), $this->input($r), $this->uploads($r));
        } catch (ValidationException $e) {
            return $this->form($r, $this->errors($e), $this->input($r), null, 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/welfare', $e);
        }
        $this->flash($r, 'success', 'welfare.flash.saved');
        return $this->redirect($r, '/welfare/claims/' . $pid);
    }

    public function edit(Request $r): Response
    {
        $this->guard($r);
        $c = $this->svc()->view($this->ctx($r)->az(), (string) $r->params['id']);
        if (!$c['own'] || !in_array($c['status'], ClaimService::EDITABLE, true)) {
            return $this->redirect($r, '/welfare/claims/' . $c['public_id']);
        }
        return $this->form($r, [], [], $c);
    }

    public function update(Request $r): Response
    {
        $this->guard($r);
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->update($this->ctx($r)->az(), $pid, $this->input($r), $this->uploads($r));
        } catch (ValidationException $e) {
            return $this->form($r, $this->errors($e), $this->input($r), $this->svc()->view($this->ctx($r)->az(), $pid), 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/welfare/claims/' . $pid, $e);
        }
        $this->flash($r, 'success', 'welfare.flash.saved');
        return $this->redirect($r, '/welfare/claims/' . $pid);
    }

    public function removeFile(Request $r): Response
    {
        $this->guard($r);
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->removeFile($this->ctx($r)->az(), $pid, (int) $r->params['fid']);
        } catch (BusinessException $e) {
            return $this->back($r, '/welfare/claims/' . $pid, $e);
        }
        $this->flash($r, 'success', 'welfare.flash.file_removed');
        return $this->redirect($r, '/welfare/claims/' . $pid . '/edit');
    }

    public function act(Request $r): Response
    {
        $this->guard($r);
        $pid = (string) $r->params['id'];
        $action = (string) $r->params['action'];
        try {
            if ($action === 'submit') {
                $this->svc()->submit($this->ctx($r)->az(), $pid);
            } else {
                $this->svc()->cancel($this->ctx($r)->az(), $pid, $this->str($r, 'reason', 300));
            }
        } catch (BusinessException $e) {
            return $this->back($r, '/welfare/claims/' . $pid, $e);
        }
        $this->flash($r, 'success', 'welfare.flash.' . $action);
        return $this->redirect($r, '/welfare/claims/' . $pid);
    }

    /** @param array<string, string> $errors */
    public function show(Request $r, array $errors = [], int $status = 200): Response
    {
        $c = $this->svc()->view($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->page('@welfare/claim', ['title' => $this->app->translator()->get('welfare.claim_title', ['no' => (string) ($c['claim_no'] ?? $this->app->translator()->get('welfare.status.draft'))]),
            'c' => $c, 'errors' => $errors, 'oldAmount' => $this->str($r, 'amount', 20)], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function decide(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $action = $this->str($r, 'decision', 10);
        try {
            if ($action === 'approve') {
                $this->ctx($r)->requireFreshReauth();
            }
            $this->svc()->decide($this->ctx($r)->az(), $pid, $action, $this->str($r, 'amount', 20), $this->str($r, 'note', 500));
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/welfare/claims/' . $pid, $e);
        }
        $this->flash($r, 'success', 'welfare.flash.decided_' . $action);
        return $this->redirect($r, '/welfare/claims/' . $pid);
    }

    public function file(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $f = $this->svc()->file($this->ctx($r)->az(), $pid, (int) $r->params['fid']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], true)->withHeader('Cache-Control', 'no-store, max-age=0');
    }
}
