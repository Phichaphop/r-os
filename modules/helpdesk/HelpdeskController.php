<?php

declare(strict_types=1);

namespace Blue\Modules\Helpdesk;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** Helpdesk screens (M15 · 9B-1). */
final class HelpdeskController extends Controller
{
    private function svc(): HelpdeskService
    {
        return HelpdeskService::of($this->app);
    }

    private function t(string $k): string
    {
        return $this->app->translator()->get($k);
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} */
    private function company(Request $r, string $perm = 'helpdesk.use', ?string $want = null): array
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

    /** @return list<UploadedFile> */
    private function files(Request $r): array
    {
        return array_values(array_filter((array) ($r->files['files'] ?? []), static fn (mixed $f): bool => $f instanceof UploadedFile && $f->error !== UPLOAD_ERR_NO_FILE));
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

    private function isStaff(Request $r): bool
    {
        $az = $this->ctx($r)->az();
        return $this->svc()->companies($az, 'helpdesk.manage') !== [] || $this->app->db()->scalar('SELECT id FROM hd_members WHERE user_id = ? LIMIT 1', [$az->userId]) !== null;
    }

    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        return $this->fresh($this->page('@helpdesk/index', ['title' => $this->t('helpdesk.title'), 'rows' => $this->svc()->mine($az), 'unrated' => $this->svc()->unrated($az->userId),
            'staff' => $this->isStaff($r), 'canManage' => $this->svc()->companies($az, 'helpdesk.manage') !== []]));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function form(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $az = $this->ctx($r)->az();
        if ($old === []) { // a link from another screen may fill in the equipment, place and site (e.g. "report a fault" on an asset)
            foreach (['asset' => 120, 'location' => 160, 'site' => 26] as $k => $max) {
                if (is_string($r->query[$k] ?? null) && $r->query[$k] !== '') {
                    $old[$k] = mb_substr(trim($r->query[$k]), 0, $max);
                }
            }
        }
        return $this->fresh($this->page('@helpdesk/form', ['title' => $this->t('helpdesk.new_title'), 'companies' => $companies, 'lePid' => $lePid, 'categories' => $this->svc()->categories($le, true),
            'sites' => $this->app->db()->all("SELECT public_id, code FROM sites WHERE legal_entity_id = ? AND status = 'active' ORDER BY code", [$le]), 'unrated' => $this->svc()->unrated($az->userId),
            'staff' => $this->isStaff($r), 'canManage' => $this->svc()->companies($az, 'helpdesk.manage') !== [], 'errors' => $errors, 'old' => $old,
            'kbSuggest' => $this->app->modules()->isEnabled('kb')], $status));
    }

    public function open(Request $r): Response
    {
        [, , $le] = $this->company($r);
        try {
            $pid = $this->svc()->open($this->ctx($r)->az(), $le, $r->body, $this->files($r));
        } catch (ValidationException $e) {
            return $this->form($r, $this->errors($e), $r->body, 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/helpdesk', $e);
        }
        $this->flash($r, 'success', 'helpdesk.flash.opened');
        return $this->redirect($r, '/helpdesk/t/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function ticket(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $t = $this->svc()->ticket($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->fresh($this->page('@helpdesk/ticket', ['title' => (string) $t['ref'], 'tk' => $t, 'categories' => $t['lead'] ? $this->svc()->categories((int) $t['legal_entity_id'], true) : [],
            'nowAt' => $this->svc()->nowLocal()->format('Y-m-d\TH:i'), 'errors' => $errors, 'old' => $old, 'form' => $form], $status));
    }

