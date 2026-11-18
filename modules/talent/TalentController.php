<?php

declare(strict_types=1);

namespace Blue\Modules\Talent;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;
use Blue\Modules\Recruit\RecruitService;

/** M49 screens: pool list + search · add · profile (edit, status, star, contacts, CV, put forward, erase) · resume builder. */
final class TalentController extends Controller
{
    private function svc(): TalentService
    {
        return TalentService::of($this->app);
    }

    private function t(string $key): string
    {
        return $this->app->translator()->get($key);
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} companies (public id => code), chosen public id, chosen id */
    private function company(Request $r): array
    {
        $les = $this->svc()->companies($this->ctx($r)->az());
        if ($les === []) {
            throw HttpException::forbidden();
        }
        $companies = [];
        foreach ($les as $id) {
            $row = $this->app->db()->one('SELECT public_id, code FROM legal_entities WHERE id = ?', [$id]);
            $companies[(string) $row['public_id']] = (string) $row['code'];
        }
        $lePid = (string) ($r->query['le'] ?? $r->body['le'] ?? '');
        if (!isset($companies[$lePid])) {
            $lePid = (string) array_key_first($companies);
        }
        return [$companies, $lePid, (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid])];
    }

    public function index(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $f = [];
        foreach (['q' => 80, 'status' => 20, 'edu' => 20, 'years' => 2, 'starred' => 1, 'source' => 20] as $k => $max) {
            $f[$k] = mb_substr(trim((string) ($r->query[$k] ?? '')), 0, $max);
        }
        return $this->page('@talent/index', ['title' => $this->t('talent.title'), 'companies' => $companies, 'lePid' => $lePid, 'rows' => $this->svc()->search($this->ctx($r)->az(), $le, $f), 'f' => $f,
            'rule' => $this->svc()->retentionUntil($this->svc()->today()) !== null]);
    }

    /** @param array<string, string> $errors */
    public function add(Request $r, array $errors = [], int $status = 200): Response
    {
        [$companies, $lePid] = $this->company($r);
        return $this->page('@talent/new', ['title' => $this->t('talent.new_title'), 'companies' => $companies, 'lePid' => $lePid, 'errors' => $errors,
            'old' => $r->method === 'POST' ? $r->body : [], 'today' => $this->svc()->today(), 'until' => $this->svc()->retentionUntil($this->svc()->today())], $status);
    }

    public function create(Request $r): Response
    {
        [, , $le] = $this->company($r);
        $cv = ($r->files['cv'] ?? [])[0] ?? null;
        try {
            $res = $this->svc()->create($this->ctx($r)->az(), $le, $r->body, $cv instanceof UploadedFile ? $cv : null);
        } catch (ValidationException $e) {
            return $this->add($r, $this->errors($e), 422);
        }
        $this->flash($r, $res['merged'] ? 'info' : 'success', $res['merged'] ? 'talent.flash.merged' : 'talent.flash.created');
        return $this->redirect($r, '/talent/p/' . $res['profile']);
    }

    public function fromCandidate(Request $r): Response
    {
        try {
            $res = $this->svc()->fromCandidate($this->ctx($r)->az(), (string) $r->params['id']);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/recruit/c/' . $r->params['id']);
        }
        $this->flash($r, $res['merged'] ? 'info' : 'success', $res['merged'] ? 'talent.flash.merged' : 'talent.flash.created');
        return $this->redirect($r, '/talent/p/' . $res['profile']);
    }

    /** @param array<string, string> $errors */
    public function profile(Request $r, array $errors = [], string $form = '', int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $p = $this->svc()->profile($az, (string) $r->params['id']);
        $le = (int) $p['legal_entity_id'];
        return $this->page('@talent/profile', ['title' => $p['anonymized_at'] === null ? (string) $p['name'] : $this->t('talent.anonymized'), 'p' => $p, 'contacts' => $this->svc()->contacts((int) $p['id']),
            'apps' => $this->svc()->applications($p), 'matches' => $p['anonymized_at'] === null ? $this->svc()->matchingPostings($p) : [],
            'postings' => RecruitService::of($this->app)->linkablePostings($az, $le), 'canErase' => $az->scope('talent.manage')->coversLegalEntity($le),
            'errors' => $errors, 'form' => $form, 'old' => $r->method === 'POST' ? $r->body : []], $status);
    }

    private function act(Request $r, string $form, callable $do, string $flash): Response
    {
        try {
            $do();
        } catch (ValidationException $e) {
            return $this->profile($r, $this->errors($e), $form, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/talent/p/' . $r->params['id']);
        }
        $this->flash($r, 'success', $flash);
        return $this->redirect($r, '/talent/p/' . $r->params['id']);
    }

    public function update(Request $r): Response
    {
        return $this->act($r, 'edit', fn () => $this->svc()->update($this->ctx($r)->az(), (string) $r->params['id'], $r->body), 'talent.flash.saved');
    }

    public function status(Request $r): Response
    {
        return $this->act($r, 'status', fn () => $this->svc()->setStatus($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'status', 20)), 'talent.flash.saved');
    }

    public function star(Request $r): Response
    {
        return $this->act($r, 'star', fn () => $this->svc()->star($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'on', 1) === '1'), 'talent.flash.saved');
    }

    public function contact(Request $r): Response
    {
        return $this->act($r, 'contact', fn () => $this->svc()->logContact($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'channel', 20), (string) ($r->body['note'] ?? '')), 'talent.flash.contact');
    }

    public function cvUpload(Request $r): Response
    {
        $f = ($r->files['cv'] ?? [])[0] ?? null;
        return $this->act($r, 'cv', fn () => $this->svc()->uploadCv($this->ctx($r)->az(), (string) $r->params['id'], $f instanceof UploadedFile ? $f : null), 'talent.flash.cv');
    }

    public function cv(Request $r): Response
    {
        $f = $this->svc()->cv($this->ctx($r)->az(), (string) $r->params['id']);
        return DocumentsController::fileResponse((string) $f['bytes'], (string) $f['mime'], (string) $f['name'], false);
    }

    public function link(Request $r): Response
    {
        $cand = '';
        $res = $this->act($r, 'link', function () use ($r, &$cand): void {
            $cand = $this->svc()->link($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'posting', 26));
        }, 'talent.flash.linked');
        return $cand !== '' ? $this->redirect($r, '/recruit/c/' . $cand) : $res;
    }

    public function erase(Request $r): Response
    {
        return $this->act($r, 'erase', fn () => $this->svc()->erase($this->ctx($r)->az(), (string) $r->params['id'], (string) ($r->body['reason'] ?? '')), 'talent.flash.erased');
    }

    // ---- resume builder (anyone; nothing is stored) ----------------------------------------------------------------------

    public function builder(Request $r): Response
    {
        if (!$this->ctx($r)->can('talent.builder')) {
            throw HttpException::forbidden();
        }
        $in = $r->method === 'POST' ? ResumeBuilder::clean($r->body) : null;
        return $this->page('@talent/builder', ['title' => $this->t('talent.builder_title'), 'resume' => $in, 'old' => $r->method === 'POST' ? $r->body : []])
            ->withHeader('Cache-Control', 'no-store, max-age=0');
    }
}
