<?php

declare(strict_types=1);

namespace Blue\Core\Calendar;

use DateTimeImmutable;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\TableExport;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** Work & service calendars (03 §6.14): list, month view, holidays, settings, import/export, "is it a working day" API. */
final class CalendarController extends Controller
{
    /**
     * "Work calendar" for everyone (0.6.1): the calendar that applies to me — month view, today, this year's holidays.
     * ?cal= shows another calendar the person may view; managing calendars lives under /admin/calendars.
     */
    public function index(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $az = $ctx->az();
        $svc = $this->app->calendars();
        $tz = new \DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
        $today = $this->app->clock->now()->setTimezone($tz)->format('Y-m-d');
        $ctxLe = $this->store($r)->get('ctx_le');
        $mine = $svc->forUser($ctx->userId(), is_int($ctxLe) ? $ctxLe : $this->firstCompany($az), $today);
        $others = $az->can('core.calendar.view') || $az->can('core.calendar.manage')
            ? array_column(array_filter($svc->list($az), static fn (array $c): bool => $c['calendar_type'] === 'work'), 'name', 'public_id') : [];
        $pick = $this->str($r, 'cal', 26);
        $cal = $mine['calendar'];
        if ($pick !== '' && ($cal === null || $pick !== (string) $cal['public_id'])) {
            $cal = $svc->get($az, $pick); // 404/403 unless the person may view it
        }
        $month = $this->month($r, $today);
        [$y, $mo] = array_map('intval', explode('-', $month));
        $first = new DateTimeImmutable($month . '-01');
        $days = $cal !== null ? $svc->month($cal, $y, $mo) : [];
        $count = ['working' => 0, 'holiday' => 0, 'weekend' => 0];
        foreach ($days as $d) {
            $count[$d['type']] = ($count[$d['type']] ?? 0) + 1;
        }
        return $this->page('calendars/mine', [
            'title' => $this->app->translator()->get('calendar.title'), 'cal' => $cal, 'via' => $cal !== null && $cal === $mine['calendar'] ? $mine['via'] : 'picked',
            'isMine' => $cal !== null && $mine['calendar'] !== null && $cal['public_id'] === $mine['calendar']['public_id'],
            'month' => $month, 'days' => $days, 'count' => $count, 'today' => $today,
            'todayInfo' => $cal !== null ? $svc->day($cal, $today) : null,
            'prev' => $first->modify('-1 month')->format('Y-m'), 'next' => $first->modify('+1 month')->format('Y-m'),
            'year' => $y, 'exceptions' => $cal !== null ? $svc->yearExceptions($cal, $y) : [],
            'others' => $others, 'canManage' => !$az->scope('core.calendar.manage')->isEmpty(),
        ]);
    }

    /** Calendar administration (list + create) — people who manage calendars. */
    public function admin(Request $r): Response
    {
        return $this->indexPage($r, [], []);
    }

    /** Old /calendars/{id} links: managers go to the admin page, everyone else to the read-only view. */
    public function legacy(Request $r): Response
    {
        $cal = $this->app->calendars()->get($this->ctx($r)->az(), (string) $r->params['id']);
        $q = isset($r->query['month']) ? '&month=' . rawurlencode((string) $r->query['month']) : '';
        return $this->redirect($r, $cal['can_manage'] ? '/admin/calendars/' . $cal['public_id'] . ($q !== '' ? '?' . substr($q, 1) : '') : '/calendars?cal=' . $cal['public_id'] . $q);
    }

    public function create(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $scope = $this->str($r, 'scope', 40);
        [$level, $id] = array_pad(explode(':', $scope, 2), 2, '0');
        $in = [
            'code' => strtoupper($this->str($r, 'code', 40)), 'name' => $this->str($r, 'name', 120), 'type' => $this->str($r, 'type', 10),
            'scope_level' => $level, 'scope_id' => (int) $id, 'pattern' => $this->pattern($r), 'base' => $this->str($r, 'base', 26),
        ];
        try {
            $pid = $this->app->calendars()->create($az, $in);
        } catch (ValidationException $e) {
            return $this->indexPage($r, $this->errors($e), $in + ['scope' => $scope], 422);
        }
        $this->flash($r, 'success', 'calendar.flash.created');
        return $this->redirect($r, '/admin/calendars/' . $pid);
    }

