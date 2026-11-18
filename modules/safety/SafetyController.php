<?php

declare(strict_types=1);

namespace Blue\Modules\Safety;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;
use Blue\Modules\Employees\Audience;

/** Safety incident screens (M39 · 9E-1). */
final class SafetyController extends Controller
{
    private function svc(): SafetyService
    {
        return SafetyService::of($this->app);
    }

    private function t(string $k): string
    {
        return $this->app->translator()->get($k);
    }

    private function fresh(Response $res): Response
    {
        return $res->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    private function canManage(Request $r): bool
    {
        $az = $this->ctx($r)->az();
        return $this->svc()->companies($az, 'safety.manage') !== [] || $this->svc()->companies($az, 'safety.view') !== [];
    }

    private function fail(Request $r, string $to, BusinessException $e): Response
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
        $this->flash($r, 'danger', $e->messageKey, $e->params);
        return $this->redirect($r, $to);
    }

    /** @return list<UploadedFile> */
    private function files(Request $r, string $field): array
    {
        return array_values(array_filter((array) ($r->files[$field] ?? []), static fn ($f): bool => $f instanceof UploadedFile && $f->error !== UPLOAD_ERR_NO_FILE));
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} */
    private function legalEntity(Request $r, bool $manageOnly = false): array
    {
        $az = $this->ctx($r)->az();
        $ids = $this->svc()->companies($az, 'safety.manage');
        if (!$manageOnly) {
            $ids = array_values(array_unique(array_merge($ids, $this->svc()->companies($az, 'safety.view'))));
        }
        if ($ids === []) {
            throw HttpException::forbidden();
        }
        $companies = [];
        foreach ($this->app->db()->all('SELECT public_id, code FROM legal_entities WHERE id IN (' . implode(',', $ids) . ') ORDER BY code') as $row) {
            $companies[(string) $row['public_id']] = (string) $row['code'];
        }
        $pid = (string) ($r->query['le'] ?? $r->body['le'] ?? '');
        if (!isset($companies[$pid])) {
            $pid = (string) array_key_first($companies);
        }
        return [$companies, $pid, (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$pid])];
    }

    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $le = $this->svc()->myCompany($az);
        return $this->fresh($this->page('@safety/index', ['title' => $this->t('safety.title'), 'le' => $le, 'board' => $le === null ? null : $this->svc()->board($le),
            'mine' => $this->svc()->mine($az), 'canManage' => $this->canManage($r), 'here' => '/safety']));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function reportForm(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $le = $this->svc()->myCompany($this->ctx($r)->az());
        if ($le === null) {
            throw HttpException::forbidden();
        }
        $set = $this->svc()->settings($le);
        return $this->fresh($this->page('@safety/report', ['title' => $this->t('safety.report_title'), 'sites' => Audience::options($this->app, $le)['sites'], 'set' => $set,
            'errors' => $errors, 'old' => $old, 'canManage' => $this->canManage($r), 'here' => '/safety'], $status));
    }

    public function report(Request $r): Response
    {
        try {
            $res = $this->svc()->report($this->ctx($r)->az(), $r->body, $this->files($r, 'files'));
        } catch (ValidationException $e) {
            return $this->reportForm($r, $this->errors($e), $r->body, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/safety', $e);
        }
        if ($res['code'] !== null) {
            // the tracking code is shown once, on this page — it is not kept anywhere
            return $this->fresh($this->page('@safety/sent', ['title' => $this->t('safety.sent_title'), 'ref' => $res['ref'], 'code' => $res['code'], 'canManage' => $this->canManage($r), 'here' => '/safety']));
        }
        $this->flash($r, 'success', 'safety.flash.reported', ['ref' => $res['ref']]);
        return $this->redirect($r, '/safety/i/' . $res['pid']);
    }

    public function track(Request $r): Response
    {
        $code = $r->method === 'POST' ? $this->str($r, 'code', 20) : '';
        $found = $code === '' ? null : $this->svc()->track($code);
        return $this->fresh($this->page('@safety/track', ['title' => $this->t('safety.track_title'), 'asked' => $code !== '', 'found' => $found, 'canManage' => $this->canManage($r), 'here' => '/safety']));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function show(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200, ?string $pid = null): Response
    {
        $i = $this->svc()->incident($this->ctx($r)->az(), $pid ?? (string) $r->params['id']);
        return $this->fresh($this->page('@safety/show', ['title' => (string) $i['ref'], 'in' => $i, 'errors' => $errors, 'old' => $old, 'form' => $form, 'uid' => $this->ctx($r)->az()->userId,
            'canManage' => $this->canManage($r), 'here' => $i['roles']['manage'] || $i['roles']['view'] ? '/safety/manage' : '/safety'], $status));
    }

    public function file(Request $r): Response
    {
        $f = $this->svc()->file($this->ctx($r)->az(), (string) $r->params['id'], (int) $r->params['fid']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], false)->withHeader('Cache-Control', 'private, no-store');
    }

    public function incidentPost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $action = (string) $r->params['action'];
        $az = $this->ctx($r)->az();
        $s = $this->svc();
        $flash = 'safety.flash.' . $action;
        try {
            switch ($action) {
                case 'triage':
                    $s->triage($az, $pid, $r->body);
                    break;
                case 'assign':
                    $s->assign($az, $pid, $this->str($r, 'who', 200));
                    break;
                case 'reject':
                    $s->reject($az, $pid, $this->str($r, 'reason', 20), $this->str($r, 'note', 1200));
                    break;
                case 'investigate':
                    $s->investigate($az, $pid, $r->body, $this->files($r, 'files'));
                    break;
                case 'witness':
                    $s->addWitness($az, $pid, $this->str($r, 'who', 200), $this->str($r, 'statement', 6000));
                    break;
                case 'medical':
                    $s->addMedical($az, $pid, $r->body);
                    break;
                case 'action':
                    $s->addAction($az, $pid, $r->body);
                    break;
                case 'verify':
                    $ok = $this->str($r, 'decision', 10) === 'effective';
                    $s->verify($az, $pid, $ok, $this->str($r, 'note', 3500));
                    $flash = $ok ? 'safety.flash.closed' : 'safety.flash.not_effective';
                    break;
                case 'reopen':
                    $s->reopen($az, $pid, $this->str($r, 'reason', 1200));
                    break;
                case 'comm':
                    $s->addComm($az, $pid, $r->body);
                    break;
            }
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/safety/i/' . $pid, $e);
        }
        $this->flash($r, 'success', $flash);
        return $this->redirect($r, '/safety/i/' . $pid);
    }

    public function actionPost(Request $r): Response
    {
        $apid = (string) $r->params['id'];
        $pid = (string) $this->app->db()->scalar('SELECT i.public_id FROM sf_actions a JOIN sf_incidents i ON i.id = a.incident_id WHERE a.public_id = ?', [$apid]);
        if ($pid === '') {
            throw HttpException::notFound();
        }
        $az = $this->ctx($r)->az();
        try {
            if ($r->params['action'] === 'done') {
                $s = $this->svc();
                $s->completeAction($az, $apid, $this->str($r, 'note', 2200), $r->file('file'));
            } else {
                $this->svc()->cancelAction($az, $apid, $this->str($r, 'reason', 600));
            }
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $r->body, 'a_' . $r->params['action'] . '_' . $apid, 422, $pid);
        } catch (BusinessException $e) {
            return $this->fail($r, '/safety/i/' . $pid, $e);
        }
        $this->flash($r, 'success', 'safety.flash.action_' . $r->params['action']);
        return $this->redirect($r, '/safety/i/' . $pid);
    }

    public function manage(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r);
        $f = ['status' => $this->str($r, 'status', 20), 'kind' => $this->str($r, 'kind', 20), 'severity' => $this->str($r, 'severity', 1), 'site' => $this->str($r, 'site', 26), 'q' => $this->str($r, 'q', 60)];
        return $this->fresh($this->page('@safety/manage', ['title' => $this->t('safety.manage_title'), 'companies' => $companies, 'lePid' => $lePid, 'f' => $f,
            'rows' => $this->svc()->manageList($this->ctx($r)->az(), $le, $f), 'sites' => Audience::options($this->app, $le)['sites'], 'board' => $this->svc()->board($le),
            'canManage' => true, 'here' => '/safety/manage']));
    }

    public function stats(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r);
        return $this->fresh($this->page('@safety/stats', ['title' => $this->t('safety.stats_title'), 'companies' => $companies, 'lePid' => $lePid,
            'st' => $this->svc()->stats($this->ctx($r)->az(), $le), 'canManage' => true, 'here' => '/safety/stats']));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function settings(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r, true);
        return $this->fresh($this->page('@safety/settings', ['title' => $this->t('safety.settings_title'), 'companies' => $companies, 'lePid' => $lePid,
            'set' => $this->svc()->settings($le), 'errors' => $errors, 'old' => $old, 'canManage' => true, 'here' => '/safety/settings'], $status));
    }

    public function saveSettings(Request $r): Response
    {
        [, $lePid, $le] = $this->legalEntity($r, true);
        try {
            $this->svc()->saveSettings($this->ctx($r)->az(), $le, $r->body);
        } catch (ValidationException $e) {
            return $this->settings($r, $this->errors($e), $r->body, 422);
        }
        $this->flash($r, 'success', 'safety.flash.settings');
        return $this->redirect($r, '/safety/settings?le=' . rawurlencode($lePid));
    }
}
