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

/** 4C screens (DEC-66): attendance periods — create, review exceptions, send for close, reopen, payroll-input file. */
final class PeriodController extends Controller
{
    private function svc(): PeriodService
    {
        return PeriodService::of($this->app);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    public function index(Request $r, array $errors = [], array $old = [], int $status = 200): Response
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
        $periods = $this->svc()->list($az, $le);
        $last = $periods[0] ?? null;
        $suggest = $this->suggest($last);
        return $this->page('@attendance/periods', [
            'title' => $this->app->translator()->get('attendance.periods_title'), 'companies' => $companies, 'le' => $le, 'periods' => $periods,
            'canManage' => $az->scope('attendance.period')->coversLegalEntity($le), 'errors' => $errors,
            'old' => $old + $suggest, 'today' => AttendanceService::of($this->app)->today(),
        ], $status);
    }

    public function create(Request $r): Response
    {
        $in = ['le' => $this->str($r, 'le', 12), 'period_start' => $this->str($r, 'period_start', 10), 'period_end' => $this->str($r, 'period_end', 10), 'label' => $this->str($r, 'label', 60)];
        try {
            $pid = $this->svc()->create($this->ctx($r)->az(), (int) $in['le'], $in['period_start'], $in['period_end'], $in['label']);
        } catch (ValidationException $e) {
            return $this->index($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'attendance.period_flash.created');
        return $this->redirect($r, '/attendance/periods/' . $pid);
    }

    /** @param array<string, string> $errors */
    public function show(Request $r, array $errors = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $svc = $this->svc();
        $p = $svc->get($az, (string) $r->params['id']);
        $canManage = $az->scope('attendance.period')->coversLegalEntity((int) $p['legal_entity_id']);
        $final = in_array($p['status'], ['finalized', 'reopen_pending'], true);
        $review = !$final ? $svc->review($p, $p['status'] === 'open' && $canManage) : null;
        $snap = $final ? $svc->snapshot($p) : null;
        $table = $final && $snap !== null ? $svc->table($snap) : $svc->table($svc->summary($p));
        return $this->page('@attendance/period', [
            'title' => $this->app->translator()->get('attendance.period_title', ['range' => $svc->rangeLabel($p)]), 'p' => $p, 'review' => $review, 'snap' => $snap, 'table' => $table,
            'company' => (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$p['legal_entity_id']]),
            'canManage' => $canManage, 'canExport' => $az->scope('attendance.export')->coversLegalEntity((int) $p['legal_entity_id']),
            'exports' => $svc->exports((int) $p['id']), 'rangeLabel' => $svc->rangeLabel($p), 'errors' => $errors,
            'instance' => $p['workflow_instance_id'] !== null ? $this->app->db()->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$p['workflow_instance_id']]) : null,
            'reopenInstance' => $p['reopen_instance_id'] !== null ? $this->app->db()->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$p['reopen_instance_id']]) : null,
        ], $status);
    }

    public function submit(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->submit($this->ctx($r)->az(), $pid, $this->str($r, 'note', 500), $r->input('confirm') === '1');
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, $e->params);
            return $this->redirect($r, '/attendance/periods/' . $pid);
        }
        $this->flash($r, 'success', 'attendance.period_flash.submitted');
        return $this->redirect($r, '/attendance/periods/' . $pid);
    }

    public function reopen(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->requestReopen($this->ctx($r)->az(), $pid, $this->str($r, 'reason', 500));
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, $e->params);
            return $this->redirect($r, '/attendance/periods/' . $pid);
        }
        $this->flash($r, 'success', 'attendance.period_flash.reopen_requested');
        return $this->redirect($r, '/attendance/periods/' . $pid);
    }

    public function export(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $f = $this->svc()->export($this->ctx($r)->az(), $pid, ($r->query['format'] ?? '') === 'csv' ? 'csv' : 'xlsx');
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, $e->params);
            return $this->redirect($r, '/attendance/periods/' . $pid);
        }
        return Response::attachment($f['bytes'], $f['mime'], $f['name']);
    }

    /** @return array<int, string> companies where the user may see periods */
    private function companies(Authorizer $az): array
    {
        $out = [];
        foreach (['attendance.period', 'attendance.period_approve', 'attendance.export'] as $perm) {
            $out += $this->app->org()->scopeOptions($az->scope($perm), $this->app->translator()->locale())['legal_entity'];
        }
        ksort($out);
        return $out;
    }

    /**
     * The next period after the last one, same length in months when it was a whole month — a suggestion only.
     *
     * @param array<string, mixed>|null $last
     * @return array<string, string>
     */
    private function suggest(?array $last): array
    {
        $today = AttendanceService::of($this->app)->today();
        if ($last === null) {
            $first = $this->day(substr($today, 0, 7) . '-01')->modify('-1 month');
            return ['period_start' => $first->format('Y-m-d'), 'period_end' => $first->modify('last day of this month')->format('Y-m-d')];
        }
        $start = $this->day((string) $last['period_end'])->modify('+1 day');
        $len = (int) $this->day((string) $last['period_start'])->diff($this->day((string) $last['period_end']))->days;
        $end = $start->format('d') === '01' ? $start->modify('last day of this month') : $start->modify('+' . $len . ' days');
        return ['period_start' => $start->format('Y-m-d'), 'period_end' => $end->format('Y-m-d')];
    }

    private function day(string $ymd): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd) ?: throw new \RuntimeException('bad date ' . $ymd);
    }
}