    public function act(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        $s = $this->svc();
        try {
            if ($action === 'message') {
                $s->message($az, $pid, $this->str($r, 'body', 5000), $this->files($r), !empty($r->body['internal']));
            } elseif ($action === 'assign') {
                $s->assign($az, $pid, $r->body);
            } elseif ($action === 'priority') {
                $s->setPriority($az, $pid, $this->str($r, 'priority', 10), $this->str($r, 'priority_reason', 300));
            } elseif ($action === 'transfer') {
                $s->transfer($az, $pid, $this->str($r, 'category', 26), $this->str($r, 'transfer_reason', 300));
            } elseif ($action === 'status') {
                $s->setStatus($az, $pid, $this->str($r, 'status', 20), $this->str($r, 'status_note', 300));
            } elseif ($action === 'resolve') {
                $s->resolve($az, $pid, $this->str($r, 'resolution', 2000));
            } elseif ($action === 'confirm') {
                $s->confirm($az, $pid, $this->str($r, 'rating', 2), $this->str($r, 'rating_note', 500));
            } elseif ($action === 'rate') {
                $s->rate($az, $pid, $this->str($r, 'rating', 2), $this->str($r, 'rating_note', 500));
            } elseif ($action === 'reopen') {
                $s->reopen($az, $pid, $this->str($r, 'reopen_reason', 500));
            } else {
                $s->cancel($az, $pid, $this->str($r, 'cancel_reason', 300));
            }
        } catch (ValidationException $e) {
            return $this->ticket($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/helpdesk/t/' . $pid, $e);
        }
        $this->flash($r, 'success', 'helpdesk.flash.' . $action);
        return $this->redirect($r, '/helpdesk/t/' . $pid . ($action === 'message' ? '#hd-thread' : ''));
    }

    public function file(Request $r): Response
    {
        $f = $this->svc()->file($this->ctx($r)->az(), (string) $r->params['id'], (int) $r->params['fid']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], true)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function queue(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $q = $this->svc()->queue($az);
        if (!$q['member']) {
            throw HttpException::forbidden();
        }
        return $this->fresh($this->page('@helpdesk/queue', ['title' => $this->t('helpdesk.queue_title'), 'q' => $q, 'now' => $this->svc()->nowLocal()->format('Y-m-d H:i:s'),
            'staff' => true, 'canManage' => $this->svc()->companies($az, 'helpdesk.manage') !== []]));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function admin(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200, ?string $want = null): Response
    {
        [$companies, $lePid, $le] = $this->company($r, 'helpdesk.manage', $want);
        return $this->page('@helpdesk/admin', ['title' => $this->t('helpdesk.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'categories' => $this->svc()->categories($le),
            'policy' => $this->svc()->policy($le), 'errors' => $errors, 'old' => $old, 'form' => $form], $status);
    }

    public function adminPost(Request $r): Response
    {
        [, $lePid, $le] = $this->company($r, 'helpdesk.manage');
        $az = $this->ctx($r)->az();
        $what = (string) $r->params['what'];
        try {
            if ($what === 'category') {
                $this->svc()->saveCategory($az, $le, $r->body);
            } else {
                $this->svc()->savePolicy($az, $le, $r->body);
            }
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $r->body, $what, 422);
        }
        $this->flash($r, 'success', 'helpdesk.flash.saved_' . $what);
        return $this->redirect($r, '/helpdesk/admin?le=' . rawurlencode($lePid));
    }

    public function categoryPost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $c = $this->svc()->category($pid);
        $az = $this->ctx($r)->az();
        $le = (int) $c['legal_entity_id'];
        $lePid = (string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        $what = (string) $r->params['what'];
        try {
            if ($what === 'edit') {
                $this->svc()->saveCategory($az, $le, $r->body, $pid);
            } elseif ($what === 'member') {
                $this->svc()->addMember($az, $pid, $this->str($r, 'member', 120), $this->str($r, 'role', 10));
            } else {
                $this->svc()->removeMember($az, $pid, (int) ($r->body['user'] ?? 0));
            }
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $r->body + ['cat' => $pid], 'cat-' . $what, 422, $lePid);
        }
        $this->flash($r, 'success', 'helpdesk.flash.saved_category');
        return $this->redirect($r, '/helpdesk/admin?le=' . rawurlencode($lePid) . '#hc-' . $pid);
    }

    private function month(Request $r): string
    {
        $m = (string) ($r->query['month'] ?? '');
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) === 1 ? $m : $this->svc()->nowLocal()->format('Y-m');
    }

    public function report(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r, 'helpdesk.manage');
        $month = $this->month($r);
        return $this->page('@helpdesk/report', ['title' => $this->t('helpdesk.report_title'), 'companies' => $companies, 'lePid' => $lePid, 'month' => $month,
            'report' => $this->svc()->report($this->ctx($r)->az(), $le, $month), 'staff' => true, 'canManage' => true]);
    }

    public function export(Request $r): Response
    {
        [, , $le] = $this->company($r, 'helpdesk.manage');
        $f = $this->svc()->export($this->ctx($r)->az(), $le, $this->month($r));
        return DocumentsController::fileResponse($f['bytes'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $f['name'], false)->withHeader('Cache-Control', 'no-store, max-age=0');
    }
}
