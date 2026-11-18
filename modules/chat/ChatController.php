<?php

declare(strict_types=1);

namespace Blue\Modules\Chat;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** M17 chat screens (R5 · 0.41.0): conversation list, a conversation (polled only while open — Q-01), new chat, report queue. */
final class ChatController extends Controller
{
    private function svc(): ChatService
    {
        return ChatService::of($this->app);
    }

    private function fresh(Response $res): Response
    {
        return $res->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    private function canModerate(Request $r): bool
    {
        return !$this->ctx($r)->az()->scope('chat.moderate')->isEmpty();
    }

    private function fail(Request $r, string $to, BusinessException $e): Response
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
        if ($r->wantsJson()) {
            return Response::json(['ok' => false, 'error' => $this->app->translator()->get($e->messageKey, $e->params)], $e->httpStatus >= 400 ? $e->httpStatus : 409);
        }
        $this->flash($r, 'danger', $e->messageKey, $e->params);
        return $this->redirect($r, $to);
    }

    /** @param array<string, mixed> $extra */
    private function chatPage(Request $r, string $view, array $extra, int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('chat.use')) {
            throw HttpException::forbidden();
        }
        $can = $this->svc()->companyOf($az) !== null;
        return $this->fresh($this->page('@chat/' . $view, $extra + ['title' => $this->app->translator()->get('chat.title'), 'canChat' => $can,
            'list' => $can ? $this->svc()->conversations($az) : [], 'canModerate' => $this->canModerate($r), 'current' => '', 'settings' => $this->svc()->settings()], $status));
    }

    public function index(Request $r): Response
    {
        $q = trim((string) ($r->query['q'] ?? ''));
        $az = $this->ctx($r)->az();
        return $this->chatPage($r, 'index', ['q' => $q, 'found' => $q !== '' && $this->svc()->companyOf($az) !== null ? $this->svc()->search($az, $q) : []]);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function newForm(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $q = trim((string) ($r->query['q'] ?? ''));
        $people = $this->svc()->companyOf($az) !== null ? $this->svc()->colleagues($az, $q, 60) : [];
        return $this->chatPage($r, 'new', ['q' => $q, 'people' => $people, 'errors' => $errors, 'old' => $old], $status);
    }

    public function create(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $people = array_values(array_filter(array_map('intval', (array) ($r->body['people'] ?? []))));
        $title = $this->str($r, 'title', 80);
        try {
            $pid = count($people) === 1 && $title === '' ? $this->svc()->direct($az, $people[0]) : $this->svc()->group($az, $title, $people);
        } catch (ValidationException $e) {
            return $this->newForm($r, $this->errors($e), ['people' => $people, 'title' => $title], 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/chat', $e);
        }
        return $this->redirect($r, '/chat/c/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function show(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $c = $this->svc()->conversation($az, (string) $r->params['id']);
        $before = isset($r->query['before']) && ctype_digit((string) $r->query['before']) ? (int) $r->query['before'] : null;
        $msgs = $this->svc()->messages($az, $c, null, $before);
        $last = (int) $this->app->db()->scalar('SELECT IFNULL(MAX(id), 0) FROM ch_messages WHERE conversation_id = ?', [$c['id']]);
        if ($before === null) {
            $this->svc()->markRead($az, $c, $last);
        }
        $edit = (string) ($r->query['edit'] ?? '');
        return $this->chatPage($r, 'conversation', ['c' => $c, 'msgs' => $msgs, 'last' => $last, 'before' => $before, 'errors' => $errors, 'old' => $old,
            'current' => (string) $c['public_id'], 'edit' => $edit, 'reportId' => (string) ($r->query['report'] ?? ''),
            'now' => $this->app->clock->now()->format('Y-m-d H:i:s.u'), 'colleagues' => $c['kind'] === 'group' && $c['me']['role'] === 'owner' ? $this->svc()->colleagues($az, '', 200) : []], $status);
    }

    /**
     * Message HTML for the page's script (same partial as the page; text escaped there).
     * @param array<string, mixed> $c
     * @param array<string, mixed> $m
     */
    private function fragment(array $c, array $m): string
    {
        return $this->app->view()->render('@chat/_message', ['m' => $m, 'c' => $c, 'reads' => $this->reads($c)], null);
    }

    /**
     * @param array<string, mixed> $c
     * @return array<int, int> member id → last read message id (for read receipts)
     */
    private function reads(array $c): array
    {
        $out = [];
        foreach ($this->app->db()->all('SELECT user_id, last_read_id FROM ch_members WHERE conversation_id = ? AND left_at IS NULL', [$c['id']]) as $m) {
            $out[(int) $m['user_id']] = (int) $m['last_read_id'];
        }
        return $out;
    }

    public function send(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $body = (string) ($r->body['body'] ?? '');
        try {
            $res = $this->svc()->send($az, $pid, $body, $r->files['files'] ?? [], (string) ($r->body['client_id'] ?? ''));
        } catch (ValidationException $e) {
            if ($r->wantsJson()) {
                return Response::json(['ok' => false, 'error' => $this->firstError($e)], 422);
            }
            return $this->show($r, $this->errors($e), ['body' => $body], 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/chat/c/' . $pid, $e);
        }
        if ($r->wantsJson()) {
            return $this->fresh(Response::json(['ok' => true, 'id' => $res['id'], 'duplicate' => $res['duplicate']]));
        }
        return $this->redirect($r, '/chat/c/' . $pid . '#m-' . $res['public_id']);
    }

    /** Polled every chat.poll_seconds by the open conversation page only (Q-01): new messages, changed ones, read receipts. */
    public function poll(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $c = $this->svc()->conversation($az, (string) $r->params['id']);
        $after = ctype_digit((string) ($r->query['after'] ?? '')) ? (int) $r->query['after'] : 0;
        $since = (string) ($r->query['since'] ?? '');
        $since = preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(\.\d{1,6})?$/', $since) === 1 ? $since : $this->app->clock->now()->format('Y-m-d H:i:s.u');
        $now = $this->app->clock->now()->format('Y-m-d H:i:s.u');
        $new = $this->svc()->messages($az, $c, $after, null, 100);
        $changed = $this->svc()->changed($az, $c, $since, $after);
        $last = $after;
        foreach ($new as $m) {
            $last = max($last, (int) $m['id']);
        }
        if ($new !== []) {
            $this->svc()->markRead($az, $c, $last);
        }
        $out = ['ok' => true, 'last' => $last, 'now' => $now, 'messages' => [], 'changed' => []];
        foreach ($new as $m) {
            $out['messages'][] = ['id' => (int) $m['id'], 'html' => $this->fragment($c, $m)];
        }
        foreach ($changed as $m) {
            $out['changed'][] = ['id' => (int) $m['id'], 'html' => $this->fragment($c, $m)];
        }
        // read receipts of my latest messages
        $reads = $this->reads($c);
        $mine = $this->app->db()->all('SELECT id FROM ch_messages WHERE conversation_id = ? AND sender_id = ? AND id > ? ORDER BY id DESC LIMIT 20', [$c['id'], $az->userId, (int) $c['me']['visible_from_id']]);
        $out['reads'] = [];
        foreach ($mine as $m) {
            $n = 0;
            foreach ($reads as $u => $rid) {
                if ($u !== $az->userId && $rid >= (int) $m['id']) {
                    $n++;
                }
            }
            $out['reads'][(int) $m['id']] = $n;
        }
        return $this->fresh(Response::json($out));
    }

    public function message(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $mid = (string) $r->params['mid'];
        $action = (string) $r->params['action'];
        $az = $this->ctx($r)->az();
        try {
            if ($action === 'report') {
                $this->svc()->report($az, $pid, $mid, $this->str($r, 'reason', 500));
            } else {
                $this->svc()->change($az, $pid, $mid, $action, (string) ($r->body['body'] ?? ''));
            }
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, '/chat/c/' . $pid . '?' . ($action === 'report' ? 'report=' : 'edit=') . $mid . '#m-' . $mid);
        } catch (BusinessException $e) {
            return $this->fail($r, '/chat/c/' . $pid, $e);
        }
        $this->flash($r, 'success', 'chat.flash.' . $action);
        return $this->redirect($r, '/chat/c/' . $pid . '#m-' . $mid);
    }

    public function file(Request $r): Response
    {
        $f = $this->svc()->file($this->ctx($r)->az(), (string) $r->params['id'], (int) $r->params['fid']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], str_starts_with($f['mime'], 'image/'))->withHeader('Cache-Control', 'private, no-store');
    }

    public function members(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $action = (string) $r->params['action'];
        try {
            $this->svc()->manage($this->ctx($r)->az(), $pid, $action, ['user' => (int) ($r->body['user'] ?? 0), 'title' => $this->str($r, 'title', 80)]);
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, '/chat/c/' . $pid);
        } catch (BusinessException $e) {
            return $this->fail($r, '/chat/c/' . $pid, $e);
        }
        $this->flash($r, 'success', 'chat.flash.group_' . $action);
        return $this->redirect($r, $action === 'leave' ? '/chat' : '/chat/c/' . $pid);
    }

    /** @param array<string, string> $errors */
    public function moderate(Request $r, array $errors = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $state = ($r->query['status'] ?? '') === 'done' ? 'done' : 'open';
        $reports = $this->svc()->reports($az, $state);
        return $this->fresh($this->page('@chat/moderate', ['title' => $this->app->translator()->get('chat.moderate.title'), 'reports' => $reports, 'state' => $state, 'errors' => $errors], $status));
    }

    public function handle(Request $r): Response
    {
        try {
            $this->svc()->handle($this->ctx($r)->az(), (string) $r->params['id'], $r->params['decision'] === 'uphold', $this->str($r, 'note', 500));
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, '/chat/moderate');
        } catch (BusinessException $e) {
            return $this->fail($r, '/chat/moderate', $e);
        }
        $this->flash($r, 'success', 'chat.flash.' . (string) $r->params['decision']);
        return $this->redirect($r, '/chat/moderate');
    }

    public function reportFile(Request $r): Response
    {
        $f = $this->svc()->reportFile($this->ctx($r)->az(), (string) $r->params['id'], (int) $r->params['fid']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], false)->withHeader('Cache-Control', 'private, no-store');
    }
}
