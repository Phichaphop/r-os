<?php

declare(strict_types=1);

namespace Blue\Modules\Recruit;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;
use Blue\Modules\Employees\EmployeeService;

/** M08 screens: public jobs + application + status check + evaluator link · requisitions · postings · pipeline · candidate · settings. */
final class RecruitController extends Controller
{
    private function svc(): RecruitService
    {
        return RecruitService::of($this->app);
    }

    private function t(string $key): string
    {
        return $this->app->translator()->get($key);
    }

    // ---- public ----------------------------------------------------------------------------------------------------------

    public function jobs(Request $r): Response
    {
        if (!$this->ctx($r)->can('recruit.apply')) {
            throw HttpException::forbidden();
        }
        $q = mb_substr(trim((string) ($r->query['q'] ?? '')), 0, 80);
        return $this->page('@recruit/jobs', ['title' => $this->t('recruit.jobs_title'), 'rows' => $this->svc()->openPostings($q), 'q' => $q]);
    }

    public function job(Request $r): Response
    {
        if (!$this->ctx($r)->can('recruit.apply')) {
            throw HttpException::forbidden();
        }
        $p = $this->svc()->openPosting((string) $r->params['id']);
        return $this->page('@recruit/job', ['title' => (string) $p['title'], 'job' => $p]);
    }

