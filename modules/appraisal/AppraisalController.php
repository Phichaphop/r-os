<?php

declare(strict_types=1);

namespace Blue\Modules\Appraisal;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\TableExport;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Modules\Employees\Audience;

/** M38 screens: my appraisals + to evaluate · one appraisal (self, manager, final review, acknowledge, HR actions) · HR cycles, cycle page, forms. */
final class AppraisalController extends Controller
{
    private function svc(): AppraisalService
    {
        return AppraisalService::of($this->app);
    }

    private function t(string $key): string
    {
        return $this->app->translator()->get($key);
    }

    public function index(Request $r): Response
    {
        if (!$this->ctx($r)->can('appraisal.use')) {
            throw HttpException::forbidden();
        }
        $az = $this->ctx($r)->az();
        $rows = $this->svc()->inbox($az);
        $groups = ['self' => [], 'manager' => [], 'reviewer' => []];
        foreach ($rows as $x) {
            $groups[(string) $x['my_role']][] = $x;
        }
        return $this->page('@appraisal/index', ['title' => $this->t('appraisal.title'), 'groups' => $groups, 'today' => $this->svc()->today(), 'canManage' => $this->svc()->companies($az) !== []]);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function show(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $a = $this->svc()->view($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->page('@appraisal/show', ['title' => (string) $a['name_th'], 'a' => $a, 'events' => $a['is_hr'] || $a['is_manager'] ? $this->svc()->events((int) $a['id']) : [],
            'previous' => $this->svc()->history((int) $a['employee_id'], (int) $a['id'], $a['is_self'] && !$a['is_manager']), 'today' => $this->svc()->today(),
            'errors' => $errors, 'old' => $old, 'form' => $form], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    /** @return array<int, array{score: string, note: string}> */
    private function items(Request $r): array
    {
        $out = [];
        foreach ((array) ($r->body['score'] ?? []) as $k => $v) {
            $out[(int) $k]['score'] = is_scalar($v) ? mb_substr((string) $v, 0, 3) : '';
        }
        foreach ((array) ($r->body['note'] ?? []) as $k => $v) {
            $out[(int) $k]['note'] = is_scalar($v) ? mb_substr((string) $v, 0, 1000) : '';
        }
        return $out;
    }

    private function text(Request $r, string $k, int $max): string
    {
        return mb_substr(is_scalar($r->body[$k] ?? null) ? (string) $r->body[$k] : '', 0, $max);
    }

    public function act(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        $submit = ($r->body['submit'] ?? '') === '1';
        try {
            switch ($action) {
                case 'self':
                    $this->svc()->saveSelf($az, $pid, $this->items($r), $this->text($r, 'self_comment', 4000), $submit);
                    $this->flash($r, 'success', $submit ? 'appraisal.flash.self_submitted' : 'appraisal.flash.saved');
                    break;
                case 'manager':
                    $text = [];
                    foreach (['manager_comment' => 4000, 'strengths' => 4000, 'development_plan' => 4000, 'comp_proposal' => 500] as $k => $max) {
                        $text[$k] = $this->text($r, $k, $max);
                    }
                    $this->svc()->saveManager($az, $pid, $this->items($r), $text, $submit);
                    $this->flash($r, 'success', $submit ? 'appraisal.flash.manager_submitted' : 'appraisal.flash.saved');
                    break;
                case 'final':
                    $decision = ($r->body['decision'] ?? '') === 'return' ? 'return' : 'approve';
                    $this->svc()->finalReview($az, $pid, $decision, $this->text($r, 'reviewer_comment', 4000), $this->str($r, 'calibrated', 6), $this->text($r, 'calibration_reason', 500));
                    $this->flash($r, 'success', 'appraisal.flash.' . ($decision === 'return' ? 'returned' : 'finalized'));
                    break;
                case 'ack':
                    $appeal = ($r->body['appeal'] ?? '') === '1';
                    $this->svc()->acknowledge($az, $pid, $appeal, $this->text($r, 'employee_comment', 4000));
                    $this->flash($r, 'success', $appeal ? 'appraisal.flash.appealed' : 'appraisal.flash.acknowledged');
                    break;
                case 'evaluator':
                    $this->svc()->changeEvaluator($az, $pid, ($r->body['role'] ?? '') === 'reviewer' ? 'reviewer' : 'manager', $this->str($r, 'username', 100), $this->text($r, 'reason', 300));
                    $this->flash($r, 'success', 'appraisal.flash.evaluator');
                    break;
                case 'skip':
                    $this->svc()->skipSelf($az, $pid, $this->text($r, 'reason', 300));
                    $this->flash($r, 'success', 'appraisal.flash.skipped');
                    break;
                default:
                    $this->svc()->resolveAppeal($az, $pid, ($r->body['outcome_action'] ?? '') === 'reopen' ? 'reopen' : 'close', $this->text($r, 'outcome', 500));
                    $this->flash($r, 'success', 'appraisal.flash.appeal_done');
            }
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/appraisal/a/' . $pid);
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

    /** @return array<string, string> */
    private function formOptions(int $le): array
    {
        $out = [];
        foreach ($this->svc()->forms($le) as $f) {
            if ($f['status'] === 'active') {
                $out[(string) $f['public_id']] = $f['code'] . ' · ' . $f['name'];
            }
        }
        return $out;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function admin(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        return $this->page('@appraisal/admin', ['title' => $this->t('appraisal.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'rows' => $this->svc()->cycles($le),
            'formOptions' => $this->formOptions($le), 'audOptions' => Audience::options($this->app, $le), 'errors' => $errors, 'old' => $old], $status);
    }

    /** @return array<string, mixed> */
    private function cycleInput(Request $r): array
    {
        $in = [];
        foreach (['code' => 30, 'name' => 150, 'period' => 12, 'form' => 26, 'self_due' => 10, 'manager_due' => 10, 'final_due' => 10, 'results_on' => 10, 'final_review' => 1, 'groups' => 500] as $k => $max) {
            $in[$k] = $this->str($r, $k, $max);
        }
        foreach (['sites', 'departments'] as $k) {
            $in[$k] = array_values(array_filter(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', (array) ($r->body[$k] ?? []))));
        }
        return $in;
    }

    public function saveCycle(Request $r): Response
    {
        $pid = isset($r->params['id']) ? (string) $r->params['id'] : null;
        $az = $this->ctx($r)->az();
        if ($pid !== null) {
            $le = (int) $this->svc()->managedCycle($az, $pid)['legal_entity_id'];
        } else {
            [, , $le] = $this->company($r);
        }
        $in = $this->cycleInput($r);
        try {
            $pid = $this->svc()->saveCycle($az, $le, $in, $pid);
        } catch (ValidationException $e) {
            return isset($r->params['id']) ? $this->cycle($r, $this->errors($e), $in, 422) : $this->admin($r, $this->errors($e), $in, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/appraisal/admin/c/' . $pid);
        }
        $this->flash($r, 'success', 'appraisal.flash.cycle_saved');
        return $this->redirect($r, '/appraisal/admin/c/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function cycle(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $c = $this->svc()->managedCycle($this->ctx($r)->az(), (string) $r->params['id']);
        $le = (int) $c['legal_entity_id'];
        $aud = Audience::decode((string) $c['audience_json']);
        return $this->page('@appraisal/cycle', ['title' => (string) $c['name'], 'c' => $c, 'roster' => $this->svc()->roster((int) $c['id']), 'sum' => $this->svc()->summary((int) $c['id']),
            'lePid' => (string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]),
            'formOptions' => $this->formOptions($le), 'audOptions' => Audience::options($this->app, $le), 'audIn' => Audience::toInput($this->app, $aud), 'audText' => Audience::describe($this->app, $aud),
            'errors' => $errors, 'old' => $old], $status);
    }

    public function cycleAction(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            if ($r->params['action'] === 'open') {
                $n = $this->svc()->open($this->ctx($r)->az(), $pid);
                $this->flash($r, 'success', 'appraisal.flash.opened', ['n' => (string) $n]);
            } else {
                $this->svc()->close($this->ctx($r)->az(), $pid);
                $this->flash($r, 'success', 'appraisal.flash.closed');
            }
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/appraisal/admin/c/' . $pid);
    }

    public function export(Request $r): Response
    {
        $c = $this->svc()->managedCycle($this->ctx($r)->az(), (string) $r->params['id']);
        $num = static fn (mixed $v): string => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $rows = [];
        foreach ($this->svc()->roster((int) $c['id']) as $x) {
            $rows[] = [(string) $x['employee_code'], (string) $x['name_th'], (string) ($x['dept_code'] ?? ''), (string) ($x['manager_name'] ?? ''), $this->t('appraisal.status.' . $x['status']),
                $num($x['self_score']), $num($x['manager_score']), $num($x['final_score']), (string) ($x['rating_label'] ?? ''), (int) $x['appeal'] === 1 ? $this->t('appraisal.appeal_' . ($x['appeal_status'] ?? 'open')) : ''];
        }
        $headers = [$this->t('appraisal.col.code'), $this->t('appraisal.col.name'), $this->t('appraisal.col.unit'), $this->t('appraisal.col.manager'), $this->t('appraisal.col.status'),
            $this->t('appraisal.col.self'), $this->t('appraisal.col.manager_score'), $this->t('appraisal.col.final'), $this->t('appraisal.col.rating'), $this->t('appraisal.col.appeal')];
        $this->app->audit()->record('appraisal.exported', 'success', 'apr_cycle', (string) $c['public_id'], (int) $c['legal_entity_id'], null, ['rows' => count($rows)]);
        return TableExport::response(($r->query['format'] ?? '') === 'csv' ? 'csv' : 'xlsx', 'appraisal-' . $c['code'], $headers, $rows, [5, 6, 7]);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function forms(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $edit = null;
        if (isset($r->params['id'])) {
            $edit = $this->app->db()->one('SELECT f.*, (SELECT COUNT(*) FROM apr_cycles c WHERE c.form_id = f.id) AS cycles FROM apr_forms f WHERE f.public_id = ?', [(string) $r->params['id']]);
            if ($edit === null || !in_array((int) $edit['legal_entity_id'], $this->svc()->companies($this->ctx($r)->az()), true)) {
                throw HttpException::notFound();
            }
            $le = (int) $edit['legal_entity_id'];
            $lePid = (string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        }
        return $this->page('@appraisal/forms', ['title' => $this->t('appraisal.forms_title'), 'companies' => $companies, 'lePid' => $lePid, 'rows' => $this->svc()->forms($le), 'edit' => $edit,
            'errors' => $errors, 'old' => $old], $status);
    }

    public function saveForm(Request $r): Response
    {
        [, $lePid, $le] = $this->company($r);
        $pid = isset($r->params['id']) ? (string) $r->params['id'] : null;
        if ($pid !== null) {
            $le = (int) $this->app->db()->scalar('SELECT legal_entity_id FROM apr_forms WHERE public_id = ?', [$pid]);
            $lePid = (string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        }
        $in = ['code' => $this->str($r, 'code', 30), 'name' => $this->str($r, 'name', 150), 'scale_max' => $this->str($r, 'scale_max', 2), 'status' => $this->str($r, 'status', 10),
            'bands' => $this->text($r, 'bands', 2000), 'items' => []];
        foreach ((array) ($r->body['items'] ?? []) as $i => $it) {
            if (is_array($it) && (int) $i < 40) {
                $in['items'][(int) $i] = ['section' => is_scalar($it['section'] ?? null) ? (string) $it['section'] : 'kpi', 'title' => is_scalar($it['title'] ?? null) ? mb_substr((string) $it['title'], 0, 200) : '',
                    'weight' => is_scalar($it['weight'] ?? null) ? mb_substr((string) $it['weight'], 0, 8) : '', 'description' => is_scalar($it['description'] ?? null) ? mb_substr((string) $it['description'], 0, 1000) : ''];
            }
        }
        try {
            $this->svc()->saveForm($this->ctx($r)->az(), $le, $in, $pid);
        } catch (ValidationException $e) {
            return $this->forms($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'appraisal.flash.form_saved');
        return $this->redirect($r, '/appraisal/admin/forms?le=' . rawurlencode($lePid));
    }
}
