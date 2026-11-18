<?php

declare(strict_types=1);

namespace Blue\Modules\Filing;

/**
 * FILING_V1 — the rows, totals and checks of a monthly government filing, worked out only from the inputs the
 * Calculation Ledger keeps (so a revision can be replayed and compared). Amounts are units of 1/10 000 baht.
 *
 * Inputs: kind (wht_monthly | sso_monthly), employer {present}, due_date, runs [{pid, run_no, run_type, intact}],
 * open_runs [{run_no, status}], people [{code, name, id_ok, id_last4, taxable, wht, sso_ee, sso_er, sso_base,
 * sso_wage_used, sso_wage_unknown, ytd {taxable, wht, sso} | null}], not_paid [codes].
 *
 * The rows are what the company paid and withheld — nothing is worked out again here. Whether this is the content an
 * official form wants (and in which layout) is decided by the official format adapter, not by this class.
 */
final class FilingCalc
{
    public const ENGINE = 'FILING_V1';

    public const KINDS = ['wht_monthly', 'sso_monthly'];

    public const SCHEMA = ['wht_monthly' => 'BLUE_FILING_WHT_REVIEW_v1', 'sso_monthly' => 'BLUE_FILING_SSO_REVIEW_v1'];

    /**
     * @param array<string, mixed> $in
     * @return array{rows: list<array<string, mixed>>, totals: array{people: int, a: int, b: int, c: int}, errors: list<array{key: string, params: array<string, string>}>, warnings: list<array{key: string, params: array<string, string>}>}
     */
    public static function build(array $in): array
    {
        $wht = ($in['kind'] ?? '') === 'wht_monthly';
        $errors = [];
        $warnings = [];
        $e = static function (string $key, array $params = []) use (&$errors): void {
            $errors[] = ['key' => $key, 'params' => array_map('strval', $params)];
        };
        $w = static function (string $key, array $params = []) use (&$warnings): void {
            $warnings[] = ['key' => $key, 'params' => array_map('strval', $params)];
        };
        if ((array) ($in['runs'] ?? []) === []) {
            $e('filing.check.no_runs');
        }
        if (!(bool) ($in['employer']['present'] ?? false)) {
            $e($wht ? 'filing.check.payer_missing' : 'filing.check.sso_employer_missing');
        }
        foreach ((array) ($in['runs'] ?? []) as $r) {
            if (!(bool) ($r['intact'] ?? false)) {
                $e('filing.check.ledger_damaged', ['run' => (string) $r['run_no']]);
            }
        }
        $rows = [];
        $tot = ['people' => 0, 'a' => 0, 'b' => 0, 'c' => 0];
        foreach ((array) ($in['people'] ?? []) as $p) {
            $code = (string) $p['code'];
            $y = $p['ytd'] ?? null;
            if (!is_array($y) || (int) $y['taxable'] !== (int) $p['taxable'] || (int) $y['wht'] !== (int) $p['wht'] || (int) $y['sso'] !== (int) $p['sso_ee']) {
                $e('filing.check.ytd_mismatch', ['code' => $code]); // the payroll lines and the year-to-date entries the approval wrote must agree
            }
            if ($wht) {
                if ((int) $p['taxable'] === 0 && (int) $p['wht'] === 0) {
                    continue;
                }
                $row = ['code' => $code, 'name' => (string) $p['name'], 'id_last4' => (string) ($p['id_last4'] ?? ''), 'income' => (int) $p['taxable'], 'wht' => (int) $p['wht']];
                if ($row['income'] < 0 || $row['wht'] < 0) {
                    $w('filing.check.negative', ['code' => $code]);
                }
                $tot['a'] += $row['income'];
                $tot['b'] += $row['wht'];
            } else {
                if ((int) $p['sso_ee'] === 0 && (int) $p['sso_er'] === 0) {
                    continue;
                }
                $row = ['code' => $code, 'name' => (string) $p['name'], 'id_last4' => (string) ($p['id_last4'] ?? ''), 'wage' => (int) $p['sso_base'], 'wage_used' => (int) $p['sso_wage_used'],
                    'ee' => (int) $p['sso_ee'], 'er' => (int) $p['sso_er'], 'wage_unknown' => (bool) $p['sso_wage_unknown']];
                if ($row['ee'] < 0 || $row['er'] < 0) {
                    $w('filing.check.negative', ['code' => $code]);
                }
                if ($row['wage_unknown']) {
                    $w('filing.check.wage_unknown', ['code' => $code]);
                }
                $tot['a'] += $row['wage_used'];
                $tot['b'] += $row['ee'];
                $tot['c'] += $row['er'];
            }
            if (!(bool) ($p['id_ok'] ?? false)) {
                $e('filing.check.no_id', ['code' => $code]);
            }
            $rows[] = $row;
            $tot['people']++;
        }
        foreach ((array) ($in['open_runs'] ?? []) as $r) {
            $w('filing.check.open_run', ['run' => (string) $r['run_no']]);
        }
        $notPaid = array_values(array_map('strval', (array) ($in['not_paid'] ?? [])));
        if ($notPaid !== []) {
            $w('filing.check.not_paid', ['n' => (string) count($notPaid), 'codes' => implode(', ', array_slice($notPaid, 0, 10)) . (count($notPaid) > 10 ? ' …' : '')]);
        }
        if (($in['due_date'] ?? null) === null) {
            $w('filing.check.due_missing');
        }
        return ['rows' => $rows, 'totals' => $tot, 'errors' => $errors, 'warnings' => $warnings];
    }
}
