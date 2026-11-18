<?php

declare(strict_types=1);

namespace Blue\Modules\Attendance;

use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Modules\Employees\EmployeeService;

/** M34 screens: my time (month, day, correction), team/HR month, HR settings (schedule, device codes, import), Time Collector. */
final class AttendanceController extends Controller
{
    private const CORR = ['work_date' => 10, 'kind' => 12, 'proposed_in' => 5, 'proposed_out' => 5, 'reason' => 500];

    private function svc(): AttendanceService
    {
        return AttendanceService::of($this->app);
    }

    // ---- employee ----------------------------------------------------------------------------------------

    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('attendance.use')) {
            throw HttpException::forbidden();
        }
        $svc = $this->svc();
        $me = $svc->emp()->forUser($az->userId);
        $month = $this->month($r);
        $days = $me !== null ? $svc->month((int) $me['id'], $month) : [];
        $le = $me !== null && $me['job'] !== null ? (int) $me['job']['legal_entity_id'] : null;
        return $this->page('@attendance/index', $this->monthData($month) + [
            'title' => $this->app->translator()->get('attendance.title'), 'me' => $me, 'emp' => $me, 'days' => $days, 'totals' => AttendanceService::totals($days),
            'own' => true, 'schedule' => $le !== null ? $svc->schedule($le, $svc->today()) : null, 'lastPull' => $svc->lastPull(),
            'corrections' => $me !== null ? CorrectionService::of($this->app)->forEmployee((int) $me['id'], null, 20) : [],
            'isManager' => $me !== null && $svc->emp()->directReports((int) $me['id'], $svc->today()) !== [], 'isHr' => !$az->scope('attendance.approve')->isEmpty(),
            'canManage' => !$az->scope('attendance.manage')->isEmpty(), 'tz' => $svc->siteTz($me !== null && $me['job'] !== null && $me['job']['site_id'] !== null ? (int) $me['job']['site_id'] : null)->getName(),
            'base' => '/attendance',
        ]);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    public function day(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $svc = $this->svc();
        try {
            $me = $svc->self($az);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'warning', $e->messageKey);
            return $this->redirect($r, '/attendance');
        }
        return $this->dayPage($r, $me, 'own', (string) $r->params['date'], $errors, $old, $status);
    }

    public function submit(Request $r): Response
    {
        $in = $this->input($r);
        try {
            $pid = CorrectionService::of($this->app)->submit($this->ctx($r)->az(), $in, $r->file('evidence'));
        } catch (ValidationException $e) {
            $r->params['date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['work_date']) === 1 ? $in['work_date'] : $this->svc()->today();
            return $this->day($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'attendance.flash.sent');
        return $this->redirect($r, '/attendance/c/' . $pid);
    }

    public function correction(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $cs = CorrectionService::of($this->app);
        $c = $cs->correction($az, (string) $r->params['id']);
        $history = $this->app->db()->all(
            'SELECT d.decision, d.comment, d.decided_at, u.display_name FROM workflow_decisions d LEFT JOIN users u ON u.id = d.actor_id WHERE d.instance_id = ? ORDER BY d.id',
            [(int) ($c['workflow_instance_id'] ?? 0)]
        );
        return $this->page('@attendance/correction', [
            'title' => $this->app->translator()->get('attendance.correction_title'), 'c' => $c, 'summary' => $cs->describe((string) $c['public_id'])['rows'], 'history' => $history,
            'canEvidence' => $c['document_pid'] !== null && (in_array($c['access'], ['own', 'manager', 'approver'], true) || EmployeeService::covers($az->scope('attendance.manage'), $c)),
        ]);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    public function edit(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $c = CorrectionService::of($this->app)->correction($az, (string) $r->params['id']);
        if ($c['access'] !== 'own' || $c['status'] !== 'returned') {
            throw HttpException::notFound();
        }
        $old += ['work_date' => (string) $c['work_date'], 'kind' => (string) $c['kind'], 'proposed_in' => substr((string) ($c['proposed_in'] ?? ''), 0, 5),
            'proposed_out' => substr((string) ($c['proposed_out'] ?? ''), 0, 5), 'reason' => (string) $c['reason']];
        $me = $this->svc()->self($az);
        return $this->dayPage($r, $me, 'own', (string) $c['work_date'], $errors, $old, $status, $c);
    }

    public function resubmit(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $in = $this->input($r);
        try {
            CorrectionService::of($this->app)->resubmit($this->ctx($r)->az(), $pid, $in, $r->file('evidence'));
        } catch (ValidationException $e) {
            return $this->edit($r, $this->errors($e), $in, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/attendance/c/' . $pid);
        }
        $this->flash($r, 'success', 'attendance.flash.resent');
        return $this->redirect($r, '/attendance/c/' . $pid);
    }

    public function withdraw(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            CorrectionService::of($this->app)->withdraw($this->ctx($r)->az(), $pid, $this->str($r, 'reason', 300));
        } catch (ValidationException) {
            $this->flash($r, 'danger', 'iam.error.reason_required');
            return $this->redirect($r, '/attendance/c/' . $pid . '#withdraw');
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/attendance/c/' . $pid);
        }
        $this->flash($r, 'success', 'attendance.flash.withdrawn');
        return $this->redirect($r, '/attendance/c/' . $pid);
    }

    public function evidence(Request $r): Response
    {
        $doc = CorrectionService::of($this->app)->evidence($this->ctx($r)->az(), (string) $r->params['id']);
        return (new Response($doc['bytes'], 200, ['Content-Type' => $doc['mime'], 'Cache-Control' => 'no-store']))
            ->withHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $doc['name']) . '"');
    }

    // ---- manager / HR --------------------------------------------------------------------------------------

    public function team(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('attendance.use')) {
            throw HttpException::forbidden();
        }
        $month = $this->month($r);
        $le = (int) $r->input('le', 0);
        $team = $this->svc()->team($az, $month, $le > 0 ? $le : null);
        if (!$team['team'] && !$team['hr']) {
            $this->flash($r, 'info', 'attendance.team_none');
            return $this->redirect($r, '/attendance');
        }
        return $this->page('@attendance/team', $this->monthData($month) + [
            'title' => $this->app->translator()->get('attendance.team_title'), 'team' => $team, 'le' => $le,
            'companies' => $team['hr'] ? $this->app->org()->scopeOptions($az->scope('attendance.approve'), $this->app->translator()->locale())['legal_entity'] : [],
            'lastPull' => $this->svc()->lastPull(),
        ]);
    }

    public function recalcTeam(Request $r): Response
    {
        $month = $this->month($r);
        $le = (int) $r->input('le', 0);
        $n = $this->svc()->recalcVisible($this->ctx($r)->az(), $month, $le > 0 ? $le : null);
        $this->flash($r, 'success', 'attendance.flash.recalculated', ['n' => (string) $n]);
        return $this->redirect($r, '/attendance/team?month=' . $month . ($le > 0 ? '&le=' . $le : ''));
    }

    public function employee(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $svc = $this->svc();
        [$emp, $access] = $svc->visibleEmployee($az, (string) $r->params['id']);
        if ($access === 'own') {
            return $this->redirect($r, '/attendance?month=' . $this->month($r));
        }
        $month = $this->month($r);
        $days = $svc->month((int) $emp['id'], $month);
        return $this->page('@attendance/index', $this->monthData($month) + [
            'title' => $svc->emp()->displayName($emp), 'me' => null, 'emp' => $emp, 'days' => $days, 'totals' => AttendanceService::totals($days), 'own' => false,
            'schedule' => $emp['job'] !== null ? $svc->schedule((int) $emp['job']['legal_entity_id'], $svc->today()) : null, 'lastPull' => $svc->lastPull(),
            'corrections' => CorrectionService::of($this->app)->forEmployee((int) $emp['id'], null, 20), 'isManager' => false, 'isHr' => false, 'canManage' => false,
            'tz' => $svc->siteTz($emp['job'] !== null && $emp['job']['site_id'] !== null ? (int) $emp['job']['site_id'] : null)->getName(),
            'base' => '/attendance/e/' . $emp['public_id'],
        ]);
    }

    public function employeeDay(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        [$emp, $access] = $this->svc()->visibleEmployee($az, (string) $r->params['id']);
        if ($access === 'own') {
            return $this->redirect($r, '/attendance/day/' . $r->params['date']);
        }
        return $this->dayPage($r, $emp, $access, (string) $r->params['date'], [], [], 200);
    }

    // ---- HR settings -------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function admin(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $companies = $this->companies($az, 'attendance.manage');
        if ($companies === []) {
            throw HttpException::forbidden();
        }
        $le = (int) ($old['le'] ?? $r->input('le', 0));
        if (!isset($companies[$le])) {
            $le = (int) array_key_first($companies);
        }
        $svc = $this->svc();
        $dev = DeviceService::of($this->app);
        $schedule = $svc->schedule($le, $svc->today());
        $devices = [];
        foreach ($dev->devices() as $d) {
            $devices[(string) $d['public_id']] = (string) $d['device_code'] . ($d['name'] !== null ? ' · ' . $d['name'] : '');
        }
        return $this->page('@attendance/admin', [
            'title' => $this->app->translator()->get('attendance.admin_title'), 'companies' => $companies, 'le' => $le,
            'schedule' => $schedule, 'scheduleRows' => $schedule !== null ? WorkSchedule::describe($this->app, $schedule['value']) : [], 'versions' => $svc->scheduleVersions($le), 'canPolicy' => $az->scope('attendance.policy')->coversLegalEntity($le),
            'mappings' => $dev->mappings($az, $le), 'unmapped' => $dev->unmapped($az), 'devices' => $devices, 'today' => $svc->today(),
            'errors' => $errors, 'old' => $old, 'form' => $form, 'canCollector' => !$az->scope('attendance.collector')->isEmpty(),
        ], $status);
    }

    public function proposeSchedule(Request $r): Response
    {
        $le = (int) $r->input('le', 0);
        $groups = $r->input('groups', []);
        $in = ['le' => $le, 'day_start' => $this->str($r, 'day_start', 5), 'correction_max_days' => $this->str($r, 'correction_max_days', 3),
            'groups' => is_array($groups) ? array_values($groups) : [], 'effective_from' => $this->str($r, 'effective_from', 10),
            'source_reference' => $this->str($r, 'source_reference', 300), 'reason' => $this->str($r, 'reason', 300)];
        try {
            $this->svc()->proposeSchedule($this->ctx($r)->az(), $le, $in, $in['effective_from'], $in['source_reference'], $in['reason']);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $in, 'schedule', 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->admin($r, ['_form' => $this->app->translator()->get($e->messageKey)], $in, 'schedule', 422);
        }
        $this->flash($r, 'success', 'attendance.flash.schedule_sent');
        return $this->redirect($r, '/attendance/admin?le=' . $le . '#schedule');
    }

    public function map(Request $r): Response
    {
        $le = (int) $r->input('le', 0);
        $in = ['le' => (string) $le, 'device' => $this->str($r, 'device', 26), 'code' => $this->str($r, 'code', 32), 'employee_code' => $this->str($r, 'employee_code', 20),
            'valid_from' => $this->str($r, 'valid_from', 10), 'note' => $this->str($r, 'note', 200)];
        try {
            DeviceService::of($this->app)->map($this->ctx($r)->az(), $in);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $in, 'map', 422);
        }
        $this->flash($r, 'success', 'attendance.flash.mapped');
        return $this->redirect($r, '/attendance/admin?le=' . $le . '#codes');
    }

    public function endMapping(Request $r): Response
    {
        $le = (int) $r->input('le', 0);
        try {
            DeviceService::of($this->app)->endMapping($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'valid_to', 10));
        } catch (ValidationException) {
            $this->flash($r, 'danger', 'attendance.error.end_date');
            return $this->redirect($r, '/attendance/admin?le=' . $le . '#codes');
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/attendance/admin?le=' . $le . '#codes');
        }
        $this->flash($r, 'success', 'attendance.flash.mapping_ended');
        return $this->redirect($r, '/attendance/admin?le=' . $le . '#codes');
    }

    // ---- Time Collector ----------------------------------------------------------------------------------

    /** @param array<string, string> $errors */
    public function collector(Request $r, array $errors = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        if ($az->scope('attendance.collector')->isEmpty()) {
            throw HttpException::forbidden();
        }
        $p = PunchPuller::of($this->app);
        $sites = [0 => '—'] + $this->app->org()->scopeOptions($az->scope('attendance.collector'), $this->app->translator()->locale())['site'];
        $ready = $p->readiness();
        return $this->page('@attendance/collector', [
            'title' => $this->app->translator()->get('attendance.collector_title'), 'ready' => $ready, 'runs' => $p->runs(), 'hours' => $p->intervalHours(),
            'devices' => DeviceService::of($this->app)->devices(), 'sites' => $sites, 'errors' => $errors,
            'next' => $this->app->db()->scalar("SELECT next_run_at FROM scheduled_jobs WHERE job_type = 'attendance.pull'"),
            'keys' => $ready['integration'] !== null ? count($this->app->integrations()->outboundCredentials((int) $ready['integration']['id'])) : 0,
            'canIntegrations' => $az->can('core.integration.view'),
        ], $status);
    }

    public function pull(Request $r): Response
    {
        try {
            $run = PunchPuller::of($this->app)->pullNow($this->ctx($r)->az());
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'warning', $e->messageKey);
            return $this->redirect($r, '/attendance/collector');
        }
        $tone = ['success' => 'success', 'partial' => 'warning'][(string) $run['status']] ?? 'danger';
        $this->flash($r, $tone, 'attendance.flash.pulled_' . ($tone === 'danger' ? 'failed' : 'ok'), ['n' => (string) $run['inserted'], 'dup' => (string) $run['duplicates'], 'unmapped' => (string) $run['unmapped']]);
        return $this->redirect($r, '/attendance/collector#runs');
    }

    public function interval(Request $r): Response
    {
        try {
            PunchPuller::of($this->app)->setInterval($this->ctx($r)->az(), (int) $this->str($r, 'hours', 2));
        } catch (ValidationException $e) {
            return $this->collector($r, $this->errors($e), 422);
        }
        $this->flash($r, 'success', 'attendance.flash.interval');
        return $this->redirect($r, '/attendance/collector');
    }

    public function device(Request $r): Response
    {
        try {
            DeviceService::of($this->app)->updateDevice($this->ctx($r)->az(), (string) $r->params['id'], [
                'name' => $this->str($r, 'name', 80), 'site' => $this->str($r, 'site', 12), 'status' => $this->str($r, 'status', 10),
            ]);
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', (string) (array_values($e->errors)[0] ?? 'form.invalid'));
            return $this->redirect($r, '/attendance/collector#devices');
        }
        $this->flash($r, 'success', 'attendance.flash.device_saved');
        return $this->redirect($r, '/attendance/collector#devices');
    }

    // ---- helpers ---------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $emp employee (with job)
     * @param array<string, string> $errors
     * @param array<string, string> $old
     * @param array<string, mixed>|null $editing a returned correction being edited
     */
    private function dayPage(Request $r, array $emp, string $access, string $date, array $errors, array $old, int $status, ?array $editing = null): Response
    {
        $svc = $this->svc();
        if (EmployeeService::ymd($date)->format('Y-m-d') !== $date) {
            throw HttpException::notFound();
        }
        if ($date <= $svc->today()) {
            $svc->recalc((int) $emp['id'], $date, $date);
        }
        $job = $svc->emp()->employment((int) $emp['id'], $date);
        $day = $this->app->db()->one('SELECT * FROM att_days WHERE employee_id = ? AND work_date = ?', [$emp['id'], $date]);
        $sched = $job !== null ? $svc->schedule((int) $job['legal_entity_id'], $date) : null;
        return $this->page('@attendance/day', [
            'title' => $this->app->translator()->get('attendance.day_title'), 'emp' => $emp, 'access' => $access, 'date' => $date, 'day' => $day,
            'punches' => $svc->rawPunches((int) $emp['id'], $date, $date), 'corrections' => CorrectionService::of($this->app)->forEmployee((int) $emp['id'], $date),
            'rule' => WorkSchedule::forGroup($sched['value'] ?? null, (string) ($job['employee_group'] ?? '')), 'today' => $svc->today(),
            'tz' => $svc->siteTz($job !== null && $job['site_id'] !== null ? (int) $job['site_id'] : null)->getName(),
            'errors' => $errors, 'old' => $old + ['work_date' => $date], 'editing' => $editing,
            'back' => $access === 'own' ? '/attendance?month=' . substr($date, 0, 7) : '/attendance/e/' . $emp['public_id'] . '?month=' . substr($date, 0, 7),
        ], $status);
    }

    private function month(Request $r): string
    {
        $m = $this->str($r, 'month', 7);
        if (preg_match('/^(\d{4})-(\d{2})$/', $m, $x) !== 1 || (int) $x[2] < 1 || (int) $x[2] > 12 || (int) $x[1] < 2000 || (int) $x[1] > 2100) {
            return substr($this->svc()->today(), 0, 7);
        }
        return $m;
    }

    /** @return array{month: string, label: string, prev: string, next: string} */
    private function monthData(string $month): array
    {
        $first = EmployeeService::ymd($month . '-01');
        $th = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        return [
            'month' => $month, 'label' => $this->app->translator()->locale() === 'th' ? $th[(int) $first->format('n') - 1] . ' ' . ((int) $first->format('Y') + 543) : $first->format('F Y'),
            'prev' => $first->modify('-1 month')->format('Y-m'), 'next' => $first->modify('+1 month')->format('Y-m'),
        ];
    }

    /** @return array<string, string> */
    private function input(Request $r): array
    {
        $out = [];
        foreach (self::CORR as $k => $max) {
            $out[$k] = $this->str($r, $k, $max);
        }
        return $out;
    }

    /** @return array<int, string> */
    private function companies(Authorizer $az, string $perm): array
    {
        return $this->app->org()->scopeOptions($az->scope($perm), $this->app->translator()->locale())['legal_entity'];
    }
}
