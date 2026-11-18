<?php

declare(strict_types=1);

namespace Blue\Modules\Overtime;

use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** M33 screens: my OT + request (self / team) · detail + certify hours · HR: rules, payroll batches, month report. */
final class OvertimeController extends Controller
{
    private function svc(): OvertimeService
    {
        return OvertimeService::of($this->app);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function index(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('overtime.use')) {
            throw HttpException::forbidden();
        }
        $svc = $this->svc();
        $me = $svc->emp()->forUser($az->userId);
        $team = [];
        if ($me !== null) {
            foreach ($svc->emp()->directReports((int) $me['id'], $svc->today()) as $id) {
                $e = $svc->emp()->byId($id);
                if ($e !== null) {
                    $team[(string) $e['public_id']] = $e['employee_code'] . ' · ' . $svc->emp()->displayName($e);
                }
            }
        }
        $units = [];
        if ($me !== null && $me['job'] !== null) {
            foreach ($this->app->db()->all("SELECT public_id, code FROM departments WHERE legal_entity_id = ? AND status = 'active' ORDER BY code", [$me['job']['legal_entity_id']]) as $d) {
                $units[(string) $d['public_id']] = (string) $d['code'];
            }
        }
        $le = $me !== null && $me['job'] !== null ? (int) $me['job']['legal_entity_id'] : null;
        return $this->page('@overtime/index', [
            'title' => $this->app->translator()->get('overtime.title'), 'me' => $me, 'requests' => $me !== null ? $svc->forEmployee((int) $me['id']) : [],
            'team' => $team, 'units' => $units, 'rules' => $le !== null ? $svc->rules($le, $svc->today()) : null, 'today' => $svc->today(),
            'toVerify' => count($svc->toVerify($az, 500)), 'canAdmin' => !$az->scope('overtime.export')->isEmpty(), 'errors' => $errors, 'old' => $old,
        ], $status);
    }

