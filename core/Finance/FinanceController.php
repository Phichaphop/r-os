<?php

declare(strict_types=1);

namespace Blue\Core\Finance;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\TableExport;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Support\Money;
use Blue\Core\Ui\Paging;

/** Minimal Finance Core screens (DEC-12): overview, journals, posting requests, chart of accounts, rules, periods, trial balance. */
final class FinanceController extends Controller
{
    // ---- overview ---------------------------------------------------------------------------------------

    public function index(Request $r): Response
    {
        $az = $this->guard($r);
        $le = $this->le($r);
        $db = $this->app->db();
        $counts = $this->app->posting()->counts($az);
        $journals = [];
        foreach ($db->all('SELECT status, COUNT(*) AS n FROM fin_journals WHERE legal_entity_id = ? GROUP BY status', [$le]) as $row) {
            $journals[(string) $row['status']] = (int) $row['n'];
        }
        return $this->page('finance/index', $this->common($r, 'overview', $le) + [
            'title' => $this->app->translator()->get('finance.title'),
            'counts' => $counts, 'journals' => $journals,
            'recent' => $this->app->journals()->list($az, ['legal_entity' => $le, 'status' => '', 'q' => '', 'from' => '', 'to' => ''], 1, 8)['rows'],
        ]);
    }

    // ---- journals ---------------------------------------------------------------------------------------

    public function journals(Request $r): Response
    {
        $az = $this->guard($r);
        $le = $this->le($r);
        $paging = Paging::from($r, $this->app->preferences(), $this->ctx($r)->userId(), 'fin_journals');
        $f = ['legal_entity' => $le, 'status' => $this->str($r, 'status', 20), 'q' => $this->str($r, 'q', 60), 'from' => $this->date($r, 'from'), 'to' => $this->date($r, 'to')];
        $list = $this->app->journals()->list($az, $f, $paging->page, $paging->per);
        return $this->page('finance/journals', $this->common($r, 'journals', $le) + [
            'title' => $this->app->translator()->get('finance.journals.title'), 'list' => $list, 'f' => $f, 'page' => $paging->page, 'per' => $paging->per,
        ]);
    }