    public function show(Request $r): Response
    {
        return $this->showPage($r, [], []);
    }

    public function update(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $in = ['name' => $this->str($r, 'name', 120), 'pattern' => $this->pattern($r), 'base' => $this->str($r, 'base', 26), 'reason' => $this->str($r, 'reason', 500)];
        try {
            $this->app->calendars()->update($this->ctx($r)->az(), $pid, $in);
        } catch (ValidationException $e) {
            return $this->showPage($r, $this->errors($e), ['settings' => $in], 422);
        }
        $this->flash($r, 'success', 'calendar.flash.updated');
        return $this->redirect($r, '/admin/calendars/' . $pid);
    }

    public function setDay(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $in = ['date' => $this->str($r, 'date', 10), 'type' => $this->str($r, 'day_type', 10), 'kind' => $this->str($r, 'kind', 10), 'name' => $this->str($r, 'name', 120), 'note' => $this->str($r, 'note', 255)];
        try {
            $this->app->calendars()->setDay($this->ctx($r)->az(), $pid, $in['date'], $in['type'], $in['kind'] !== '' ? $in['kind'] : null, $in['name'], $in['note']);
        } catch (ValidationException $e) {
            return $this->showPage($r, $this->errors($e), ['day' => $in], 422);
        }
        $this->flash($r, 'success', 'calendar.flash.day_saved');
        return $this->redirect($r, '/admin/calendars/' . $pid . '?month=' . substr($in['date'], 0, 7));
    }

    public function removeDay(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $date = (string) $r->params['date'];
        $this->app->calendars()->removeDay($this->ctx($r)->az(), $pid, $date);
        $this->flash($r, 'success', 'calendar.flash.day_removed');
        return $this->redirect($r, '/admin/calendars/' . $pid . '?month=' . substr($date, 0, 7));
    }

    public function export(Request $r): Response
    {
        $c = $this->app->calendars()->get($this->ctx($r)->az(), (string) $r->params['id']);
        $year = $this->year($r);
        $t = $this->app->translator();
        $rows = array_map(static fn (array $d): array => [(string) $d['day_date'], (string) $d['day_type'], (string) ($d['holiday_kind'] ?? ''), (string) ($d['name'] ?? ''), (string) ($d['note'] ?? '')], $this->app->calendars()->days((int) $c['id'], $year));
        $this->app->audit()->record('calendar.exported', 'success', 'calendar', (string) $c['public_id'], null, null, ['year' => $year, 'rows' => count($rows)]);
        // same column keys as the import template, so an export can be edited and imported back
        return TableExport::response(TableExport::format($r->query['format'] ?? 'xlsx'), 'calendar-' . $c['code'] . '-' . $year, ['date', 'type', 'kind', 'name', 'note'], $rows);
    }

