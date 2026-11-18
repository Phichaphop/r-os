<?php

declare(strict_types=1);

namespace Blue\Modules\Forum;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** Discussion board screens (M54 · 9D-2). */
final class ForumController extends Controller
{
    private function svc(): ForumService
    {
        return ForumService::of($this->app);
    }

    private function t(string $k): string
    {
        return $this->app->translator()->get($k);
    }

    private function fresh(Response $res): Response
    {
        return $res->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    private function canModerate(Request $r): bool
    {
        return $this->svc()->companies($this->ctx($r)->az(), 'forum.moderate') !== [];
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
        $le = $this->svc()->boardOf($az);
        $f = ['category' => $this->str($r, 'category', 26), 'q' => $this->str($r, 'q', 60), 'sort' => $this->str($r, 'sort', 10), 'mine' => $this->str($r, 'mine', 1)];
        return $this->fresh($this->page('@forum/index', ['title' => $this->t('forum.title'), 'le' => $le, 'f' => $f,
            'categories' => $le === null ? [] : $this->svc()->categories($le, true), 'rows' => $le === null ? [] : $this->svc()->threads($az, $le, $f),
            'canModerate' => $this->canModerate($r), 'here' => '/forum']));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function newForm(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $le = $this->svc()->boardOf($this->ctx($r)->az());
        if ($le === null) {
            throw HttpException::forbidden();
        }
        return $this->fresh($this->page('@forum/new', ['title' => $this->t('forum.new_thread'), 'categories' => $this->svc()->categories($le, true), 'errors' => $errors, 'old' => $old,
            'canModerate' => $this->canModerate($r), 'here' => '/forum'], $status));
    }

    public function create(Request $r): Response
    {
        $files = array_values(array_filter((array) ($r->files['files'] ?? []), static fn ($f): bool => $f instanceof UploadedFile && $f->error !== UPLOAD_ERR_NO_FILE));
        try {
            $pid = $this->svc()->create($this->ctx($r)->az(), $r->body, $files);
        } catch (ValidationException $e) {
            return $this->newForm($r, $this->errors($e), $r->body, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/forum', $e);
        }
        $this->flash($r, 'success', 'forum.flash.posted');
        return $this->redirect($r, '/forum/t/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function thread(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200, ?string $pid = null): Response
    {
        $t = $this->svc()->thread($this->ctx($r)->az(), $pid ?? (string) $r->params['id']);
        return $this->fresh($this->page('@forum/thread', ['title' => (string) $t['title'], 'th' => $t, 'errors' => $errors, 'old' => $old, 'form' => $form,
            'canModerate' => $this->canModerate($r), 'here' => '/forum'], $status));
    }

    public function file(Request $r): Response
    {
        $f = $this->svc()->file($this->ctx($r)->az(), (string) $r->params['id'], (int) $r->params['fid']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], false)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function threadPost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $action = (string) $r->params['action'];
        $az = $this->ctx($r)->az();
        $s = $this->svc();
        $flash = 'forum.flash.' . $action;
        try {
            switch ($action) {
                case 'reply':
                    $s->reply($az, $pid, (string) ($r->body['body'] ?? ''));
                    break;
                case 'edit':
                    $s->edit($az, 'thread', $pid, $r->body);
                    break;
                case 'like':
                    $flash = $s->like($az, 'thread', $pid) ? 'forum.flash.liked' : 'forum.flash.unliked';
                    break;
                case 'report':
                    $s->report($az, 'thread', $pid, $this->str($r, 'reason', 500));
                    break;
                default:
                    $s->moderate($az, 'thread', $pid, $action, $this->str($r, 'reason', 500));
            }
        } catch (ValidationException $e) {
            return $this->thread($r, $this->errors($e), $r->body, 'thread_' . $action, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/forum/t/' . $pid, $e);
        }
        $this->flash($r, 'success', $flash);
        return $this->redirect($r, '/forum/t/' . $pid . ($action === 'reply' ? '#replies-end' : ''));
    }

    public function postPost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $action = (string) $r->params['action'];
        $az = $this->ctx($r)->az();
        $s = $this->svc();
        $thread = (string) $this->app->db()->scalar('SELECT t.public_id FROM fm_posts p JOIN fm_threads t ON t.id = p.thread_id WHERE p.public_id = ?', [$pid]);
        if ($thread === '') {
            throw HttpException::notFound();
        }
        $flash = 'forum.flash.' . $action;
        try {
            switch ($action) {
                case 'edit':
                    $s->edit($az, 'post', $pid, $r->body);
                    break;
                case 'like':
                    $flash = $s->like($az, 'post', $pid) ? 'forum.flash.liked' : 'forum.flash.unliked';
                    break;
                case 'report':
                    $s->report($az, 'post', $pid, $this->str($r, 'reason', 500));
                    break;
                case 'remove':
                    $s->remove($az, $pid);
                    break;
                default:
                    $s->moderate($az, 'post', $pid, $action, $this->str($r, 'reason', 500));
            }
        } catch (ValidationException $e) {
            return $this->thread($r, $this->errors($e), $r->body, 'post_' . $action . '_' . $pid, 422, $thread);
        } catch (BusinessException $e) {
            return $this->fail($r, '/forum/t/' . $thread, $e);
        }
        $this->flash($r, 'success', $flash);
        return $this->redirect($r, '/forum/t/' . $thread . '#p-' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function moderate(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $ids = $this->svc()->companies($az, 'forum.moderate');
        if ($ids === []) {
            throw HttpException::forbidden();
        }
        $companies = [];
        foreach ($this->app->db()->all('SELECT id, public_id, code FROM legal_entities WHERE id IN (' . implode(',', $ids) . ') ORDER BY code') as $row) {
            $companies[(string) $row['public_id']] = [(int) $row['id'], (string) $row['code']];
        }
        $lePid = (string) ($r->query['le'] ?? $r->body['le'] ?? '');
        if (!isset($companies[$lePid])) {
            $lePid = (string) array_key_first($companies);
        }
        $le = $companies[$lePid][0];
        return $this->fresh($this->page('@forum/moderate', ['title' => $this->t('forum.moderate_title'), 'companies' => array_map(static fn (array $c): string => $c[1], $companies), 'lePid' => $lePid,
            'reports' => $this->svc()->reports($az, $le), 'categories' => $this->svc()->categories($le), 'errors' => $errors, 'old' => $old, 'canModerate' => true, 'here' => '/forum/moderate'], $status));
    }

    public function handle(Request $r): Response
    {
        try {
            $this->svc()->handle($this->ctx($r)->az(), (string) $r->params['id'], $r->params['decision'] === 'uphold', $this->str($r, 'note', 500));
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, '/forum/moderate');
        } catch (BusinessException $e) {
            return $this->fail($r, '/forum/moderate', $e);
        }
        $this->flash($r, 'success', 'forum.flash.' . $r->params['decision']);
        return $this->redirect($r, '/forum/moderate');
    }

    public function categoryPost(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $lePid = $this->str($r, 'le', 26);
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        $pid = isset($r->params['id']) ? (string) $r->params['id'] : null;
        try {
            $this->svc()->saveCategory($az, $le, $r->body, $pid);
        } catch (ValidationException $e) {
            return $this->moderate($r, $this->errors($e), $r->body + ['_pid' => $pid ?? ''], 422);
        }
        $this->flash($r, 'success', 'forum.flash.saved');
        return $this->redirect($r, '/forum/moderate?le=' . rawurlencode($lePid));
    }
}
