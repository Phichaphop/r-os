<?php

declare(strict_types=1);

namespace Blue\Modules\Library;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;
use Blue\Core\Ui\Paging;
use Blue\Modules\Employees\Audience;

/** Online library screens (M03 · 9D-3). */
final class LibraryController extends Controller
{
    private function svc(): LibraryService
    {
        return LibraryService::of($this->app);
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
        return $this->svc()->companies($this->ctx($r)->az(), 'library.manage') !== [];
    }

    private function fail(Request $r, string $to, BusinessException $e): Response
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
        $this->flash($r, 'danger', $e->messageKey, $e->params);
        return $this->redirect($r, $to);
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} companies (public id => code), chosen public id, its id */
    private function legalEntity(Request $r): array
    {
        $ids = $this->svc()->companies($this->ctx($r)->az(), 'library.manage');
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

    // ---- readers -------------------------------------------------------------------------------------------------------------

    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $le = $this->svc()->readerCompany($az);
        $f = ['q' => $this->str($r, 'q', 80), 'category' => $this->str($r, 'category', 26), 'kind' => $this->str($r, 'kind', 10), 'lang' => $this->str($r, 'lang', 5), 'sort' => $this->str($r, 'sort', 10)];
        $paging = Paging::from($r, $this->app->preferences(), $this->ctx($r)->userId(), 'library');
        $res = $le === null ? ['rows' => [], 'total' => 0] : $this->svc()->search($az, $le, $f, $paging->page, $paging->per);
        return $this->fresh($this->page('@library/index', ['title' => $this->t('library.title'), 'le' => $le, 'f' => $f, 'pg' => $paging->page, 'per' => $paging->per, 'res' => $res,
            'categories' => $le === null ? [] : $this->svc()->categories($le, true), 'recent' => $this->svc()->recent($az),
            'canManage' => $this->canManage($r), 'here' => '/library']));
    }

    /** @param array<string, string> $errors */
    public function item(Request $r, array $errors = [], string $form = '', int $status = 200, ?string $pid = null): Response
    {
        $item = $this->svc()->item($this->ctx($r)->az(), $pid ?? (string) $r->params['id'], $this->str($r, 'q', 80));
        return $this->fresh($this->page('@library/item', ['title' => (string) $item['title'], 'it' => $item, 'errors' => $errors, 'form' => $form, 'q' => $this->str($r, 'q', 80),
            'canManage' => $this->canManage($r), 'here' => '/library'], $status));
    }

    public function read(Request $r): Response
    {
        $item = $this->svc()->item($this->ctx($r)->az(), (string) $r->params['id']);
        if (!$item['can_read_online']) {
            throw HttpException::notFound();
        }
        $start = (int) ($r->query['page'] ?? ($item['read']['last_page'] ?? 1));
        return $this->fresh($this->page('@library/read', ['title' => (string) $item['title'], 'it' => $item, 'start' => max(1, $start),
            'canManage' => $this->canManage($r), 'here' => '/library']));
    }

    public function file(Request $r): Response
    {
        $download = ($r->params['mode'] ?? '') === 'download';
        try {
            $f = $this->svc()->file($this->ctx($r)->az(), (string) $r->params['id'], $download);
        } catch (BusinessException $e) {
            return $this->fail($r, '/library/i/' . $r->params['id'], $e);
        }
        $res = DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], !$download);
        if (!$download) {
            // the browser's own PDF viewer will not open under "sandbox" (the fallback link when the page reader cannot run)
            $res->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }
        return $res->withHeader('Cache-Control', 'private, no-store');
    }

    public function text(Request $r): Response
    {
        $page = max(1, (int) ($r->query['page'] ?? 1));
        $text = $this->svc()->pageText($this->ctx($r)->az(), (string) $r->params['id'], $page);
        return (new Response((string) json_encode(['page' => $page, 'text' => $text], JSON_UNESCAPED_UNICODE), 200, ['Content-Type' => 'application/json; charset=utf-8']))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    public function itemPost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $action = (string) $r->params['action'];
        $az = $this->ctx($r)->az();
        $s = $this->svc();
        $flash = 'library.flash.' . $action;
        try {
            switch ($action) {
                case 'progress':
                    $s->progress($az, $pid, (int) ($r->body['page'] ?? 0));
                    if ($r->header('x-requested-with') === 'fetch') {
                        return new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']);
                    }
                    break;
                case 'bookmark':
                    $s->bookmark($az, $pid, (int) ($r->body['page'] ?? 0), $this->str($r, 'note', 300));
                    break;
                case 'unbookmark':
                    $s->unbookmark($az, $pid, (int) ($r->body['bookmark'] ?? 0));
                    break;
                case 'reserve':
                    $flash = $s->reserve($az, $pid) === 'ready' ? 'library.flash.reserved_ready' : 'library.flash.reserved_waiting';
                    break;
            }
        } catch (ValidationException $e) {
            if ($action === 'progress') {
                return new Response('{"ok":false}', 422, ['Content-Type' => 'application/json']);
            }
            return $this->item($r, $this->errors($e), $action, 422, $pid);
        } catch (BusinessException $e) {
            return $this->fail($r, '/library/i/' . $pid, $e);
        }
        $this->flash($r, 'success', $flash);
        $back = $this->str($r, 'back', 10) === 'read' ? '/library/i/' . $pid . '/read?page=' . (int) ($r->body['page'] ?? 1) : '/library/i/' . $pid;
        return $this->redirect($r, $back);
    }

    public function loans(Request $r): Response
    {
        return $this->fresh($this->page('@library/loans', ['title' => $this->t('library.my_loans'), 'rows' => $this->svc()->myLoans($this->ctx($r)->az()),
            'today' => $this->svc()->today(), 'canManage' => $this->canManage($r), 'here' => '/library/loans']));
    }

    public function cancelMine(Request $r): Response
    {
        try {
            $this->svc()->cancelMine($this->ctx($r)->az(), (string) $r->params['id']);
        } catch (BusinessException $e) {
            return $this->fail($r, '/library/loans', $e);
        }
        $this->flash($r, 'success', 'library.flash.cancelled');
        return $this->redirect($r, '/library/loans');
    }

    // ---- librarians ----------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function manageList(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r);
        $f = ['q' => $this->str($r, 'q', 80), 'status' => $this->str($r, 'status', 10), 'kind' => $this->str($r, 'kind', 10)];
        return $this->fresh($this->page('@library/manage_list', ['title' => $this->t('library.manage_title'), 'companies' => $companies, 'lePid' => $lePid, 'f' => $f,
            'rows' => $this->svc()->manageList($this->ctx($r)->az(), $le, $f), 'categories' => $this->svc()->categories($le), 'errors' => $errors, 'old' => $old,
            'canManage' => true, 'here' => '/library/manage'], $status));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function newForm(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r);
        $audIn = $old !== [] ? $old : Audience::toInput($this->app, Audience::empty());
        return $this->fresh($this->page('@library/edit', ['title' => $this->t('library.new_item'), 'companies' => $companies, 'lePid' => $lePid,
            'categories' => $this->svc()->categories($le, true), 'audOptions' => Audience::options($this->app, $le), 'audIn' => $audIn,
            'errors' => $errors, 'old' => $old, 'canManage' => true, 'here' => '/library/manage'], $status));
    }

    public function create(Request $r): Response
    {
        [, , $le] = $this->legalEntity($r);
        try {
            $pid = $this->svc()->save($this->ctx($r)->az(), $le, $r->body);
        } catch (ValidationException $e) {
            return $this->newForm($r, $this->errors($e), $r->body, 422);
        }
        $this->flash($r, 'success', 'library.flash.created');
        return $this->redirect($r, '/library/m/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function manage(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $item = $this->svc()->manage($this->ctx($r)->az(), (string) $r->params['id']);
        $le = (int) $item['legal_entity_id'];
        $fill = $form === 'save' ? $old : $item + ['category' => $item['category_pid']];
        $audIn = $form === 'save' ? $old : $item['audience_in'];
        return $this->fresh($this->page('@library/manage', ['title' => (string) $item['title'], 'it' => $item, 'fill' => $fill, 'audIn' => $audIn,
            'audOptions' => Audience::options($this->app, $le), 'categories' => $this->svc()->categories($le, true), 'errors' => $errors, 'old' => $old, 'form' => $form,
            'canManage' => true, 'here' => '/library/manage'], $status));
    }

    public function managePost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $action = (string) $r->params['action'];
        $az = $this->ctx($r)->az();
        $s = $this->svc();
        $flash = 'library.flash.' . $action;
        $params = [];
        try {
            switch ($action) {
                case 'save':
                    $le = (int) $this->app->db()->scalar('SELECT legal_entity_id FROM lb_items WHERE public_id = ?', [$pid]);
                    $s->save($az, $le, $r->body, $pid);
                    break;
                case 'upload':
                    $file = $r->file('file');
                    $res = $s->upload($az, $pid, $file instanceof UploadedFile && $file->error !== UPLOAD_ERR_NO_FILE ? $file : null, $this->str($r, 'note', 400));
                    $flash = 'library.flash.uploaded_' . $res['text_status'];
                    $params = ['pages' => (string) ($res['pages'] ?? 0)];
                    break;
                case 'publish':
                    $s->publish($az, $pid);
                    break;
                case 'archive':
                    $s->archive($az, $pid, $this->str($r, 'reason', 600));
                    break;
                case 'restore':
                    $s->restore($az, $pid);
                    break;
                case 'copy':
                    $s->addCopy($az, $pid, $this->str($r, 'code', 60), $this->str($r, 'location', 200));
                    break;
                case 'lend':
                    $res = $s->lend($az, $pid, $this->str($r, 'who', 200), $this->str($r, 'copy', 60));
                    $flash = 'library.flash.lent';
                    $params = $res;
                    break;
                case 'return':
                    $res = $s->giveBack($az, $pid, $this->str($r, 'copy', 60));
                    $flash = $res['late_days'] > 0 ? 'library.flash.returned_late' : ($res['next'] ? 'library.flash.returned_next' : 'library.flash.returned');
                    $params = ['name' => $res['name'], 'days' => (string) $res['late_days']];
                    break;
            }
        } catch (ValidationException $e) {
            return $this->manage($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/library/m/' . $pid, $e);
        }
        $this->flash($r, 'success', $flash, $params);
        return $this->redirect($r, '/library/m/' . $pid . (in_array($action, ['lend', 'return', 'copy'], true) ? '#loans' : ''));
    }

    public function version(Request $r): Response
    {
        $f = $this->svc()->version($this->ctx($r)->az(), (string) $r->params['id'], (int) $r->params['no']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], false)->withHeader('Cache-Control', 'private, no-store');
    }

    public function copyPost(Request $r): Response
    {
        $item = (string) $this->app->db()->scalar('SELECT i.public_id FROM lb_copies k JOIN lb_items i ON i.id = k.item_id WHERE k.public_id = ?', [(string) $r->params['id']]);
        if ($item === '') {
            throw HttpException::notFound();
        }
        try {
            $this->svc()->withdrawCopy($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'reason', 400));
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, '/library/m/' . $item . '#copies');
        } catch (BusinessException $e) {
            return $this->fail($r, '/library/m/' . $item, $e);
        }
        $this->flash($r, 'success', 'library.flash.copy_withdrawn');
        return $this->redirect($r, '/library/m/' . $item . '#copies');
    }

    public function loanPost(Request $r): Response
    {
        $lp = (string) $r->params['id'];
        $item = (string) $this->app->db()->scalar('SELECT i.public_id FROM lb_loans l JOIN lb_items i ON i.id = l.item_id WHERE l.public_id = ?', [$lp]);
        if ($item === '') {
            throw HttpException::notFound();
        }
        $back = $this->str($r, 'back', 10) === 'desk' ? '/library/desk' : '/library/m/' . $item . '#loans';
        try {
            if ($r->params['action'] === 'lost') {
                $this->svc()->markLost($this->ctx($r)->az(), $lp, $this->str($r, 'reason', 600));
            } else {
                $this->svc()->cancelReservation($this->ctx($r)->az(), $lp, $this->str($r, 'reason', 600));
            }
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, $back);
        } catch (BusinessException $e) {
            return $this->fail($r, $back, $e);
        }
        $this->flash($r, 'success', 'library.flash.loan_' . $r->params['action']);
        return $this->redirect($r, $back);
    }

    public function desk(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r);
        return $this->fresh($this->page('@library/desk', ['title' => $this->t('library.desk_title'), 'companies' => $companies, 'lePid' => $lePid,
            'rows' => $this->svc()->desk($this->ctx($r)->az(), $le), 'today' => $this->svc()->today(), 'canManage' => true, 'here' => '/library/desk']));
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
            return $this->manageList($r, $this->errors($e), $r->body + ['_pid' => $pid ?? ''], 422);
        }
        $this->flash($r, 'success', 'library.flash.category_saved');
        return $this->redirect($r, '/library/manage?le=' . rawurlencode($lePid) . '#categories');
    }
}
