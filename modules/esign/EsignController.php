<?php

declare(strict_types=1);

namespace Blue\Modules\Esign;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** M20 screens: inbox (to sign, signed, sent) · new / edit draft · a request · company list · verify a file. */
final class EsignController extends Controller
{
    private function svc(): EsignService
    {
        return EsignService::of($this->app);
    }

    private function t(string $key): string
    {
        return $this->app->translator()->get($key);
    }

    public function index(Request $r): Response
    {
        if (!$this->ctx($r)->can('esign.use')) {
            throw HttpException::forbidden();
        }
        $az = $this->ctx($r)->az();
        return $this->page('@esign/index', ['title' => $this->t('esign.title'), 'box' => $this->svc()->inbox($az), 'canSend' => $this->svc()->companies($az) !== [],
            'canManage' => $this->ctx($r)->can('esign.manage'), 'today' => $this->svc()->today()]);
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} */
    private function company(Request $r): array
    {
        $les = $this->svc()->companies($this->ctx($r)->az());
        if ($les === []) {
            throw HttpException::forbidden();
        }
        $companies = [];
        foreach ($les as $id) {
            $row = $this->app->db()->one('SELECT public_id, code FROM legal_entities WHERE id = ?', [$id]);
            $companies[(string) $row['public_id']] = (string) $row['code'];
        }
        $lePid = (string) ($r->query['le'] ?? $r->body['le'] ?? '');
        if (!isset($companies[$lePid])) {
            $lePid = (string) array_key_first($companies);
        }
        return [$companies, $lePid, (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid])];
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function form(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid] = $this->company($r);
        $edit = null;
        if (isset($r->params['id'])) {
            $edit = $this->svc()->request($this->ctx($r)->az(), (string) $r->params['id']);
            if (!$edit['is_sender'] || $edit['status'] !== 'draft') {
                throw HttpException::notFound();
            }
            $lePid = (string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$edit['legal_entity_id']]);
        }
        $fromForm = $edit === null ? $this->fromForm($r) : null;
        return $this->page('@esign/form', ['title' => $this->t($edit !== null ? 'esign.edit_title' : 'esign.new_title'), 'companies' => $companies, 'lePid' => $lePid, 'edit' => $edit, 'fromForm' => $fromForm,
            'errors' => $errors, 'old' => $old, 'today' => $this->svc()->today()], $status);
    }

    /**
     * M02 (when on): "send to sign" from a central form — its current PDF is used as the document.
     *
     * @return array{pid: string, label: string, mime: string}|null
     */
    private function fromForm(Request $r): ?array
    {
        $pid = (string) ($r->query['form'] ?? $r->body['form'] ?? '');
        if ($pid === '' || !$this->app->modules()->isEnabled('forms') || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $pid) !== 1) {
            return null;
        }
        $f = \Blue\Modules\Forms\FormService::of($this->app)->form($this->ctx($r)->az(), $pid);
        return ['pid' => $pid, 'label' => $f['code'] . ' · ' . $f['title'], 'mime' => (string) ($f['mime'] ?? '')];
    }

    public function save(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        [, , $le] = $this->company($r);
        $pid = isset($r->params['id']) ? (string) $r->params['id'] : null;
        if ($pid !== null) {
            $le = (int) $this->svc()->request($az, $pid)['legal_entity_id'];
        }
        $in = ['title' => $this->str($r, 'title', 200), 'category' => $this->str($r, 'category', 60), 'message' => mb_substr(is_scalar($r->body['message'] ?? null) ? (string) $r->body['message'] : '', 0, 1000),
            'sequential' => $this->str($r, 'sequential', 1), 'expires_on' => $this->str($r, 'expires_on', 10), 'signers' => []];
        foreach ((array) ($r->body['signers'] ?? []) as $i => $s) {
            if (is_array($s) && (int) $i < 20) {
                $in['signers'][(int) $i] = array_map(static fn (mixed $v): string => is_scalar($v) ? mb_substr((string) $v, 0, 100) : '', array_intersect_key($s, array_flip(['username', 'role', 'page', 'anchor'])));
            }
        }
        $f = ($r->files['file'] ?? [])[0] ?? null;
        $bytes = null;
        $name = 'document.pdf';
        $errors = [];
        if ($f instanceof UploadedFile && ($f->ok() || $f->errorKey() !== 'file.error.missing')) {
            if ($f->ok()) {
                $bytes = $f->bytes();
                $name = $f->safeName();
            } else {
                $errors['file'] = $f->errorKey();
            }
        }
        $form = $pid === null && $bytes === null && !isset($errors['file']) ? $this->fromForm($r) : null;
        if ($form !== null) {
            if ($form['mime'] !== 'application/pdf') {
                $errors['file'] = 'esign.error.form_pdf';
            } else {
                $ff = \Blue\Modules\Forms\FormService::of($this->app)->file($az, $form['pid'], null, 'download');
                $bytes = $ff['bytes'];
                $name = $ff['name'];
            }
        }
        try {
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            $pid = $this->svc()->fromBytes($az, $le, $in, $bytes, $name, $pid);
        } catch (ValidationException $e) {
            return $this->form($r, $this->errors($e), $in, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/esign');
        }
        $this->flash($r, 'success', 'esign.flash.draft');
        return $this->redirect($r, '/esign/r/' . $pid);
    }

    /** @param array<string, string> $errors */
    public function show(Request $r, array $errors = [], string $form = '', int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $req = $this->svc()->request($az, (string) $r->params['id']);
        return $this->page('@esign/show', ['title' => (string) $req['title'], 'req' => $req, 'myName' => (string) $this->app->db()->scalar('SELECT display_name FROM users WHERE id = ?', [$az->userId]), 'events' => $req['is_sender'] || $req['is_manager'] || $req['me'] !== null ? $this->svc()->events((int) $req['id']) : [],
            'errors' => $errors, 'form' => $form, 'today' => $this->svc()->today(), 'old' => $r->method === 'POST' ? $r->body : []], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function act(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        $text = static fn (string $k): string => is_scalar($r->body[$k] ?? null) ? (string) $r->body[$k] : '';
        try {
            match ($action) {
                'send' => $this->svc()->send($az, $pid),
                'sign' => $this->svc()->sign($az, $pid, mb_substr($text('typed_name'), 0, 150), mb_substr($text('password'), 0, 200), mb_substr($text('sha256'), 0, 64), $text('read') === '1', $r->ip),
                'decline' => $this->svc()->decline($az, $pid, mb_substr($text('reason'), 0, 500)),
                'cancel' => $this->svc()->cancel($az, $pid, mb_substr($text('reason'), 0, 500)),
                default => $this->svc()->void($az, $pid, mb_substr($text('reason'), 0, 500)),
            };
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $action, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/esign/r/' . $pid);
        }
        $this->flash($r, 'success', 'esign.flash.' . $action);
        return $this->redirect($r, '/esign/r/' . $pid);
    }

    public function file(Request $r): Response
    {
        $f = $this->svc()->file($this->ctx($r)->az(), (string) $r->params['id'], (string) $r->params['which']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], true);
    }

    public function admin(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $les = array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $az->scope('esign.manage')->coversLegalEntity($id)));
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
        $status = (string) ($r->query['status'] ?? '');
        $status = in_array($status, ['sent', 'partial', 'completed', 'declined', 'expired', 'cancelled', 'voided'], true) ? $status : '';
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        return $this->page('@esign/admin', ['title' => $this->t('esign.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'status' => $status, 'rows' => $this->svc()->company($le, $status)]);
    }

    /** @param array<string, mixed>|null $result */
    public function verifyForm(Request $r, ?array $result = null, bool $checked = false, ?string $error = null, int $status = 200): Response
    {
        if (!$this->ctx($r)->can('esign.use')) {
            throw HttpException::forbidden();
        }
        return $this->page('@esign/verify', ['title' => $this->t('esign.verify_title'), 'result' => $result, 'checked' => $checked, 'error' => $error], $status);
    }

    public function verify(Request $r): Response
    {
        if (!$this->ctx($r)->can('esign.use')) {
            throw HttpException::forbidden();
        }
        $f = ($r->files['file'] ?? [])[0] ?? null;
        if (!$f instanceof UploadedFile || !$f->ok()) {
            return $this->verifyForm($r, null, false, $this->t($f instanceof UploadedFile ? $f->errorKey() : 'file.error.missing'), 422);
        }
        return $this->verifyForm($r, $this->svc()->verify($this->ctx($r)->az(), $f->bytes()), true);
    }
}
