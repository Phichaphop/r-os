<?php

declare(strict_types=1);

namespace Blue\Modules\Payroll;

use Blue\Core\App;
use Blue\Core\Support\Money;

/**
 * Withholding-tax values in the Config Registry (DEC-85). NOTHING is shipped — Tax/Payroll enter them from the official
 * source with the reference, another person approves.
 *
 *  payroll.wht (LAW):
 *    method            "cumulative" — expected income of the tax year = taxable income paid so far (approved runs +
 *                       opening amounts) + this month's × the months left (this one included); tax for this month =
 *                       (tax on the whole year − tax already withheld) ÷ months left
 *                      "annualize_current" — this month's taxable income × 12; tax for this month = tax on that ÷ 12
 *                      (both assume monthly pay; which method fits the company is Tax's decision)
 *    expense_rate, expense_cap     expense deduction: rate % of the yearly income, at most the cap
 *    personal_allowance             deducted for everyone
 *    allowances        list of {code, name, unit "amount" | "count", per_unit (count only), cap (empty = none)} —
 *                      what employees may declare; a declared amount (or count × per_unit) is cut to the cap
 *    sso_allowance_cap empty = social security is not deducted; else the employee's yearly contributions up to this
 *    brackets          list of {up_to (empty for the last), rate %} on the yearly net income, from the lowest
 *    rounding          "half_up_satang" | "half_up_baht" | "down_baht" for the monthly tax
 *
 *  payroll.wht_payer (COMPANY_POLICY, per company): {tax_id (13 digits), name, address, branch} printed on certificates.
 */
final class TaxRules
{
    public const METHODS = ['cumulative', 'annualize_current'];
    public const MAX_BRACKETS = 10;
    public const MAX_ALLOWANCES = 15;