    public function journal(Request $r): Response
    {
        $az = $this->guard($r);
        $j = $this->app->journals()->get($az, (string) $r->params['id']);
        $le = (int) $j['legal_entity_id'];
        return $this->page('finance/journal', $this->common($r, 'journals', $le) + [
            'title' => $this->app->translator()->get('finance.journal.title') . ' ' . ($j['journal_no'] ?? ''), 'j' => $j,
            'wf' => $j['workflow_instance_id'] !== null ? $this->app->db()->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$j['workflow_instance_id']]) : null,
        ]);
    }

    public function journalForm(Request $r): Response
    {
        $this->guard($r);
        $le = $this->le($r);
        $this->app->accounts()->requireLe($this->ctx($r)->az(), 'core.finance.manage', $le);
        $id = $r->params['id'] ?? null;
        $old = ['date' => $this->app->clock->now()->format('Y-m-d'), 'description' => '', 'lines' => []];
        if ($id !== null) {
            $j = $this->app->journals()->get($this->ctx($r)->az(), (string) $id);
            $le = (int) $j['legal_entity_id'];
            $old = ['date' => (string) $j['journal_date'], 'description' => (string) $j['description'], 'lines' => array_map(static fn (array $l): array => [
                'account' => (string) $l['account_code'], 'debit' => Money::fromDb($l['debit']) > 0 ? Money::format(Money::fromDb($l['debit']), 2, false) : '',
                'credit' => Money::fromDb($l['credit']) > 0 ? Money::format(Money::fromDb($l['credit']), 2, false) : '', 'description' => (string) ($l['description'] ?? ''), 'site' => $l['site_id'] !== null ? (int) $l['site_id'] : null,
            ], $j['lines'])];
        }
        return $this->journalFormPage($r, $le, $id !== null ? (string) $id : null, $old, []);
    }

    public function createJournal(Request $r): Response
    {
        $this->guard($r);
        $le = $this->le($r);
        $in = $this->journalInput($r);
        try {
            $pid = $this->app->journals()->createManual($this->ctx($r)->az(), $le, $in['date'], $in['description'], $in['lines']);
        } catch (ValidationException $e) {
            return $this->journalFormPage($r, $le, null, $in, $this->errors($e), 422);
        }
        if ($r->input('submit_now') === '1') {
            return $this->act($r, $pid, fn () => $this->app->journals()->submit($this->ctx($r)->az(), $pid), 'finance.flash.submitted');
        }
        $this->flash($r, 'success', 'finance.flash.draft_saved');
        return $this->redirect($r, '/finance/journals/' . $pid);
    }

    public function updateJournal(Request $r): Response
    {
        $this->guard($r);
        $pid = (string) $r->params['id'];
        $j = $this->app->journals()->get($this->ctx($r)->az(), $pid);
        $in = $this->journalInput($r);
        try {
            $this->app->journals()->updateDraft($this->ctx($r)->az(), $pid, $in['date'], $in['description'], $in['lines']);
        } catch (ValidationException $e) {
            return $this->journalFormPage($r, (int) $j['legal_entity_id'], $pid, $in, $this->errors($e), 422);
        }
        $this->flash($r, 'success', 'finance.flash.draft_saved');
        return $this->redirect($r, '/finance/journals/' . $pid);
    }

    public function submitJournal(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        return $this->act($r, $pid, fn () => $this->app->journals()->submit($this->ctx($r)->az(), $pid), 'finance.flash.submitted');
    }

    public function resubmitJournal(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        return $this->act($r, $pid, fn () => $this->app->journals()->resubmit($this->ctx($r)->az(), $pid), 'finance.flash.submitted');
    }

    public function cancelJournal(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        return $this->act($r, $pid, fn () => $this->app->journals()->cancelDraft($this->ctx($r)->az(), $pid, $this->str($r, 'reason', 500)), 'finance.flash.cancelled');
    }

    public function reverseJournal(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $new = null;
        $res = $this->act($r, $pid, function () use ($r, $pid, &$new): void {
            $new = $this->app->journals()->reverse($this->ctx($r)->az(), $pid, $this->date($r, 'date') ?: $this->app->clock->now()->format('Y-m-d'), $this->str($r, 'reason', 500));
        }, 'finance.flash.reversal_ready');
        return $new !== null ? $this->redirect($r, '/finance/journals/' . $new) : $res;
    }

    // ---- posting requests ---------------------------------------------------------------------------------

    public function requests(Request $r): Response
    {
        $az = $this->guard($r);
        $le = $this->le($r);
        $paging = Paging::from($r, $this->app->preferences(), $this->ctx($r)->userId(), 'fin_requests');
        $status = $this->str($r, 'status', 20);
        return $this->page('finance/requests', $this->common($r, 'requests', $le) + [
            'title' => $this->app->translator()->get('finance.requests.title'), 'status' => $status,
            'list' => $this->app->posting()->list($az, ['legal_entity' => $le, 'status' => $status], $paging->page, $paging->per), 'page' => $paging->page, 'per' => $paging->per,
        ]);
    }

    public function request(Request $r): Response
    {
        $az = $this->guard($r);
        $req = $this->app->posting()->get($az, (string) $r->params['id']);
        return $this->page('finance/request', $this->common($r, 'requests', (int) $req['legal_entity_id']) + [
            'title' => $this->app->translator()->get('finance.request.title'), 'req' => $req,
        ]);
    }

    public function retryRequest(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $this->guard($r);
        try {
            $status = $this->app->posting()->retry($this->ctx($r)->az(), $pid);
            $this->flash($r, $status === 'draft_journal' ? 'success' : 'warning', $status === 'draft_journal' ? 'finance.flash.mapped' : 'finance.flash.still_waiting');
        } catch (BusinessException $e) {
            $this->rethrow($e);
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/finance/requests/' . $pid);
    }

    public function rejectRequest(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $this->guard($r);
        try {
            $this->app->posting()->reject($this->ctx($r)->az(), $pid, $this->str($r, 'reason', 500));
            $this->flash($r, 'success', 'finance.flash.request_rejected');
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
        } catch (BusinessException $e) {
            $this->rethrow($e);
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/finance/requests/' . $pid);
    }

    // ---- chart of accounts ----------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function accounts(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->guard($r);
        $le = $this->le($r);
        return $this->page('finance/accounts', $this->common($r, 'accounts', $le) + [
            'title' => $this->app->translator()->get('finance.accounts.title'), 'accounts' => $this->app->accounts()->accounts($le),
            'canManage' => $az->scope('core.finance.manage')->coversLegalEntity($le), 'errors' => $errors, 'old' => $old,
        ], $status);
    }

    public function createAccount(Request $r): Response
    {
        $this->guard($r);
        $le = $this->le($r);
        $in = ['code' => $this->str($r, 'code', 20), 'name' => $this->str($r, 'name', 200), 'type' => $this->str($r, 'type', 20), 'parent' => $this->str($r, 'parent', 20), 'postable' => $r->input('postable') === '1'];
        try {
            $this->app->accounts()->createAccount($this->ctx($r)->az(), $le, $in);
        } catch (ValidationException $e) {
            return $this->accounts($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'finance.flash.account_created');
        return $this->redirect($r, '/finance/accounts?le=' . $le);
    }

    public function accountStatus(Request $r): Response
    {
        $this->guard($r);
        $le = $this->le($r);
        try {
            $this->app->accounts()->setStatus($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'status', 10), $this->str($r, 'reason', 500));
            $this->flash($r, 'success', 'finance.flash.account_updated');
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
        } catch (BusinessException $e) {
            $this->rethrow($e);
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/finance/accounts?le=' . $le);
    }

    // ---- posting rules ----------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function rules(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->guard($r);
        $le = $this->le($r);
        return $this->page('finance/rules', $this->common($r, 'rules', $le) + [
            'title' => $this->app->translator()->get('finance.rules.title'), 'rules' => $this->app->postingRules()->list($le),
            'accounts' => $this->app->accounts()->accounts($le, true), 'canPropose' => $az->scope('core.finance.rules')->coversLegalEntity($le),
            'errors' => $errors, 'old' => $old,
        ], $status);
    }

    public function rule(Request $r): Response
    {
        $az = $this->guard($r);
        $rule = $this->app->postingRules()->get((string) $r->params['id']);
        if (!$az->scope('core.finance.view')->coversLegalEntity((int) $rule['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        return $this->redirect($r, '/finance/rules?le=' . $rule['legal_entity_id'] . '#r-' . $rule['public_id']);
    }

    public function proposeRule(Request $r): Response
    {
        $this->guard($r);
        $le = $this->le($r);
        $in = ['module' => $this->str($r, 'module', 60), 'line_type' => $this->str($r, 'line_type', 60), 'debit' => $this->str($r, 'debit', 20), 'credit' => $this->str($r, 'credit', 20),
            'effective_from' => $this->str($r, 'effective_from', 10), 'reason' => $this->str($r, 'reason', 500), 'source' => $this->str($r, 'source', 255)];
        try {
            $pid = $this->app->postingRules()->propose($this->ctx($r)->az(), $le, $in);
            if ($r->input('submit_now') === '1') {
                $this->app->postingRules()->submit($this->ctx($r)->az(), $pid);
                $this->flash($r, 'success', 'finance.flash.rule_submitted');
            } else {
                $this->flash($r, 'success', 'finance.flash.rule_saved');
            }
        } catch (ValidationException $e) {
            return $this->rules($r, $this->errors($e), $in, 422);
        } catch (BusinessException $e) {
            $this->rethrow($e);
            return $this->rules($r, ['form' => $this->app->translator()->get($e->messageKey)], $in, 422);
        }
        return $this->redirect($r, '/finance/rules?le=' . $le);
    }

    public function submitRule(Request $r): Response
    {
        $this->guard($r);
        $rule = $this->app->postingRules()->get((string) $r->params['id']);
        try {
            $this->app->postingRules()->submit($this->ctx($r)->az(), (string) $rule['public_id']);
            $this->flash($r, 'success', 'finance.flash.rule_submitted');
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
        } catch (BusinessException $e) {
            $this->rethrow($e);
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/finance/rules?le=' . $rule['legal_entity_id']);
    }

    public function cancelRule(Request $r): Response
    {
        $this->guard($r);
        $rule = $this->app->postingRules()->get((string) $r->params['id']);
        try {
            $this->app->postingRules()->cancelDraft($this->ctx($r)->az(), (string) $rule['public_id']);
            $this->flash($r, 'success', 'finance.flash.rule_cancelled');
        } catch (BusinessException $e) {
            $this->rethrow($e);
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/finance/rules?le=' . $rule['legal_entity_id']);
    }

    // ---- periods ----------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function periods(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->guard($r);
        $le = $this->le($r);
        $years = $this->app->accounts()->fiscalYears($le);
        foreach ($years as &$y) {
            $y['periods'] = $this->app->accounts()->periods((int) $y['id']);
        }
        return $this->page('finance/periods', $this->common($r, 'periods', $le) + [
            'title' => $this->app->translator()->get('finance.periods.title'), 'years' => $years,
            'canManage' => $az->scope('core.finance.manage')->coversLegalEntity($le), 'canClose' => $az->scope('core.finance.close')->coversLegalEntity($le),
            'errors' => $errors, 'old' => $old,
        ], $status);
    }

    public function createYear(Request $r): Response
    {
        $this->guard($r);
        $le = $this->le($r);
        $in = ['code' => $this->str($r, 'code', 20), 'start_date' => $this->str($r, 'start_date', 10), 'months' => $this->str($r, 'months', 3)];
        try {
            $this->app->accounts()->createFiscalYear($this->ctx($r)->az(), $le, $in['code'], $in['start_date'], ctype_digit($in['months']) ? (int) $in['months'] : 0);
        } catch (ValidationException $e) {
            return $this->periods($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'finance.flash.year_created');
        return $this->redirect($r, '/finance/periods?le=' . $le);
    }

    public function closePeriod(Request $r): Response
    {
        $this->guard($r);
        $le = $this->le($r);
        $period = $this->str($r, 'period', 30);
        $reopen = $r->input('action') === 'reopen';
        try {
            if ($reopen) {
                $this->app->accounts()->requestReopen($this->ctx($r)->az(), $le, $period, $this->str($r, 'reason', 500));
                $this->flash($r, 'success', 'finance.flash.reopen_requested');
            } else {
                $this->ctx($r)->requireFreshReauth();
                $this->app->accounts()->closePeriod($this->ctx($r)->az(), $le, $period, $this->str($r, 'reason', 500));
                $this->flash($r, 'success', 'finance.flash.period_closed', ['period' => $period]);
            }
        } catch (BusinessException $e) {
            $this->rethrow($e);
            $this->flash($r, 'danger', $e->messageKey, array_map('strval', $e->params));
        }
        return $this->redirect($r, '/finance/periods?le=' . $le);
    }

    // ---- trial balance ---------------------------------------------------------------------------------------------

    public function trialBalance(Request $r): Response
    {
        $az = $this->guard($r);
        $le = $this->le($r);
        $year = $this->app->db()->one('SELECT * FROM fin_fiscal_years WHERE legal_entity_id = ? AND start_date <= ? AND end_date >= ?', [$le, $this->app->clock->now()->format('Y-m-d'), $this->app->clock->now()->format('Y-m-d')]);
        $from = $this->date($r, 'from') ?: (string) ($year['start_date'] ?? $this->app->clock->now()->format('Y-01-01'));
        $to = $this->date($r, 'to') ?: (string) ($year['end_date'] ?? $this->app->clock->now()->format('Y-12-31'));
        $tb = $this->app->journals()->trialBalance($az, $le, $from, $to);
        $export = (string) ($r->query['export'] ?? '');
        if ($export !== '') {
            $t = $this->app->translator();
            $rows = array_map(static fn (array $x): array => [$x['code'], $x['name'], $t->get('finance.type.' . $x['account_type']), Money::format($x['debit_units'], 2, false), Money::format($x['credit_units'], 2, false), Money::format($x['balance_units'], 2, false)], $tb['rows']);
            $rows[] = ['', $t->get('finance.field.total'), '', Money::format($tb['debit'], 2, false), Money::format($tb['credit'], 2, false), Money::format($tb['debit'] - $tb['credit'], 2, false)];
            $this->app->audit()->record('finance.trial_balance_exported', 'success', 'legal_entity', (string) $le, $le, null, ['from' => $from, 'to' => $to]);
            return TableExport::response(
                $export === 'csv' ? 'csv' : 'xlsx',
                'trial-balance-' . $from . '-' . $to,
                [$t->get('finance.field.account'), $t->get('finance.field.name'), $t->get('finance.field.type'), $t->get('finance.field.debit'), $t->get('finance.field.credit'), $t->get('finance.field.balance')],
                $rows,
                [3, 4, 5]
            );
        }
        return $this->page('finance/trial_balance', $this->common($r, 'trial', $le) + [
            'title' => $this->app->translator()->get('finance.trial.title'), 'tb' => $tb, 'from' => $from, 'to' => $to,
        ]);
    }

    // ---- helpers --------------------------------------------------------------------------------------------------

    /**
     * @param array{date: string, description: string, lines: list<array{account: string, debit: string, credit: string, description: string, site: ?int}>} $old
     * @param array<string, string> $errors
     */
    private function journalFormPage(Request $r, int $le, ?string $pid, array $old, array $errors, int $status = 200): Response
    {
        $sites = [];
        foreach ($this->app->db()->all('SELECT id, code FROM sites WHERE legal_entity_id = ? ORDER BY code', [$le]) as $s) {
            $sites[(int) $s['id']] = (string) $s['code'];
        }
        return $this->page('finance/journal_form', $this->common($r, 'journals', $le) + [
            'title' => $this->app->translator()->get($pid === null ? 'finance.journal.new' : 'finance.journal.edit'), 'pid' => $pid, 'old' => $old,
            'accounts' => $this->app->accounts()->accounts($le, true), 'sites' => $sites, 'errors' => $errors,
        ], $status);
    }

    /** @return array{date: string, description: string, lines: list<array{account: string, debit: string, credit: string, description: string, site: ?int}>} */
    private function journalInput(Request $r): array
    {
        $lines = [];
        $acc = (array) $r->input('account', []);
        $dr = (array) $r->input('debit', []);
        $cr = (array) $r->input('credit', []);
        $desc = (array) $r->input('line_description', []);
        $site = (array) $r->input('site', []);
        $n = min(Journals::MAX_LINES, max(count($acc), count($dr), count($cr)));
        for ($i = 0; $i < $n; $i++) {
            $s = is_scalar($site[$i] ?? null) ? (string) $site[$i] : '';
            $lines[] = [
                'account' => mb_substr(trim(is_scalar($acc[$i] ?? null) ? (string) $acc[$i] : ''), 0, 20),
                'debit' => mb_substr(trim(is_scalar($dr[$i] ?? null) ? (string) $dr[$i] : ''), 0, 25),
                'credit' => mb_substr(trim(is_scalar($cr[$i] ?? null) ? (string) $cr[$i] : ''), 0, 25),
                'description' => mb_substr(trim(is_scalar($desc[$i] ?? null) ? (string) $desc[$i] : ''), 0, 300),
                'site' => ctype_digit($s) ? (int) $s : null,
            ];
        }
        return ['date' => $this->date($r, 'date'), 'description' => $this->str($r, 'description', 300), 'lines' => $lines];
    }

    private function act(Request $r, string $pid, callable $fn, string $okKey): Response
    {
        $this->guard($r);
        try {
            $fn();
            $this->flash($r, 'success', $okKey);
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
        } catch (BusinessException $e) {
            $this->rethrow($e);
            $this->flash($r, 'danger', $e->messageKey, array_map('strval', $e->params));
        }
        return $this->redirect($r, '/finance/journals/' . $pid);
    }

    /** @return array<string, mixed> data every finance page needs: company switcher, activation mode, tab */
    private function common(Request $r, string $tab, int $le): array
    {
        $az = $this->ctx($r)->az();
        $opts = $this->app->org()->scopeOptions($az->scope('core.finance.view'), $this->app->translator()->locale())['legal_entity'];
        return ['tab' => $tab, 'le' => $le, 'leOptions' => $opts, 'mode' => $this->app->accounts()->mode($le),
            'canManage' => $az->scope('core.finance.manage')->coversLegalEntity($le), 'canApprove' => $az->scope('core.finance.approve')->coversLegalEntity($le)];
    }

    private function le(Request $r): int
    {
        $az = $this->ctx($r)->az();
        $s = $az->scope('core.finance.view');
        $want = $this->str($r, 'le', 20);
        if (ctype_digit($want) && $s->coversLegalEntity((int) $want)) {
            return (int) $want;
        }
        if ($want !== '' && ctype_digit($want)) {
            throw HttpException::notFound();
        }
        $first = $s->all ? $this->app->db()->scalar('SELECT id FROM legal_entities ORDER BY code LIMIT 1') : ($s->legalEntityIds[0] ?? null);
        return (int) ($first ?? throw HttpException::forbidden());
    }

    private function date(Request $r, string $key): string
    {
        $v = $this->str($r, $key, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }

    private function guard(Request $r): \Blue\Core\Authz\Authorizer
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('core.finance.view')) {
            throw HttpException::forbidden();
        }
        return $az;
    }

    private function rethrow(BusinessException $e): void
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
    }
}
