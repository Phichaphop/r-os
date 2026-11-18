<?php

declare(strict_types=1);

namespace Blue\Modules\Assets;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** Asset register screens (M42 · 9C-1). */
final class AssetsController extends Controller
{
    private function svc(): AssetService
    {
        return AssetService::of($this->app);
    }

    private function t(string $k): string
    {
        return $this->app->translator()->get($k);
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} */
    private function company(Request $r, string $perm = 'assets.view', ?string $want = null): array
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

    private function upload(Request $r, string $name): ?UploadedFile
    {
        $f = ($r->files[$name] ?? [])[0] ?? null;
        return $f instanceof UploadedFile && $f->error !== UPLOAD_ERR_NO_FILE ? $f : null;
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

    /** @return array<string, bool> */
    private function roles(Request $r): array
    {
        $az = $this->ctx($r)->az();
        return ['canView' => $this->svc()->companies($az, 'assets.view') !== [], 'canManage' => $this->svc()->companies($az, 'assets.manage') !== [],
            'canCount' => $this->svc()->companies($az, 'assets.count') !== []];
    }

    /** @return array<string, string> */
    private function filters(Request $r): array
    {
        $f = [];
        foreach (['q' => 60, 'site' => 26, 'type' => 26, 'status' => 20, 'department' => 26] as $k => $max) {
            $f[$k] = mb_substr(trim((string) ($r->query[$k] ?? '')), 0, $max);
        }
        if (!in_array($f['status'], ['', 'available', 'assigned', 'maintenance', 'lost', 'disposal_pending', 'disposed'], true)) {
            $f['status'] = '';
        }
        return $f;
    }

    public function index(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $f = $this->filters($r);
        return $this->fresh($this->page('@assets/index', ['title' => $this->t('assets.title'), 'companies' => $companies, 'lePid' => $lePid, 'f' => $f,
            'rows' => $this->svc()->listing($this->ctx($r)->az(), $le, $f), 'sites' => $this->svc()->sites($le), 'types' => $this->svc()->types($le),
            'depts' => $this->svc()->departments($le), 'here' => '/assets'] + $this->roles($r)));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function form(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r, 'assets.manage');
        return $this->page('@assets/form', ['title' => $this->t('assets.new_title'), 'companies' => $companies, 'lePid' => $lePid, 'types' => $this->svc()->types($le, true),
            'sites' => $this->svc()->sites($le), 'depts' => $this->svc()->departments($le), 'errors' => $errors, 'old' => $old, 'here' => '/assets/new'] + $this->roles($r), $status);
    }

    public function create(Request $r): Response
    {
        [, , $le] = $this->company($r, 'assets.manage');
        try {
            $pid = $this->svc()->create($this->ctx($r)->az(), $le, $r->body);
        } catch (ValidationException $e) {
            return $this->form($r, $this->errors($e), $r->body, 422);
        }
        $this->flash($r, 'success', 'assets.flash.registered');
        return $this->redirect($r, '/assets/a/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function show(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $a = $this->svc()->asset($az, (string) $r->params['id']);
        $le = (int) $a['legal_entity_id'];
        $helpdesk = $this->app->modules()->isEnabled('helpdesk') && $az->can('helpdesk.use');
        return $this->fresh($this->page('@assets/asset', ['title' => $a['code'] . ' · ' . $a['name'], 'item' => $a, 'qr' => $a['can_view'] ? $this->svc()->qrSvg($a, $this->origin($r), 4) : null,
            'types' => $a['can_manage'] ? $this->svc()->types($le, true) : [], 'sites' => $a['can_manage'] ? $this->svc()->sites($le) : [], 'depts' => $a['can_manage'] ? $this->svc()->departments($le) : [],
            'helpdesk' => $helpdesk, 'errors' => $errors, 'old' => $old, 'form' => $form, 'here' => ''] + $this->roles($r), $status));
    }

    public function act(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        $s = $this->svc();
        try {
            match ($action) {
                'edit' => $s->update($az, $pid, $r->body),
                'assign' => $s->assign($az, $pid, $this->str($r, 'who', 120), $this->str($r, 'condition_out', 10), $this->str($r, 'assign_note', 500)),
                'ack' => $s->acknowledge($az, $pid),
                'return' => $s->returnAsset($az, $pid, $this->str($r, 'condition_in', 10), $this->str($r, 'return_note', 500)),
                'status' => $s->setStatus($az, $pid, $this->str($r, 'status', 20), $this->str($r, 'status_reason', 500)),
                'file' => $s->addFile($az, $pid, $this->str($r, 'file_kind', 20), $this->upload($r, 'file')),
                'dispose' => $s->requestDisposal($az, $pid, $this->str($r, 'method', 20), $this->str($r, 'disposal_reason', 1000), $this->str($r, 'proceeds', 20)),
                default => $s->newQr($az, $pid),
            };
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, $e->params);
            return $this->redirect($r, '/assets/a/' . $pid);
        }
        $this->flash($r, 'success', 'assets.flash.' . $action);
        return $this->redirect($r, $action === 'ack' ? '/assets/mine' : '/assets/a/' . $pid);
    }

    public function file(Request $r): Response
    {
        $f = $this->svc()->file($this->ctx($r)->az(), (string) $r->params['id'], (int) $r->params['fid']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], true)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function qr(Request $r): Response
    {
        return $this->redirect($r, '/assets/a/' . $this->svc()->byQr($this->ctx($r)->az(), (string) $r->params['token']));
    }

    public function labels(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r, 'assets.manage');
        $f = $this->filters($r);
        $rows = $this->svc()->listing($this->ctx($r)->az(), $le, $f, 300);
        $origin = $this->origin($r);
        $labels = array_map(fn (array $a): array => ['code' => (string) $a['code'], 'name' => (string) $a['name'], 'site' => (string) $a['site_code'], 'svg' => $this->svc()->qrSvg($a, $origin, 3)], $rows);
        return $this->page('@assets/labels', ['title' => $this->t('assets.labels_title'), 'companies' => $companies, 'lePid' => $lePid, 'labels' => $labels, 'f' => $f,
            'sites' => $this->svc()->sites($le), 'here' => '/assets/labels'] + $this->roles($r));
    }

