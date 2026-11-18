<?php

declare(strict_types=1);

namespace Blue\Modules\Dashboard;

use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\TableExport;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\I18n\DateFormat;

/** M46 screens: the dashboard (filters, refresh, export, snapshot) · snapshots · a KPI's definition · the KPI dictionary. */
final class DashboardController extends Controller
{
    private function svc(): DashboardService
    {
        return DashboardService::of($this->app);
    }

    private function t(string $key): string
    {
        return $this->app->translator()->get($key);
    }

    /**
     * @param list<int> $les
     * @return array{0: array<string, string>, 1: string, 2: int}
     */
    private function pickCompany(Request $r, array $les): array
    {
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

    /** @return array{companies: array<string, string>, lePid: string, f: array{le: int, sites: list<int>, departments: list<int>, whole: bool, asked: bool}, site: string, dept: string, month: string} */
    private function state(Request $r): array
    {
        $az = $this->ctx($r)->az();
        [$companies, $lePid, $le] = $this->pickCompany($r, $this->svc()->companies($az));
        $in = $r->method === 'POST' ? $r->body : $r->query;
        $site = is_scalar($in['site'] ?? null) ? (string) $in['site'] : '';
        $dept = is_scalar($in['dept'] ?? null) ? (string) $in['dept'] : '';
        $f = $this->svc()->filter($az, $le, $site !== '' ? [$site] : [], $dept !== '' ? [$dept] : []);
        [$from] = $this->svc()->period(is_scalar($in['month'] ?? null) ? (string) $in['month'] : '');
        return ['companies' => $companies, 'lePid' => $lePid, 'f' => $f, 'site' => $site, 'dept' => $dept, 'month' => substr($from, 0, 7)];
    }

    /** @param array<string, mixed> $s */
    private function query(array $s): string
    {
        return http_build_query(array_filter(['le' => $s['lePid'], 'site' => $s['site'], 'dept' => $s['dept'], 'month' => $s['month']], static fn (string $v): bool => $v !== ''));
    }

    /** @param array<string, string> $errors */
    public function index(Request $r, array $errors = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $s = $this->state($r);
        $cards = $this->svc()->board($az, $s['f'], $s['month']);
        $months = [];
        $m = substr($this->svc()->today(), 0, 7);
        $locale = $this->app->translator()->locale();
        for ($i = 0; $i < 13; $i++) {
            $mm = DashboardService::prevMonth($m, $i);
            $months[$mm] = (string) preg_replace('/^\d+ /', '', DateFormat::day($mm . '-01', $locale, true));
        }
        foreach ($cards as $c) {
            foreach ($c['trend'] as $p) {
                $months[$p['month']] ??= (string) preg_replace('/^\d+ /', '', DateFormat::day($p['month'] . '-01', $locale));
            }
        }
        return $this->page('@dashboard/index', ['title' => $this->t('dashboard.title'), 'cards' => $cards, 'st' => $s, 'qs' => $this->query($s), 'months' => $months,
            'opts' => $this->svc()->filterOptions($az, $s['f']['le']), 'canManage' => $az->scope('dashboard.manage')->coversLegalEntity($s['f']['le']), 'errors' => $errors], $status)
            ->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function refresh(Request $r): Response
    {
        $s = $this->state($r);
        $this->svc()->board($this->ctx($r)->az(), $s['f'], $s['month'], true);
        $this->flash($r, 'success', 'dashboard.flash.refreshed');
        return $this->redirect($r, '/dashboard?' . $this->query($s));
    }

    public function export(Request $r): Response
    {
        $s = $this->state($r);
        $lines = $this->svc()->lines($this->svc()->board($this->ctx($r)->az(), $s['f'], $s['month']));
        $rows = [];
        foreach ($lines as $l) {
            $rows[] = [$l['code'], $l['name'], (string) $l['version'], $l['from'], $l['to'], $l['value'] === null ? '' : (string) round((float) $l['value'], 2), $this->t('dashboard.unit.' . $l['unit']),
                $this->t('dashboard.status.' . $l['status']), (string) ($l['computed_at'] ?? ''), $l['formula'], $l['measure']];
        }
        $headers = [$this->t('dashboard.col.code'), $this->t('dashboard.col.kpi'), $this->t('dashboard.col.version'), $this->t('dashboard.col.from'), $this->t('dashboard.col.to'),
            $this->t('dashboard.col.value'), $this->t('dashboard.col.unit'), $this->t('dashboard.col.status'), $this->t('dashboard.col.computed_at'), $this->t('dashboard.col.formula'), $this->t('dashboard.col.measure')];
        $this->app->audit()->record('dashboard.exported', 'success', 'legal_entity', $s['lePid'], $s['f']['le'], null, ['month' => $s['month'], 'sites' => $s['f']['sites'], 'departments' => $s['f']['departments']]);
        return TableExport::response(($r->query['format'] ?? '') === 'csv' ? 'csv' : 'xlsx', 'dashboard-' . $s['month'], $headers, $rows, [5]);
    }

    public function snapshot(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $s = $this->state($r);
        try {
            $pid = $this->svc()->snapshot($az, $s['f'], $s['month'], $this->svc()->board($az, $s['f'], $s['month']), mb_substr(is_scalar($r->body['note'] ?? null) ? (string) $r->body['note'] : '', 0, 500));
        } catch (ValidationException $e) {
            return $this->index($r, $this->errors($e), 422);
        }
        $this->flash($r, 'success', 'dashboard.flash.snapshot');
        return $this->redirect($r, '/dashboard/snapshots/' . $pid);
    }

    public function snapshots(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        [$companies, $lePid, $le] = $this->pickCompany($r, $this->svc()->companies($az));
        return $this->page('@dashboard/snapshots', ['title' => $this->t('dashboard.snapshots_title'), 'companies' => $companies, 'lePid' => $lePid, 'rows' => $this->svc()->snapshots($az, $le)]);
    }

    public function snapshotShow(Request $r): Response
    {
        $snap = $this->svc()->snapshotRow($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->page('@dashboard/snapshot', ['title' => $this->t('dashboard.snapshot_title'), 'snap' => $snap, 'lines' => (array) json_decode((string) $snap['values_json'], true),
            'filter' => (array) json_decode((string) $snap['filter_json'], true), 'lePid' => (string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$snap['legal_entity_id']])]);
    }

    public function definition(Request $r): Response
    {
        $k = $this->svc()->kpi($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->page('@dashboard/definition', ['title' => (string) $k['name_th'], 'k' => $k, 'm' => $this->svc()->catalog()[(string) $k['measure']] ?? null, 'versions' => $this->svc()->versions((int) $k['id']),
            'lePid' => (string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$k['legal_entity_id']])]);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function kpis(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $les = array_values(array_filter($this->svc()->companies($az, 'dashboard.manage'), static fn (int $id): bool => $az->scope('dashboard.manage')->coversLegalEntity($id)));
        [$companies, $lePid, $le] = $this->pickCompany($r, $les);
        $edit = null;
        if (isset($r->params['id'])) {
            $edit = $this->svc()->kpi($az, (string) $r->params['id'], true);
            $le = (int) $edit['legal_entity_id'];
            $lePid = (string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        }
        $catalog = $this->svc()->catalog();
        $measures = [];
        foreach ($catalog as $code => $m) {
            $measures[$code] = $this->t((string) $m['label']) . ' · ' . $this->t((string) $m['source']);
        }
        return $this->page('@dashboard/kpis', ['title' => $this->t('dashboard.kpis_title'), 'companies' => $companies, 'lePid' => $lePid, 'rows' => $this->svc()->kpis($le), 'edit' => $edit,
            'catalog' => $catalog, 'measures' => $measures, 'versions' => $edit !== null ? $this->svc()->versions((int) $edit['id']) : [], 'errors' => $errors, 'old' => $old], $status);
    }

    public function saveKpi(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $pid = isset($r->params['id']) ? (string) $r->params['id'] : null;
        if ($pid !== null) {
            $le = (int) $this->svc()->kpi($az, $pid, true)['legal_entity_id'];
        } else {
            $les = array_values(array_filter($this->svc()->companies($az, 'dashboard.manage'), static fn (int $id): bool => $az->scope('dashboard.manage')->coversLegalEntity($id)));
            [, , $le] = $this->pickCompany($r, $les);
        }
        $in = [];
        foreach (['code' => 30, 'measure' => 60, 'name_th' => 120, 'name_en' => 120, 'direction' => 4, 'warn_at' => 16, 'alert_at' => 16, 'refresh_minutes' => 5, 'owner' => 100, 'status' => 10, 'sort_order' => 4] as $k => $max) {
            $in[$k] = $this->str($r, $k, $max);
        }
        $in['purpose'] = mb_substr(is_scalar($r->body['purpose'] ?? null) ? (string) $r->body['purpose'] : '', 0, 500);
        $in['reason'] = mb_substr(is_scalar($r->body['reason'] ?? null) ? (string) $r->body['reason'] : '', 0, 300);
        try {
            $pid = $this->svc()->saveKpi($az, $le, $in, $pid);
        } catch (ValidationException $e) {
            return $this->kpis($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'dashboard.flash.kpi_saved');
        return $this->redirect($r, '/dashboard/kpis/' . $pid);
    }
}
