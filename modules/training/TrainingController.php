<?php

declare(strict_types=1);

namespace Blue\Modules\Training;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;
use Blue\Modules\Employees\EmployeeService;

/** M10 screens: open sessions + my training · session · HR sessions, courses, session work page (roster, check-in, results, certificates), matrix, a person's history. */
final class TrainingController extends Controller
{
    private function svc(): TrainingService
    {
        return TrainingService::of($this->app);
    }

    private function t(string $key): string
    {
        return $this->app->translator()->get($key);
    }

    public function index(Request $r): Response
    {
        if (!$this->ctx($r)->can('training.enroll')) {
            throw HttpException::forbidden();
        }
        $az = $this->ctx($r)->az();
        $me = EmployeeService::of($this->app)->forUser($az->userId);
        $le = $me['job']['legal_entity_id'] ?? null;
        $mine = $me !== null ? $this->svc()->history((int) $me['id']) : [];
        $required = [];
        if ($le !== null) {
            $m = $this->svc()->matrix((int) $le);
            foreach ($m['rows'] as $row) {
                if ((int) $row['employee']['id'] === (int) $me['id']) {
                    foreach ($m['courses'] as $c) {
                        if (($row['cells'][(int) $c['id']] ?? '') !== '') {
                            $required[] = ['title' => (string) $c['title'], 'code' => (string) $c['code'], 'state' => $row['cells'][(int) $c['id']]];
                        }
                    }
                }
            }
        }
        return $this->page('@training/index', ['title' => $this->t('training.title'), 'open' => $le !== null ? $this->svc()->sessions((int) $le, $this->svc()->today(), true) : [],
            'mine' => $mine, 'required' => $required, 'employee' => $me, 'today' => $this->svc()->today(), 'canManage' => $this->svc()->companies($az) !== []]);
    }

    public function session(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $s = $this->svc()->session((string) $r->params['id']);
        $me = EmployeeService::of($this->app)->forUser($az->userId);
        $manage = $s !== null && $az->scope('training.manage')->coversLegalEntity((int) $s['legal_entity_id']);
        if ($s === null || (!$manage && ((int) ($me['job']['legal_entity_id'] ?? 0) !== (int) $s['legal_entity_id']))) {
            throw HttpException::notFound();
        }
        $mine = $me !== null ? $this->app->db()->one('SELECT * FROM trn_enrollments WHERE session_id = ? AND employee_id = ?', [$s['id'], $me['id']]) : null;
        return $this->page('@training/session', ['title' => (string) $s['title'], 's' => $s, 'mineEnr' => $mine, 'canManage' => $manage, 'today' => $this->svc()->today()]);
    }

