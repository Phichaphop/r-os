<?php

declare(strict_types=1);

namespace Blue\Modules\Projects;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** Project management screens (M24 · 9E-2). */
final class ProjectsController extends Controller
{
    private const VIEWS = ['board', 'table', 'timeline', 'team', 'updates'];

    private function ps(): ProjectService
    {
        return ProjectService::of($this->app);
    }

    private function ts(): TaskService
    {
        return TaskService::of($this->app);
    }

    private function t(string $k): string
    {
        return $this->app->translator()->get($k);
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

    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        return $this->fresh($this->page('@projects/index', ['title' => $this->t('projects.title'), 'mine' => $this->ps()->mine($az), 'tasks' => $this->ts()->myTasks($az),
            'canCreate' => $this->ps()->myCompany($az) !== null, 'today' => $this->ps()->today()]));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function newForm(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        if ($this->ps()->myCompany($this->ctx($r)->az()) === null) {
            throw HttpException::forbidden();
        }
        return $this->fresh($this->page('@projects/new', ['title' => $this->t('projects.new'), 'errors' => $errors, 'old' => $old], $status));
    }

    public function create(Request $r): Response
    {
        try {
            $pid = $this->ps()->create($this->ctx($r)->az(), $r->body);
        } catch (ValidationException $e) {
            return $this->newForm($r, $this->errors($e), $r->body, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/projects', $e);
        }
        $this->flash($r, 'success', 'projects.flash.created');
        return $this->redirect($r, '/projects/p/' . $pid . '?view=team');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function show(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200, ?string $pid = null): Response
    {
        $p = $this->ps()->project($this->ctx($r)->az(), $pid ?? (string) $r->params['id']);
        $view = (string) ($r->query['view'] ?? '');
        if ($form !== '') {
            $view = in_array($form, ['invite', 'member'], true) ? 'team' : (in_array($form, ['status', 'complete', 'close', 'reopen', 'edit'], true) ? 'updates' : 'board');
        }
        $view = in_array($view, self::VIEWS, true) ? $view : 'board';
        return $this->fresh($this->page('@projects/show', ['title' => (string) $p['name'], 'pj' => $p, 'view' => $view, 'errors' => $errors, 'old' => $old, 'form' => $form,
            'tl' => $view === 'timeline' ? $this->ps()->timeline((string) $p['starts_on'], (string) $p['ends_on'], $p['tasks']) : null], $status));
    }

    public function projectPost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $action = (string) $r->params['action'];
        $az = $this->ctx($r)->az();
        $ps = $this->ps();
        $back = '/projects/p/' . $pid;
        try {
            switch ($action) {
                case 'edit':
                    $ps->update($az, $pid, $r->body);
                    $back .= '?view=updates';
                    break;
                case 'invite':
                    $ps->invite($az, $pid, $this->str($r, 'who', 200));
                    $back .= '?view=team';
                    break;
                case 'accept':
                case 'decline':
                    $ps->respond($az, $pid, $action === 'accept');
                    $back = $action === 'accept' ? $back : '/projects';
                    break;
                case 'start':
                    $ps->start($az, $pid);
                    break;
                case 'status':
                    $ps->statusUpdate($az, $pid, $this->str($r, 'health', 20), $this->str($r, 'note', 3500), $this->str($r, 'status', 20));
                    $back .= '?view=updates';
                    break;
                case 'complete':
                    $ps->complete($az, $pid, $this->str($r, 'summary', 5500), $this->str($r, 'lessons', 5500));
                    $back .= '?view=updates';
                    break;
                case 'close':
                    $ps->close($az, $pid, $this->str($r, 'reason', 1200));
                    $back .= '?view=updates';
                    break;
                case 'reopen':
                    $ps->reopen($az, $pid, $this->str($r, 'reason', 1200));
                    $back .= '?view=updates';
                    break;
                case 'task':
                    $tpid = $this->ts()->create($az, $pid, $r->body);
                    $this->flash($r, 'success', 'projects.flash.task');
                    return $this->redirect($r, '/projects/t/' . $tpid);
            }
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/projects/p/' . $pid, $e);
        }
        $this->flash($r, 'success', 'projects.flash.' . $action);
        return $this->redirect($r, $back);
    }

    public function memberPost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->ps()->setMember($this->ctx($r)->az(), $pid, (int) $r->params['uid'], (string) $r->params['action']);
        } catch (BusinessException $e) {
            return $this->fail($r, '/projects/p/' . $pid . '?view=team', $e);
        }
        $this->flash($r, 'success', 'projects.flash.member_' . $r->params['action']);
        return $this->redirect($r, '/projects/p/' . $pid . '?view=team');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function task(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $t = $this->ts()->task($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->fresh($this->page('@projects/task', ['title' => (string) $t['title'], 'tk' => $t, 'errors' => $errors, 'old' => $old, 'form' => $form], $status));
    }

    public function file(Request $r): Response
    {
        $f = $this->ts()->file($this->ctx($r)->az(), (string) $r->params['id'], (int) $r->params['fid']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], false)->withHeader('Cache-Control', 'private, no-store');
    }

    public function taskPost(Request $r): Response
    {
        $tpid = (string) $r->params['id'];
        $action = (string) $r->params['action'];
        $az = $this->ctx($r)->az();
        $ts = $this->ts();
        $flash = 'projects.flash.task_' . $action;
        try {
            switch ($action) {
                case 'edit':
                    $ts->edit($az, $tpid, $r->body);
                    break;
                case 'mine':
                    $ts->updateMine($az, $tpid, $r->body);
                    break;
                case 'submit':
                    $files = array_values(array_filter((array) ($r->files['files'] ?? []), static fn ($f): bool => $f instanceof UploadedFile && $f->error !== UPLOAD_ERR_NO_FILE));
                    $ts->submit($az, $tpid, $this->str($r, 'note', 5500), $this->str($r, 'link', 600), $files);
                    break;
                case 'accept':
                case 'return':
                    $ts->decide($az, $tpid, $action === 'accept', $this->str($r, 'feedback', 3500));
                    break;
                case 'cancel':
                    $ts->cancel($az, $tpid, $this->str($r, 'reason', 600));
                    break;
                case 'comment':
                    $ts->comment($az, $tpid, $this->str($r, 'body', 2500));
                    break;
            }
        } catch (ValidationException $e) {
            return $this->task($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/projects/t/' . $tpid, $e);
        }
        $this->flash($r, 'success', $flash);
        return $this->redirect($r, '/projects/t/' . $tpid . ($action === 'comment' ? '#comments' : ''));
    }
}