    /** @param array<string, string> $errors */
    public function apply(Request $r, array $errors = [], int $status = 200): Response
    {
        if (!$this->ctx($r)->can('recruit.apply')) {
            throw HttpException::forbidden();
        }
        $p = $this->svc()->openPosting((string) $r->params['id']);
        return $this->page('@recruit/apply', ['title' => $this->t('recruit.apply_title') . ' · ' . $p['title'], 'job' => $p, 'errors' => $errors, 'old' => $r->method === 'POST' ? $r->body : []], $status)
            ->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function submit(Request $r): Response
    {
        if (!$this->ctx($r)->can('recruit.apply')) {
            throw HttpException::forbidden();
        }
        if (trim((string) ($r->body['website'] ?? '')) !== '') {
            throw HttpException::notFound(); // honeypot: people never fill a field they cannot see
        }
        $files = [];
        foreach (array_keys(Lists::DOC_GROUPS) as $g) {
            $f = ($r->files['file_' . $g] ?? [])[0] ?? null;
            $files[$g] = $f instanceof UploadedFile ? $f : null;
        }
        try {
            $code = $this->svc()->apply((string) $r->params['id'], $r->body, $files, $r->ip);
        } catch (ValidationException $e) {
            return $this->apply($r, $this->errors($e), 422);
        }
        $p = $this->svc()->openPosting((string) $r->params['id']);
        return $this->page('@recruit/applied', ['title' => $this->t('recruit.applied_title'), 'code' => $code, 'job' => $p])->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function status(Request $r): Response
    {
        if (!$this->ctx($r)->can('recruit.apply')) {
            throw HttpException::forbidden();
        }
        $result = null;
        $tried = false;
        if ($r->method === 'POST') {
            $tried = true;
            $result = $this->svc()->statusCheck($this->str($r, 'code', 12), $this->str($r, 'phone', 30));
            $this->app->audit()->record('recruit.status_checked', $result !== null ? 'success' : 'denied', 'rec_candidate', null, null);
        }
        return $this->page('@recruit/status', ['title' => $this->t('recruit.status_title'), 'result' => $result, 'tried' => $tried, 'code' => $this->str($r, 'code', 12)])
            ->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    /** @param array<string, string> $errors */
    public function evaluate(Request $r, array $errors = [], int $status = 200, bool $done = false): Response
    {
        $ev = $this->svc()->evaluation((string) $r->params['token']);
        return $this->page('@recruit/evaluate', ['title' => $this->t('recruit.eval_title'), 'ev' => $ev, 'errors' => $errors, 'done' => $done, 'token' => (string) $r->params['token']], $status)
            ->withHeader('Cache-Control', 'no-store, max-age=0')->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function evaluateSubmit(Request $r): Response
    {
        try {
            $this->svc()->submitEvaluation((string) $r->params['token'], $this->str($r, 'score', 1), $this->str($r, 'recommendation', 10), $this->str($r, 'comment', 2000));
        } catch (ValidationException $e) {
            return $this->evaluate($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->evaluate($r, ['form' => $this->t($e->messageKey)], $e->httpStatus);
        }
        return $this->evaluate($r, [], 200, true);
    }

    // ---- requisitions ----------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function requisitions(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('recruit.request') && $this->svc()->companies($az) === []) {
            throw HttpException::forbidden();
        }
        $ctx = $this->svc()->requestContext($az);
        $depts = [];
        if ($ctx['free']) {
            foreach ($this->svc()->companies($az) as $le) {
                foreach ($this->app->db()->all("SELECT d.public_id, d.code, l.code AS le FROM departments d JOIN legal_entities l ON l.id = d.legal_entity_id WHERE d.legal_entity_id = ? AND d.status = 'active' ORDER BY d.code", [$le]) as $d) {
                    $depts[(string) $d['public_id']] = $d['le'] . ' · ' . $d['code'];
                }
            }
        }
        $myUnit = $ctx['department'] !== null ? (string) $this->app->db()->scalar('SELECT code FROM departments WHERE id = ?', [$ctx['department']]) : '';
        return $this->page('@recruit/requisitions', ['title' => $this->t('recruit.req_title'), 'rows' => $this->svc()->requisitions($az), 'canRequest' => $az->can('recruit.request'),
            'ctx' => $ctx, 'depts' => $depts, 'myUnit' => $myUnit, 'errors' => $errors, 'old' => $old], $status);
    }

    public function createRequisition(Request $r): Response
    {
        $in = [];
        foreach (['department' => 26, 'position_title' => 120, 'headcount' => 3, 'reason' => 12, 'replaces' => 500, 'employment_type' => 20, 'salary_min' => 20, 'salary_max' => 20, 'needed_by' => 10, 'justification' => 1000] as $k => $max) {
            $in[$k] = $this->str($r, $k, $max);
        }
        try {
            $pid = $this->svc()->createRequisition($this->ctx($r)->az(), $in);
        } catch (ValidationException $e) {
            return $this->requisitions($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'recruit.flash.requisition');
        return $this->redirect($r, '/recruit/r/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function requisition(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $req = $this->svc()->requisition($az, (string) $r->params['id']);
        $db = $this->app->db();
        return $this->page('@recruit/requisition', ['title' => (string) $req['position_title'], 'req' => $req, 'postings' => $this->svc()->postings((int) $req['id']), 'budget' => $this->svc()->budgetNote((int) $req['id']),
            'recruiter' => $az->scope('recruit.manage')->coversLegalEntity((int) $req['legal_entity_id']),
            'unit' => trim((string) $db->scalar('SELECT l.code FROM legal_entities l WHERE l.id = ?', [$req['legal_entity_id']]) . ' · ' . (string) ($db->scalar('SELECT code FROM departments WHERE id = ?', [$req['department_id']]) ?? ''), ' ·'),
            'requester' => (string) ($db->scalar('SELECT display_name FROM users WHERE id = ?', [$req['requester_user_id']]) ?? ''),
            'notice' => $this->svc()->settings((int) $req['legal_entity_id'])['notice_text'] !== '', 'errors' => $errors, 'old' => $old], $status);
    }

    /** @return array<string, string> */
    private function postingInput(Request $r): array
    {
        return ['title' => $this->str($r, 'title', 120), 'description' => mb_substr((string) ($r->body['description'] ?? ''), 0, 8000), 'location' => $this->str($r, 'location', 120), 'closes_on' => $this->str($r, 'closes_on', 10)];
    }

    public function createPosting(Request $r): Response
    {
        $in = $this->postingInput($r);
        try {
            $pid = $this->svc()->savePosting($this->ctx($r)->az(), (string) $r->params['id'], $in);
        } catch (ValidationException $e) {
            return $this->requisition($r, $this->errors($e), $in, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/recruit/r/' . $r->params['id']);
        }
        $this->flash($r, 'success', 'recruit.flash.posting');
        return $this->redirect($r, '/recruit/p/' . $pid);
    }

    // ---- postings and pipeline --------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function posting(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $p = $this->svc()->posting($az, (string) $r->params['id']);
        $stage = (string) ($r->query['stage'] ?? '');
        return $this->page('@recruit/posting', ['title' => (string) $p['title'], 'p' => $p, 'rows' => $this->svc()->pipeline($az, (string) $p['public_id'], $stage), 'stage' => $stage,
            'recruiter' => $az->scope('recruit.manage')->coversLegalEntity((int) $p['legal_entity_id']), 'errors' => $errors, 'old' => $old], $status);
    }

    public function updatePosting(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $p = $this->svc()->posting($az, (string) $r->params['id']);
        $in = $this->postingInput($r);
        try {
            $this->svc()->savePosting($az, (string) $p['req_pid'], $in, (string) $p['public_id']);
        } catch (ValidationException $e) {
            return $this->posting($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'recruit.flash.saved');
        return $this->redirect($r, '/recruit/p/' . $p['public_id']);
    }

    public function postingStatus(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $to = $r->input('to') === 'closed' ? 'closed' : 'published';
        try {
            $this->svc()->setPostingStatus($this->ctx($r)->az(), $pid, $to);
            $this->flash($r, 'success', 'recruit.flash.' . $to);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/recruit/p/' . $pid);
    }

    // ---- candidate ------------------------------------------------------------------------------------------------------

    /** @param array<string, string> $errors */
    public function candidate(Request $r, array $errors = [], string $form = '', int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $c = $this->svc()->candidate($az, (string) $r->params['id']);
        $id = (int) $c['id'];
        $le = (int) $c['legal_entity_id'];
        $managers = [];
        if ($c['recruiter']) {
            foreach ($this->app->db()->all("SELECT public_id, employee_code, name_th FROM employees WHERE status = 'active' AND id IN (SELECT employee_id FROM employment_history WHERE legal_entity_id = ? AND effective_to IS NULL) ORDER BY employee_code LIMIT 500", [$le]) as $m) {
                $managers[(string) $m['public_id']] = $m['employee_code'] . ' · ' . $m['name_th'];
            }
        }
        $token = $this->store($r)->get('rec_eval_link');
        $this->store($r)->forget('rec_eval_link');
        return $this->page('@recruit/candidate', ['title' => (string) $c['name'], 'c' => $c, 'events' => $this->svc()->events($id), 'files' => $this->svc()->files($id),
            'interviews' => $this->svc()->interviews($id), 'offers' => $this->svc()->offers($id), 'ready' => $this->svc()->readiness($id),
            'required' => $this->svc()->settings($le)['required_docs'], 'managers' => $managers, 'evalLink' => is_string($token) ? $this->origin($r) . '/recruit/eval/' . $token : null,
            'empPid' => $c['employee_id'] !== null ? (string) $this->app->db()->scalar('SELECT public_id FROM employees WHERE id = ?', [$c['employee_id']]) : null,
            'tz' => $this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'),
            'talent' => $this->app->modules()->isEnabled('talent'), 'canErase' => $az->scope('recruit.settings')->coversLegalEntity($le), 'today' => $this->svc()->today(),
            'errors' => $errors, 'form' => $form], $status);
    }

    /** Absolute base URL for the evaluator link (sent to someone else). */
    private function origin(Request $r): string
    {
        $configured = rtrim($this->app->config->string('APP_URL'), '/');
        return $configured !== '' ? $configured : ($r->secure ? 'https' : 'http') . '://' . $r->host . $r->basePath();
    }

    /** Runs a recruiter action on a candidate and returns to its page. */
    private function act(Request $r, string $form, callable $do, string $flash): Response
    {
        try {
            $do();
        } catch (ValidationException $e) {
            return $this->candidate($r, $this->errors($e), $form, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/recruit/c/' . $r->params['id']);
        }
        $this->flash($r, 'success', $flash);
        return $this->redirect($r, '/recruit/c/' . $r->params['id']);
    }

    public function move(Request $r): Response
    {
        return $this->act($r, 'move', fn () => $this->svc()->move($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'to', 20), $this->str($r, 'note', 500)), 'recruit.flash.moved');
    }

    public function note(Request $r): Response
    {
        return $this->act($r, 'note', fn () => $this->svc()->note($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'note', 500)), 'recruit.flash.note');
    }

    public function upload(Request $r): Response
    {
        $f = ($r->files['file'] ?? [])[0] ?? null;
        return $this->act($r, 'file', fn () => $this->svc()->uploadFile($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'group', 20), $f instanceof UploadedFile ? $f : null), 'recruit.flash.file');
    }

    public function verify(Request $r): Response
    {
        return $this->act($r, 'file', fn () => $this->svc()->verifyFile($this->ctx($r)->az(), (string) $r->params['id'], (int) $r->params['f']), 'recruit.flash.verified');
    }

    public function file(Request $r): Response
    {
        $f = $this->svc()->fileRead($this->ctx($r)->az(), (string) $r->params['id'], (int) $r->params['f']);
        return DocumentsController::fileResponse((string) $f['bytes'], (string) $f['mime'], (string) $f['name'], false);
    }

    public function schedule(Request $r): Response
    {
        return $this->act($r, 'schedule', fn () => $this->svc()->schedule($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'kind', 20), $this->str($r, 'date', 10), $this->str($r, 'time', 5), $this->str($r, 'location', 200)), 'recruit.flash.scheduled');
    }

    public function evaluator(Request $r): Response
    {
        $cand = (string) $this->app->db()->scalar('SELECT c.public_id FROM rec_interviews i JOIN rec_candidates c ON c.id = i.candidate_id WHERE i.public_id = ?', [(string) $r->params['i']]);
        if ($cand === '' || $cand !== (string) $r->params['id']) {
            throw HttpException::notFound();
        }
        return $this->act($r, 'evaluator', function () use ($r): void {
            $token = $this->svc()->addEvaluator($this->ctx($r)->az(), (string) $r->params['i'], $this->str($r, 'evaluator', 120));
            $this->store($r)->set('rec_eval_link', $token); // shown once on the next page
        }, 'recruit.flash.evaluator');
    }

    public function offer(Request $r): Response
    {
        $in = ['position_title' => $this->str($r, 'position_title', 120), 'salary' => $this->str($r, 'salary', 20), 'start_date' => $this->str($r, 'start_date', 10),
            'employment_type' => $this->str($r, 'employment_type', 20), 'note' => $this->str($r, 'note', 1000)];
        return $this->act($r, 'offer', fn () => $this->svc()->offer($this->ctx($r)->az(), (string) $r->params['id'], $in), 'recruit.flash.offer');
    }

    public function answer(Request $r): Response
    {
        return $this->act($r, 'answer', fn () => $this->svc()->offerAnswer($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'answer', 10), $this->str($r, 'note', 500)), 'recruit.flash.answer');
    }

    public function hire(Request $r): Response
    {
        $in = ['employee_code' => $this->str($r, 'employee_code', 30), 'employee_group' => $this->str($r, 'employee_group', 20), 'manager' => $this->str($r, 'manager', 26)];
        try {
            $res = $this->svc()->hire($this->ctx($r)->az(), (string) $r->params['id'], $in);
        } catch (ValidationException $e) {
            return $this->candidate($r, $this->errors($e), 'hire', 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/recruit/c/' . $r->params['id']);
        }
        $this->flash($r, $res['warnings'] === [] ? 'success' : 'warning', $res['warnings'] === [] ? 'recruit.flash.hired' : 'recruit.flash.hired_warn');
        return $this->redirect($r, '/recruit/c/' . $r->params['id']);
    }

    public function erase(Request $r): Response
    {
        return $this->act($r, 'erase', fn () => $this->svc()->eraseOnRequest($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'reason', 300)), 'recruit.flash.erased');
    }

    // ---- overview and settings ------------------------------------------------------------------------------------------

    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $les = $this->svc()->companies($az);
        if ($les === []) {
            throw HttpException::forbidden();
        }
        $companies = [];
        foreach ($les as $id) {
            $companies[(string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$id])] = (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$id]);
        }
        $lePid = (string) ($r->query['le'] ?? '');
        if (!isset($companies[$lePid])) {
            $lePid = (string) array_key_first($companies);
        }
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        $waiting = array_values(array_filter($this->svc()->requisitions($az), static fn (array $x): bool => (int) $x['legal_entity_id'] === $le && $x['status'] === 'approved' && (int) $x['published'] === 0));
        return $this->page('@recruit/index', ['title' => $this->t('recruit.title'), 'companies' => $companies, 'lePid' => $lePid, 'funnel' => $this->svc()->funnel($az, $le),
            'waiting' => $waiting, 'notice' => $this->svc()->settings($le)['notice_text'] !== '']);
    }

