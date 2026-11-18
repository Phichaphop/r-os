<?php

declare(strict_types=1);

namespace Blue\Modules\Leave;

use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\I18n\DateFormat;
use Blue\Modules\Employees\EmployeeService;

/** M32 screens: my leave (balances, requests, new request with a live check), request detail, team calendar, HR settings. */
final class LeaveController extends Controller
{
    private const REQ = ['type' => 26, 'start_date' => 10, 'end_date' => 10, 'part' => 4, 'reason' => 500];

    // ---- employee ------------------------------------------------------------------------------------

    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('leave.use')) {
            throw HttpException::forbidden();
        }
        $svc = LeaveService::of($this->app);
        $me = EmployeeService::of($this->app)->forUser($az->userId);
        $year = (int) ($r->input('year', 0) ?: substr($svc->today(), 0, 4));
        $year = max(2000, min(2100, $year));
        $balances = [];
        if ($me !== null && $me['job'] !== null) {
            foreach ($svc->types((int) $me['job']['legal_entity_id']) as $t) {
                $balances[] = ['type' => $t, 'b' => $svc->balance($me, $t, $year)];
            }
        }
        return $this->page('@leave/index', [
            'title' => $this->app->translator()->get('leave.title'), 'me' => $me, 'balances' => $balances, 'year' => $year,
            'requests' => $me !== null ? $svc->myRequests((int) $me['id']) : [], 'today' => $svc->today(),
            'isManager' => $me !== null && EmployeeService::of($this->app)->directReports((int) $me['id'], $svc->today()) !== [],
            'isHr' => !$az->scope('leave.approve')->isEmpty(), 'canManage' => !$az->scope('leave.manage')->isEmpty(),
        ]);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     * @param array<string, mixed>|null $req
     */
    public function form(Request $r, array $errors = [], array $old = [], ?array $req = null, int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $svc = LeaveService::of($this->app);
        try {
            $me = $svc->self($az);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'warning', $e->messageKey);
            return $this->redirect($r, '/leave');
        }
        $types = $me['job'] !== null ? $svc->types((int) $me['job']['legal_entity_id']) : [];
        $rules = [];
        foreach ($types as $t) {
            $b = $svc->balance($me, $t, (int) substr($svc->today(), 0, 4));
            $rules[(string) $t['public_id']] = ['half' => (bool) ($b['rule']['half_day'] ?? false), 'policy' => $b['rule'] !== null, 'doc' => (int) $t['allow_document'] === 1 || ($b['rule']['document_from_days'] ?? null) !== null];
        }
        return $this->page('@leave/form', [
            'title' => $this->app->translator()->get($req !== null ? 'leave.edit_title' : 'leave.new_title'), 'me' => $me, 'types' => $types, 'rules' => $rules,
            'errors' => $errors, 'old' => $old + ['start_date' => $svc->today(), 'part' => 'full'], 'req' => $req,
        ], $status);
    }

    public function preview(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $svc = LeaveService::of($this->app);
        $t = $this->app->translator();
        try {
            $me = $svc->self($az);
            $except = null;
            $pid = $this->str($r, 'request', 26);
            if ($pid !== '') {
                $req = $svc->request($az, $pid);
                $except = $req['access'] === 'own' ? (int) $req['id'] : null;
            }
            $ev = $svc->evaluate($me, $this->input($r), $except);
        } catch (ValidationException $e) {
            return Response::json(['ok' => false, 'errors' => $this->errors($e)], 200);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return Response::json(['ok' => false, 'errors' => ['_form' => $t->get($e->messageKey, array_map('strval', $e->params))]], 200);
        }
        $loc = $t->locale();
        $b = $ev['balance'];
        return Response::json([
            'ok' => true,
            'units' => $t->get('leave.days', ['n' => LeaveService::units($ev['units'])]),
            'days' => array_map(static fn (string $d): string => DateFormat::day($d, $loc), $ev['days']),
            'balance' => $b['tracked'] ? $t->get('leave.preview_balance', ['now' => LeaveService::units($b['available_for_this']), 'after' => LeaveService::units($b['available_for_this'] - $ev['units'])]) : $t->get('leave.not_tracked'),
            'document' => $ev['needs_document'] ? $t->get('leave.document_needed') : '',
            'warnings' => array_map(static fn (string $w): string => $t->get($w), $ev['warnings']),
        ]);
    }

    public function submit(Request $r): Response
    {
        $in = $this->input($r);
        try {
            $pid = LeaveService::of($this->app)->submit($this->ctx($r)->az(), $in, $r->file('document'));
        } catch (ValidationException $e) {
            return $this->form($r, $this->errors($e), $in, null, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->form($r, ['_form' => $this->app->translator()->get($e->messageKey, array_map('strval', $e->params))], $in, null, 422);
        }
        $this->flash($r, 'success', 'leave.flash.sent');
        return $this->redirect($r, '/leave/r/' . $pid);
    }

    public function show(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $svc = LeaveService::of($this->app);
        $req = $svc->request($az, (string) $r->params['id']);
        $history = [];
        foreach ([$req['instance_pid'], $req['cancel_instance_pid']] as $ipid) {
            if ($ipid !== null) {
                $history[] = $this->app->db()->all(
                    'SELECT d.decision, d.comment, d.decided_at, u.display_name FROM workflow_decisions d JOIN workflow_instances i ON i.id = d.instance_id LEFT JOIN users u ON u.id = d.actor_id
                      WHERE i.public_id = ? ORDER BY d.id',
                    [$ipid]
                );
            }
        }
        return $this->page('@leave/show', [
            'title' => $this->app->translator()->get('leave.request_title'), 'req' => $req, 'history' => array_merge(...($history ?: [[]])),
            'canDoc' => $svc->canSeeDocument($az, $req), 'today' => $svc->today(), 'isHr' => EmployeeService::covers($az->scope('leave.manage'), $req),
            'summary' => LeaveRequestSubject::rows($this->app, (string) $req['public_id'])['rows'],
        ]);
    }

    public function edit(Request $r): Response
    {
        $req = LeaveService::of($this->app)->request($this->ctx($r)->az(), (string) $r->params['id']);
        if ($req['access'] !== 'own' || $req['status'] !== 'returned') {
            throw HttpException::notFound();
        }
        $type = (string) $this->app->db()->scalar('SELECT public_id FROM lv_types WHERE id = ?', [$req['lv_type_id']]);
        return $this->form($r, [], ['type' => $type, 'start_date' => (string) $req['start_date'], 'end_date' => (string) $req['end_date'], 'part' => (string) $req['part'],
            'reason' => (string) ($req['reason'] ?? '')], $req);
    }

    public function resubmit(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $pid = (string) $r->params['id'];
        $in = $this->input($r);
        try {
            LeaveService::of($this->app)->resubmit($az, $pid, $in, $r->file('document'));
        } catch (ValidationException $e) {
            return $this->form($r, $this->errors($e), $in, LeaveService::of($this->app)->request($az, $pid), 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, array_map('strval', $e->params));
            return $this->redirect($r, '/leave/r/' . $pid);
        }
        $this->flash($r, 'success', 'leave.flash.resent');
        return $this->redirect($r, '/leave/r/' . $pid);
    }

    public function cancel(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $what = LeaveService::of($this->app)->cancel($this->ctx($r)->az(), $pid, $this->str($r, 'reason', 300));
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', 'iam.error.reason_required');
            return $this->redirect($r, '/leave/r/' . $pid . '#cancel');
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/leave/r/' . $pid);
        }
        $this->flash($r, 'success', 'leave.flash.' . $what);
        return $this->redirect($r, '/leave/r/' . $pid);
    }

    public function document(Request $r): Response
    {
        $doc = LeaveService::of($this->app)->document($this->ctx($r)->az(), (string) $r->params['id']);
        return (new Response($doc['bytes'], 200, ['Content-Type' => $doc['mime'], 'Cache-Control' => 'no-store']))
            ->withHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $doc['name']) . '"');
    }

    public function team(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('leave.use')) {
            throw HttpException::forbidden();
        }
        $svc = LeaveService::of($this->app);
        $month = $this->str($r, 'month', 7);
        if (preg_match('/^(\d{4})-(\d{2})$/', $month, $m) !== 1 || (int) $m[2] < 1 || (int) $m[2] > 12) {
            $month = substr($svc->today(), 0, 7);
            preg_match('/^(\d{4})-(\d{2})$/', $month, $m);
        }
        $le = (int) $r->input('le', 0);
        $cal = $svc->calendar($az, (int) $m[1], (int) $m[2], $le > 0 ? $le : null);
        if (!$cal['team'] && !$cal['hr']) {
            $this->flash($r, 'info', 'leave.team_none');
            return $this->redirect($r, '/leave');
        }
        $first = EmployeeService::ymd($month . '-01');
        $loc = $this->app->translator()->locale();
        $thMonths = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        $days = [];
        for ($d = 1; $d <= (int) $first->format('t'); $d++) {
            $days[$d] = (int) $first->modify('+' . ($d - 1) . ' days')->format('w');
        }
        return $this->page('@leave/team', [
            'title' => $this->app->translator()->get('leave.team_title'), 'cal' => $cal, 'month' => $month, 'today' => $svc->today(), 'days' => $days,
            'label' => $loc === 'th' ? $thMonths[(int) $first->format('n') - 1] . ' ' . ((int) $first->format('Y') + 543) : $first->format('F Y'),
            'prev' => self::shift($month, -1), 'next' => self::shift($month, 1),
            'companies' => $cal['hr'] ? $this->app->org()->scopeOptions($az->scope('leave.approve'), $this->app->translator()->locale())['legal_entity'] : [], 'le' => $le,
        ]);
    }

    // ---- HR -------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function admin(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $companies = $this->companies($az);
        if ($companies === []) {
            throw HttpException::forbidden();
        }
        $le = (int) ($old['le'] ?? $r->input('le', 0));
        if (!isset($companies[$le])) {
            $le = (int) array_key_first($companies);
        }
        $svc = LeaveService::of($this->app);
        $policy = $svc->policy($le, $svc->today());
        return $this->page('@leave/admin', [
            'title' => $this->app->translator()->get('leave.admin_title'), 'companies' => $companies, 'le' => $le, 'types' => $svc->types($le, true),
            'policy' => $policy, 'versions' => $svc->policyVersions($le), 'canPolicy' => $az->scope('leave.policy')->coversLegalEntity($le),
            'q' => $this->str($r, 'q', 60), 'found' => $svc->searchEmployees($az, $this->str($r, 'q', 60)), 'errors' => $errors, 'old' => $old, 'form' => $form, 'today' => $svc->today(),
        ], $status);
    }

    public function saveType(Request $r): Response
    {
        $in = ['le' => (int) $r->input('le', 0), 'pid' => $this->str($r, 'pid', 26)];
        foreach (['code' => 20, 'name_th' => 80, 'name_en' => 80, 'sort_order' => 4, 'status' => 10, 'is_statutory' => 1, 'allow_document' => 1] as $k => $max) {
            $in[$k] = $this->str($r, $k, $max);
        }
        try {
            LeaveService::of($this->app)->saveType($this->ctx($r)->az(), $in['le'], $in['pid'] !== '' ? $in['pid'] : null, $in);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $in, 'type:' . ($in['pid'] ?: 'new'), 422);
        }
        $this->flash($r, 'success', 'leave.flash.type_saved');
        return $this->redirect($r, '/leave/admin?le=' . $in['le'] . '#types');
    }

    public function proposePolicy(Request $r): Response
    {
        $le = (int) $r->input('le', 0);
        $types = $r->input('types', []);
        $in = ['le' => $le, 'types' => is_array($types) ? $types : [], 'effective_from' => $this->str($r, 'effective_from', 10),
            'source_reference' => $this->str($r, 'source_reference', 300), 'reason' => $this->str($r, 'reason', 300)];
        try {
            LeaveService::of($this->app)->proposePolicy($this->ctx($r)->az(), $le, $in['types'], $in['effective_from'], $in['source_reference'], $in['reason']);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $in, 'policy', 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->admin($r, ['_form' => $this->app->translator()->get($e->messageKey)], $in, 'policy', 422);
        }
        $this->flash($r, 'success', 'leave.flash.policy_sent');
        return $this->redirect($r, '/leave/admin?le=' . $le . '#policy');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    public function employee(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $svc = LeaveService::of($this->app);
        $emp = $svc->hrEmployee($az, (string) $r->params['id']);
        $year = max(2000, min(2100, (int) ($r->input('year', 0) ?: substr($svc->today(), 0, 4))));
        $types = $emp['job'] !== null ? $svc->types((int) $emp['job']['legal_entity_id'], true) : [];
        $balances = [];
        foreach ($types as $t) {
            $balances[] = ['type' => $t, 'b' => $svc->balance($emp, $t, $year)];
        }
        return $this->page('@leave/employee', [
            'title' => EmployeeService::of($this->app)->displayName($emp), 'emp' => $emp, 'year' => $year, 'types' => $types, 'balances' => $balances,
            'ledger' => $svc->ledger((int) $emp['id'], $year), 'requests' => $svc->myRequests((int) $emp['id'], 100), 'errors' => $errors, 'old' => $old,
        ], $status);
    }

    public function adjust(Request $r): Response
    {
        $in = ['type' => $this->str($r, 'type', 26), 'year' => $this->str($r, 'year', 4), 'units' => $this->str($r, 'units', 8), 'reason' => $this->str($r, 'reason', 255)];
        try {
            LeaveService::of($this->app)->adjust($this->ctx($r)->az(), (string) $r->params['id'], $in['type'], (int) $in['year'], $in['units'], $in['reason']);
        } catch (ValidationException $e) {
            return $this->employee($r, $this->errors($e), $in, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->employee($r, ['_form' => $this->app->translator()->get($e->messageKey)], $in, 403);
        }
        $this->flash($r, 'success', 'leave.flash.adjusted');
        return $this->redirect($r, '/leave/admin/employee/' . $r->params['id'] . '?year=' . (int) $in['year']);
    }

    // ---- helpers --------------------------------------------------------------------------------------

    /** @return array<string, string> */
    private function input(Request $r): array
    {
        $out = [];
        foreach (self::REQ as $k => $max) {
            $out[$k] = $this->str($r, $k, $max);
        }
        return $out;
    }

    /** @return array<int, string> companies where the user manages leave */
    private function companies(Authorizer $az): array
    {
        return $this->app->org()->scopeOptions($az->scope('leave.manage'), $this->app->translator()->locale())['legal_entity'];
    }

    /** First day of the month after/before $month (Y-m) — for the calendar's previous/next links. */
    public static function shift(string $month, int $by): string
    {
        return EmployeeService::ymd($month . '-01')->modify(($by >= 0 ? '+' : '') . $by . ' month')->format('Y-m');
    }
}
