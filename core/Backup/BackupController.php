<?php

declare(strict_types=1);

namespace Blue\Core\Backup;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Ui\Paging;

/** M48 screens: backup catalogue, run / download / upload / check, restore drill, restore request + execution. */
final class BackupController extends Controller
{
    public function index(Request $r): Response
    {
        $az = $this->guard($r);
        $paging = Paging::from($r, $this->app->preferences(), $this->ctx($r)->userId(), 'backup_runs');
        $svc = $this->app->backups();
        return $this->page('admin/backups/index', [
            'title' => $this->app->translator()->get('backup.title'), 'health' => $svc->health(), 'list' => $svc->list($az, $paging->page, $paging->per),
            'page' => $paging->page, 'per' => $paging->per, 'canRun' => $az->scope('core.backup.run')->all, 'offsite' => $svc->offsitePath() !== '',
            'drillDb' => $this->app->restores()->drillDatabase(), 'includeDefault' => $svc->includeFilesDefault(),
            'requests' => $this->app->db()->all('SELECT r.*, u.display_name AS requested_name FROM restore_requests r LEFT JOIN users u ON u.id = r.requested_by ORDER BY r.id DESC LIMIT 10'),
            'settings' => ['interval_hours' => $svc->setting('interval_hours', 24), 'max_age_hours' => $svc->setting('max_age_hours', 26),
                'keep_days' => $svc->setting('keep_days', 35), 'keep_monthly' => $svc->setting('keep_monthly', 12)],
            'scheduler' => $this->app->config->string('SCHEDULER', 'piggyback'),
        ]);
    }

    public function run(Request $r): Response
    {
        $az = $this->guard($r);
        try {
            $pid = $this->app->backups()->startManual($az, $r->input('include_files') === '1');
        } catch (BusinessException $e) {
            $this->rethrow($e);
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/admin/backups');
        }
        return $this->redirect($r, '/admin/backups/' . $pid);
    }

    public function show(Request $r): Response
    {
        $az = $this->guard($r);
        $b = $this->app->backups()->get($az, (string) $r->params['id']);
        $open = $this->app->db()->scalar("SELECT public_id FROM restore_requests WHERE status IN ('in_review','returned','approved','executing') LIMIT 1");
        return $this->page('admin/backups/show', [
            'title' => $this->app->translator()->get('backup.detail'), 'b' => $b, 'canRun' => $az->scope('core.backup.run')->all,
            'canDownload' => $az->scope('core.backup.download')->all, 'canRestore' => $az->scope('core.backup.restore')->all, 'openRequest' => $open,
            'newest' => (int) ($this->app->db()->scalar("SELECT id FROM backup_runs WHERE status = 'verified' ORDER BY finished_at DESC LIMIT 1") ?? 0) === (int) $b['id'],
        ]);
    }

    /** "Continue" — advances a running backup (the page repeats this until done). */
    public function step(Request $r): Response
    {
        $this->guard($r, 'core.backup.run');
        $pid = (string) $r->params['id'];
        $this->app->backups()->step($pid, (float) $this->app->backups()->setting('step_seconds', 20));
        return $this->redirect($r, '/admin/backups/' . $pid);
    }

