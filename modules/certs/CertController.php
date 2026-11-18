<?php

declare(strict_types=1);

namespace Blue\Modules\Certs;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** M12 screens: ask for a certificate + my requests · HR queue, types, preparation and handover. */
final class CertController extends Controller
{
    private function svc(): CertService
    {
        return CertService::of($this->app);
    }

    private function t(string $key): string
    {
        return $this->app->translator()->get($key);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function index(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        if (!$this->ctx($r)->can('certs.request')) {
            throw HttpException::forbidden();
        }
        $az = $this->ctx($r)->az();
        return $this->page('@certs/index', ['title' => $this->t('certs.title'), 'mine' => $this->svc()->mine($az), 'errors' => $errors, 'old' => $old,
            'canManage' => $this->svc()->companies($az) !== []], $status);
    }

    public function submit(Request $r): Response
    {
        $in = ['type' => $this->str($r, 'type', 26), 'language' => $this->str($r, 'language', 2), 'copies' => $this->str($r, 'copies', 2), 'purpose' => $this->str($r, 'purpose', 300)];
        try {
            $pid = $this->svc()->submit($this->ctx($r)->az(), $in);
        } catch (ValidationException $e) {
            return $this->index($r, $this->errors($e), $in, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/certs');
        }
        $this->flash($r, 'success', 'certs.flash.submitted');
        return $this->redirect($r, '/certs/r/' . $pid);
    }

    /** @param array<string, string> $errors */
    public function show(Request $r, array $errors = [], int $status = 200): Response
    {
        $req = $this->svc()->request($this->ctx($r)->az(), (string) $r->params['id']);
        if (!$req['mine']) {
            return $this->redirect($r, '/certs/admin/r/' . $req['public_id']);
        }
        return $this->page('@certs/show', ['title' => (string) $req['request_no'], 'req' => $req, 'events' => $this->svc()->events((int) $req['id']), 'errors' => $errors], $status)
            ->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function mine(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        try {
            match ($action) {
                'answer' => $this->svc()->answerInfo($az, $pid, (string) ($r->body['answer'] ?? '')),
                'cancel' => $this->svc()->cancelMine($az, $pid),
                default => $this->svc()->rate($az, $pid, (int) $r->input('rating', '0'), (string) ($r->body['comment'] ?? '')),
            };
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/certs/r/' . $pid);
        }
        $this->flash($r, 'success', 'certs.flash.' . $action);
        return $this->redirect($r, $action === 'rate' ? '/certs' : '/certs/r/' . $pid);
    }

    public function doc(Request $r): Response
    {
        $f = $this->svc()->document($this->ctx($r)->az(), (string) $r->params['id']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['name'], true);
    }

    // ---- HR -------------------------------------------------------------------------------------------------------------

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

    public function admin(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $status = in_array($r->query['status'] ?? '', ['open', 'received', 'cancelled', 'all'], true) ? (string) $r->query['status'] : 'open';
        return $this->page('@certs/admin', ['title' => $this->t('certs.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'rows' => $this->svc()->queue($le, $status),
            'status' => $status, 'ratings' => $this->svc()->ratings($le), 'hasTypes' => $this->svc()->types($le) !== []]);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function types(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $edit = null;
        if (isset($r->params['id'])) {
            $edit = $this->app->db()->one('SELECT t.*, th.public_id AS template_th, en.public_id AS template_en FROM cert_types t LEFT JOIN document_templates th ON th.id = t.template_th_id
                LEFT JOIN document_templates en ON en.id = t.template_en_id WHERE t.public_id = ?', [(string) $r->params['id']]);
            if ($edit === null || !in_array((int) $edit['legal_entity_id'], $this->svc()->companies($this->ctx($r)->az()), true)) {
                throw HttpException::notFound();
            }
            $le = (int) $edit['legal_entity_id'];
            $lePid = (string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        }
        return $this->page('@certs/types', ['title' => $this->t('certs.types_title'), 'companies' => $companies, 'lePid' => $lePid, 'rows' => $this->svc()->types($le, false),
            'templates' => $this->svc()->templateOptions($le), 'edit' => $edit, 'errors' => $errors, 'old' => $old, 'auto' => CertService::AUTO_FIELDS], $status);
    }

    public function saveType(Request $r): Response
    {
        [, , $le] = $this->company($r);
        $pid = isset($r->params['id']) ? (string) $r->params['id'] : null;
        if ($pid !== null) {
            $le = (int) $this->app->db()->scalar('SELECT legal_entity_id FROM cert_types WHERE public_id = ?', [$pid]);
        }
        $in = [];
        foreach (['code' => 30, 'name_th' => 150, 'name_en' => 150, 'description' => 500, 'template_th' => 26, 'template_en' => 26, 'max_copies' => 2, 'signer_name' => 150, 'signer_title' => 150, 'status' => 10] as $k => $max) {
            $in[$k] = $this->str($r, $k, $max);
        }
        try {
            $this->svc()->saveType($this->ctx($r)->az(), $le, $in, $pid);
        } catch (ValidationException $e) {
            return $this->types($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'certs.flash.type_saved');
        return $this->redirect($r, '/certs/admin/types?le=' . rawurlencode((string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le])));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function work(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $req = $this->svc()->request($az, (string) $r->params['id']);
        if (!$req['hr']) {
            throw HttpException::notFound();
        }
        return $this->page('@certs/work', ['title' => (string) $req['request_no'], 'req' => $req, 'pre' => $this->svc()->prefill($az, (string) $req['public_id']),
            'issues' => $this->svc()->issues((int) $req['id']), 'events' => $this->svc()->events((int) $req['id']), 'canIssue' => $az->scope('core.template.issue')->coversLegalEntity((int) $req['legal_entity_id']),
            'errors' => $errors, 'old' => $old, 'form' => $form], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function hr(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        try {
            match ($action) {
                'take' => $this->svc()->take($az, $pid),
                'info' => $this->svc()->askInfo($az, $pid, (string) ($r->body['note'] ?? '')),
                'issue' => $this->svc()->issue($az, $pid, array_map('strval', array_filter((array) ($r->body['f'] ?? []), 'is_scalar')), (string) ($r->body['void_reason'] ?? '')),
                'ready' => $this->svc()->markReady($az, $pid),
                'handover' => $this->svc()->handover($az, $pid, $this->str($r, 'code', 6), $this->str($r, 'receiver', 150), $this->str($r, 'relation', 5), (string) ($r->body['proxy_note'] ?? '')),
                'cancel' => $this->svc()->cancelByHr($az, $pid, (string) ($r->body['reason'] ?? '')),
                default => $this->svc()->waiveRating($az, $pid, (string) ($r->body['waive_reason'] ?? '')),
            };
        } catch (ValidationException $e) {
            return $this->work($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/certs/admin/r/' . $pid);
        }
        $this->flash($r, 'success', 'certs.flash.' . $action);
        return $this->redirect($r, '/certs/admin/r/' . $pid);
    }
}