    /** JSON for modules / pages: { working: true|false|null, calendar, type, name } — needs calendar view rights. */
    public function api(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $date = $this->str($r, 'date', 10);
        if (DateTimeImmutable::createFromFormat('!Y-m-d', $date) === false) {
            return Response::json(['error' => 'date must be YYYY-MM-DD'], 400);
        }
        $site = ctype_digit($this->str($r, 'site', 20)) ? (int) $this->str($r, 'site', 20) : null;
        $le = ctype_digit($this->str($r, 'legal_entity', 20)) ? (int) $this->str($r, 'legal_entity', 20) : null;
        $res = $this->app->calendars()->isWorkingDay($site, $le, $date, $this->str($r, 'type', 10) === 'service' ? 'service' : 'work');
        if ($res['calendar'] !== null) {
            $cal = $this->app->db()->one('SELECT * FROM calendars WHERE code = ?', [$res['calendar']]) ?? [];
            if (!$this->app->calendars()->allowed($az, 'core.calendar.view', $cal) && !$this->app->calendars()->allowed($az, 'core.calendar.manage', $cal)) {
                throw HttpException::forbidden();
            }
        } elseif (!$az->can('core.calendar.view')) {
            throw HttpException::forbidden();
        }
        return Response::json(['date' => $date] + $res);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private function indexPage(Request $r, array $errors, array $old, int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $manage = $az->scope('core.calendar.manage');
        if ($manage->isEmpty()) {
            throw HttpException::forbidden();
        }
        $opts = $this->app->org()->scopeOptions($manage, $this->app->translator()->locale());
        $scopes = [];
        if ($manage->all) {
            $scopes['organization:' . (int) $this->app->db()->scalar('SELECT id FROM organizations ORDER BY id LIMIT 1')] = $this->app->translator()->get('config.scope.organization');
        }
        foreach (['legal_entity', 'site'] as $lv) {
            foreach ($opts[$lv] as $id => $label) {
                $scopes[$lv . ':' . $id] = $this->app->translator()->get('config.scope.' . $lv) . ' ' . $label;
            }
        }
        $list = $this->app->calendars()->list($az);
        return $this->page('calendars/index', [
            'title' => $this->app->translator()->get('calendar.manage_title'), 'calendars' => $list, 'canCreate' => true, 'scopes' => $scopes,
            'bases' => array_column(array_filter($list, static fn (array $c): bool => $c['calendar_type'] === 'work'), 'name', 'public_id'),
            'errors' => $errors, 'old' => $old + ['code' => '', 'name' => '', 'type' => 'work', 'scope' => '', 'pattern' => '0111110', 'base' => ''],
        ], $status);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, array<string, string>> $old
     */
    private function showPage(Request $r, array $errors, array $old, int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $cal = $this->app->calendars()->get($az, (string) $r->params['id'], true);
        $tz = new \DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
        $month = $this->month($r, $this->app->clock->now()->setTimezone($tz)->format('Y-m-d'));
        [$y, $mo] = array_map('intval', explode('-', $month));
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $mo));
        return $this->page('calendars/show', [
            'title' => (string) $cal['name'], 'cal' => $cal, 'month' => $month, 'days' => $this->app->calendars()->month($cal, $y, $mo),
            'prev' => $first->modify('-1 month')->format('Y-m'), 'next' => $first->modify('+1 month')->format('Y-m'),
            'exceptions' => $this->app->calendars()->days((int) $cal['id'], $y), 'year' => $y,
            'today' => $this->app->clock->now()->setTimezone($tz)->format('Y-m-d'),
            'bases' => array_column(array_filter($this->app->calendars()->list($az), static fn (array $c): bool => $c['calendar_type'] === 'work' && $c['public_id'] !== $cal['public_id']), 'name', 'public_id'),
            'errors' => $errors, 'old' => $old,
        ], $status);
    }

    /** Weekly pattern from 7 checkboxes (wd0 = Sunday … wd6 = Saturday). */
    private function pattern(Request $r): string
    {
        $p = '';
        for ($i = 0; $i < 7; $i++) {
            $p .= $r->input('wd' . $i) === '1' ? '1' : '0';
        }
        return $p;
    }

    /** ?month=YYYY-MM, or the month of $today */
    private function month(Request $r, string $today): string
    {
        $month = $this->str($r, 'month', 7);
        if (preg_match('/^(\d{4})-(\d{2})$/', $month, $m) !== 1 || (int) $m[1] < 2000 || (int) $m[1] > 2100 || (int) $m[2] < 1 || (int) $m[2] > 12) {
            return substr($today, 0, 7);
        }
        return $month;
    }

    private function firstCompany(\Blue\Core\Authz\Authorizer $az): ?int
    {
        $opts = $this->app->org()->scopeOptions($az->reachableScope(), $this->app->translator()->locale())['legal_entity'];
        return $opts === [] ? null : (int) array_key_first($opts);
    }

    private function year(Request $r): int
    {
        $y = (int) $this->str($r, 'year', 4);
        return $y >= 2000 && $y <= 2100 ? $y : (int) $this->app->clock->now()->format('Y');
    }
}
