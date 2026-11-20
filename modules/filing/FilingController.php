<?php

declare(strict_types=1);

namespace Blue\Modules\Filing;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** Government filing screens (M58 · 6E): the months of a year with their filings, one revision with its checks and actions. */
final class FilingController extends Controller
{
    private function svc(): FilingService
    {
        return FilingService::of($this->app);
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} companies the person may see, the chosen one */
    private function company(Request $r, string $perm = 'filing.view'): array
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

    private function year(Request $r): int
    {
        $y = (string) ($r->query['year'] ?? $r->body['year'] ?? '');
        $now = (int) substr($this->svc()->today(), 0, 4);
        return ctype_digit($y) && (int) $y >= $now - 5 && (int) $y <= $now ? (int) $y : $now;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function index(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $az = $this->ctx($r)->az();
        $year = $this->year($r);
        return $this->page('@filing/index', ['title' => $this->app->translator()->get('filing.title'), 'companies' => $companies, 'lePid' => $lePid, 'year' => $year,
            'months' => $this->svc()->overview($az, $le, $year), 'annual' => $this->svc()->annual($az, $le, $year), 'canPrepare' => $az->scope('filing.prepare')->coversLegalEntity($le), 'today' => $this->svc()->today(),
            'errors' => $errors, 'old' => $old], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function generate(Request $r): Response
    {
        [, $lePid, $le] = $this->company($r, 'filing.prepare');
        try {
            $pid = $this->svc()->generate($this->ctx($r)->az(), $le, $this->str($r, 'kind', 20), $this->str($r, 'period', 7), $this->str($r, 'reason', 500));
        } catch (ValidationException $e) {
            return $this->index($r, $this->errors($e), $r->body, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, $e->params);
            return $this->redirect($r, '/filing?le=' . rawurlencode($lePid));
        }
        $this->flash($r, 'success', 'filing.flash.generated');
        return $this->redirect($r, '/filing/r/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function show(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $rev = $this->svc()->revision($this->ctx($r)->az(), (string) $r->params['id']);
        $t = $this->app->translator();
        $official = null;
        if ($rev['can_prepare'] && in_array($rev['status'], FilingService::OFFICIAL_FROM, true) && !($rev['status'] === 'approved' && $rev['source_changed'])) {
            $official = $this->svc()->official($this->ctx($r)->az(), (string) $rev['public_id'], false);
        }
        return $this->page('@filing/revision', ['title' => $t->get('filing.kind.' . $rev['kind']) . ' ' . $rev['period'], 'rev' => $rev, 'today' => $this->svc()->today(), 'official' => $official,
            'errors' => $errors, 'old' => $old, 'form' => $form], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    private function act(Request $r, callable $fn, string $flash, string $form): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $fn($pid);
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $r->body, $form, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, $e->params);
            return $this->redirect($r, '/filing/r/' . $pid);
        }
        $this->flash($r, 'success', $flash);
        return $this->redirect($r, '/filing/r/' . $pid);
    }

    public function submit(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        return $this->act($r, fn (string $pid) => $this->svc()->submit($az, $pid, $this->str($r, 'note', 500)), 'filing.flash.submitted', 'submit');
    }

    public function export(Request $r): Response
    {
        $this->ctx($r)->requireFreshReauth();
        $f = $this->svc()->export($this->ctx($r)->az(), (string) $r->params['id']);
        return DocumentsController::fileResponse($f['bytes'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $f['name'], false)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    /** the official upload file (0.46.0) — password confirmed again; a revision with something missing goes back with the first problem */
    public function official(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $this->svc()->revision($this->ctx($r)->az(), $pid); // 404 for those who may not see it, before asking for the password
        $this->ctx($r)->requireFreshReauth();
        try {
            $f = $this->svc()->official($this->ctx($r)->az(), $pid);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, $e->params);
            return $this->redirect($r, '/filing/r/' . $pid);
        }
        if ($f['bytes'] === null) {
            $this->flash($r, 'danger', $f['problems'][0]['key'] ?? 'filing.official.blocked', $f['problems'][0]['params'] ?? []);
            return $this->redirect($r, '/filing/r/' . $pid . '#official');
        }
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['name'], false)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function submission(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        return $this->act($r, fn (string $pid) => $this->svc()->recordSubmission($az, $pid, $r->body, $r->file('receipt')), 'filing.flash.filed', 'submission');
    }

    public function result(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        return $this->act($r, fn (string $pid) => $this->svc()->recordResult($az, $pid, $this->str($r, 'result', 20), $this->str($r, 'note', 500)), 'filing.flash.result', 'result');
    }

    public function receipt(Request $r): Response
    {
        $f = $this->svc()->receipt($this->ctx($r)->az(), (string) $r->params['id']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], true)->withHeader('Cache-Control', 'no-store, max-age=0');
    }
}
