<?php

declare(strict_types=1);

namespace Blue\Modules\Inspection;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;
use Blue\Modules\Helpdesk\HelpdeskService;

/** Inspection screens (M18 · 9B-1). */
final class InspectionController extends Controller
{
    private function svc(): InspectionService
    {
        return InspectionService::of($this->app);
    }

    private function t(string $k): string
    {
        return $this->app->translator()->get($k);
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} */
    private function company(Request $r, string $perm = 'inspection.manage', ?string $want = null): array
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

    private function canManage(Request $r): bool
    {
        return $this->svc()->companies($this->ctx($r)->az(), 'inspection.manage') !== [];
    }

    private function back(Request $r, string $to, BusinessException $e): Response
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
        $this->flash($r, 'danger', $e->messageKey, $e->params);
        return $this->redirect($r, $to);
    }

    private function fresh(Response $res): Response
    {
        return $res->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    private function origin(Request $r): string
    {
        $configured = rtrim($this->app->config->string('APP_URL'), '/');
        return $configured !== '' ? $configured : ($r->secure ? 'https' : 'http') . '://' . $r->host . $r->basePath();
    }

    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        return $this->fresh($this->page('@inspection/index', ['title' => $this->t('inspection.title'), 'rounds' => $this->svc()->mine($az), 'actions' => $this->svc()->myActions($az),
            'today' => $this->svc()->today(), 'canManage' => $this->canManage($r)]));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function round(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $round = $this->svc()->round($az, (string) $r->params['id']);
        return $this->fresh($this->page('@inspection/round', ['title' => $round['code'] . ' · ' . $round['due_date'], 'rd' => $round, 'errors' => $errors, 'old' => $old, 'form' => $form,
            'today' => $this->svc()->today(), 'meId' => $az->userId], $status));
    }

    public function act(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        try {
            if ($action === 'save' || $action === 'submit') {
                $photos = [];
                foreach ($r->files as $field => $list) {
                    if (preg_match('/^photo_(\d{1,2})$/', (string) $field, $m) === 1) {
                        foreach ($list as $f) {
                            if ($f instanceof UploadedFile && $f->error !== UPLOAD_ERR_NO_FILE) {
                                $photos[(int) $m[1]] = $f;
                            }
                        }
                    }
                }
                $this->svc()->save($az, $pid, $r->body, $photos, $action === 'submit');
            } elseif ($action === 'review') {
                $this->svc()->review($az, $pid, ($r->body['decision'] ?? '') === 'accept', $this->str($r, 'review_note', 500));
            } else {
                $this->svc()->close($az, $pid);
            }
        } catch (ValidationException $e) {
            return $this->round($r, $this->errors($e), $r->body, $action === 'save' ? 'submit' : $action, 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/inspection/r/' . $pid, $e);
        }
        $this->flash($r, 'success', 'inspection.flash.' . $action);
        return $this->redirect($r, $action === 'submit' && !$this->canManage($r) ? '/inspection' : '/inspection/r/' . $pid);
    }

    public function photo(Request $r): Response
    {
        $f = $this->svc()->photo($this->ctx($r)->az(), (string) $r->params['id'], (int) $r->params['rid']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], true)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function qr(Request $r): Response
    {
        try {
            return $this->redirect($r, '/inspection/r/' . $this->svc()->byQr($this->ctx($r)->az(), (string) $r->params['token']));
        } catch (BusinessException $e) {
            return $this->back($r, '/inspection', $e);
        }
    }

    public function action(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $roundPid = (string) $this->app->db()->scalar('SELECT r.public_id FROM in_actions a JOIN in_rounds r ON r.id = a.round_id WHERE a.public_id = ?', [$pid]);
        try {
            if ($r->params['what'] === 'assign') {
                $this->svc()->assignAction($az, $pid, $r->body);
            } else {
                $this->svc()->doneAction($az, $pid, $this->str($r, 'done_note', 500));
            }
        } catch (ValidationException $e) {
            if ($roundPid === '') {
                throw HttpException::notFound();
            }
            $r->params['id'] = $roundPid;
            return $this->round($r, $this->errors($e), $r->body + ['action' => $pid], 'action-' . $r->params['what'], 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/inspection/r/' . $roundPid, $e);
        }
        $this->flash($r, 'success', 'inspection.flash.action_' . $r->params['what']);
        return $this->redirect($r, ($r->body['back'] ?? '') === 'mine' ? '/inspection' : '/inspection/r/' . $roundPid);
    }

    public function board(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        return $this->fresh($this->page('@inspection/board', ['title' => $this->t('inspection.board_title'), 'companies' => $companies, 'lePid' => $lePid, 'board' => $this->svc()->board($this->ctx($r)->az(), $le), 'canManage' => true]));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function admin(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        return $this->page('@inspection/admin', ['title' => $this->t('inspection.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'checklists' => $this->svc()->checklists($le),
            'points' => $this->svc()->points($le), 'sites' => $this->app->db()->all("SELECT public_id, code FROM sites WHERE legal_entity_id = ? AND status = 'active' ORDER BY code", [$le]),
            'categories' => HelpdeskService::of($this->app)->categories($le, true), 'today' => $this->svc()->today(), 'errors' => $errors, 'old' => $old, 'form' => $form, 'canManage' => true], $status);
    }

    public function adminPost(Request $r): Response
    {
        [, $lePid, $le] = $this->company($r);
        $az = $this->ctx($r)->az();
        $what = (string) $r->params['what'];
        try {
            $pid = $what === 'checklist' ? $this->svc()->saveChecklist($az, $le, $r->body) : $this->svc()->savePoint($az, $le, $r->body);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $r->body, $what, 422);
        }
        $this->flash($r, 'success', 'inspection.flash.saved_' . $what);
        return $this->redirect($r, $what === 'point' ? '/inspection/p/' . $pid : '/inspection/admin?le=' . rawurlencode($lePid) . '#ia-checklists');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function checklist(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $c = $this->app->db()->one('SELECT * FROM in_checklists WHERE public_id = ?', [(string) $r->params['id']]) ?? throw HttpException::notFound();
        $le = (int) $c['legal_entity_id'];
        if (!$this->ctx($r)->az()->scope('inspection.manage')->coversLegalEntity($le)) {
            throw HttpException::notFound();
        }
        $list = array_values(array_filter($this->svc()->checklists($le), static fn (array $x): bool => $x['public_id'] === $c['public_id']));
        return $this->page('@inspection/checklist', ['title' => (string) $c['name'], 'cl' => $list[0], 'today' => $this->svc()->today(), 'errors' => $errors, 'old' => $old], $status);
    }

    public function checklistPost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $le = (int) $this->app->db()->scalar('SELECT legal_entity_id FROM in_checklists WHERE public_id = ?', [$pid]);
        try {
            $this->svc()->saveChecklist($this->ctx($r)->az(), $le, $r->body, $pid);
        } catch (ValidationException $e) {
            return $this->checklist($r, $this->errors($e), $r->body, 422);
        }
        $this->flash($r, 'success', 'inspection.flash.saved_checklist');
        return $this->redirect($r, '/inspection/c/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function point(Request $r, array $errors = [], array $old = [], int $status = 200, string $layout = 'layouts/app'): Response
    {
        $p = $this->svc()->point((string) $r->params['id']);
        $le = (int) $p['legal_entity_id'];
        if (!$this->ctx($r)->az()->scope('inspection.manage')->coversLegalEntity($le)) {
            throw HttpException::notFound();
        }
        $rounds = $this->app->db()->all('SELECT public_id, due_date, status, result, fail_count, late FROM in_rounds WHERE point_id = ? ORDER BY due_date DESC LIMIT 30', [$p['id']]);
        return $this->page('@inspection/point', ['title' => $p['code'] . ' · ' . $p['name'], 'pt' => $p, 'rounds' => $rounds, 'qr' => $this->svc()->qrSvg($p, $this->origin($r)),
            'qrUrl' => rtrim($this->origin($r), '/') . '/inspection/q/' . $p['qr_token'], 'checklists' => $this->svc()->checklists($le),
            'sites' => $this->app->db()->all("SELECT public_id, code FROM sites WHERE legal_entity_id = ? AND status = 'active' ORDER BY code", [$le]),
            'categories' => HelpdeskService::of($this->app)->categories($le, true), 'errors' => $errors, 'old' => $old], $status);
    }

    public function pointPost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $p = $this->svc()->point($pid);
        $az = $this->ctx($r)->az();
        try {
            if ($r->params['what'] === 'qr') {
                $this->svc()->newQr($az, $pid);
                $this->flash($r, 'success', 'inspection.flash.qr');
            } else {
                $this->svc()->savePoint($az, (int) $p['legal_entity_id'], $r->body, $pid);
                $this->flash($r, 'success', 'inspection.flash.saved_point');
            }
        } catch (ValidationException $e) {
            return $this->point($r, $this->errors($e), $r->body, 422);
        }
        return $this->redirect($r, '/inspection/p/' . $pid);
    }

    public function label(Request $r): Response
    {
        $p = $this->svc()->point((string) $r->params['id']);
        if (!$this->ctx($r)->az()->scope('inspection.manage')->coversLegalEntity((int) $p['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        return $this->page('@inspection/label', ['title' => $this->t('inspection.label_title'), 'pt' => $p, 'qr' => $this->svc()->qrSvg($p, $this->origin($r))]);
    }

    private function month(Request $r): string
    {
        $m = (string) ($r->query['month'] ?? '');
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) === 1 ? $m : substr($this->svc()->today(), 0, 7);
    }

    public function report(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $month = $this->month($r);
        return $this->page('@inspection/report', ['title' => $this->t('inspection.report_title'), 'companies' => $companies, 'lePid' => $lePid, 'month' => $month,
            'rows' => $this->svc()->report($this->ctx($r)->az(), $le, $month), 'canManage' => true]);
    }

    public function export(Request $r): Response
    {
        [, , $le] = $this->company($r);
        $f = $this->svc()->export($this->ctx($r)->az(), $le, $this->month($r));
        return DocumentsController::fileResponse($f['bytes'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $f['name'], false)->withHeader('Cache-Control', 'no-store, max-age=0');
    }
}
