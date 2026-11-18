<?php

declare(strict_types=1);

namespace Blue\Modules\Forms;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;
use Blue\Modules\Employees\Audience;

/** M02 screens: find/preview/download forms · form managers: forms, versions (upload → approval), target group, archive. */
final class FormsController extends Controller
{
    private function svc(): FormService
    {
        return FormService::of($this->app);
    }

    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('forms.use')) {
            throw HttpException::forbidden();
        }
        $q = mb_substr(trim((string) ($r->query['q'] ?? '')), 0, 80);
        $cat = mb_substr(trim((string) ($r->query['cat'] ?? '')), 0, 60);
        $view = in_array($r->query['view'] ?? '', ['starred', 'recent'], true) ? (string) $r->query['view'] : 'all';
        return $this->page('@forms/index', [
            'title' => $this->app->translator()->get('forms.title'), 'rows' => $this->svc()->browse($az, $q, $cat, $view), 'categories' => $this->svc()->categories($az),
            'q' => $q, 'cat' => $cat, 'view' => $view, 'canManage' => $this->svc()->companies($az) !== [], 'me' => $this->svc()->emp()->forUser($az->userId),
        ]);
    }

    public function show(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $f = $this->svc()->form($az, (string) $r->params['id']);
        // 0.17.0 (DEC-81): "send to sign" opens M20 with this form's current PDF — when M20 is on and the person may send
        $sign = !$this->app->modules()->isEnabled('esign') ? 'forms.send_sign_off' : ($f['mime'] !== 'application/pdf' ? 'forms.send_sign_pdf_only' : (!$this->ctx($r)->can('esign.send') ? 'forms.send_sign_no_right' : null));
        return $this->page('@forms/show', ['title' => (string) $f['title'], 'f' => $f, 'preview' => in_array($f['mime'], FormService::PREVIEW, true), 'signOff' => $sign]);
    }

    public function file(Request $r): Response
    {
        $preview = ($r->query['preview'] ?? '') === '1';
        $file = $this->svc()->file($this->ctx($r)->az(), (string) $r->params['id'], isset($r->params['v']) ? (string) $r->params['v'] : null, $preview ? 'preview' : 'download');
        if ($file['event'] === 'preview') {
            if (str_starts_with($file['mime'], 'image/')) {
                return DocumentsController::fileResponse($file['bytes'], $file['mime'], $file['name'], true);
            }
            // PDF in the browser's own viewer: no scripts or styles of ours, never framed
            $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file['name']) ?: 'form.pdf';
            return (new Response($file['bytes'], 200, ['Content-Type' => $file['mime']]))
                ->withHeader('Content-Disposition', 'inline; filename="' . $ascii . '"')
                ->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
                ->withHeader('Cache-Control', 'private, no-store');
        }
        return DocumentsController::fileResponse($file['bytes'], $file['mime'], $file['name'], false);
    }

    public function star(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $on = $r->input('on') === '1';
        $this->svc()->star($this->ctx($r)->az(), $pid, $on);
        $this->flash($r, 'success', $on ? 'forms.flash.starred' : 'forms.flash.unstarred');
        return $this->redirect($r, '/forms/f/' . $pid);
    }

    // ---- managers -------------------------------------------------------------------------------------------------------

    /**
     * @param list<int> $ids
     * @return array<string, string>
     */
    private function companyOptions(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $l = $this->app->db()->one('SELECT public_id, code FROM legal_entities WHERE id = ?', [$id]);
            if ($l !== null) {
                $out[(string) $l['public_id']] = (string) $l['code'];
            }
        }
        return $out;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function admin(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $companies = $this->companyOptions($this->svc()->companies($az));
        if ($companies === []) {
            throw HttpException::forbidden();
        }
        $lePid = (string) ($old['legal_entity'] ?? $r->query['le'] ?? '');
        if (!isset($companies[$lePid])) {
            $lePid = (string) array_key_first($companies);
        }
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        return $this->page('@forms/admin', [
            'title' => $this->app->translator()->get('forms.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'rows' => $this->svc()->adminList($le),
            'audOptions' => Audience::options($this->app, $le), 'errors' => $errors, 'old' => $old,
        ], $status);
    }

    /** @return array<string, mixed> */
    private function input(Request $r): array
    {
        $in = [];
        foreach (['legal_entity' => 26, 'code' => 30, 'title' => 200, 'description' => 500, 'category' => 60, 'language' => 4, 'starts_on' => 10, 'ends_on' => 10, 'groups' => 300] as $k => $max) {
            $in[$k] = $this->str($r, $k, $max);
        }
        $in['sites'] = array_values(array_map('strval', (array) ($r->body['sites'] ?? [])));
        $in['departments'] = array_values(array_map('strval', (array) ($r->body['departments'] ?? [])));
        return $in;
    }

    public function create(Request $r): Response
    {
        $in = $this->input($r);
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$in['legal_entity']]);
        try {
            $pid = $this->svc()->save($this->ctx($r)->az(), $le, $in);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'forms.flash.created');
        return $this->redirect($r, '/forms/admin/f/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function manage(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $f = $this->svc()->managed($az, (string) $r->params['id']);
        $le = (int) $f['legal_entity_id'];
        $aud = Audience::decode((string) $f['audience_json']);
        return $this->page('@forms/manage', [
            'title' => (string) $f['title'], 'f' => $f, 'versions' => $this->svc()->versions((int) $f['id']), 'stats' => $this->svc()->stats((int) $f['id']),
            'audOptions' => Audience::options($this->app, $le), 'audIn' => $form === 'details' ? $old : Audience::toInput($this->app, $aud), 'audText' => Audience::describe($this->app, $aud),
            'members' => count(Audience::members($this->app, $le, $aud, $this->svc()->today())), 'errors' => $errors, 'old' => $old, 'form' => $form,
        ], $status);
    }

    public function update(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $in = $this->input($r);
        $f = $this->svc()->managed($this->ctx($r)->az(), $pid);
        try {
            $this->svc()->save($this->ctx($r)->az(), (int) $f['legal_entity_id'], $in, $pid);
        } catch (ValidationException $e) {
            return $this->manage($r, $this->errors($e), $in, 'details', 422);
        }
        $this->flash($r, 'success', 'forms.flash.saved');
        return $this->redirect($r, '/forms/admin/f/' . $pid);
    }

    public function upload(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $file = ($r->files['file'] ?? [])[0] ?? null;
        try {
            $this->svc()->upload($this->ctx($r)->az(), $pid, $file instanceof UploadedFile ? $file : null, $this->str($r, 'change_note', 500));
        } catch (ValidationException $e) {
            return $this->manage($r, $this->errors($e), ['change_note' => $this->str($r, 'change_note', 500)], 'upload', 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->manage($r, ['file' => $this->app->translator()->get($e->messageKey)], [], 'upload', 409);
        }
        $this->flash($r, 'success', 'forms.flash.uploaded');
        return $this->redirect($r, '/forms/admin/f/' . $pid);
    }

    public function versionAction(Request $r): Response
    {
        $vid = (string) $r->params['id'];
        $form = (string) $this->app->db()->scalar('SELECT f.public_id FROM frm_versions v JOIN frm_forms f ON f.id = v.form_id WHERE v.public_id = ?', [$vid]);
        if ($form === '') {
            throw HttpException::notFound();
        }
        $action = $r->input('action') === 'discard' ? 'discard' : 'submit';
        try {
            $action === 'submit' ? $this->svc()->submit($this->ctx($r)->az(), $vid) : $this->svc()->discard($this->ctx($r)->az(), $vid);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/forms/admin/f/' . $form);
        }
        $this->flash($r, 'success', 'forms.flash.' . $action);
        return $this->redirect($r, '/forms/admin/f/' . $form);
    }

    public function archive(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->archive($this->ctx($r)->az(), $pid, $this->str($r, 'reason', 255));
        } catch (ValidationException $e) {
            return $this->manage($r, $this->errors($e), [], 'archive', 422);
        }
        $this->flash($r, 'success', 'forms.flash.archived');
        return $this->redirect($r, '/forms/admin/f/' . $pid);
    }
}
