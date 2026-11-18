<?php

declare(strict_types=1);

namespace Blue\Modules\Announcements;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;
use Blue\Modules\Employees\Audience;

/** M19 screens: board + read / acknowledge / react · publishers: write, attach, submit, engagement, remind, withdraw. */
final class AnnouncementsController extends Controller
{
    private function svc(): AnnouncementService
    {
        return AnnouncementService::of($this->app);
    }

    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('announcements.read')) {
            throw HttpException::forbidden();
        }
        $q = mb_substr(trim((string) ($r->query['q'] ?? '')), 0, 80);
        $cat = mb_substr(trim((string) ($r->query['cat'] ?? '')), 0, 60);
        $view = in_array($r->query['view'] ?? '', ['unread', 'archive'], true) ? (string) $r->query['view'] : 'all';
        return $this->page('@announcements/index', [
            'title' => $this->app->translator()->get('announcements.title'), 'rows' => $this->svc()->board($az, $q, $cat, $view), 'categories' => $this->svc()->categories($az),
            'q' => $q, 'cat' => $cat, 'view' => $view, 'canPublish' => $this->svc()->companies($az) !== [], 'me' => $this->svc()->emp()->forUser($az->userId),
        ]);
    }

    public function show(Request $r): Response
    {
        $p = $this->svc()->view($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->page('@announcements/show', ['title' => (string) $p['title'], 'post' => $p]);
    }

    public function acknowledge(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $this->svc()->acknowledge($this->ctx($r)->az(), $pid);
        $this->flash($r, 'success', 'announcements.flash.acknowledged');
        return $this->redirect($r, '/announcements/p/' . $pid);
    }

    public function react(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $this->svc()->react($this->ctx($r)->az(), $pid, $this->str($r, 'reaction', 12));
        return $this->redirect($r, '/announcements/p/' . $pid . '#reactions');
    }

    public function go(Request $r): Response
    {
        $url = $this->svc()->click($this->ctx($r)->az(), (string) $r->params['id'], (string) $r->params['which']);
        return Response::redirect($url, 302)->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function attachment(Request $r): Response
    {
        $f = $this->svc()->attachment($this->ctx($r)->az(), (string) $r->params['id'], (string) $r->params['doc']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['name'], str_starts_with($f['mime'], 'image/'));
    }

    // ---- publishers -----------------------------------------------------------------------------------------------------

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
        return $this->page('@announcements/admin', [
            'title' => $this->app->translator()->get('announcements.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'rows' => $this->svc()->adminList($le),
            'audOptions' => Audience::options($this->app, $le), 'errors' => $errors, 'old' => $old + ['starts_at' => $this->svc()->local($this->svc()->nowUtc())], 'svc' => $this->svc(),
        ], $status);
    }

    /** @return array<string, mixed> */
    private function input(Request $r): array
    {
        $in = [];
        foreach (
            ['legal_entity' => 26, 'title' => 200, 'body' => 20000, 'title_en' => 200, 'body_en' => 20000, 'category' => 60, 'priority' => 10, 'pinned' => 1, 'starts_at' => 16, 'ends_at' => 16,
            'requires_ack' => 1, 'popup_seconds' => 2, 'send_email' => 1, 'video_url' => 500, 'link_url' => 500, 'groups' => 300] as $k => $max
        ) {
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
        $this->flash($r, 'success', 'announcements.flash.created');
        return $this->redirect($r, '/announcements/admin/p/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function manage(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $p = $this->svc()->managed($az, (string) $r->params['id']);
        $le = (int) $p['legal_entity_id'];
        $aud = Audience::decode((string) $p['audience_json']);
        $in = $old !== [] ? $old : array_merge($p, ['starts_at' => $this->svc()->local((string) $p['starts_at']), 'ends_at' => $this->svc()->local($p['ends_at'] !== null ? (string) $p['ends_at'] : null)], Audience::toInput($this->app, $aud));
        $inst = $p['workflow_instance_id'] !== null ? $this->app->db()->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$p['workflow_instance_id']]) : null;
        return $this->page('@announcements/manage', [
            'title' => (string) $p['title'], 'post' => $p, 'in' => $in, 'audOptions' => Audience::options($this->app, $le), 'audText' => Audience::describe($this->app, $aud),
            'attachments' => $this->app->db()->all('SELECT * FROM ann_attachments WHERE post_id = ? ORDER BY id', [$p['id']]), 'stats' => in_array($p['status'], ['published', 'expired', 'withdrawn'], true) ? $this->svc()->stats($az, (string) $p['public_id']) : null,
            'instancePid' => $inst, 'errors' => $errors, 'mail' => $this->app->mailer()->available(),
        ], $status);
    }

    public function update(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $in = $this->input($r);
        $p = $this->svc()->managed($this->ctx($r)->az(), $pid);
        try {
            $this->svc()->save($this->ctx($r)->az(), (int) $p['legal_entity_id'], $in, $pid);
        } catch (ValidationException $e) {
            return $this->manage($r, $this->errors($e), $in, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->manage($r, ['title' => $this->app->translator()->get($e->messageKey)], $in, 409);
        }
        $this->flash($r, 'success', 'announcements.flash.saved');
        return $this->redirect($r, '/announcements/admin/p/' . $pid);
    }

    public function attach(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $file = ($r->files['attachment'] ?? [])[0] ?? null;
        try {
            $this->svc()->attach($this->ctx($r)->az(), $pid, $file instanceof UploadedFile ? $file : null);
        } catch (ValidationException $e) {
            return $this->manage($r, $this->errors($e), [], 422);
        }
        $this->flash($r, 'success', 'announcements.flash.attached');
        return $this->redirect($r, '/announcements/admin/p/' . $pid);
    }

    public function action(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->input('action', '');
        try {
            match ($action) {
                'submit' => $this->svc()->submit($az, $pid),
                'withdraw' => $this->svc()->withdraw($az, $pid, $this->str($r, 'reason', 255)),
                'detach' => $this->svc()->detach($az, $pid, $this->str($r, 'doc', 26)),
                'remind' => $this->flash($r, 'success', 'announcements.flash.reminded', ['n' => (string) $this->svc()->remind($az, $pid)]),
                default => throw HttpException::notFound(),
            };
        } catch (ValidationException $e) {
            return $this->manage($r, $this->errors($e), [], 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/announcements/admin/p/' . $pid);
        }
        if ($action !== 'remind') {
            $this->flash($r, 'success', 'announcements.flash.' . $action);
        }
        return $this->redirect($r, '/announcements/admin/p/' . $pid);
    }
}
