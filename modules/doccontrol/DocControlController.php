<?php

declare(strict_types=1);

namespace Blue\Modules\Doccontrol;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Modules\Employees\Audience;

/** M40 screens: what I must acknowledge + company documents · read current version + acknowledge · managers: documents, versions, acknowledgement report. */
final class DocControlController extends Controller
{
    private function svc(): DocControlService
    {
        return DocControlService::of($this->app);
    }

    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('doccontrol.read')) {
            throw HttpException::forbidden();
        }
        $q = mb_substr(trim((string) ($r->query['q'] ?? '')), 0, 80);
        return $this->page('@doccontrol/index', [
            'title' => $this->app->translator()->get('doccontrol.title'), 'mine' => $this->svc()->mine($az, $q), 'q' => $q, 'canManage' => $this->svc()->companies($az) !== [],
            'me' => $this->svc()->emp()->forUser($az->userId),
        ]);
    }

    /** @param array<string, string> $errors */
    public function show(Request $r, array $errors = [], int $status = 200): Response
    {
        $d = $this->svc()->view($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->page('@doccontrol/show', ['title' => (string) $d['title'], 'doc' => $d, 'errors' => $errors], $status);
    }

    public function file(Request $r): Response
    {
        $f = $this->svc()->file($this->ctx($r)->az(), (string) $r->params['id'], isset($r->params['v']) ? (string) $r->params['v'] : null);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $f['name']) ?: 'document.pdf';
        return (new Response($f['bytes'], 200, ['Content-Type' => $f['mime']]))
            ->withHeader('Content-Disposition', 'inline; filename="' . $ascii . '"')
            ->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
            ->withHeader('Cache-Control', 'private, no-store');
    }

    public function acknowledge(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->acknowledge($this->ctx($r)->az(), $pid, $r->input('confirm') === '1', $r->ip, (string) $r->header('user-agent'));
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->show($r, ['confirm' => $this->app->translator()->get($e->messageKey)], 422);
        }
        $this->flash($r, 'success', 'doccontrol.flash.acknowledged');
        return $this->redirect($r, '/policies/d/' . $pid);
    }

    // ---- managers -------------------------------------------------------------------------------------------------------

    /**
     * @param list<int> $ids
     * @return array<string, string>
     */
    private function companies(array $ids): array
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
        $companies = $this->companies($this->svc()->companies($az));
        if ($companies === []) {
            throw HttpException::forbidden();
        }
        $lePid = (string) ($old['legal_entity'] ?? $r->query['le'] ?? '');
        if (!isset($companies[$lePid])) {
            $lePid = (string) array_key_first($companies);
        }
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        return $this->page('@doccontrol/admin', [
            'title' => $this->app->translator()->get('doccontrol.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'rows' => $this->svc()->adminList($le),
            'audOptions' => Audience::options($this->app, $le), 'errors' => $errors, 'old' => $old,
        ], $status);
    }

    /** @return array<string, mixed> */
    private function input(Request $r): array
    {
        $in = [];
        foreach (['legal_entity' => 26, 'code' => 30, 'title' => 200, 'doc_type' => 20, 'review_months' => 3, 'groups' => 300] as $k => $max) {
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
        $this->flash($r, 'success', 'doccontrol.flash.created');
        return $this->redirect($r, '/policies/admin/d/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function manage(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $d = $this->svc()->managed($az, (string) $r->params['id']);
        $aud = Audience::decode((string) $d['audience_json']);
        return $this->page('@doccontrol/manage', [
            'title' => (string) $d['title'], 'doc' => $d, 'versions' => $this->svc()->versions((int) $d['id']), 'report' => $d['current_version_id'] !== null ? $this->svc()->report((int) $d['current_version_id']) : null,
            'audOptions' => Audience::options($this->app, (int) $d['legal_entity_id']), 'audIn' => $form === 'details' ? $old : Audience::toInput($this->app, $aud), 'audText' => Audience::describe($this->app, $aud),
            'errors' => $errors, 'old' => $old, 'form' => $form, 'today' => $this->svc()->today(),
        ], $status);
    }

    public function update(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $in = $this->input($r);
        $d = $this->svc()->managed($this->ctx($r)->az(), $pid);
        try {
            $this->svc()->save($this->ctx($r)->az(), (int) $d['legal_entity_id'], $in, $pid);
        } catch (ValidationException $e) {
            return $this->manage($r, $this->errors($e), $in, 'details', 422);
        }
        $this->flash($r, 'success', 'doccontrol.flash.saved');
        return $this->redirect($r, '/policies/admin/d/' . $pid);
    }

    public function upload(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $in = ['change_summary' => $this->str($r, 'change_summary', 1000), 'effective_from' => $this->str($r, 'effective_from', 10), 'requires_ack' => $this->str($r, 'requires_ack', 1)];
        $file = ($r->files['file'] ?? [])[0] ?? null;
        try {
            $this->svc()->upload($this->ctx($r)->az(), $pid, $file instanceof UploadedFile ? $file : null, $in);
        } catch (ValidationException $e) {
            return $this->manage($r, $this->errors($e), $in, 'upload', 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->manage($r, ['file' => $this->app->translator()->get($e->messageKey)], $in, 'upload', 409);
        }
        $this->flash($r, 'success', 'doccontrol.flash.uploaded');
        return $this->redirect($r, '/policies/admin/d/' . $pid);
    }

    public function versionAction(Request $r): Response
    {
        $vid = (string) $r->params['id'];
        $doc = (string) $this->app->db()->scalar('SELECT d.public_id FROM dc_versions v JOIN dc_documents d ON d.id = v.document_id WHERE v.public_id = ?', [$vid]);
        if ($doc === '') {
            throw HttpException::notFound();
        }
        $action = $r->input('action') === 'withdraw' ? 'withdraw' : 'submit';
        try {
            $action === 'submit' ? $this->svc()->submit($this->ctx($r)->az(), $vid) : $this->svc()->withdraw($this->ctx($r)->az(), $vid);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/policies/admin/d/' . $doc);
        }
        $this->flash($r, 'success', 'doccontrol.flash.' . $action);
        return $this->redirect($r, '/policies/admin/d/' . $doc);
    }

    public function action(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->input('action', '');
        try {
            if ($action === 'remind') {
                $this->flash($r, 'success', 'doccontrol.flash.reminded', ['n' => (string) $this->svc()->remind($az, $pid)]);
            } elseif ($action === 'exception') {
                $this->svc()->exception($az, $pid, (int) $r->input('ack', '0'), $this->str($r, 'exception_reason', 300));
                $this->flash($r, 'success', 'doccontrol.flash.exception');
            } elseif ($action === 'archive') {
                $this->svc()->archive($az, $pid, $this->str($r, 'reason', 255));
                $this->flash($r, 'success', 'doccontrol.flash.archived');
            } else {
                throw HttpException::notFound();
            }
        } catch (ValidationException $e) {
            return $this->manage($r, $this->errors($e), [], $action, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/policies/admin/d/' . $pid);
    }

    public function export(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $d = $this->svc()->managed($az, (string) $r->params['id']);
        if ($d['current_version_id'] === null) {
            throw HttpException::notFound();
        }
        $rep = $this->svc()->report((int) $d['current_version_id']);
        $this->app->audit()->record('doccontrol.report_exported', 'success', 'dc_document', (string) $d['public_id'], (int) $d['legal_entity_id'], null, ['rows' => count($rep['rows'])]);
        $headers = ['employee', 'status', 'acknowledged_at', 'file_sha256', 'exception_reason'];
        $rows = array_map(static fn (array $x): array => [$x['display'], $x['status'], (string) ($x['acknowledged_at'] ?? ''), (string) ($x['file_sha256'] ?? ''), (string) ($x['exception_reason'] ?? '')], $rep['rows']);
        return \Blue\Core\Files\TableExport::response(\Blue\Core\Files\TableExport::format($r->query['format'] ?? 'xlsx'), 'acknowledgements-' . $d['code'], $headers, $rows, []);
    }
}