    public function enroll(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $st = $this->svc()->enrollSelf($this->ctx($r)->az(), $pid);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/training/s/' . $pid);
        }
        $this->flash($r, $st === 'enrolled' ? 'success' : 'info', 'training.flash.' . $st);
        return $this->redirect($r, '/training/s/' . $pid);
    }

    public function cancel(Request $r): Response
    {
        try {
            $this->svc()->cancelMine($this->ctx($r)->az(), (string) $r->params['id']);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/training');
        }
        $this->flash($r, 'success', 'training.flash.cancelled');
        return $this->redirect($r, '/training');
    }

    public function certificate(Request $r): Response
    {
        $f = $this->svc()->certificate($this->ctx($r)->az(), (string) $r->params['id']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['name'], true);
    }

    // ---- HR -------------------------------------------------------------------------------------------------------------

    /** @return array{0: array<string, string>, 1: string, 2: int} */
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

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function admin(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $past = ($r->query['past'] ?? '') === '1';
        $from = $past ? EmployeeService::ymd($this->svc()->today())->modify('-365 days')->format('Y-m-d') : $this->svc()->today();
        return $this->page('@training/admin', ['title' => $this->t('training.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'rows' => $this->svc()->sessions($le, $from),
            'courseOptions' => array_column(array_map(static fn (array $c): array => ['k' => (string) $c['public_id'], 'v' => $c['code'] . ' · ' . $c['title']], $this->svc()->courses($le, true)), 'v', 'k'),
            'past' => $past, 'errors' => $errors, 'old' => $old], $status);
    }

    /** @return array<string, string> */
    private function sessionInput(Request $r): array
    {
        $in = [];
        foreach (['course' => 26, 'start_date' => 10, 'end_date' => 10, 'start_time' => 5, 'end_time' => 5, 'location' => 200, 'trainer' => 150, 'seats' => 4, 'cost' => 15, 'enroll_until' => 10] as $k => $max) {
            $in[$k] = $this->str($r, $k, $max);
        }
        return $in;
    }

    public function createSession(Request $r): Response
    {
        [, , $le] = $this->company($r);
        $in = $this->sessionInput($r);
        try {
            $pid = $this->svc()->saveSession($this->ctx($r)->az(), $le, $in);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'training.flash.session_created');
        return $this->redirect($r, '/training/admin/s/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function courses(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $edit = null;
        if (isset($r->params['id'])) {
            $edit = $this->app->db()->one('SELECT c.*, p.public_id AS prerequisite FROM trn_courses c LEFT JOIN trn_courses p ON p.id = c.prerequisite_id WHERE c.public_id = ?', [(string) $r->params['id']]);
            if ($edit === null || !in_array((int) $edit['legal_entity_id'], $this->svc()->companies($this->ctx($r)->az()), true)) {
                throw HttpException::notFound();
            }
            $le = (int) $edit['legal_entity_id'];
            $lePid = (string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        }
        $list = $this->svc()->courses($le);
        return $this->page('@training/courses', ['title' => $this->t('training.courses_title'), 'companies' => $companies, 'lePid' => $lePid, 'rows' => $list, 'edit' => $edit,
            'prereqs' => array_column(array_map(static fn (array $c): array => ['k' => (string) $c['public_id'], 'v' => $c['code'] . ' · ' . $c['title']], array_filter($list, static fn (array $c): bool => $edit === null || $c['id'] !== $edit['id'])), 'v', 'k'),
            'errors' => $errors, 'old' => $old], $status);
    }

    public function saveCourse(Request $r): Response
    {
        [, $lePid, $le] = $this->company($r);
        $pid = isset($r->params['id']) ? (string) $r->params['id'] : null;
        if ($pid !== null) {
            $le = (int) $this->app->db()->scalar('SELECT legal_entity_id FROM trn_courses WHERE public_id = ?', [$pid]);
            $lePid = (string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        }
        $in = [];
        foreach (['code' => 30, 'title' => 200, 'category' => 60, 'hours' => 6, 'pass_score' => 3, 'valid_months' => 3, 'needs_approval' => 1, 'prerequisite' => 26, 'required_titles' => 500, 'status' => 10] as $k => $max) {
            $in[$k] = $this->str($r, $k, $max);
        }
        $in['description'] = mb_substr((string) ($r->body['description'] ?? ''), 0, 4000);
        $in['objectives'] = mb_substr((string) ($r->body['objectives'] ?? ''), 0, 4000);
        try {
            $this->svc()->saveCourse($this->ctx($r)->az(), $le, $in, $pid);
        } catch (ValidationException $e) {
            return $this->courses($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'training.flash.course_saved');
        return $this->redirect($r, '/training/admin/courses?le=' . rawurlencode($lePid));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     * @param array<string, mixed>|null $scan
     */
    public function manage(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200, ?array $scan = null): Response
    {
        $az = $this->ctx($r)->az();
        $s = $this->svc()->managedSession($az, (string) $r->params['id']);
        $courses = array_column(array_map(static fn (array $c): array => ['k' => (string) $c['public_id'], 'v' => $c['code'] . ' · ' . $c['title']], $this->svc()->courses((int) $s['legal_entity_id'], true)), 'v', 'k');
        $scanned = $this->store($r)->get('trn_scan');
        if ($scanned !== null) {
            $this->store($r)->forget('trn_scan');
        }
        return $this->page('@training/manage', ['title' => (string) $s['title'], 's' => $s, 'roster' => $this->svc()->roster((int) $s['id']), 'courseOptions' => $courses,
            'certs' => $this->app->db()->all('SELECT c.*, e.employee_id, p.employee_code, p.name_th FROM trn_certificates c JOIN trn_enrollments e ON e.id = c.enrollment_id JOIN employees p ON p.id = e.employee_id WHERE e.session_id = ? ORDER BY c.id DESC', [$s['id']]),
            'scan' => $scan ?? (is_array($scanned) ? $scanned : null), 'today' => $this->svc()->today(), 'errors' => $errors, 'old' => $old, 'form' => $form], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function updateSession(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $s = $this->svc()->managedSession($this->ctx($r)->az(), $pid);
        $in = $this->sessionInput($r);
        try {
            $this->svc()->saveSession($this->ctx($r)->az(), (int) $s['legal_entity_id'], $in, $pid);
        } catch (ValidationException $e) {
            return $this->manage($r, $this->errors($e), $in, 'details', 422);
        }
        $this->flash($r, 'success', 'training.flash.saved');
        return $this->redirect($r, '/training/admin/s/' . $pid);
    }

    public function sessionAction(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        try {
            switch ($action) {
                case 'status':
                    $to = $this->str($r, 'to', 10);
                    $this->svc()->setSessionStatus($az, $pid, $to, (string) ($r->body['reason'] ?? ''));
                    $this->flash($r, 'success', 'training.flash.status');
                    break;
                case 'enroll':
                    $res = $this->svc()->enrollByHr($az, $pid, (string) ($r->body['codes'] ?? ''));
                    $this->flash($r, $res['failed'] === [] ? 'success' : 'warning', 'training.flash.enrolled_hr', ['n' => (string) count($res['done']), 'failed' => implode(', ', array_map(fn (string $c, string $k): string => $c . ' (' . $this->t($k) . ')', array_keys($res['failed']), $res['failed']))]);
                    break;
                case 'checkin':
                    $res = $this->svc()->checkIn($az, $pid, (string) ($r->body['scan'] ?? ''));
                    $this->store($r)->set('trn_scan', $res);
                    break;
                case 'attend':
                    $n = $this->svc()->markAttended($az, $pid, array_map('intval', (array) ($r->body['ids'] ?? [])));
                    $this->flash($r, 'success', 'training.flash.attended', ['n' => (string) $n]);
                    break;
                case 'evaluate':
                    $scores = [];
                    foreach ((array) ($r->body['score'] ?? []) as $k => $v) {
                        if (is_scalar($v)) {
                            $scores[(int) $k] = (string) $v;
                        }
                    }
                    $n = $this->svc()->evaluate($az, $pid, $scores, ($r->body['no_shows'] ?? '') === '1');
                    $this->flash($r, 'success', 'training.flash.evaluated', ['n' => (string) $n]);
                    break;
                default:
                    $n = $this->svc()->issueCertificates($az, $pid);
                    $this->flash($r, 'success', 'training.flash.certs', ['n' => (string) $n]);
            }
        } catch (ValidationException $e) {
            return $this->manage($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/training/admin/s/' . $pid . ($action === 'checkin' ? '#checkin' : ''));
    }

    public function revoke(Request $r): Response
    {
        $cid = (string) $r->params['id'];
        $session = (string) $this->app->db()->scalar('SELECT s.public_id FROM trn_certificates c JOIN trn_enrollments e ON e.id = c.enrollment_id JOIN trn_sessions s ON s.id = e.session_id WHERE c.public_id = ?', [$cid]);
        if ($session === '') {
            throw HttpException::notFound();
        }
        try {
            $this->svc()->revokeCertificate($this->ctx($r)->az(), $cid, (string) ($r->body['reason'] ?? ''), ($r->body['reissue'] ?? '') === '1');
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e instanceof ValidationException ? 'training.error.reason' : $e->messageKey);
            return $this->redirect($r, '/training/admin/s/' . $session);
        }
        $this->flash($r, 'success', 'training.flash.revoked');
        return $this->redirect($r, '/training/admin/s/' . $session);
    }

    public function matrix(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        return $this->page('@training/matrix', ['title' => $this->t('training.matrix_title'), 'companies' => $companies, 'lePid' => $lePid, 'm' => $this->svc()->matrix($le)]);
    }

    public function person(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $emp = $this->app->db()->one('SELECT e.*, h.legal_entity_id FROM employees e JOIN employment_history h ON h.employee_id = e.id AND h.effective_to IS NULL WHERE e.public_id = ?', [(string) $r->params['id']]);
        if ($emp === null || !$az->scope('training.manage')->coversLegalEntity((int) $emp['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        $this->app->audit()->record('training.history_viewed', 'success', 'employee', (string) $emp['public_id'], (int) $emp['legal_entity_id']);
        return $this->page('@training/person', ['title' => (string) $emp['name_th'], 'emp' => $emp, 'rows' => $this->svc()->history((int) $emp['id'])]);
    }
}