    public function recheck(Request $r): Response
    {
        $az = $this->guard($r, 'core.backup.run');
        $pid = (string) $r->params['id'];
        try {
            $problems = $this->app->backups()->recheck($az, $pid);
            $problems === [] ? $this->flash($r, 'success', 'backup.flash.recheck_ok') : $this->flash($r, 'danger', 'backup.flash.recheck_failed', ['detail' => implode('; ', array_slice($problems, 0, 2))]);
        } catch (BusinessException $e) {
            $this->rethrow($e);
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/admin/backups/' . $pid);
    }

    public function drill(Request $r): Response
    {
        $az = $this->guard($r, 'core.backup.run');
        try {
            $drill = $this->app->restores()->drill($az, (string) $r->params['id']);
        } catch (BusinessException $e) {
            $this->rethrow($e);
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/admin/backups/' . $r->params['id']);
        }
        return $this->redirect($r, '/admin/backups/drills/' . $drill);
    }

    public function drillReport(Request $r): Response
    {
        $this->guard($r);
        $d = $this->app->restores()->drillRow((string) $r->params['id']);
        return $this->page('admin/backups/drill', ['title' => $this->app->translator()->get('backup.drill.title'), 'd' => $d]);
    }

    public function download(Request $r): Response
    {
        $az = $this->guard($r, 'core.backup.download');
        $this->ctx($r)->requireFreshReauth();
        $f = $this->app->backups()->download($az, (string) $r->params['id']);
        return Response::download($f['path'], 'application/octet-stream', $f['filename']);
    }

    public function delete(Request $r): Response
    {
        $az = $this->guard($r, 'core.backup.run');
        $this->ctx($r)->requireFreshReauth();
        try {
            $this->app->backups()->delete($az, (string) $r->params['id'], $this->str($r, 'reason', 255));
            $this->flash($r, 'success', 'backup.flash.deleted');
        } catch (BusinessException $e) {
            $this->rethrow($e);
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/admin/backups/' . $r->params['id']);
        }
        return $this->redirect($r, '/admin/backups');
    }

    public function upload(Request $r): Response
    {
        $az = $this->guard($r, 'core.backup.run');
        $this->ctx($r)->requireFreshReauth();
        $file = $r->file('file');
        if ($file === null || !$file->ok()) {
            $this->flash($r, 'danger', $file?->errorKey() ?? 'file.error.missing');
            return $this->redirect($r, '/admin/backups');
        }
        try {
            $pid = $this->app->backups()->importFile($az->userId, $file->path);
        } catch (BusinessException $e) {
            $this->rethrow($e);
            $this->flash($r, 'danger', $e->messageKey, array_map('strval', $e->params));
            return $this->redirect($r, '/admin/backups');
        } catch (\RuntimeException) {
            $this->flash($r, 'danger', 'backup.error.not_backup');
            return $this->redirect($r, '/admin/backups');
        }
        $this->flash($r, 'success', 'backup.flash.imported');
        return $this->redirect($r, '/admin/backups/' . $pid);
    }

    public function requestRestore(Request $r): Response
    {
        $az = $this->guard($r, 'core.backup.restore');
        $pid = (string) $r->params['id'];
        try {
            $req = $this->app->restores()->request($az, $pid, $this->str($r, 'incident', 80), $this->str($r, 'reason', 500));
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, '/admin/backups/' . $pid);
        } catch (BusinessException $e) {
            $this->rethrow($e);
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/admin/backups/' . $pid);
        }
        $this->flash($r, 'success', 'backup.flash.restore_requested');
        return $this->redirect($r, '/admin/backups/restore/' . $req);
    }

    public function restore(Request $r): Response
    {
        $az = $this->guard($r);
        $req = $this->app->restores()->requestRow((string) $r->params['id']);
        return $this->page('admin/backups/restore', [
            'title' => $this->app->translator()->get('backup.restore.title'), 'req' => $req, 'phrase' => RestoreService::confirmPhrase((string) $req['backup_pid']),
            'isRequester' => (int) $req['requested_by'] === $az->userId, 'canRestore' => $az->scope('core.backup.restore')->all,
            'wf' => $req['workflow_instance_id'] !== null ? $this->app->db()->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$req['workflow_instance_id']]) : null,
        ]);
    }

    /** Runs the approved restore. Sessions are not in backups, so the result is shown on a page that needs no sign-in. */
    public function execute(Request $r): Response
    {
        $az = $this->guard($r, 'core.backup.restore');
        $this->ctx($r)->requireFreshReauth();
        $pid = (string) $r->params['id'];
        try {
            $result = $this->app->restores()->execute($az, $pid, $this->str($r, 'phrase', 40));
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, '/admin/backups/restore/' . $pid);
        } catch (BusinessException $e) {
            $this->rethrow($e);
            $this->flash($r, 'danger', $e->messageKey, array_map('strval', $e->params));
            return $this->redirect($r, '/admin/backups/restore/' . $pid);
        }
        return Response::html($this->app->view()->render('admin/backups/restored', [
            'title' => $this->app->translator()->get('backup.restore.title'), 'result' => $result,
        ], 'layouts/minimal'), $result['ok'] ? 200 : 500);
    }

    private function guard(Request $r, string $perm = 'core.backup.view'): \Blue\Core\Authz\Authorizer
    {
        $az = $this->ctx($r)->az();
        if (!$az->scope($perm)->all) {
            throw HttpException::forbidden();
        }
        return $az;
    }

    private function rethrow(BusinessException $e): void
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
    }
}
