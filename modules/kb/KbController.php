<?php

declare(strict_types=1);

namespace Blue\Modules\Kb;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** Q&A / knowledge base screens (M55 · 9D-1). */
final class KbController extends Controller
{
    private function svc(): KbService
    {
        return KbService::of($this->app);
    }

    private function t(string $k): string
    {
        return $this->app->translator()->get($k);
    }

    private function fresh(Response $res): Response
    {
        return $res->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    /** @return array<string, bool> */
    private function roles(Request $r): array
    {
        $az = $this->ctx($r)->az();
        return ['canAuthor' => $this->svc()->companies($az, 'kb.author') !== [], 'canReview' => $this->svc()->companies($az, 'kb.review') !== []];
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} */
    private function legalEntity(Request $r, string $perm): array
    {
        $ids = $this->svc()->companies($this->ctx($r)->az(), $perm);
        if ($ids === []) {
            throw HttpException::forbidden();
        }
        $companies = [];
        foreach ($this->app->db()->all('SELECT public_id, code FROM legal_entities WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY code') as $row) {
            $companies[(string) $row['public_id']] = (string) $row['code'];
        }
        $pid = (string) ($r->query['le'] ?? $r->body['le'] ?? '');
        if (!isset($companies[$pid])) {
            $pid = (string) array_key_first($companies);
        }
        return [$companies, $pid, (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$pid])];
    }

