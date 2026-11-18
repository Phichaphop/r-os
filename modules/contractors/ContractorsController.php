<?php

declare(strict_types=1);

namespace Blue\Modules\Contractors;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** Contractor screens (M44 · 9C-3). */
final class ContractorsController extends Controller
{
    private function svc(): ContractorService
    {
        return ContractorService::of($this->app);
    }

    private function t(string $k): string
    {
        return $this->app->translator()->get($k);
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} */
    private function legalEntity(Request $r, string $perm = 'contractors.view', ?string $want = null): array
    {
        $ids = $this->svc()->companies($this->ctx($r)->az(), $perm);
        if ($ids === []) {
            throw HttpException::forbidden();
        }
        $companies = [];
        foreach ($this->app->db()->all('SELECT public_id, code FROM legal_entities WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY code') as $row) {
            $companies[(string) $row['public_id']] = (string) $row['code'];
        }
        $pid = $want ?? (string) ($r->query['le'] ?? $r->body['le'] ?? '');
        if (!isset($companies[$pid])) {
            $pid = (string) array_key_first($companies);
        }
        return [$companies, $pid, (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$pid])];
    }

    /** @return array<string, bool> */
    private function roles(Request $r): array
    {
        $az = $this->ctx($r)->az();
        return ['canView' => $this->svc()->companies($az, 'contractors.view') !== [], 'canManage' => $this->svc()->companies($az, 'contractors.manage') !== [],
            'canGate' => $this->svc()->companies($az, 'contractors.gate') !== []];
    }

    private function upload(Request $r, string $name): ?UploadedFile
    {
        $f = ($r->files[$name] ?? [])[0] ?? null;
        return $f instanceof UploadedFile && $f->error !== UPLOAD_ERR_NO_FILE ? $f : null;
    }

    private function fresh(Response $res): Response
    {
        return $res->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    private function fail(Request $r, string $to, BusinessException $e): Response
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
        $this->flash($r, 'danger', $e->messageKey, $e->params);
        return $this->redirect($r, $to);
    }

    private function lePid(int $le): string
    {
        return (string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
    }

    // ---- companies ---------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function index(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r);
        return $this->fresh($this->page('@contractors/index', ['title' => $this->t('contractors.title'), 'companies' => $companies, 'lePid' => $lePid,
            'rows' => $this->svc()->companyList($this->ctx($r)->az(), $le), 'types' => $this->svc()->docTypes($le, null, true), 'errors' => $errors, 'old' => $old, 'here' => '/contractors'] + $this->roles($r), $status));
    }

    public function createCompany(Request $r): Response
    {
        [, , $le] = $this->legalEntity($r, 'contractors.manage');
        try {
            $pid = $this->svc()->saveCompany($this->ctx($r)->az(), $le, $r->body);
        } catch (ValidationException $e) {
            return $this->index($r, $this->errors($e), $r->body, 422);
        }
        $this->flash($r, 'success', 'contractors.flash.company_saved');
        return $this->redirect($r, '/contractors/c/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function company(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $c = $this->svc()->company($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->fresh($this->page('@contractors/company', ['title' => (string) $c['name'], 'co' => $c, 'types' => $this->svc()->docTypes((int) $c['legal_entity_id'], null, true),
            'errors' => $errors, 'old' => $old, 'form' => $form, 'here' => '/contractors'] + $this->roles($r), $status));
    }

    public function companyPost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        $s = $this->svc();
        try {
            if ($action === 'edit') {
                $le = (int) ($this->app->db()->scalar('SELECT legal_entity_id FROM ct_companies WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound());
                $s->saveCompany($az, $le, $r->body, $pid);
            } elseif ($action === 'worker') {
                $s->saveWorker($az, $pid, $r->body);
            } elseif ($action === 'document') {
                $s->addDocument($az, $pid, $r->body, $this->upload($r, 'file'));
            } else {
                $s->suspend($az, $pid, $action === 'suspend', $this->str($r, 'suspend_reason', 500));
            }
        } catch (ValidationException $e) {
            return $this->company($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/contractors/c/' . $pid, $e);
        }
        $this->flash($r, 'success', 'contractors.flash.' . $action);
        return $this->redirect($r, '/contractors/c/' . $pid);
    }

    public function workerPost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->saveWorker($this->ctx($r)->az(), $pid, $r->body, (string) $r->params['wid']);
        } catch (ValidationException $e) {
            return $this->company($r, $this->errors($e), $r->body + ['wid' => (string) $r->params['wid']], 'worker-edit', 422);
        }
        $this->flash($r, 'success', 'contractors.flash.worker');
        return $this->redirect($r, '/contractors/c/' . $pid . '#workers');
    }

    public function file(Request $r): Response
    {
        $f = $this->svc()->file($this->ctx($r)->az(), (string) $r->params['id'], (int) $r->params['fid']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], true)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    // ---- permits -------------------------------------------------------------------------------------------------------------

    public function permits(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r);
        $status = (string) ($r->query['status'] ?? '');
        $status = in_array($status, ['draft', 'review', 'approved', 'on_site', 'completed', 'rejected', 'cancelled', 'expired', 'suspended'], true) ? $status : '';
        return $this->fresh($this->page('@contractors/permits', ['title' => $this->t('contractors.permits_title'), 'companies' => $companies, 'lePid' => $lePid, 'status' => $status,
            'ov' => $this->svc()->overview($this->ctx($r)->az(), $le, ['status' => $status]), 'here' => '/contractors/permits'] + $this->roles($r)));
    }

    public function permitEdit(Request $r): Response
    {
        $p = $this->svc()->permit($this->ctx($r)->az(), (string) $r->params['id']);
        if ($p['status'] !== 'draft' || !$p['can_manage']) {
            throw HttpException::notFound();
        }
        return $this->permitForm($r, [], [], 200, $p);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     * @param ?array<string, mixed> $permit
     */
    public function permitForm(Request $r, array $errors = [], array $old = [], int $status = 200, ?array $permit = null): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r, 'contractors.manage', $permit !== null ? $this->lePid((int) $permit['legal_entity_id']) : null);
        $db = $this->app->db();
        $list = $db->all('SELECT id, public_id, name, suspended FROM ct_companies WHERE legal_entity_id = ? ORDER BY name', [$le]);
        $chosen = (string) ($old['company'] ?? $r->query['company'] ?? ($permit['company_pid'] ?? ''));
        $workers = [];
        foreach ($list as $co) {
            if ($co['public_id'] === $chosen) {
                $workers = $db->all('SELECT public_id, name, position FROM ct_workers WHERE company_id = ? AND active = 1 ORDER BY name', [$co['id']]);
            }
        }
        return $this->fresh($this->page('@contractors/permit_form', ['title' => $permit !== null ? $this->t('contractors.edit_permit') : $this->t('contractors.new_permit'), 'companies' => $companies, 'lePid' => $lePid,
            'list' => $list, 'chosen' => $chosen, 'workers' => $workers, 'sites' => $db->all("SELECT public_id, code FROM sites WHERE legal_entity_id = ? AND status = 'active' ORDER BY code", [$le]),
            'permit' => $permit, 'errors' => $errors, 'old' => $old, 'here' => '/contractors/permits'] + $this->roles($r), $status));
    }

    public function permitCreate(Request $r): Response
    {
        [, , $le] = $this->legalEntity($r, 'contractors.manage');
        if (!empty($r->body['pick_company'])) { // the form reloads to list the workers of the chosen company
            return $this->permitForm($r, [], $r->body);
        }
        try {
            $pid = $this->svc()->savePermit($this->ctx($r)->az(), $le, $r->body, null, !empty($r->body['send']));
        } catch (ValidationException $e) {
            return $this->permitForm($r, $this->errors($e), $r->body, 422);
        } catch (BusinessException $e) {
            $this->flash($r, 'danger', $e->messageKey, $e->params);
            $pid = (string) $this->app->db()->scalar('SELECT public_id FROM ct_permits WHERE requester_id = ? ORDER BY id DESC LIMIT 1', [$this->ctx($r)->az()->userId]);
            return $this->redirect($r, $pid !== '' ? '/contractors/p/' . $pid : '/contractors/permits');
        }
        $this->flash($r, 'success', !empty($r->body['send']) ? 'contractors.flash.submit' : 'contractors.flash.permit_saved');
        return $this->redirect($r, '/contractors/p/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function permit(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $p = $this->svc()->permit($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->fresh($this->page('@contractors/permit', ['title' => (string) ($p['ref'] ?? $this->t('contractors.draft')), 'pm' => $p, 'errors' => $errors, 'old' => $old, 'form' => $form,
            'here' => '/contractors/permits'] + $this->roles($r), $status));
    }

    public function permitPost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        $s = $this->svc();
        $back = $action === 'gate' && ($r->body['back'] ?? '') === 'gate' ? '/contractors/gate' : '/contractors/p/' . $pid;
        try {
            if ($action === 'edit') {
                $p = $s->permit($az, $pid);
                if (!empty($r->body['pick_company'])) {
                    return $this->permitForm($r, [], $r->body, 200, $p);
                }
                $s->savePermit($az, (int) $p['legal_entity_id'], $r->body, $pid, !empty($r->body['send']));
            } elseif ($action === 'submit') {
                $s->submit($az, $pid);
            } elseif ($action === 'cancel') {
                $s->cancel($az, $pid, $this->str($r, 'cancel_reason', 500));
            } elseif ($action === 'complete') {
                $s->complete($az, $pid, $r->body);
            } else {
                $s->gate($az, $pid, $this->str($r, 'worker', 26), ($r->body['dir'] ?? '') !== 'out');
            }
        } catch (ValidationException $e) {
            if ($action === 'edit') {
                return $this->permitForm($r, $this->errors($e), $r->body, 422, $s->permit($az, $pid));
            }
            return $this->permit($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, $back, $e);
        }
        $this->flash($r, 'success', 'contractors.flash.' . ($action === 'gate' ? (($r->body['dir'] ?? '') === 'out' ? 'checked_out' : 'checked_in') : $action));
        return $this->redirect($r, $back);
    }

    public function gate(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if ($this->svc()->companies($az, 'contractors.gate') === []) {
            throw HttpException::forbidden();
        }
        $rows = $this->svc()->atGate($az);
        $permits = [];
        foreach ($rows as $row) {
            $permits[] = $this->svc()->permit($az, (string) $row['public_id']);
        }
        return $this->fresh($this->page('@contractors/gate', ['title' => $this->t('contractors.gate_title'), 'permits' => $permits, 'now' => $this->svc()->nowLocal()->format('Y-m-d H:i:s'),
            'here' => '/contractors/gate'] + $this->roles($r)));
    }

    // ---- settings ----------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function settings(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200, ?string $want = null): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r, 'contractors.manage', $want);
        return $this->page('@contractors/settings', ['title' => $this->t('contractors.settings_title'), 'companies' => $companies, 'lePid' => $lePid, 'types' => $this->svc()->docTypes($le),
            'settings' => $this->svc()->settings($le), 'errors' => $errors, 'old' => $old, 'form' => $form, 'here' => '/contractors/settings'] + $this->roles($r), $status);
    }

    public function settingsPost(Request $r): Response
    {
        [, $lePid, $le] = $this->legalEntity($r, 'contractors.manage');
        $what = (string) $r->params['what'];
        try {
            if ($what === 'type') {
                $this->svc()->saveDocType($this->ctx($r)->az(), $le, $r->body);
            } else {
                $this->svc()->saveSettings($this->ctx($r)->az(), $le, $this->str($r, 'alert_days', 4));
            }
        } catch (ValidationException $e) {
            return $this->settings($r, $this->errors($e), $r->body, $what, 422);
        }
        $this->flash($r, 'success', 'contractors.flash.settings');
        return $this->redirect($r, '/contractors/settings?le=' . rawurlencode($lePid));
    }

    public function typePost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $le = (int) ($this->app->db()->scalar('SELECT legal_entity_id FROM ct_doc_types WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound());
        try {
            $this->svc()->saveDocType($this->ctx($r)->az(), $le, $r->body, $pid);
        } catch (ValidationException $e) {
            return $this->settings($r, $this->errors($e), $r->body, $pid, 422, $this->lePid($le));
        }
        $this->flash($r, 'success', 'contractors.flash.settings');
        return $this->redirect($r, '/contractors/settings?le=' . rawurlencode($this->lePid($le)));
    }
}