    private static function money(string $raw, bool $allowEmpty = false): int|false|null
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $allowEmpty ? null : false;
        }
        $u = Money::parse($raw, 2);
        return $u === null || $u < 0 ? false : $u;
    }

    private static function pct(string $raw): bool
    {
        return preg_match('/^\d{1,2}(\.\d{1,2})?$|^100(\.0{1,2})?$/', trim($raw)) === 1;
    }

    /**
     * @param array<string, mixed> $in
     * @return array{0: array<string, mixed>|null, 1: array<string, string>}
     */
    public static function validateWht(array $in): array
    {
        $errors = [];
        $out = [];
        $method = (string) ($in['method'] ?? '');
        if (!in_array($method, self::METHODS, true)) {
            $errors['method'] = 'form.invalid_choice';
        }
        $out['method'] = $method;
        if (!self::pct((string) ($in['expense_rate'] ?? ''))) {
            $errors['expense_rate'] = 'payroll.rules_error.percent';
        }
        $out['expense_rate'] = trim((string) ($in['expense_rate'] ?? ''));
        foreach (['expense_cap', 'personal_allowance'] as $k) {
            $u = self::money((string) ($in[$k] ?? ''));
            if ($u === false || $u === null) {
                $errors[$k] = 'payroll.rules_error.money';
            } else {
                $out[$k] = Money::format($u, 2, false);
            }
        }
        $sso = self::money((string) ($in['sso_allowance_cap'] ?? ''), true);
        if ($sso === false) {
            $errors['sso_allowance_cap'] = 'payroll.rules_error.money';
        }
        $out['sso_allowance_cap'] = is_int($sso) ? Money::format($sso, 2, false) : null;
        // brackets: from the lowest; every limit above the one before; the last one open
        $brackets = [];
        $prev = -1;
        $rows = array_values(array_filter((array) ($in['brackets'] ?? []), static fn ($b): bool => is_array($b) && (trim((string) ($b['up_to'] ?? '')) !== '' || trim((string) ($b['rate'] ?? '')) !== '')));
        foreach (array_slice($rows, 0, self::MAX_BRACKETS) as $i => $b) {
            $last = $i === count($rows) - 1;
            $up = self::money((string) ($b['up_to'] ?? ''), true);
            if (!self::pct((string) ($b['rate'] ?? ''))) {
                $errors["brackets.{$i}.rate"] = 'payroll.rules_error.percent';
            }
            if ($last && $up !== null) {
                $errors["brackets.{$i}.up_to"] = 'payroll.rules_error.last_open';
            } elseif (!$last && ($up === null || $up === false || $up <= $prev)) {
                $errors["brackets.{$i}.up_to"] = 'payroll.rules_error.ascending';
            }
            if (is_int($up)) {
                $prev = $up;
            }
            $brackets[] = ['up_to' => is_int($up) ? Money::format($up, 2, false) : null, 'rate' => trim((string) ($b['rate'] ?? ''))];
        }
        if ($brackets === []) {
            $errors['brackets'] = 'payroll.rules_error.brackets';
        }
        $out['brackets'] = $brackets;
        $allow = [];
        $codes = [];
        foreach (array_slice(array_values(array_filter((array) ($in['allowances'] ?? []), static fn ($a): bool => is_array($a) && trim((string) ($a['code'] ?? '')) !== '')), 0, self::MAX_ALLOWANCES) as $i => $a) {
            $code = strtoupper(trim((string) $a['code']));
            $name = trim((string) ($a['name'] ?? ''));
            $unit = (string) ($a['unit'] ?? 'amount') === 'count' ? 'count' : 'amount';
            if (preg_match('/^[A-Z][A-Z0-9_]{1,19}$/', $code) !== 1 || isset($codes[$code])) {
                $errors["allowances.{$i}.code"] = 'payroll.error.code';
            }
            $codes[$code] = true;
            if ($name === '' || mb_strlen($name) > 80) {
                $errors["allowances.{$i}.name"] = 'payroll.error.name';
            }
            $per = self::money((string) ($a['per_unit'] ?? ''), true);
            if ($unit === 'count' && !is_int($per)) {
                $errors["allowances.{$i}.per_unit"] = 'payroll.rules_error.money';
            }
            $cap = self::money((string) ($a['cap'] ?? ''), true);
            if ($cap === false) {
                $errors["allowances.{$i}.cap"] = 'payroll.rules_error.money';
            }
            $allow[] = ['code' => $code, 'name' => mb_substr($name, 0, 80), 'unit' => $unit, 'per_unit' => $unit === 'count' && is_int($per) ? Money::format($per, 2, false) : null,
                'cap' => is_int($cap) ? Money::format($cap, 2, false) : null];
        }
        $out['allowances'] = $allow;
        $round = (string) ($in['rounding'] ?? '');
        if (!in_array($round, PayrollRules::ROUNDING, true)) {
            $errors['rounding'] = 'form.invalid_choice';
        }
        $out['rounding'] = $round;
        return $errors !== [] ? [null, $errors] : [$out, []];
    }

    /**
     * @param array<string, mixed> $in
     * @return array{0: array<string, mixed>|null, 1: array<string, string>}
     */
    public static function validatePayer(array $in): array
    {
        $errors = [];
        $tax = preg_replace('/[\s-]/', '', (string) ($in['tax_id'] ?? '')) ?? '';
        if (preg_match('/^\d{13}$/', $tax) !== 1) {
            $errors['tax_id'] = 'payroll.rules_error.tax_id';
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 200) {
            $errors['name'] = 'payroll.error.name';
        }
        $addr = trim((string) ($in['address'] ?? ''));
        if (mb_strlen($addr) < 10 || mb_strlen($addr) > 400) {
            $errors['address'] = 'payroll.rules_error.address';
        }
        $branch = trim((string) ($in['branch'] ?? ''));
        if ($branch !== '' && preg_match('/^\d{5}$/', $branch) !== 1) {
            $errors['branch'] = 'payroll.rules_error.branch';
        }
        return $errors !== [] ? [null, $errors] : [['tax_id' => $tax, 'name' => $name, 'address' => $addr, 'branch' => $branch], []];
    }

    /**
     * The employer's social security account (6E): the numbers the Social Security Office gave the company — digits
     * only; the system does not assume a length (company data, entered from the office's letter).
     *
     * @param array<string, mixed> $in account_no, branch_no, name
     * @return array{0: ?array<string, string>, 1: array<string, string>}
     */
    public static function validateSsoEmployer(array $in): array
    {
        $errors = [];
        $acc = preg_replace('/[\s-]/', '', (string) ($in['account_no'] ?? '')) ?? '';
        if (preg_match('/^\d{1,20}$/', $acc) !== 1) {
            $errors['account_no'] = 'payroll.rules_error.digits';
        }
        $branch = preg_replace('/[\s-]/', '', (string) ($in['branch_no'] ?? '')) ?? '';
        if ($branch !== '' && preg_match('/^\d{1,10}$/', $branch) !== 1) {
            $errors['branch_no'] = 'payroll.rules_error.digits';
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 200) {
            $errors['name'] = 'payroll.error.name';
        }
        return $errors !== [] ? [null, $errors] : [['account_no' => $acc, 'branch_no' => $branch, 'name' => $name], []];
    }

    /** @return list<array{0: string, 1: string}> */
    public static function describeSsoEmployer(App $app, mixed $value): array
    {
        $t = $app->translator();
        $v = is_array($value) ? $value : [];
        return [
            [$t->get('payroll.sso_employer.account_no'), (string) ($v['account_no'] ?? '—')],
            [$t->get('payroll.sso_employer.branch_no'), (string) (($v['branch_no'] ?? '') !== '' ? $v['branch_no'] : '—')],
            [$t->get('payroll.sso_employer.name'), (string) ($v['name'] ?? '—')],
        ];
    }

    /**
     * Filing due days (6E, LAW — no default): the day of the following month by which the monthly withholding tax and
     * social security filings are due, as the official source says (with its reference on the proposal).
     *
     * @param array<string, mixed> $in wht_day, sso_day
     * @return array{0: ?array<string, int>, 1: array<string, string>}
     */
    public static function validateFilingDue(array $in): array
    {
        $errors = [];
        $out = [];
        foreach (['wht_day', 'sso_day'] as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            if (!ctype_digit($v) || (int) $v < 1 || (int) $v > 31) {
                $errors[$k] = 'payroll.rules_error.day';
            } else {
                $out[$k] = (int) $v;
            }
        }
        return $errors !== [] ? [null, $errors] : [$out, []];
    }

    /** @return list<array{0: string, 1: string}> */
    public static function describeFilingDue(App $app, mixed $value): array
    {
        $t = $app->translator();
        $v = is_array($value) ? $value : [];
        $d = static fn (string $k): string => isset($v[$k]) ? $t->get('payroll.filing_due.day_of_next', ['d' => (string) $v[$k]]) : '—';
        return [[$t->get('payroll.filing_due.wht_day'), $d('wht_day')], [$t->get('payroll.filing_due.sso_day'), $d('sso_day')]];
    }

    /** the fixed-value fields of the Revenue Department text file (0.46.0 · DEC-115) and their digit length */
    public const RD_FIELDS = ['type_original' => 1, 'type_additional' => 1, 'h_field1' => 4, 'h_field3' => 6, 'h_field6' => 4, 'd_branch' => 6, 'd_tin' => 10, 'd_cond' => 1];

    /** what the sample file the owner gave uses (shown as a hint only — never filled in for the user) */
    public const RD_SAMPLE = ['type_original' => '2', 'type_additional' => '', 'h_field1' => '0000', 'h_field3' => '000000', 'h_field6' => '0000', 'd_branch' => '000000', 'd_tin' => '0000000000',
        'd_cond' => '1', 'd_tax_rate' => '0.00', 'names_line' => '1'];

    /**
     * Settings of the official withholding tax text file (P.N.D. 1, RD Prep layout — 0.46.0 · DEC-115): the codes the
     * file needs that the system cannot know — the filing type numbers, the fixed fields of the H and D lines, the tax
     * rate column and whether the first line holds the column names. No default: taken from the Revenue Department's
     * specification by the payroll/tax owner, with its reference; another person approves.
     *
     * @param array<string, mixed> $in
     * @return array{0: ?array<string, string|bool>, 1: array<string, string>}
     */
    public static function validateRdFile(array $in): array
    {
        $errors = [];
        $out = [];
        foreach (self::RD_FIELDS as $k => $len) {
            $v = trim((string) ($in[$k] ?? ''));
            if (preg_match('/^\d{' . $len . '}$/', $v) !== 1) {
                $errors[$k] = 'payroll.rd_file.error_digits_' . $len;
            } else {
                $out[$k] = $v;
            }
        }
        if (!isset($errors['type_original']) && !isset($errors['type_additional']) && $out['type_original'] === $out['type_additional']) {
            $errors['type_additional'] = 'payroll.rd_file.error_same_type';
        }
        $rate = trim((string) ($in['d_tax_rate'] ?? ''));
        if (preg_match('/^\d{1,2}\.\d{2}$/', $rate) !== 1) {
            $errors['d_tax_rate'] = 'payroll.rd_file.error_rate';
        } else {
            $out['d_tax_rate'] = $rate;
        }
        $out['names_line'] = !empty($in['names_line']);
        return $errors !== [] ? [null, $errors] : [$out, []];
    }

    /** @return list<array{0: string, 1: string}> */
    public static function describeRdFile(App $app, mixed $value): array
    {
        $t = $app->translator();
        $v = is_array($value) ? $value : [];
        $rows = [];
        foreach (array_merge(array_keys(self::RD_FIELDS), ['d_tax_rate']) as $k) {
            $rows[] = [$t->get('payroll.rd_file.' . $k), (string) (($v[$k] ?? '') !== '' ? $v[$k] : '—')];
        }
        $rows[] = [$t->get('payroll.rd_file.names_line'), $v === [] ? '—' : $t->get(!empty($v['names_line']) ? 'payroll.rd_file.yes' : 'payroll.rd_file.no')];
        return $rows;
    }

    /** @return list<array{0: string, 1: string}> */
    public static function describeWht(App $app, mixed $value): array
    {
        $t = $app->translator();
        $v = is_array($value) ? $value : [];
        $rows = [
            [$t->get('payroll.tax.method'), isset($v['method']) ? $t->get('payroll.tax.method.' . $v['method']) : '—'],
            [$t->get('payroll.tax.expense'), ($v['expense_rate'] ?? '—') . ' % · ' . $t->get('payroll.tax.cap') . ' ' . ($v['expense_cap'] ?? '—')],
            [$t->get('payroll.tax.personal_allowance'), (string) ($v['personal_allowance'] ?? '—')],
            [$t->get('payroll.tax.sso_allowance_cap'), ($v['sso_allowance_cap'] ?? null) === null ? $t->get('payroll.tax.not_deducted') : (string) $v['sso_allowance_cap']],
        ];
        $from = '0.00';
        foreach ((array) ($v['brackets'] ?? []) as $b) {
            $rows[] = [$t->get('payroll.tax.bracket', ['from' => $from, 'to' => (string) ($b['up_to'] ?? '∞')]), $b['rate'] . ' %'];
            $from = (string) ($b['up_to'] ?? '');
        }
        foreach ((array) ($v['allowances'] ?? []) as $a) {
            $rows[] = [$t->get('payroll.tax.allowance') . ' ' . $a['code'] . ' · ' . $a['name'], ($a['unit'] === 'count' ? $t->get('payroll.tax.per_unit_v', ['v' => (string) $a['per_unit']]) . ' · ' : '')
                . $t->get('payroll.tax.cap') . ' ' . ($a['cap'] ?? '—')];
        }
        $rows[] = [$t->get('payroll.rules.rounding'), isset($v['rounding']) ? $t->get('payroll.rounding.' . $v['rounding']) : '—'];
        return $rows;
    }

    /** @return list<array{0: string, 1: string}> */
    public static function describePayer(App $app, mixed $value): array
    {
        $t = $app->translator();
        $v = is_array($value) ? $value : [];
        return [
            [$t->get('payroll.payer.tax_id'), (string) ($v['tax_id'] ?? '—')],
            [$t->get('payroll.payer.name'), (string) ($v['name'] ?? '—')],
            [$t->get('payroll.payer.address'), (string) ($v['address'] ?? '—')],
            [$t->get('payroll.payer.branch'), (string) (($v['branch'] ?? '') !== '' ? $v['branch'] : '—')],
        ];
    }

    /**
     * Yearly tax on a net income by the brackets (units of 1/10 000 baht; exact to the unit, half up).
     *
     * @param list<array{up_to: ?string, rate: string}> $brackets
     */
    public static function progressive(int $net, array $brackets): int
    {
        $tax = 0;
        $lower = 0;
        foreach ($brackets as $b) {
            $upper = $b['up_to'] === null ? PHP_INT_MAX : (Money::parse((string) $b['up_to'], 2) ?? 0);
            if ($net <= $lower) {
                break;
            }
            $part = min($net, $upper) - $lower;
            $tax += PayCalc::div($part * PayCalc::hundredths((string) $b['rate']), 10000, 1);
            $lower = $upper;
        }
        return $tax;
    }
}
