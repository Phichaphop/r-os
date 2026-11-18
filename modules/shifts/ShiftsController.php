<?php

declare(strict_types=1);

namespace Blue\Modules\Shifts;

use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Modules\Employees\EmployeeService;

/** M35 screens: my shifts + swaps · planning (patterns, rosters, the grid, publish/revise) · company rules. */
final class ShiftsController extends Controller
{
    private const PATTERN = ['id' => 26, 'legal_entity' => 26, 'code' => 20, 'name' => 120, 'start' => 5, 'end' => 5, 'break_minutes' => 3, 'late_grace_minutes' => 3,
        'early_grace_minutes' => 3, 'tone' => 10, 'site' => 26, 'status' => 10, 'reason' => 255];
    private const ROSTER = ['legal_entity' => 26, 'site' => 26, 'department' => 26, 'name' => 120, 'start_date' => 10, 'end_date' => 10, 'note' => 500];

    private function svc(): ShiftService
    {
        return ShiftService::of($this->app);
    }

    // ---- employee ------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    public function index(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('shifts.use')) {
            throw HttpException::forbidden();
        }
        $svc = $this->svc();
        $me = $svc->emp()->forUser($az->userId);
        $today = $svc->today();
        $to = EmployeeService::ymd($today)->modify('+34 day')->format('Y-m-d');
        $mine = $me !== null ? $svc->shiftsFor((int) $me['id'], $today, $to) : [];
        $colleagues = [];
        if ($me !== null && $me['job'] !== null) {
            foreach (
                $this->app->db()->all(
                    "SELECT DISTINCT e.public_id, e.employee_code, e.name_th, e.name_en, e.preferred_name FROM sh_assignments a JOIN sh_rosters x ON x.id = a.roster_id AND x.status = 'published'
                       JOIN employees e ON e.id = a.employee_id WHERE x.legal_entity_id = ? AND a.work_date BETWEEN ? AND ? AND a.employee_id <> ? AND e.status <> 'former' ORDER BY e.employee_code",
                    [(int) $me['job']['legal_entity_id'], $today, $to, (int) $me['id']]
                ) as $c
            ) {
                $colleagues[(string) $c['public_id']] = $c['employee_code'] . ' · ' . $svc->emp()->displayName($c);
            }
        }
        return $this->page('@shifts/index', [
            'title' => $this->app->translator()->get('shifts.title'), 'me' => $me, 'mine' => $mine, 'today' => $today, 'to' => $to,
            'swaps' => $me !== null ? SwapService::of($this->app)->forEmployee((int) $me['id']) : [], 'colleagues' => $colleagues,
            'canManage' => !$az->scope('shifts.manage')->isEmpty(), 'errors' => $errors, 'old' => $old,
        ], $status);
    }

    public function requestSwap(Request $r): Response
    {
        $in = ['partner' => $this->str($r, 'partner', 26), 'my_date' => $this->str($r, 'my_date', 10), 'partner_date' => $this->str($r, 'partner_date', 10), 'reason' => $this->str($r, 'reason', 500)];
        try {
            SwapService::of($this->app)->request($this->ctx($r)->az(), $in);
        } catch (ValidationException $e) {
            return $this->index($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'shifts.flash.swap_requested');
        return $this->redirect($r, '/shifts');
    }

    public function answerSwap(Request $r): Response
    {
        $accept = $r->input('answer') === 'accept';
        SwapService::of($this->app)->answer($this->ctx($r)->az(), (string) $r->params['id'], $accept, $this->str($r, 'note', 255));
        $this->flash($r, 'success', $accept ? 'shifts.flash.swap_agreed' : 'shifts.flash.swap_declined');
        return $this->redirect($r, '/shifts');
    }

    public function cancelSwap(Request $r): Response
    {
        SwapService::of($this->app)->cancel($this->ctx($r)->az(), (string) $r->params['id']);
        $this->flash($r, 'success', 'shifts.flash.swap_cancelled');
        return $this->redirect($r, '/shifts');
    }

    // ---- planning --------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
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
        $edit = null;
        if (($r->query['pattern'] ?? '') !== '' && $form === '') {
            $edit = $this->app->db()->one('SELECT p.*, s.public_id AS site_pid FROM sh_patterns p LEFT JOIN sites s ON s.id = p.site_id WHERE p.public_id = ? AND p.legal_entity_id = ?', [(string) $r->query['pattern'], $le]);
        }
        $canPolicy = $az->scope('shifts.policy')->coversLegalEntity($le);
        return $this->page('@shifts/admin', [
            'title' => $this->app->translator()->get('shifts.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'le' => $le,
            'patterns' => $svc->patterns($le), 'rosters' => array_values(array_filter($svc->rosters($az), static fn (array $x): bool => (int) $x['legal_entity_id'] === $le)),
            'sites' => $this->options('sites', $le), 'units' => $this->options('departments', $le),
            'canCompany' => $az->scope('shifts.manage')->coversLegalEntity($le), 'canPolicy' => $canPolicy,
            'rules' => $svc->rules($le, $svc->today()), 'errors' => $errors, 'old' => $old, 'form' => $form, 'edit' => $edit, 'today' => $svc->today(),
            'svc' => $svc,
        ], $status);
    }

    public function savePattern(Request $r): Response
    {
        $in = [];
        foreach (self::PATTERN as $k => $max) {
            $in[$k] = $this->str($r, $k, $max);
        }
        try {
            $this->svc()->savePattern($this->ctx($r)->az(), $in['id'] !== '' ? $in['id'] : null, $in);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $in, 'pattern', 422);
        }
        $this->flash($r, 'success', 'shifts.flash.pattern_saved');
        return $this->redirect($r, '/shifts/admin?le=' . rawurlencode($in['legal_entity']));
    }

    public function createRoster(Request $r): Response
    {
        $in = [];
        foreach (self::ROSTER as $k => $max) {
            $in[$k] = $this->str($r, $k, $max);
        }
        try {
            $pid = $this->svc()->createRoster($this->ctx($r)->az(), $in);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $in, 'roster', 422);
        }
        $this->flash($r, 'success', 'shifts.flash.roster_created');
        return $this->redirect($r, '/shifts/roster/' . $pid);
    }

    public function proposeRules(Request $r): Response
    {
        $in = ['legal_entity' => $this->str($r, 'legal_entity', 26), 'min_rest_hours' => $this->str($r, 'min_rest_hours', 3), 'max_consecutive_days' => $this->str($r, 'max_consecutive_days', 3),
            'effective_from' => $this->str($r, 'effective_from', 10), 'source_reference' => $this->str($r, 'source_reference', 255), 'reason' => $this->str($r, 'reason', 255)];
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
        $this->flash($r, 'success', 'shifts.flash.rules_proposed');
        return $this->redirect($r, '/shifts/admin?le=' . rawurlencode($in['legal_entity']));
    }

    // ---- the roster grid -------------------------------------------------------------------------------------------

    /** @param array<string, string> $errors */
    public function roster(Request $r, array $errors = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $svc = $this->svc();
        $ro = $svc->roster($az, (string) $r->params['id']);
        $dates = [];
        for ($d = EmployeeService::ymd((string) $ro['start_date']); $d->format('Y-m-d') <= (string) $ro['end_date']; $d = $d->modify('+1 day')) {
            $dates[] = $d->format('Y-m-d');
        }
        $patterns = $svc->patterns((int) $ro['legal_entity_id']);
        $byId = [];
        foreach ($patterns as $p) {
            $byId[(int) $p['id']] = $p;
        }
        $draft = $ro['status'] === 'draft';
        return $this->page('@shifts/roster', [
            'title' => (string) $ro['name'], 'ro' => $ro, 'dates' => $dates, 'people' => $svc->people($ro), 'cells' => $svc->cells((int) $ro['id']),
            'patterns' => $patterns, 'byId' => $byId, 'draft' => $draft, 'check' => $draft ? $svc->check($ro) : null,
            'diff' => $draft && $ro['supersedes_id'] !== null ? count($svc->diff($ro)) : 0, 'changes' => $draft ? [] : $svc->changes($ro, 100),
            'holidays' => $this->holidays((int) $ro['legal_entity_id'], $ro['site_id'] !== null ? (int) $ro['site_id'] : null, $dates),
            'errors' => $errors, 'today' => $svc->today(),
        ], $status);
    }

    public function saveCells(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $cells = $r->body['cell'] ?? [];
        try {
            $this->svc()->saveCells($this->ctx($r)->az(), $pid, is_array($cells) ? $cells : []);
        } catch (ValidationException $e) {
            return $this->roster($r, $this->errors($e), 422);
        }
        $this->flash($r, 'success', 'shifts.flash.saved');
        return $this->redirect($r, '/shifts/roster/' . $pid);
    }

    public function fill(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $emps = $r->body['employees'] ?? [];
        $days = $r->body['weekdays'] ?? [];
        try {
            $this->svc()->fill(
                $this->ctx($r)->az(),
                $pid,
                is_array($emps) ? array_map('strval', $emps) : [],
                $this->str($r, 'from', 10),
                $this->str($r, 'to', 10),
                is_array($days) ? array_values($days) : [],
                $this->str($r, 'pattern', 26)
            );
        } catch (ValidationException $e) {
            return $this->roster($r, $this->errors($e), 422);
        }
        $this->flash($r, 'success', 'shifts.flash.saved');
        return $this->redirect($r, '/shifts/roster/' . $pid);
    }

    public function copyWeek(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->copyWeek($this->ctx($r)->az(), $pid, $this->str($r, 'source', 10), $this->str($r, 'target', 10));
        } catch (ValidationException $e) {
            return $this->roster($r, $this->errors($e), 422);
        }
        $this->flash($r, 'success', 'shifts.flash.copied');
        return $this->redirect($r, '/shifts/roster/' . $pid);
    }

    public function publish(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $res = $this->svc()->publish($this->ctx($r)->az(), $pid, $r->input('acknowledge') === '1');
        } catch (ValidationException $e) {
            return $this->roster($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->roster($r, ['publish' => $this->app->translator()->get($e->messageKey)], 422);
        }
        $this->flash($r, 'success', 'shifts.flash.published', ['n' => (string) $res['notified']]);
        return $this->redirect($r, '/shifts/roster/' . $pid);
    }

    public function revise(Request $r): Response
    {
        $new = $this->svc()->revise($this->ctx($r)->az(), (string) $r->params['id']);
        $this->flash($r, 'info', 'shifts.flash.revision');
        return $this->redirect($r, '/shifts/roster/' . $new);
    }

    public function discard(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $ro = $this->svc()->roster($az, (string) $r->params['id']);
        $this->svc()->discard($az, (string) $r->params['id']);
        $this->flash($r, 'success', 'shifts.flash.discarded');
        return $this->redirect($r, $ro['supersedes_pid'] !== null ? '/shifts/roster/' . $ro['supersedes_pid'] : '/shifts/admin?le=' . rawurlencode((string) $ro['le_pid']));
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

    /** @return array<string, string> companies (public id => label) where the user may plan shifts */
    private function companies(Authorizer $az): array
    {
        $scope = $az->scope('shifts.manage');
        if ($scope->isEmpty()) {
            return [];
        }
        $out = [];
        foreach ($this->app->db()->all('SELECT l.id, l.public_id, l.code FROM legal_entities l ORDER BY l.code') as $l) {
            $id = (int) $l['id'];
            $mine = $scope->all || in_array($id, $scope->legalEntityIds, true)
                || ($scope->siteIds !== [] && $this->app->db()->scalar('SELECT id FROM sites WHERE legal_entity_id = ? AND id IN (' . implode(',', array_map('intval', $scope->siteIds)) . ') LIMIT 1', [$id]) !== null)
                || ($scope->departmentIds !== [] && $this->app->db()->scalar('SELECT id FROM departments WHERE legal_entity_id = ? AND id IN (' . implode(',', array_map('intval', $scope->departmentIds)) . ') LIMIT 1', [$id]) !== null);
            if ($mine) {
                $out[(string) $l['public_id']] = (string) $l['code'];
            }
        }
        return $out;
    }

    /** @return array<string, string> public id => "CODE" */
    private function options(string $table, int $le): array
    {
        $out = [];
        foreach ($this->app->db()->all("SELECT public_id, code FROM {$table} WHERE legal_entity_id = ? AND status = 'active' ORDER BY code", [$le]) as $x) {
            $out[(string) $x['public_id']] = (string) $x['code'];
        }
        return $out;
    }

    /**
     * @param list<string> $dates
     * @return array<string, string> date => calendar type for non-working days (holiday / weekend)
     */
    private function holidays(int $le, ?int $site, array $dates): array
    {
        $cal = $this->app->calendars()->resolve($site, $le);
        if ($cal === null) {
            return [];
        }
        $out = [];
        foreach ($dates as $d) {
            $x = $this->app->calendars()->day($cal, $d);
            if (!$x['working']) {
                $out[$d] = (string) $x['type'];
            }
        }
        return $out;
    }
}
