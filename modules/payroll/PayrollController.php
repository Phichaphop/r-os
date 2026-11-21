<?php

declare(strict_types=1);

namespace Blue\Modules\Payroll;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** Payroll screens (6A): runs of a company · one run · a person's lines · components · pay profiles · rules. */
final class PayrollController extends Controller
{
    private function svc(): PayrollService
    {
        return PayrollService::of($this->app);
    }

    private function t(string $key): string
    {
        return $this->app->translator()->get($key);
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} companies (public id => code), chosen one, its id */
    private function company(Request $r, string $perm = 'payroll.view'): array
    {
        $les = $this->svc()->companies($this->ctx($r)->az(), $perm);
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

    private function back(Request $r, string $to, BusinessException $e): Response
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
        $this->flash($r, 'danger', $e->messageKey, $e->params);
        return $this->redirect($r, $to);
    }

    // ---- runs ----------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function index(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $az = $this->ctx($r)->az();
        return $this->page('@payroll/index', ['title' => $this->t('payroll.title'), 'companies' => $companies, 'lePid' => $lePid, 'runs' => $this->svc()->runs($le),
            'ready' => $this->svc()->readiness($le), 'canPrepare' => $az->scope('payroll.prepare')->coversLegalEntity($le), 'today' => $this->svc()->today(),
            'errors' => $errors, 'old' => $old], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function create(Request $r): Response
    {
        [, , $le] = $this->company($r, 'payroll.prepare');
        $in = ['le' => $this->str($r, 'le', 26), 'label' => $this->str($r, 'label', 120), 'period_start' => $this->str($r, 'period_start', 10), 'period_end' => $this->str($r, 'period_end', 10),
            'pay_date' => $this->str($r, 'pay_date', 10), 'type' => $this->str($r, 'type', 12), 'purpose' => $this->str($r, 'purpose', 300)];
        try {
            $pid = $this->svc()->createRun($this->ctx($r)->az(), $le, $in);
        } catch (ValidationException $e) {
            return $this->index($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'payroll.flash.created');
        return $this->redirect($r, '/payroll/runs/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function show(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $run = $this->svc()->run($az, (string) $r->params['id']);
        $le = (int) $run['legal_entity_id'];
        $people = [];
        foreach (
            $this->app->db()->all(
                "SELECT DISTINCT e.public_id, e.employee_code, e.name_th FROM employees e JOIN employment_history h ON h.employee_id = e.id
              WHERE h.legal_entity_id = ? AND h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?) AND e.status <> 'pre_hire' ORDER BY e.employee_code",
                [$le, $run['period_end'], $run['period_start']]
            ) as $p
        ) {
            $people[(string) $p['public_id']] = $p['employee_code'] . ' · ' . $p['name_th'];
        }
        $manualComps = [];
        foreach ($this->svc()->components($le, true) as $c) {
            if ($c['method'] === 'manual') {
                $manualComps[(string) $c['public_id']] = $c['code'] . ' · ' . $c['name_th'];
            }
        }
        $timePeriod = $run['att_period_id'] !== null ? $this->app->db()->one('SELECT public_id, period_start, period_end, revision FROM att_periods WHERE id = ?', [$run['att_period_id']]) : null;
        $welfareNames = $run['welfare_ids'] !== [] ? array_column($this->app->db()->all('SELECT batch_no FROM wf_batches WHERE id IN (' . implode(',', array_map('intval', $run['welfare_ids'])) . ') ORDER BY batch_no'), 'batch_no') : [];
        return $this->page('@payroll/run', ['title' => (string) $run['label'], 'run' => $run, 'results' => $this->svc()->results((int) $run['id']), 'manual' => $this->svc()->manualEntries((int) $run['id']),
            'choices' => $this->svc()->sourceChoices($run), 'timePeriod' => $timePeriod, 'welfareNames' => $welfareNames, 'people' => $people, 'manualComps' => $manualComps, 'events' => $this->svc()->events((int) $run['id']),
            'canExport' => $az->scope('payroll.export')->coversLegalEntity($le), 'canPay' => $az->scope('core.payment.prepare')->coversLegalEntity($le),
            'slips' => PayslipService::of($this->app)->runStatus((int) $run['id']),
            'original' => $run['run_type'] === 'correction' ? $this->app->db()->one('SELECT public_id, label FROM pay_runs WHERE id = ?', [$run['corrects_run_id']]) : null,
            'corrections' => $this->app->db()->all('SELECT public_id, run_no, label, status FROM pay_runs WHERE corrects_run_id = ? ORDER BY id', [$run['id']]),
            'reviews' => PayrollReviews::of($this->app)->history((int) $run['id']), 'reviewBlock' => PayrollReviews::of($this->app)->blocker($az, $run),
            'reasons' => $this->svc()->correctionReasons($run), 'runWarnings' => $this->svc()->runWarnings($run), 'recon' => $run['status'] === 'approved' ? $this->svc()->reconciliation($run) : null, 'today' => $this->svc()->today(),
            'errors' => $errors, 'old' => $old, 'form' => $form], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function sources(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $batches = array_values(array_filter(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', (array) ($r->body['ot_batches'] ?? []))));
        $welfare = array_values(array_filter(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', (array) ($r->body['welfare_batches'] ?? []))));
        try {
            $this->svc()->setSources($this->ctx($r)->az(), $pid, $this->str($r, 'att_period', 26), $batches, $welfare);
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $r->body, 'sources', 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/payroll/runs/' . $pid, $e);
        }
        $this->flash($r, 'success', 'payroll.flash.sources');
        return $this->redirect($r, '/payroll/runs/' . $pid);
    }

    public function addManual(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $in = ['employee' => $this->str($r, 'employee', 26), 'component' => $this->str($r, 'component', 26), 'amount' => $this->str($r, 'amount', 20), 'note' => $this->str($r, 'note', 255)];
        try {
            $this->svc()->addManual($this->ctx($r)->az(), $pid, $in);
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $in, 'manual', 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/payroll/runs/' . $pid, $e);
        }
        $this->flash($r, 'success', 'payroll.flash.manual');
        return $this->redirect($r, '/payroll/runs/' . $pid . '#manual');
    }

    public function removeManual(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->removeManual($this->ctx($r)->az(), $pid, (string) $r->params['entry']);
        } catch (BusinessException $e) {
            return $this->back($r, '/payroll/runs/' . $pid, $e);
        }
        $this->flash($r, 'success', 'payroll.flash.manual_removed');
        return $this->redirect($r, '/payroll/runs/' . $pid . '#manual');
    }

    public function correction(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $new = $this->svc()->createCorrection($this->ctx($r)->az(), $pid, ['pay_date' => $this->str($r, 'pay_date', 10), 'purpose' => $this->str($r, 'purpose', 300)]);
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $r->body, 'correction', 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/payroll/runs/' . $pid, $e);
        }
        $this->flash($r, 'success', 'payroll.flash.correction');
        return $this->redirect($r, '/payroll/runs/' . $new);
    }

    public function act(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        try {
            switch ($action) {
                case 'calculate':
                    $this->svc()->calculate($az, $pid);
                    break;
                case 'submit':
                    $this->svc()->submit($az, $pid, $this->str($r, 'note', 500));
                    break;
                case 'cancel':
                    $this->svc()->cancel($az, $pid, $this->str($r, 'reason', 500));
                    break;
                case 'payment':
                    $res = $this->svc()->createPayment($az, $pid);
                    $this->flash($r, 'success', $res['left_out'] > 0 ? 'payroll.flash.payment_left_out' : 'payroll.flash.payment', ['n' => (string) $res['items'], 'left' => (string) $res['left_out']]);
                    return $this->redirect($r, '/payroll/runs/' . $pid);
                default:
                    $this->svc()->requestPosting($az, $pid);
            }
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/payroll/runs/' . $pid, $e);
        }
        $this->flash($r, 'success', 'payroll.flash.' . $action);
        return $this->redirect($r, '/payroll/runs/' . $pid);
    }

    // ---- Payroll Checker reviews (R8-4) ----------------------------------------------------------------------------------

    /** Approved runs of a company by pay period with their review state (anyone with payroll.view; reviewing needs payroll.review). */
    public function reviews(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $rv = PayrollReviews::of($this->app);
        $state = (string) ($r->query['state'] ?? '');
        $state = in_array($state, PayrollReviews::STATES, true) ? $state : '';
        return $this->page('@payroll/reviews', ['title' => $this->t('payroll.review.title'), 'companies' => $companies, 'lePid' => $lePid, 'periods' => $rv->periods($le, $state),
            'counts' => $rv->counts($le), 'state' => $state, 'appointed' => $rv->appointed($le), 'canReview' => $this->ctx($r)->az()->scope('payroll.review')->coversLegalEntity($le)])
            ->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function review(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            PayrollReviews::of($this->app)->review($this->ctx($r)->az(), $pid, $this->str($r, 'result', 10), $this->str($r, 'note', 2000));
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $r->body, 'review', 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/payroll/runs/' . $pid, $e);
        }
        $this->flash($r, 'success', 'payroll.review.flash');
        return $this->redirect($r, '/payroll/runs/' . $pid . '#review');
    }

    public function register(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $ctx->requireFreshReauth(); // whole pay of everyone in the run
        try {
            $f = $this->svc()->register($ctx->az(), (string) $r->params['id']);
        } catch (BusinessException $e) {
            return $this->back($r, '/payroll/runs/' . $r->params['id'], $e);
        }
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['name'], true);
    }

    public function result(Request $r): Response
    {
        $res = $this->svc()->result($this->ctx($r)->az(), (string) $r->params['id'], (string) $r->params['emp']);
        return $this->page('@payroll/result', ['title' => (string) $res['employee']['snap']['code'] . ' · ' . $res['run']['label'], 'run' => $res['run'], 'emp' => $res['employee'], 'lines' => $res['lines']])
            ->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    // ---- components ------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function components(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $az = $this->ctx($r)->az();
        return $this->page('@payroll/components', ['title' => $this->t('payroll.components_title'), 'companies' => $companies, 'lePid' => $lePid, 'rows' => $this->svc()->components($le),
            'canPrepare' => $az->scope('payroll.prepare')->coversLegalEntity($le), 'canApprove' => $az->scope('payroll.approve')->coversLegalEntity($le), 'me' => $az->userId,
            'errors' => $errors, 'old' => $old], $status);
    }

    public function saveComponent(Request $r): Response
    {
        [, $lePid, $le] = $this->company($r, 'payroll.prepare');
        $in = ['le' => $lePid];
        foreach (['code' => 20, 'name_th' => 120, 'name_en' => 120, 'kind' => 20, 'method' => 20, 'statutory' => 10, 'sort_order' => 4, 'taxable' => 1, 'sso_base' => 1] as $k => $n) {
            $in[$k] = $this->str($r, $k, $n);
        }
        try {
            $this->svc()->saveComponent($this->ctx($r)->az(), $le, $in);
        } catch (ValidationException $e) {
            return $this->components($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'payroll.flash.component');
        return $this->redirect($r, '/payroll/components?le=' . rawurlencode($lePid));
    }

    public function decideComponent(Request $r): Response
    {
        $lePid = $this->str($r, 'le', 26);
        try {
            $this->svc()->decideComponent($this->ctx($r)->az(), (string) $r->params['id'], (string) $r->params['action']);
        } catch (BusinessException $e) {
            return $this->back($r, '/payroll/components?le=' . rawurlencode($lePid), $e);
        }
        $this->flash($r, 'success', 'payroll.flash.component_' . $r->params['action']);
        return $this->redirect($r, '/payroll/components?le=' . rawurlencode($lePid));
    }

    // ---- people ----------------------------------------------------------------------------------------------------------

    public function people(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        return $this->page('@payroll/people', ['title' => $this->t('payroll.people_title'), 'companies' => $companies, 'lePid' => $lePid, 'rows' => $this->svc()->staff($le, $this->svc()->today())])
            ->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function person(Request $r, array $errors = [], array $old = [], int $status = 200, string $form = 'profile'): Response
    {
        $az = $this->ctx($r)->az();
        $e = $this->svc()->employee($az, (string) $r->params['emp']);
        $le = (int) $e['legal_entity_id'];
        $fixed = [];
        foreach ($this->svc()->components($le, true) as $c) {
            if ($c['method'] === 'fixed') {
                $fixed[(string) $c['public_id']] = $c['code'] . ' · ' . $c['name_th'];
            }
        }
        $this->app->audit()->record('payroll.profile_viewed', 'success', 'employee', (string) $e['public_id'], $le);
        return $this->page('@payroll/person', ['title' => $e['employee_code'] . ' · ' . $e['name_th'], 'emp' => $e, 'profiles' => $this->svc()->profiles((int) $e['id'], $le),
            'current' => $this->svc()->profileAt((int) $e['id'], $le, $this->svc()->today()), 'fixed' => $fixed, 'canPrepare' => $az->scope('payroll.prepare')->coversLegalEntity($le),
            'today' => $this->svc()->today(), 'lePid' => (string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]),
            'ytdEntries' => PayslipService::of($this->app)->ytdEntries((int) $e['id'], $le), 'canApprove' => $az->scope('payroll.approve')->coversLegalEntity($le), 'me' => $az->userId,
            'errors' => $errors, 'old' => $old, 'form' => $form], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function propose(Request $r): Response
    {
        $emp = (string) $r->params['emp'];
        $in = ['effective_from' => $this->str($r, 'effective_from', 10), 'pay_basis' => $this->str($r, 'pay_basis', 10), 'base_amount' => $this->str($r, 'base_amount', 20),
            'bank_code' => $this->str($r, 'bank_code', 20), 'account_no' => $this->str($r, 'account_no', 30), 'reason' => $this->str($r, 'reason', 500), 'items' => [],
            'retro' => !empty($r->body['retro'])];
        foreach (array_slice((array) ($r->body['items'] ?? []), 0, PayrollService::MAX_ITEMS) as $it) {
            if (is_array($it)) {
                $in['items'][] = ['component' => is_scalar($it['component'] ?? null) ? mb_substr((string) $it['component'], 0, 26) : '', 'amount' => is_scalar($it['amount'] ?? null) ? mb_substr((string) $it['amount'], 0, 20) : ''];
            }
        }
        try {
            $this->svc()->proposeProfile($this->ctx($r)->az(), $emp, $in);
        } catch (ValidationException $e) {
            $in['account_no'] = ''; // never sent back into the page
            return $this->person($r, $this->errors($e), $in, 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/payroll/people/' . $emp, $e);
        }
        $this->flash($r, 'success', 'payroll.flash.proposed');
        return $this->redirect($r, '/payroll/people/' . $emp);
    }

    // ---- rules ----------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function settings(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $az = $this->ctx($r)->az();
        $db = $this->app->db();
        $pending = [];
        foreach (PayrollService::RULE_KEYS as $k) {
            $pending[$k] = (int) $db->scalar(
                "SELECT COUNT(*) FROM config_versions v JOIN config_definitions d ON d.id = v.definition_id WHERE d.config_key = ? AND v.scope_level = 'legal_entity' AND v.scope_id = ? AND v.status IN ('draft','in_review','returned')",
                [$k, $le]
            );
        }
        $rules = $this->svc()->rules($le, $this->svc()->today());
        return $this->page('@payroll/settings', ['title' => $this->t('payroll.settings_title'), 'companies' => $companies, 'lePid' => $lePid, 'rules' => $rules,
            'policyRows' => PayrollRules::describePolicy($this->app, $rules['policy']?->value), 'ssoRows' => PayrollRules::describeSso($this->app, $rules['sso']?->value),
            'whtRows' => $rules['wht'] !== null ? TaxRules::describeWht($this->app, $rules['wht']->value) : [], 'payerRows' => TaxRules::describePayer($this->app, TaxService::of($this->app)->payer($le)),
            'ssoEmployerRows' => TaxRules::describeSsoEmployer($this->app, TaxService::of($this->app)->filingSetting('sso_employer', $le)), 'dueRows' => TaxRules::describeFilingDue($this->app, TaxService::of($this->app)->filingSetting('filing_due', $le)),
            'rdRows' => TaxRules::describeRdFile($this->app, TaxService::of($this->app)->filingSetting('rd_file', $le)),
            'pending' => $pending, 'canPrepare' => $az->scope('payroll.prepare')->coversLegalEntity($le), 'today' => $this->svc()->today(), 'errors' => $errors, 'old' => $old, 'form' => $form], $status);
    }

    public function proposeRules(Request $r): Response
    {
        [, $lePid, $le] = $this->company($r, 'payroll.prepare');
        $which = (string) $r->params['key'];
        $in = $r->body;
        try {
            $this->svc()->proposeRules($this->ctx($r)->az(), $le, 'payroll.' . $which, $in, $this->str($r, 'effective_from', 10), $this->str($r, 'source_reference', 300), $this->str($r, 'reason', 500));
        } catch (ValidationException $e) {
            return $this->settings($r, $this->errors($e), $in, $which, 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/payroll/settings?le=' . rawurlencode($lePid), $e);
        }
        $this->flash($r, 'success', 'payroll.flash.rules');
        return $this->redirect($r, '/payroll/settings?le=' . rawurlencode($lePid));
    }
}
