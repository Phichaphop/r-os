<?php

declare(strict_types=1);

namespace Blue\Modules\Calc;

use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/**
 * M26 screens. General calculators (calculator, age, service length, units, BMI) run in the browser only — BMI never
 * reaches the server (7B item 4). Legal calculators post to the server, which reads the approved rate table in force.
 */
final class CalcController extends Controller
{
    public const CLIENT_TABS = ['basic', 'age', 'service', 'unit', 'bmi'];
    public const SERVER_TABS = ['sso', 'ewf', 'severance', 'retirement', 'pit'];
    private const INPUTS = ['at', 'wage', 'wage_type', 'start', 'end', 'income'];

    public function index(Request $r): Response
    {
        $tab = (string) ($r->query['tab'] ?? 'basic');
        if (!in_array($tab, [...self::CLIENT_TABS, ...self::SERVER_TABS], true)) {
            $tab = 'basic';
        }
        return $this->render($r, $tab, [], null, [], 200);
    }

    public function calculate(Request $r): Response
    {
        $type = (string) $r->params['type'];
        $in = $this->input($r);
        try {
            $out = CalcService::of($this->app)->calculate($this->ctx($r)->az(), $type, $in, $this->le($r));
        } catch (ValidationException $e) {
            return $this->render($r, $type, $in, null, $this->errors($e), 422);
        }
        return $this->render($r, $type, $in, $out, [], 200);
    }

    public function share(Request $r): Response
    {
        $type = (string) $r->params['type'];
        $in = $this->input($r);
        $svc = CalcService::of($this->app);
        $az = $this->ctx($r)->az();
        try {
            $out = $svc->calculate($az, $type, $in, $this->le($r));
        } catch (ValidationException $e) {
            return $this->render($r, $type, $in, null, $this->errors($e), 422);
        }
        if ($out['result'] === null) {
            return $this->render($r, $type, $in, $out, [], 422);
        }
        $keep = array_filter($in, static fn ($v): bool => $v !== '' && $v !== []);
        $token = $svc->share($az, $type, $keep, ['outputs' => $out['result']['outputs'], 'steps' => $out['result']['steps'], 'at' => $out['at'],
            'source' => $out['rule']['source'] ?? null, 'effective_from' => $out['rule']['effective_from'] ?? null], $out['rule']['version'] ?? null, $this->le($r));
        $this->flash($r, 'success', 'calc.shared');
        return $this->redirect($r, '/calc/s/' . $token);
    }

    public function shared(Request $r): Response
    {
        $row = CalcService::of($this->app)->shared($this->ctx($r)->az(), (string) $r->params['token']);
        if ($row === null) {
            return $this->page('@calc/gone', ['title' => $this->app->translator()->get('calc.share_gone')], 410);
        }
        return $this->page('@calc/shared', ['title' => $this->app->translator()->get('calc.tab.' . $row['calculator']), 'row' => $row,
            'mine' => (int) $row['owner_user_id'] === $this->ctx($r)->az()->userId]);
    }

    public function unshare(Request $r): Response
    {
        CalcService::of($this->app)->unshare($this->ctx($r)->az(), (string) $r->params['token']);
        $this->flash($r, 'success', 'calc.unshared');
        return $this->redirect($r, '/calc');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function rates(Request $r, array $errors = [], string $type = '', array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('calc.rates')) {
            throw HttpException::forbidden();
        }
        $svc = CalcService::of($this->app);
        $now = $this->app->clock->now();
        $tables = [];
        foreach (CalcRules::TYPES as $t) {
            $tables[$t] = ['current' => $svc->rule($t, null, $now), 'versions' => $svc->versions($t)];
        }
        return $this->page('@calc/rates', ['title' => $this->app->translator()->get('calc.rates.title'), 'tables' => $tables, 'errors' => $errors,
            'errType' => $type, 'old' => $old, 'companies' => $this->app->org()->scopeOptions($az->reachableScope(), $this->app->translator()->locale())['legal_entity']], $status);
    }

    public function propose(Request $r): Response
    {
        $type = (string) $r->params['type'];
        if (!in_array($type, CalcRules::TYPES, true)) {
            throw HttpException::notFound();
        }
        $in = $r->body;
        $le = (string) ($in['legal_entity_id'] ?? '');
        try {
            CalcService::of($this->app)->propose(
                $this->ctx($r)->az(),
                $type,
                $in,
                $this->str($r, 'effective_from', 10),
                $this->str($r, 'source_reference', 255),
                $this->str($r, 'reason', 300),
                $le !== '' ? (int) $le : null
            );
        } catch (ValidationException $e) {
            return $this->rates($r, $this->errors($e), $type, $in, 422);
        }
        $this->flash($r, 'success', 'calc.rates.submitted');
        return $this->redirect($r, '/calc/rates#t-' . $type);
    }

    /**
     * @param array<string, mixed> $in
     * @param array<string, mixed>|null $out
     * @param array<string, string> $errors
     */
    private function render(Request $r, string $tab, array $in, ?array $out, array $errors, int $status): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('calc.use')) {
            throw HttpException::forbidden();
        }
        $svc = CalcService::of($this->app);
        $rule = null;
        $allowances = [];
        if (in_array($tab, self::SERVER_TABS, true)) {
            $rule = $out['rule'] ?? $svc->rule($tab === 'retirement' ? 'severance' : $tab, $this->le($r), $this->app->clock->now());
            if ($tab === 'pit' && ($rule['state'] ?? '') === 'ok') {
                $allowances = (array) $rule['rule']['allowances'];
            }
        }
        return $this->page('@calc/index', [
            'title' => $this->app->translator()->get('calc.title'), 'tab' => $tab, 'in' => $in, 'out' => $out, 'rule' => $rule, 'errors' => $errors,
            'allowances' => $allowances, 'canRates' => $az->can('calc.rates'), 'shares' => $svc->myShares($az),
            'today' => $svc->referenceDate('')->format('Y-m-d'),
        ], $status);
    }

    /** @return array<string, mixed> */
    private function input(Request $r): array
    {
        $in = [];
        foreach (self::INPUTS as $f) {
            $in[$f] = $this->str($r, $f, 20);
        }
        $a = $r->body['allowance'] ?? [];
        $in['allowance'] = is_array($a) ? array_map(static fn ($v): string => is_scalar($v) ? mb_substr(trim((string) $v), 0, 20) : '', array_slice($a, 0, CalcRules::MAX_ROWS, true)) : [];
        return $in;
    }

    private function le(Request $r): ?int
    {
        $le = $this->store($r)->get('ctx_le');
        return is_int($le) ? $le : null;
    }
}
