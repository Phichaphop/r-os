<?php

declare(strict_types=1);

namespace Blue\Core\Payments;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** Bank file formats of the companies a person may see (0.48.0 · R8-3 · DEC-117). */
final class BankFormatsController extends Controller
{
    private function svc(): BankFormats
    {
        return $this->app->bankFormats();
    }

    /** @return array<int, string> companies (id → code) the person may see payments of */
    private function companies(Request $r, string $perm = 'core.payment.view'): array
    {
        $az = $this->ctx($r)->az();
        if (!$az->can($perm)) {
            throw HttpException::forbidden();
        }
        $out = [];
        foreach ($this->app->db()->all('SELECT id, code FROM legal_entities ORDER BY code') as $l) {
            if ($az->scope($perm)->coversLegalEntity((int) $l['id'])) {
                $out[(int) $l['id']] = (string) $l['code'];
            }
        }
        return $out;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function index(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $view = $this->companies($r);
        $prep = array_filter($view, fn (int $id): bool => $this->ctx($r)->az()->scope('core.payment.prepare')->coversLegalEntity($id), ARRAY_FILTER_USE_KEY);
        return $this->page('payments/formats', ['title' => $this->app->translator()->get('payment.format.title'), 'formats' => $this->svc()->list(array_keys($view)),
            'companies' => $prep, 'samples' => BankTemplate::samples(), 'errors' => $errors, 'old' => $old], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function create(Request $r): Response
    {
        $companies = $this->companies($r, 'core.payment.prepare');
        $le = (int) $this->str($r, 'legal_entity', 20);
        if (!isset($companies[$le])) {
            throw HttpException::forbidden();
        }
        try {
            $pid = $this->svc()->create($this->ctx($r)->az(), $le, $this->str($r, 'from', 20), $this->str($r, 'code', 30));
        } catch (ValidationException $e) {
            return $this->index($r, $this->errors($e), $r->body, 422);
        }
        $this->flash($r, 'success', 'payment.format.flash_created');
        return $this->redirect($r, '/payments/formats/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function show(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $f = $this->svc()->get($this->ctx($r)->az(), (string) $r->params['id']);
        $today = $this->app->clock->now()->format('Y-m-d');
        $tokens = [];
        if ($old !== []) { // a refused save: name every token that is wrong
            $params = [];
            foreach (preg_split('/\r\n|\n|\r/', (string) ($old['params'] ?? '')) ?: [] as $line) {
                $k = trim((string) explode('=', $line, 2)[0]);
                if ($k !== '') {
                    $params[] = $k;
                }
            }
            foreach (['header' => false, 'detail' => true, 'footer' => false] as $part => $detail) {
                foreach (BankTemplate::check((string) ($old[$part] ?? ''), $detail, $params) as $p) {
                    $tokens[] = ['part' => $part] + $p;
                }
            }
        }
        $versions = $this->app->db()->all('SELECT public_id, version, status, activated_at FROM pmt_bank_formats WHERE legal_entity_id = ? AND code = ? ORDER BY version DESC', [$f['legal_entity_id'], $f['code']]);
        return $this->page('payments/format', ['title' => $this->app->translator()->get('payment.format.one', ['code' => (string) $f['code'], 'v' => (string) $f['version']]), 'f' => $f,
            'preview' => BankFormats::preview($f, $today), 'versions' => $versions, 'tokens' => $tokens, 'errors' => $errors, 'old' => $old], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    /** @return array<string, mixed> */
    private function input(Request $r): array
    {
        return ['name' => $this->str($r, 'name', 120), 'bank_code' => $this->str($r, 'bank_code', 10), 'extension' => $this->str($r, 'extension', 5), 'encoding' => $this->str($r, 'encoding', 10),
            'line_end' => $this->str($r, 'line_end', 5), 'final_newline' => $this->str($r, 'final_newline', 1), 'header' => mb_substr((string) ($r->body['header'] ?? ''), 0, 4001),
            'detail' => mb_substr((string) ($r->body['detail'] ?? ''), 0, 4001), 'footer' => mb_substr((string) ($r->body['footer'] ?? ''), 0, 4001), 'params' => mb_substr((string) ($r->body['params'] ?? ''), 0, 3000)];
    }

    public function save(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $in = $this->input($r);
        try {
            $this->svc()->save($this->ctx($r)->az(), $pid, $in);
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $in, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, $e->params);
            return $this->redirect($r, '/payments/formats/' . $pid);
        }
        $this->flash($r, 'success', 'payment.format.flash_saved');
        return $this->redirect($r, '/payments/formats/' . $pid . '#preview');
    }

    public function activate(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->activate($this->ctx($r)->az(), $pid, ($r->body['checked'] ?? '') === '1', $this->str($r, 'reference', 200));
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), ['reference' => $this->str($r, 'reference', 200)], 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, $e->params);
            return $this->redirect($r, '/payments/formats/' . $pid);
        }
        $this->flash($r, 'success', 'payment.format.flash_activated');
        return $this->redirect($r, '/payments/formats/' . $pid);
    }

    public function copy(Request $r): Response
    {
        $f = $this->svc()->get($this->ctx($r)->az(), (string) $r->params['id']);
        try {
            $pid = $this->svc()->create($this->ctx($r)->az(), (int) $f['legal_entity_id'], 'copy:' . $f['public_id'], '');
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, $e->params);
            return $this->redirect($r, '/payments/formats/' . $f['public_id']);
        }
        $this->flash($r, 'success', 'payment.format.flash_created');
        return $this->redirect($r, '/payments/formats/' . $pid);
    }

    public function discard(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->discard($this->ctx($r)->az(), $pid);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, $e->params);
            return $this->redirect($r, '/payments/formats/' . $pid);
        }
        $this->flash($r, 'success', 'payment.format.flash_discarded');
        return $this->redirect($r, '/payments/formats');
    }

    public function retire(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->retire($this->ctx($r)->az(), $pid, $this->str($r, 'reason', 200));
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), ['reason' => $this->str($r, 'reason', 200)], 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, $e->params);
            return $this->redirect($r, '/payments/formats/' . $pid);
        }
        $this->flash($r, 'success', 'payment.format.flash_retired');
        return $this->redirect($r, '/payments/formats/' . $pid);
    }
}