    private function fail(Request $r, string $to, BusinessException $e): Response
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
        $this->flash($r, 'danger', $e->messageKey, $e->params);
        return $this->redirect($r, $to);
    }

    // ---- reading -------------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function index(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $le = $this->svc()->readerCompany($az);
        $q = $this->str($r, 'q', 100);
        $cat = $this->str($r, 'category', 26);
        $searching = $q !== '' || $cat !== '';
        return $this->fresh($this->page('@kb/index', ['title' => $this->t('kb.title'), 'le' => $le, 'q' => $q, 'cat' => $cat, 'searching' => $searching,
            'categories' => $le === null ? [] : $this->svc()->categories($le, true), 'results' => $le === null || !$searching ? [] : $this->svc()->search($le, $q, $cat !== '' ? $cat : null),
            'popular' => $le === null ? [] : $this->svc()->popular($le, 8), 'mine' => $this->svc()->myQuestions($az), 'errors' => $errors, 'old' => $old, 'here' => '/kb'] + $this->roles($r), $status));
    }

    /** Up to five published answers matching what someone is typing (the help desk form asks this). */
    public function suggest(Request $r): Response
    {
        $le = $this->svc()->readerCompany($this->ctx($r)->az());
        $q = $this->str($r, 'q', 100);
        $rows = $le === null || mb_strlen($q) < 3 ? [] : $this->svc()->search($le, $q, null, 5);
        return $this->fresh($this->page('@kb/_suggest', ['rows' => $rows, 'q' => $q], 200, null));
    }

    public function ask(Request $r): Response
    {
        try {
            $this->svc()->ask($this->ctx($r)->az(), $r->body);
        } catch (ValidationException $e) {
            return $this->index($r, $this->errors($e), $r->body, 422);
        }
        $this->flash($r, 'success', 'kb.flash.asked');
        return $this->redirect($r, '/kb');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function article(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $a = $this->svc()->read($this->ctx($r)->az(), (string) $r->params['id']);
        $en = $this->app->translator()->locale() === 'en' && $a['rev']['question_en'] !== null;
        return $this->fresh($this->page('@kb/article', ['title' => (string) ($en ? $a['rev']['question_en'] : $a['rev']['question_th']), 'a' => $a, 'en' => $en, 'errors' => $errors, 'old' => $old, 'here' => '/kb'] + $this->roles($r), $status));
    }

    public function feedback(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->feedback($this->ctx($r)->az(), $pid, ($r->body['helpful'] ?? '') === '1', $this->str($r, 'comment', 600));
        } catch (ValidationException $e) {
            return $this->article($r, $this->errors($e), $r->body, 422);
        }
        $this->flash($r, 'success', 'kb.flash.feedback');
        return $this->redirect($r, '/kb/a/' . $pid);
    }

    public function file(Request $r): Response
    {
        $f = $this->svc()->file($this->ctx($r)->az(), (string) $r->params['id'], (int) $r->params['fid']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], true)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    // ---- authoring -----------------------------------------------------------------------------------------------------------------

    public function manage(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r, 'kb.author');
        $view = ($r->query['view'] ?? '') === 'questions' ? 'questions' : 'articles';
        $f = ['status' => (string) ($r->query['status'] ?? ''), 'q' => $this->str($r, 'q', 100)];
        $az = $this->ctx($r)->az();
        return $this->fresh($this->page('@kb/manage', ['title' => $this->t('kb.manage_title'), 'companies' => $companies, 'lePid' => $lePid, 'view' => $view, 'f' => $f,
            'rows' => $view === 'articles' ? $this->svc()->adminList($az, $le, $f) : [], 'questions' => $this->svc()->questions($az, $le), 'categories' => $this->svc()->categories($le, true),
            'here' => '/kb/manage'] + $this->roles($r)));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function newForm(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r, 'kb.author');
        $question = null;
        $qp = $this->str($r, 'question', 26);
        if ($qp !== '') {
            $question = $this->svc()->question($this->ctx($r)->az(), $qp);
            if ($old === []) {
                $old = ['question_th' => mb_substr((string) $question['text'], 0, 300)];
            }
        }
        return $this->fresh($this->page('@kb/edit', ['title' => $this->t('kb.new_article'), 'companies' => $companies, 'lePid' => $lePid, 'categories' => $this->svc()->categories($le, true),
            'article' => null, 'question' => $question, 'errors' => $errors, 'old' => $old, 'here' => '/kb/manage'] + $this->roles($r), $status));
    }

    public function create(Request $r): Response
    {
        [, , $le] = $this->legalEntity($r, 'kb.author');
        $qp = $this->str($r, 'question', 26);
        try {
            $pid = $this->svc()->create($this->ctx($r)->az(), $le, $r->body, $qp !== '' ? $qp : null);
        } catch (ValidationException $e) {
            return $this->newForm($r, $this->errors($e), $r->body, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/kb/manage', $e);
        }
        $this->flash($r, 'success', 'kb.flash.saved');
        return $this->redirect($r, '/kb/manage/a/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function manageArticle(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $a = $this->svc()->manage($this->ctx($r)->az(), (string) $r->params['id']);
        $base = $a['working'] ?? $a['published'];
        $fill = $form === 'edit' ? $old : ['category' => (string) $a['category']['public_id']] + array_intersect_key((array) $base, array_flip(['question_th', 'answer_th', 'question_en', 'answer_en', 'tags', 'source']))
            + ['change_reason' => $a['working'] !== null ? (string) ($a['working']['change_reason'] ?? '') : ''];
        return $this->fresh($this->page('@kb/article_manage', ['title' => (string) ($base['question_th'] ?? ''), 'a' => $a, 'categories' => $this->svc()->categories((int) $a['legal_entity_id'], true),
            'fill' => $fill, 'me' => $this->ctx($r)->az()->userId, 'errors' => $errors, 'old' => $old, 'form' => $form, 'here' => '/kb/manage'] + $this->roles($r), $status));
    }

    public function articlePost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $action = (string) $r->params['action'];
        $az = $this->ctx($r)->az();
        $s = $this->svc();
        $params = [];
        try {
            switch ($action) {
                case 'edit':
                    $s->edit($az, $pid, $r->body);
                    break;
                case 'submit':
                    $s->submit($az, $pid, $this->str($r, 'review_due', 10));
                    break;
                case 'approve':
                case 'return':
                    $s->decide($az, $pid, $action === 'approve', $this->str($r, 'note', 1000));
                    break;
                case 'withdraw':
                    $params['n'] = (string) $s->withdraw($az, $pid, $this->str($r, 'reason', 500), !empty($r->body['tell']));
                    break;
                case 'owner':
                    $s->changeOwner($az, $pid, $this->str($r, 'owner', 60));
                    break;
                default:
                    $f = ($r->files['file'] ?? [])[0] ?? null;
                    $s->attach($az, $pid, $f instanceof UploadedFile && $f->error !== UPLOAD_ERR_NO_FILE ? $f : null);
            }
        } catch (ValidationException $e) {
            return $this->manageArticle($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/kb/manage/a/' . $pid, $e);
        }
        $this->flash($r, 'success', 'kb.flash.' . $action, $params);
        return $this->redirect($r, '/kb/manage/a/' . $pid);
    }

    public function decline(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->decline($this->ctx($r)->az(), $pid, $this->str($r, 'reply', 1000));
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, '/kb/manage?view=questions');
        } catch (BusinessException $e) {
            return $this->fail($r, '/kb/manage?view=questions', $e);
        }
        $this->flash($r, 'success', 'kb.flash.declined');
        return $this->redirect($r, '/kb/manage?view=questions');
    }

    // ---- settings ------------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function settings(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r, 'kb.review');
        return $this->fresh($this->page('@kb/settings', ['title' => $this->t('kb.settings_title'), 'companies' => $companies, 'lePid' => $lePid, 'categories' => $this->svc()->categories($le),
            'synonyms' => $old['synonyms'] ?? $this->svc()->synonyms($le), 'errors' => $errors, 'old' => $old, 'here' => '/kb/settings'] + $this->roles($r), $status));
    }

    public function categoryPost(Request $r): Response
    {
        [, $lePid, $le] = $this->legalEntity($r, 'kb.review');
        $pid = isset($r->params['id']) ? (string) $r->params['id'] : null;
        try {
            $this->svc()->saveCategory($this->ctx($r)->az(), $le, $r->body, $pid);
        } catch (ValidationException $e) {
            return $this->settings($r, $this->errors($e), $r->body + ['_pid' => $pid ?? ''], 422);
        }
        $this->flash($r, 'success', 'kb.flash.saved');
        return $this->redirect($r, '/kb/settings?le=' . rawurlencode($lePid));
    }

    public function synonymsPost(Request $r): Response
    {
        [, $lePid, $le] = $this->legalEntity($r, 'kb.review');
        try {
            $this->svc()->saveSynonyms($this->ctx($r)->az(), $le, (string) ($r->body['synonyms'] ?? ''));
        } catch (ValidationException $e) {
            return $this->settings($r, $this->errors($e), $r->body + ['_pid' => '-'], 422);
        }
        $this->flash($r, 'success', 'kb.flash.saved');
        return $this->redirect($r, '/kb/settings?le=' . rawurlencode($lePid));
    }
}