    public function mine(Request $r): Response
    {
        return $this->fresh($this->page('@assets/mine', ['title' => $this->t('assets.mine_title'), 'rows' => $this->svc()->mine($this->ctx($r)->az()), 'here' => '/assets/mine'] + $this->roles($r)));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function types(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200, ?string $want = null): Response
    {
        [$companies, $lePid, $le] = $this->company($r, 'assets.manage', $want);
        return $this->page('@assets/types', ['title' => $this->t('assets.types_title'), 'companies' => $companies, 'lePid' => $lePid, 'types' => $this->svc()->types($le),
            'errors' => $errors, 'old' => $old, 'form' => $form, 'here' => '/assets/types'] + $this->roles($r), $status);
    }

    public function typePost(Request $r): Response
    {
        $pid = isset($r->params['id']) ? (string) $r->params['id'] : null;
        $want = null;
        if ($pid !== null) {
            $le = $this->app->db()->scalar('SELECT legal_entity_id FROM as_types WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
            $want = (string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        }
        [, $lePid, $le] = $this->company($r, 'assets.manage', $want);
        try {
            $this->svc()->saveType($this->ctx($r)->az(), $le, $r->body, $pid);
        } catch (ValidationException $e) {
            return $this->types($r, $this->errors($e), $r->body, $pid ?? 'new', 422, $lePid);
        }
        $this->flash($r, 'success', 'assets.flash.type_saved');
        return $this->redirect($r, '/assets/types?le=' . rawurlencode($lePid));
    }

    public function report(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        return $this->page('@assets/report', ['title' => $this->t('assets.report_title'), 'companies' => $companies, 'lePid' => $lePid, 'report' => $this->svc()->report($this->ctx($r)->az(), $le),
            'here' => '/assets/report'] + $this->roles($r));
    }

    public function export(Request $r): Response
    {
        [, , $le] = $this->company($r);
        $f = $this->svc()->export($this->ctx($r)->az(), $le);
        return DocumentsController::fileResponse($f['bytes'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $f['name'], false)->withHeader('Cache-Control', 'no-store, max-age=0');
    }
}
