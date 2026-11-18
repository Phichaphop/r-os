<?php

declare(strict_types=1);

namespace Blue\Modules\Kaizen;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;
use Blue\Modules\Employees\Audience;

/** Suggestions / Kaizen and survey screens (M41 · 9D-1). */
final class KaizenController extends Controller
{
    private function kz(): KaizenService
    {
        return KaizenService::of($this->app);
    }

    private function sv(): SurveyService
    {
        return SurveyService::of($this->app);
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
        return ['canManage' => $this->kz()->companies($az, 'kaizen.manage') !== [], 'canSurvey' => $this->kz()->companies($az, 'kaizen.survey') !== []];
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} */
    private function legalEntity(Request $r, string $perm): array
    {
        $ids = $this->kz()->companies($this->ctx($r)->az(), $perm);
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

    // ---- ideas -----------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     * @param array<string, mixed>|null $sent
     */
    public function index(Request $r, array $errors = [], array $old = [], int $status = 200, ?array $sent = null): Response
    {
        $az = $this->ctx($r)->az();
        $le = $this->kz()->myCompany($az);
        $f = ['status' => (string) ($r->query['status'] ?? ''), 'sort' => (string) ($r->query['sort'] ?? ''), 'q' => $this->str($r, 'q', 60)];
        return $this->fresh($this->page('@kaizen/index', ['title' => $this->t('kaizen.title'), 'le' => $le, 'mine' => $this->kz()->mine($az),
            'board' => $le === null ? [] : $this->kz()->board($az, $le, $f), 'f' => $f, 'errors' => $errors, 'old' => $old, 'sent' => $sent, 'here' => '/kaizen'] + $this->roles($r), $status));
    }

    public function submit(Request $r): Response
    {
        $f = ($r->files['photo'] ?? [])[0] ?? null;
        $photo = $f instanceof UploadedFile && $f->error !== UPLOAD_ERR_NO_FILE ? $f : null;
        try {
            $out = $this->kz()->submit($this->ctx($r)->az(), $r->body, $photo);
        } catch (ValidationException $e) {
            return $this->index($r, $this->errors($e), $r->body, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/kaizen', $e);
        }
        if ($out['code'] !== null) {
            // the tracking code is shown once, on this response only — it is never stored in clear or put in a URL
            return $this->index($r, [], [], 200, $out);
        }
        $this->flash($r, 'success', 'kaizen.flash.sent', ['ref' => $out['ref']]);
        return $this->redirect($r, '/kaizen/i/' . $out['pid']);
    }

    /** @param array<string, mixed>|null $found */
    public function track(Request $r, ?array $found = null, bool $missed = false): Response
    {
        return $this->fresh($this->page('@kaizen/track', ['title' => $this->t('kaizen.track_title'), 'found' => $found, 'missed' => $missed, 'here' => '/kaizen'] + $this->roles($r)));
    }

    public function trackPost(Request $r): Response
    {
        $i = $this->kz()->track($this->str($r, 'code', 40));
        return $this->track($r, $i, $i === null);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function idea(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $i = $this->kz()->idea($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->fresh($this->page('@kaizen/idea', ['title' => (string) $i['title'], 'i' => $i, 'errors' => $errors, 'old' => $old, 'form' => $form, 'here' => $i['is_manager'] ? '/kaizen/admin' : '/kaizen'] + $this->roles($r), $status));
    }

    public function photo(Request $r): Response
    {
        $f = $this->kz()->photo($this->ctx($r)->az(), (string) $r->params['id']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], false)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function vote(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $on = $this->kz()->vote($this->ctx($r)->az(), $pid);
        } catch (BusinessException $e) {
            return $this->fail($r, '/kaizen/i/' . $pid, $e);
        }
        $this->flash($r, 'success', $on ? 'kaizen.flash.voted' : 'kaizen.flash.unvoted');
        $back = ($r->body['back'] ?? '') === 'board' ? '/kaizen' : '/kaizen/i/' . $pid;
        return $this->redirect($r, $back);
    }

    public function act(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $action = (string) $r->params['action'];
        try {
            $this->kz()->act($this->ctx($r)->az(), $pid, $action, $r->body);
        } catch (ValidationException $e) {
            return $this->idea($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/kaizen/i/' . $pid, $e);
        }
        $this->flash($r, 'success', 'kaizen.flash.' . $action);
        return $this->redirect($r, '/kaizen/i/' . $pid);
    }

    public function admin(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r, 'kaizen.manage');
        $f = ['status' => (string) ($r->query['status'] ?? 'open'), 'q' => $this->str($r, 'q', 60)];
        return $this->fresh($this->page('@kaizen/admin', ['title' => $this->t('kaizen.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'f' => $f,
            'rows' => $this->kz()->adminList($this->ctx($r)->az(), $le, $f), 'criteria' => $this->kz()->criteria($le, true), 'here' => '/kaizen/admin'] + $this->roles($r)));
    }

    public function report(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r, 'kaizen.manage');
        return $this->fresh($this->page('@kaizen/report', ['title' => $this->t('kaizen.report_title'), 'companies' => $companies, 'lePid' => $lePid,
            'rep' => $this->kz()->report($this->ctx($r)->az(), $le), 'here' => '/kaizen/report'] + $this->roles($r)));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function settings(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r, 'kaizen.manage');
        return $this->fresh($this->page('@kaizen/settings', ['title' => $this->t('kaizen.settings_title'), 'companies' => $companies, 'lePid' => $lePid,
            'criteria' => $this->kz()->criteria($le), 'errors' => $errors, 'old' => $old, 'here' => '/kaizen/settings'] + $this->roles($r), $status));
    }

    public function criterionPost(Request $r): Response
    {
        [, $lePid, $le] = $this->legalEntity($r, 'kaizen.manage');
        $pid = isset($r->params['id']) ? (string) $r->params['id'] : null;
        try {
            $this->kz()->saveCriterion($this->ctx($r)->az(), $le, $r->body, $pid);
        } catch (ValidationException $e) {
            return $this->settings($r, $this->errors($e), $r->body + ['_pid' => $pid ?? ''], 422);
        }
        $this->flash($r, 'success', 'kaizen.flash.saved');
        return $this->redirect($r, '/kaizen/settings?le=' . rawurlencode($lePid));
    }

    // ---- surveys ---------------------------------------------------------------------------------------------------------------

    public function surveys(Request $r): Response
    {
        return $this->fresh($this->page('@kaizen/surveys', ['title' => $this->t('kaizen.surveys_title'), 'rows' => $this->sv()->forMe($this->ctx($r)->az()), 'here' => '/kaizen/surveys'] + $this->roles($r)));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function survey(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $s = $this->sv()->forAnswer($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->fresh($this->page('@kaizen/survey', ['title' => (string) $s['title'], 's' => $s, 'errors' => $errors, 'old' => $old, 'here' => '/kaizen/surveys'] + $this->roles($r), $status));
    }

    public function answer(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->sv()->answer($this->ctx($r)->az(), $pid, $r->body);
        } catch (ValidationException $e) {
            return $this->survey($r, $this->errors($e), $r->body, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/kaizen/surveys/s/' . $pid, $e);
        }
        $this->flash($r, 'success', 'kaizen.flash.answered');
        return $this->redirect($r, '/kaizen/surveys');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function surveyAdmin(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r, 'kaizen.survey');
        $audIn = ['sites' => (array) ($old['sites'] ?? []), 'departments' => (array) ($old['departments'] ?? []), 'groups' => (string) ($old['groups'] ?? '')];
        return $this->fresh($this->page('@kaizen/survey_admin', ['title' => $this->t('kaizen.survey_admin_title'), 'companies' => $companies, 'lePid' => $lePid,
            'rows' => $this->sv()->adminList($this->ctx($r)->az(), $le), 'audOptions' => Audience::options($this->app, $le), 'audIn' => $audIn,
            'errors' => $errors, 'old' => $old, 'here' => '/kaizen/surveys/admin'] + $this->roles($r), $status));
    }

    public function surveyCreate(Request $r): Response
    {
        [, , $le] = $this->legalEntity($r, 'kaizen.survey');
        try {
            $pid = $this->sv()->save($this->ctx($r)->az(), $le, $r->body);
        } catch (ValidationException $e) {
            return $this->surveyAdmin($r, $this->errors($e), $r->body, 422);
        }
        $this->flash($r, 'success', 'kaizen.flash.saved');
        return $this->redirect($r, '/kaizen/surveys/m/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function surveyManage(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $s = $this->sv()->manage($this->ctx($r)->az(), (string) $r->params['id']);
        $audIn = $form === 'edit' ? ['sites' => (array) ($old['sites'] ?? []), 'departments' => (array) ($old['departments'] ?? []), 'groups' => (string) ($old['groups'] ?? '')] : $s['audience_in'];
        return $this->fresh($this->page('@kaizen/survey_manage', ['title' => (string) $s['title'], 's' => $s, 'audOptions' => Audience::options($this->app, (int) $s['legal_entity_id']), 'audIn' => $audIn,
            'errors' => $errors, 'old' => $old, 'form' => $form, 'here' => '/kaizen/surveys/admin'] + $this->roles($r), $status));
    }

    public function surveyPost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $what = (string) $r->params['what'];
        $az = $this->ctx($r)->az();
        try {
            if ($what === 'edit') {
                $s = $this->sv()->manage($az, $pid);
                $this->sv()->save($az, (int) $s['legal_entity_id'], $r->body, $pid);
            } elseif ($what === 'question') {
                $this->sv()->question($az, $pid, $r->body);
            } else {
                $this->sv()->transition($az, $pid, ['open' => 'open', 'close' => 'closed', 'analyze' => 'analyzed'][$what], (string) ($r->body['summary'] ?? ''));
            }
        } catch (ValidationException $e) {
            return $this->surveyManage($r, $this->errors($e), $r->body, $what, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/kaizen/surveys/m/' . $pid, $e);
        }
        $this->flash($r, 'success', 'kaizen.flash.survey_' . $what);
        return $this->redirect($r, $what === 'analyze' ? '/kaizen/surveys/m/' . $pid . '/results' : '/kaizen/surveys/m/' . $pid);
    }

    public function results(Request $r): Response
    {
        $s = $this->sv()->results($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->fresh($this->page('@kaizen/results', ['title' => (string) $s['title'], 's' => $s, 'here' => '/kaizen/surveys/admin'] + $this->roles($r)));
    }

    public function export(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $csv = $this->sv()->exportCsv($this->ctx($r)->az(), $pid);
        } catch (BusinessException $e) {
            return $this->fail($r, '/kaizen/surveys/m/' . $pid . '/results', $e);
        }
        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="survey-' . $pid . '.csv"', 'Cache-Control' => 'no-store']);
    }
}