    /** @param array<string, string> $errors */
    public function settings(Request $r, array $errors = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $les = $this->svc()->companies($az, 'recruit.settings');
        if ($les === []) {
            throw HttpException::forbidden();
        }
        $companies = [];
        foreach ($les as $id) {
            $companies[(string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$id])] = (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$id]);
        }
        $lePid = (string) ($r->body['legal_entity'] ?? $r->query['le'] ?? '');
        if (!isset($companies[$lePid])) {
            $lePid = (string) array_key_first($companies);
        }
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        return $this->page('@recruit/settings', ['title' => $this->t('recruit.settings_title'), 'companies' => $companies, 'lePid' => $lePid, 's' => $this->svc()->settings($le),
            'retention' => $this->app->retention()->current('applicant_unsuccessful'), 'errors' => $errors, 'old' => $r->method === 'POST' ? $r->body : null], $status);
    }

    public function saveSettings(Request $r): Response
    {
        $lePid = $this->str($r, 'legal_entity', 26);
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        try {
            $this->svc()->saveSettings($this->ctx($r)->az(), $le, mb_substr((string) ($r->body['notice_text'] ?? ''), 0, 9000), array_values(array_map('strval', (array) ($r->body['required'] ?? []))));
        } catch (ValidationException $e) {
            return $this->settings($r, $this->errors($e), 422);
        }
        $this->flash($r, 'success', 'recruit.flash.settings');
        return $this->redirect($r, '/recruit/settings?le=' . rawurlencode($lePid));
    }

    /** @return list<string> */
    public static function employmentTypes(): array
    {
        return EmployeeService::TYPES;
    }
}
