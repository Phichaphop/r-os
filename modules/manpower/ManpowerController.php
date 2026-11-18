<?php

declare(strict_types=1);

namespace Blue\Modules\Manpower;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\TableExport;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** M11 screens: plans of a company + headcount history · one plan: lines (draft), plan vs actual, what-if against the active plan, export. */
final class ManpowerController extends Controller
{
    private function svc(): ManpowerService
    {
        return ManpowerService::of($this->app);
    }

    private function t(string $key): string
    {
        return $this->app->translator()->get($key);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function index(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $les = $this->svc()->companies($az);
        if ($les === []) {
            throw HttpException::forbidden();
        }
        $companies = [];
        foreach ($les as $id) {
            $row = $this->app->db()->one('SELECT public_id, code FROM legal_entities WHERE id = ?', [$id]);
            $companies[(string) $row['public_id']] = (string) $row['code'];
        }
        $lePid = (string) ($old['le'] ?? $r->query['le'] ?? '');
        if (!isset($companies[$lePid])) {
            $lePid = (string) array_key_first($companies);
        }
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        $year = (int) substr($this->svc()->today(), 0, 4);
        $active = $this->svc()->active($le, $year);
        return $this->page('@manpower/index', ['title' => $this->t('manpower.title'), 'companies' => $companies, 'lePid' => $lePid, 'plans' => $this->svc()->plans($le),
            'active' => $active, 'summary' => $active !== null ? $this->svc()->report((int) $active['id'])['totals'] : null, 'history' => $this->svc()->history($le),
            'settings' => $this->svc()->settings($le), 'canManage' => $az->scope('manpower.manage')->coversLegalEntity($le), 'year' => $year, 'errors' => $errors, 'old' => $old], $status);
    }

    public function create(Request $r): Response
    {
        $in = ['le' => $this->str($r, 'le', 26), 'fiscal_year' => $this->str($r, 'fiscal_year', 4), 'title' => $this->str($r, 'title', 150), 'assumptions' => mb_substr((string) ($r->body['assumptions'] ?? ''), 0, 4000)];
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$in['le']]);
        try {
            $pid = $this->svc()->create($this->ctx($r)->az(), $le, $in);
        } catch (ValidationException $e) {
            return $this->index($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'manpower.flash.created');
        return $this->redirect($r, '/manpower/p/' . $pid);
    }

    public function settings(Request $r): Response
    {
        $lePid = $this->str($r, 'le', 26);
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        $this->svc()->saveSettings($this->ctx($r)->az(), $le, $this->str($r, 'over_budget', 5));
        $this->flash($r, 'success', 'manpower.flash.settings');
        return $this->redirect($r, '/manpower?le=' . rawurlencode($lePid));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function show(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $p = $this->svc()->plan($this->ctx($r)->az(), (string) $r->params['id']);
        $db = $this->app->db();
        $depts = [];
        foreach ($db->all("SELECT public_id, code FROM departments WHERE legal_entity_id = ? AND status = 'active' ORDER BY code", [$p['legal_entity_id']]) as $d) {
            $depts[(string) $d['public_id']] = (string) $d['code'];
        }
        $report = $this->svc()->report((int) $p['id']);
        $active = $this->svc()->active((int) $p['legal_entity_id'], (int) $p['fiscal_year']);
        $compare = null;
        if ($active !== null && (int) $active['id'] !== (int) $p['id'] && in_array($p['status'], ['draft', 'review'], true)) {
            $compare = $this->svc()->report((int) $active['id']);
        }
        $titles = array_column($db->all('SELECT DISTINCT position_title FROM employment_history WHERE legal_entity_id = ? AND effective_to IS NULL AND position_title IS NOT NULL ORDER BY position_title LIMIT 200', [$p['legal_entity_id']]), 'position_title');
        return $this->page('@manpower/plan', ['title' => (string) $p['title'], 'plan' => $p, 'lines' => $this->svc()->lines((int) $p['id']), 'report' => $report, 'compare' => $compare,
            'activePlan' => $active, 'snapshot' => $p['snapshot_json'] !== null ? (array) json_decode((string) $p['snapshot_json'], true) : null, 'depts' => $depts, 'titles' => $titles,
            'errors' => $errors, 'old' => $old], $status);
    }

    public function save(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $in = ['title' => $this->str($r, 'title', 150), 'assumptions' => mb_substr((string) ($r->body['assumptions'] ?? ''), 0, 4000), 'lines' => array_values(array_filter((array) ($r->body['lines'] ?? []), 'is_array'))];
        try {
            $this->svc()->saveDraft($this->ctx($r)->az(), $pid, $in);
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $in, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/manpower/p/' . $pid);
        }
        $this->flash($r, 'success', 'manpower.flash.saved');
        return $this->redirect($r, '/manpower/p/' . $pid);
    }

    public function action(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        try {
            $to = match ($action) {
                'submit' => $this->svc()->submit($az, $pid),
                'withdraw' => $this->svc()->withdraw($az, $pid),
                'revise' => $this->svc()->revise($az, $pid),
                default => $this->svc()->close($az, $pid),
            };
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/manpower/p/' . $pid);
        }
        $this->flash($r, 'success', 'manpower.flash.' . $action);
        return $this->redirect($r, '/manpower/p/' . (is_string($to) ? $to : $pid));
    }

    public function export(Request $r): Response
    {
        $p = $this->svc()->plan($this->ctx($r)->az(), (string) $r->params['id']);
        $rep = $this->svc()->report((int) $p['id']);
        $this->app->audit()->record('manpower.exported', 'success', 'mp_plan', (string) $p['public_id'], (int) $p['legal_entity_id'], null, ['lines' => count($rep['lines'])]);
        $headers = ['unit', 'position_title', 'planned', 'actual', 'joining', 'open_requisitions', 'pending_requisitions', 'variance', 'monthly_cost_per_head', 'months', 'planned_cost'];
        $rows = array_map(static fn (array $x): array => [$x['dept'] !== '' ? $x['dept'] : '*', $x['title'] !== '' ? $x['title'] : '*', $x['planned'], $x['actual'], $x['joining'], $x['open'], $x['pending'], $x['variance'],
            $x['monthly_cost'] ?? '', $x['months'], $x['cost'] ?? ''], $rep['lines']);
        return TableExport::response(TableExport::format($r->query['format'] ?? 'xlsx'), 'manpower-' . $p['fiscal_year'] . '-v' . $p['version_no'], $headers, $rows, [2, 3, 4, 5, 6, 7, 8, 9, 10]);
    }
}