    public function submit(Request $r): Response
    {
        $in = ['work_date' => $this->str($r, 'work_date', 10), 'start' => $this->str($r, 'start', 5), 'end' => $this->str($r, 'end', 5), 'reason' => $this->str($r, 'reason', 500),
            'cost_center' => $this->str($r, 'cost_center', 26), 'for' => $this->str($r, 'for', 4)];
        $emps = $in['for'] === 'team' ? array_values(array_filter(array_map('strval', (array) ($r->body['employees'] ?? [])))) : [];
        try {
            if ($in['for'] === 'team' && $emps === []) {
                throw new ValidationException(['employees' => 'overtime.error.pick_people']);
            }
            $pids = $this->svc()->submit($this->ctx($r)->az(), $in, $emps);
        } catch (ValidationException $e) {
            $errs = $this->errors($e);
            if (isset($e->errors['_employee'])) {
                $errs['employees'] = $this->app->translator()->get('overtime.error.for_employee', ['code' => (string) $e->errors['_employee']]);
                unset($errs['_employee']);
            }
            return $this->index($r, $errs, $in + ['employees' => $emps], 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->index($r, ['work_date' => $this->app->translator()->get($e->messageKey)], $in, 422);
        }
        $this->flash($r, 'success', count($pids) > 1 ? 'overtime.flash.submitted_n' : 'overtime.flash.submitted', ['n' => (string) count($pids)]);
        return $this->redirect($r, count($pids) === 1 ? '/overtime/r/' . $pids[0] : '/overtime');
    }

    /** @param array<string, string> $errors */
    public function show(Request $r, array $errors = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $svc = $this->svc();
        $req = $svc->request($az, (string) $r->params['id']);
        $canVerify = $svc->canVerify($az, $req) && in_array($req['status'], ['approved', 'disputed'], true);
        $ended = $this->app->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') >= (string) $req['end_utc'];
        return $this->page('@overtime/show', [
            'title' => $this->app->translator()->get('overtime.request_title'), 'req' => $req, 'canVerify' => $canVerify && $ended, 'ended' => $ended,
            'proposal' => $canVerify && $ended ? $svc->proposal($req) : null, 'errors' => $errors,
            'canCancel' => in_array($req['access'], ['own', 'requester'], true) && in_array($req['status'], ['pending', 'returned'], true),
        ], $status);
    }

    public function cancel(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $this->svc()->cancel($this->ctx($r)->az(), $pid, $this->str($r, 'reason', 255));
        $this->flash($r, 'success', 'overtime.flash.cancelled');
        return $this->redirect($r, '/overtime/r/' . $pid);
    }

    public function verify(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $action = in_array($r->input('action'), ['verify', 'dispute', 'reject'], true) ? (string) $r->input('action') : 'verify';
        try {
            $this->svc()->verify($this->ctx($r)->az(), $pid, $action, $this->str($r, 'payable_minutes', 4), $this->str($r, 'note', 500));
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->show($r, ['note' => $this->app->translator()->get($e->messageKey)], 422);
        }
        $this->flash($r, 'success', 'overtime.flash.' . $action);
        return $this->redirect($r, $r->input('back') === 'list' ? '/overtime/verify' : '/overtime/r/' . $pid);
    }

    public function verifyList(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $rows = $this->svc()->toVerify($az, 500);
        return $this->page('@overtime/verify', ['title' => $this->app->translator()->get('overtime.verify_title'), 'rows' => $rows]);
    }

    // ---- HR -------------------------------------------------------------------------------------------------------

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
        $lePid = (string) ($old['legal_entity'] ?? $r->query['le'] ?? '');
        if (!isset($companies[$lePid])) {
            $lePid = (string) array_key_first($companies);
        }
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        $svc = $this->svc();
        $today = $svc->today();
        $from = (string) ($old['period_start'] ?? $r->query['from'] ?? substr($today, 0, 7) . '-01');
        $to = (string) ($old['period_end'] ?? $r->query['to'] ?? $today);
        $preview = OvertimeService::isDate($from) && OvertimeService::isDate($to) && $from <= $to ? $svc->pending($le, $from, $to) : [];
        return $this->page('@overtime/admin', [
            'title' => $this->app->translator()->get('overtime.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'le' => $le, 'rules' => $svc->rules($le, $today),
            'canPolicy' => $az->scope('overtime.policy')->coversLegalEntity($le), 'batches' => $svc->batches($le), 'preview' => $preview, 'from' => $from, 'to' => $to,
            'errors' => $errors, 'old' => $old, 'form' => $form, 'today' => $today,
        ], $status);
    }

    public function proposeRules(Request $r): Response
    {
        $in = ['legal_entity' => $this->str($r, 'legal_entity', 26), 'monthly_cap_hours' => $this->str($r, 'monthly_cap_hours', 4), 'cap_mode' => $this->str($r, 'cap_mode', 5),
            'backdate_days' => $this->str($r, 'backdate_days', 4), 'round_minutes' => $this->str($r, 'round_minutes', 3), 'min_minutes' => $this->str($r, 'min_minutes', 4),
            'effective_from' => $this->str($r, 'effective_from', 10), 'source_reference' => $this->str($r, 'source_reference', 255), 'reason' => $this->str($r, 'reason', 255)];
        $rates = [];
        foreach (OtRules::DAY_TYPES as $d) {
            $rates[$d] = ['class' => mb_substr(trim((string) ($r->body['rates'][$d]['class'] ?? '')), 0, 20), 'multiplier' => mb_substr(trim((string) ($r->body['rates'][$d]['multiplier'] ?? '')), 0, 6)];
        }
        $in['rates'] = $rates;
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$in['legal_entity']]);
        try {
            $this->svc()->proposeRules($this->ctx($r)->az(), $le, $in, $in['effective_from'], $in['source_reference'], $in['reason']);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $in, 'rules', 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->admin($r, ['source_reference' => $this->app->translator()->get($e->messageKey)], $in, 'rules', 422);
        }
        $this->flash($r, 'success', 'overtime.flash.rules_proposed');
        return $this->redirect($r, '/overtime/admin?le=' . rawurlencode($in['legal_entity']));
    }

    public function createBatch(Request $r): Response
    {
        $in = ['legal_entity' => $this->str($r, 'legal_entity', 26), 'period_start' => $this->str($r, 'period_start', 10), 'period_end' => $this->str($r, 'period_end', 10), 'note' => $this->str($r, 'note', 255)];
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$in['legal_entity']]);
        try {
            $pid = $this->svc()->createBatch($this->ctx($r)->az(), $le, $in['period_start'], $in['period_end'], $in['note']);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $in, 'batch', 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->admin($r, ['period_end' => $this->app->translator()->get($e->messageKey)], $in, 'batch', 422);
        }
        $this->flash($r, 'success', 'overtime.flash.batch_created');
        return $this->redirect($r, '/overtime/batch/' . $pid);
    }

    public function batch(Request $r): Response
    {
        $b = $this->svc()->batch($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->page('@overtime/batch', ['title' => $this->app->translator()->get('overtime.batch_title', ['n' => (string) $b['batch_no']]), 'b' => $b]);
    }

    public function file(Request $r): Response
    {
        $f = $this->svc()->export($this->ctx($r)->az(), (string) $r->params['id'], ($r->query['format'] ?? '') === 'csv' ? 'csv' : 'xlsx');
        return Response::attachment($f['bytes'], $f['mime'], $f['name']);
    }

    public function report(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $svc = $this->svc();
        $ym = (string) ($r->query['month'] ?? substr($svc->today(), 0, 7));
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym) !== 1) {
            $ym = substr($svc->today(), 0, 7);
        }
        $group = in_array($r->query['group'] ?? '', ['employee', 'department', 'site'], true) ? (string) $r->query['group'] : 'employee';
        $rows = $svc->report($az, $ym, $group);
        if (($r->query['format'] ?? '') !== '') {
            $headers = ['label', 'requests', 'pending_minutes', 'approved_minutes', 'workday_minutes', 'dayoff_minutes', 'holiday_minutes', 'sent_minutes', 'cap_warnings'];
            return \Blue\Core\Files\TableExport::response(
                \Blue\Core\Files\TableExport::format($r->query['format']),
                'overtime-' . $ym . '-' . $group,
                $headers,
                array_map(static fn (array $x): array => array_map(static fn (string $h) => $x[$h], $headers), $rows),
                range(1, 8)
            );
        }
        return $this->page('@overtime/report', ['title' => $this->app->translator()->get('overtime.report_title'), 'rows' => $rows, 'month' => $ym, 'group' => $group]);
    }

    /** @return array<string, string> companies (public id => code) for overtime.export */
    private function companies(Authorizer $az): array
    {
        $scope = $az->scope('overtime.export');
        $out = [];
        foreach ($this->app->db()->all('SELECT id, public_id, code FROM legal_entities ORDER BY code') as $l) {
            if ($scope->coversLegalEntity((int) $l['id'])) {
                $out[(string) $l['public_id']] = (string) $l['code'];
            }
        }
        return $out;
    }
}
