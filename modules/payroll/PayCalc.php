<?php

declare(strict_types=1);

namespace Blue\Modules\Payroll;

/**
 * PAYROLL_V1 — the pure calculation of one run (DEC-83). It reads only its inputs and the two config values, so the
 * Calculation Ledger can replay it from the stored snapshot and prove the same result.
 *
 * Money is in units of 1/10 000 baht (core Money); every result line is rounded to the satang (half up) — a technical
 * rule because transfer files carry satang; the social-security rounding is the configured one.
 *
 * Per employee, in this order:
 *   base       monthly: the salary (part month when the person joined / left inside the period — payroll.policy.proration)
 *              daily:   rate × the day counts payroll.policy.daily_paid names (from the time period)
 *   fixed      recurring amounts on the approved pay profile
 *   ot         approved OT minutes from the OT batches, grouped by multiplier: hourly rate × multiplier × hours
 *              (hourly rate = salary ÷ monthly_divisor ÷ hours_per_day, or daily rate ÷ hours_per_day)
 *   absence    monthly paid only: absent days × salary ÷ monthly_divisor
 *   manual     amounts Payroll typed for this run (e.g. withholding tax worked out elsewhere — 6A has no tax table)
 *   welfare    approved welfare claims (M37) from the welfare batches the run takes, on each type's component
 *              (an active earning with method "manual"; missing component = blocking warning)
 *   sso        employee and employer contribution: rate × wage, wage = earnings marked "SSO base" − deductions marked
 *              "SSO base", kept between floor and cap, then rounded as configured
 * Off-cycle runs (6F) compute only the manual and welfare lines; correction runs compute everything again and keep
 * only the difference to what was paid (difference()).
 * Anything missing (no profile, no rule value, no multiplier, no component) becomes a warning; blocking warnings stop
 * the run from being submitted. Nothing is guessed.
 */
final class PayCalc
{
    public const ENGINE = 'PAYROLL_V1';
    private const SATANG = 100;
    private const BAHT = 10000;

    /**
     * @param array<string, mixed> $in
     * @param array<string, mixed> $config config_key => value (payroll.policy, payroll.sso; null when not set)
     * @return array<string, mixed>
     */
    public static function run(array $in, array $config): array
    {
        $policy = is_array($config['payroll.policy'] ?? null) ? $config['payroll.policy'] : null;
        $sso = is_array($config['payroll.sso'] ?? null) ? $config['payroll.sso'] : null;
        $wht = is_array($config['payroll.wht'] ?? null) ? $config['payroll.wht'] : null;
        $comps = [];
        $byMethod = [];
        foreach ((array) $in['components'] as $c) {
            $comps[(int) $c['id']] = $c;
            $byMethod[(string) $c['method']] ??= $c;
        }
        if (!(bool) ($in['work_out_tax'] ?? true)) {
            unset($byMethod['wht']); // a correction of a month whose tax was not worked out (6F): none now either
        }
        $period = (array) $in['period'];
        $mode = (string) ($in['mode'] ?? 'regular');
        $out = ['employees' => [], 'totals' => ['gross' => 0, 'deductions' => 0, 'net' => 0, 'employer' => 0, 'employees' => 0, 'warnings' => 0, 'blocking' => 0]];
        foreach ((array) $in['employees'] as $e) {
            $r = self::employee($e, $period, $comps, $byMethod, $policy, $sso, (bool) $in['has_time'], $wht, $mode === 'off_cycle');
            if ($mode === 'correction') {
                $r = self::difference($r, (array) (($in['original'] ?? [])[(string) $e['code']] ?? []), $comps, (string) ($in['correction_of'] ?? ''));
                if ($r === null) {
                    continue;
                }
            }
            $out['employees'][] = $r;
            foreach (['gross', 'deductions', 'net', 'employer'] as $k) {
                $out['totals'][$k] += $r[$k];
            }
            $out['totals']['employees']++;
            $out['totals']['warnings'] += count($r['warnings']);
            $out['totals']['blocking'] += count(array_filter($r['warnings'], static fn (array $w): bool => $w['blocking']));
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $e
     * @param array<string, mixed> $period
     * @param array<int, array<string, mixed>> $comps
     * @param array<string, array<string, mixed>> $byMethod
     * @param array<string, mixed>|null $policy
     * @param array<string, mixed>|null $sso
     * @param array<string, mixed>|null $wht
     * @param bool $offCycle an off-cycle run (6F): only manual amounts and welfare — no base pay, OT, absence, social security or worked-out tax
     * @return array<string, mixed>
     */
    private static function employee(array $e, array $period, array $comps, array $byMethod, ?array $policy, ?array $sso, bool $hasTime, ?array $wht = null, bool $offCycle = false): array
    {
        $lines = [];
        $warn = [];
        $w = static function (string $key, bool $blocking, array $params = []) use (&$warn): void {
            $warn[] = ['key' => $key, 'blocking' => $blocking, 'params' => $params];
        };
        $line = static function (array $c, int $amount, ?string $qty, ?string $rate, string $src, ?string $ref, ?string $note = null) use (&$lines): void {
            $lines[] = ['component_id' => (int) $c['id'], 'code' => (string) $c['code'], 'kind' => (string) $c['kind'], 'quantity' => $qty, 'rate' => $rate,
                'amount' => $amount, 'source_type' => $src, 'source_ref' => $ref, 'note' => $note];
        };
        $base = ['employee_id' => (int) $e['employee_id'], 'code' => (string) $e['code']];
        $p = $e['profile'] ?? null;
        if (!is_array($p) && !$offCycle) {
            $w('payroll.warn.no_profile', true);
            return $base + ['lines' => [], 'gross' => 0, 'deductions' => 0, 'net' => 0, 'employer' => 0, 'warnings' => $warn];
        }
        if (is_array($p) && !$offCycle && (bool) ($p['changed_in_period'] ?? false)) {
            $w('payroll.warn.profile_changed', false, ['from' => (string) $p['effective_from']]);
        }
        if (!is_array($p) || !(bool) ($p['has_bank'] ?? false)) {
            $w('payroll.warn.no_bank', false);
        }
        $p = is_array($p) ? $p : ['pid' => '', 'basis' => 'monthly', 'base' => 0, 'items' => []];
        $salary = (int) $p['base'];
        $divisor = $policy !== null ? (int) $policy['monthly_divisor'] : null;
        $hoursH = $policy !== null ? self::hundredths((string) $policy['hours_per_day']) : null;
        $time = is_array($e['time'] ?? null) ? $e['time'] : null;
        $monthly = $p['basis'] === 'monthly';

        if (!$offCycle) {
        // --- base pay -----------------------------------------------------------------------------------------------
            $bc = $byMethod['base'] ?? null;
            if ($bc === null) {
                $w('payroll.warn.no_component', true, ['method' => 'base']);
            } elseif ($monthly) {
                $days = (int) $e['days_employed'];
                $periodDays = (int) $period['days'];
                if ($days >= $periodDays) {
                    $line($bc, self::round($salary, 1, self::SATANG), null, self::money($salary), 'profile', (string) $p['pid']);
                } elseif ($policy === null) {
                    $w('payroll.warn.policy_missing', true, ['what' => 'proration']);
                } else {
                    $amount = $policy['proration'] === 'calendar_days'
                    ? self::div($salary * $days, $periodDays, self::SATANG)
                    : min(self::round($salary, 1, self::SATANG), self::div($salary * $days, (int) $divisor, self::SATANG));
                    $line($bc, $amount, (string) $days, self::money($salary), 'profile', (string) $p['pid'], 'part_month:' . $policy['proration'] . ':' . $days . '/' . ($policy['proration'] === 'calendar_days' ? $periodDays : $divisor));
                    $w('payroll.warn.part_month', false, ['days' => (string) $days]);
                }
            } elseif ($time === null) {
                $w($hasTime ? 'payroll.warn.no_time_row' : 'payroll.warn.no_time', true);
            } elseif ($policy === null) {
                $w('payroll.warn.policy_missing', true, ['what' => 'daily_paid']);
            } else {
                $daysH = 0;
                foreach ((array) $policy['daily_paid'] as $k) {
                    $daysH += self::hundredths((string) ($time[$k] ?? '0'));
                }
                $line($bc, self::div($salary * $daysH, 100, self::SATANG), self::fromHundredths($daysH), self::money($salary), 'time', (string) ($e['time_ref'] ?? ''));
            }

        // --- fixed items on the profile -------------------------------------------------------------------------------
            foreach ((array) ($p['items'] ?? []) as $it) {
                $c = $comps[(int) $it['component_id']] ?? null;
                if ($c === null || $c['method'] !== 'fixed') {
                    $w('payroll.warn.item_inactive', false, ['code' => (string) ($it['code'] ?? '')]);
                    continue;
                }
                $line($c, self::round((int) $it['amount'], 1, self::SATANG), null, null, 'profile', (string) $p['pid']);
            }

        // --- OT ----------------------------------------------------------------------------------------------------------
            $ot = (array) ($e['ot'] ?? []);
            if ($ot !== []) {
                $oc = $byMethod['ot'] ?? null;
                $groups = [];
                foreach ($ot as $o) {
                    $groups[(string) $o['multiplier']][] = $o;
                }
                if ($oc === null) {
                    $w('payroll.warn.no_component', true, ['method' => 'ot']);
                } elseif ($policy === null) {
                    $w('payroll.warn.policy_missing', true, ['what' => 'ot']);
                } else {
                    foreach ($groups as $mult => $rows) {
                        $mult = (string) $mult; // "3" becomes an int array key
                        $minutes = array_sum(array_map(static fn (array $o): int => (int) $o['minutes'], $rows));
                        if ($mult === '' || self::hundredths($mult) <= 0) {
                            $w('payroll.warn.ot_multiplier', true, ['minutes' => (string) $minutes]);
                            continue;
                        }
                        $multH = self::hundredths($mult);
                        // hourly = salary ÷ divisor ÷ hours (monthly) or daily rate ÷ hours; × multiplier × minutes ÷ 60
                        $den = ($monthly ? (int) $divisor : 1) * (int) $hoursH * 60;
                        $amount = self::div($salary * $multH * $minutes, $den, self::SATANG);
                        $refs = implode(',', array_unique(array_map(static fn (array $o): string => (string) $o['batch'], $rows)));
                        $line($oc, $amount, self::fromHundredths(intdiv($minutes * 100, 60)), $mult, 'ot', $refs, 'minutes:' . $minutes);
                    }
                }
            }

        // --- absence (monthly paid) --------------------------------------------------------------------------------------
            if ($monthly) {
                if ($time === null) {
                    $w('payroll.warn.no_time_absence', false);
                } elseif (self::hundredths((string) ($time['absent_days'] ?? '0')) > 0) {
                    $ac = $byMethod['absence'] ?? null;
                    $absH = self::hundredths((string) $time['absent_days']);
                    if ($ac === null) {
                        $w('payroll.warn.absence_not_deducted', false, ['days' => self::fromHundredths($absH)]);
                    } elseif ($policy === null) {
                        $w('payroll.warn.policy_missing', true, ['what' => 'absence']);
                    } else {
                        $line($ac, self::div($salary * $absH, (int) $divisor * 100, self::SATANG), self::fromHundredths($absH), self::money(self::div($salary, (int) $divisor, 1)), 'time', (string) ($e['time_ref'] ?? ''));
                    }
                }
            }
        }

        // --- manual entries ----------------------------------------------------------------------------------------------
        foreach ((array) ($e['manual'] ?? []) as $m) {
            $c = $comps[(int) $m['component_id']] ?? null;
            if ($c === null) {
                $w('payroll.warn.item_inactive', true, ['code' => (string) ($m['code'] ?? '')]);
                continue;
            }
            $line($c, self::round((int) $m['amount'], 1, self::SATANG), null, null, 'manual', (string) $m['ref'], mb_substr((string) $m['note'], 0, 200));
        }

        // --- welfare claims (M37): approved amounts from welfare batches, on the type's component ------------------------
        foreach ((array) ($e['welfare'] ?? []) as $wl) {
            $c = null;
            foreach ($comps as $cand) {
                if ((string) $cand['code'] === (string) $wl['code'] && $cand['kind'] === 'earning' && $cand['method'] === 'manual') {
                    $c = $cand;
                }
            }
            if ($c === null) {
                $w('payroll.warn.welfare_component', true, ['code' => (string) $wl['code']]);
                continue;
            }
            $line($c, self::round((int) $wl['amount'], 1, self::SATANG), null, null, 'welfare', (string) $wl['ref'], mb_substr((string) $wl['note'], 0, 200));
        }

        if (!$offCycle) {
        // --- social security ---------------------------------------------------------------------------------------------
            $ssoBase = 0;
            foreach ($lines as $l) {
                $c = $comps[$l['component_id']];
                if ((int) $c['sso_base'] === 1) {
                    $ssoBase += $l['kind'] === 'earning' ? $l['amount'] : ($l['kind'] === 'deduction' ? -$l['amount'] : 0);
                }
            }
            foreach (['sso_employee' => 'rate_employee', 'sso_employer' => 'rate_employer'] as $method => $rateKey) {
                $c = $byMethod[$method] ?? null;
                if ($c === null) {
                    continue;
                }
                if ($sso === null) {
                    $w('payroll.warn.sso_missing', true);
                    continue;
                }
                if ($ssoBase <= 0) {
                    continue;
                }
                $wage = max(self::units((string) $sso['wage_floor']), min(self::units((string) $sso['wage_cap']), $ssoBase));
                $rateH = self::hundredths((string) $sso[$rateKey]);
                $num = $wage * $rateH;
                $den = 10000; // rate is a percent in hundredths
                $amount = match ((string) $sso['rounding']) {
                    'down_baht' => intdiv(intdiv($num, $den), self::BAHT) * self::BAHT,
                    'half_up_baht' => self::div($num, $den, self::BAHT),
                    default => self::div($num, $den, self::SATANG),
                };
                $line($c, $amount, null, (string) $sso[$rateKey], 'sso', self::money($wage));
            }

        // --- withholding tax (6C) ---------------------------------------------------------------------------------------
            $wc = $byMethod['wht'] ?? null;
            if ($wc !== null) {
                if ($wht === null) {
                    $w('payroll.warn.wht_missing', true);
                } else {
                    $res = self::tax($lines, $comps, $wht, (array) ($e['tax'] ?? []));
                    foreach ($res['warnings'] as $x) {
                        $w($x, false);
                    }
                    if ($res['amount'] > 0) {
                        $line($wc, $res['amount'], null, null, 'tax', null, $res['note']);
                    }
                }
                if (array_filter((array) ($e['manual'] ?? []), static fn (array $m): bool => ($comps[(int) $m['component_id']]['statutory'] ?? '') === 'wht') !== []) {
                    $w('payroll.warn.wht_twice', false);
                }
            }
        }

        $gross = $ded = $emp = 0;
        foreach ($lines as $l) {
            match ($l['kind']) {
                'earning' => $gross += $l['amount'],
                'deduction' => $ded += $l['amount'],
                default => $emp += $l['amount'],
            };
        }
        if ($gross - $ded < 0) {
            $w('payroll.warn.negative_net', true);
        }
        return $base + ['lines' => $lines, 'gross' => $gross, 'deductions' => $ded, 'net' => $gross - $ded, 'employer' => $emp, 'warnings' => $warn];
    }

    /**
     * Correction run (6F, DEC-87): the person's recalculated result minus what was already paid for the period (the
     * original run plus earlier approved corrections), per pay component. Only components that changed become lines
     * (source "correction", may be negative); a person with nothing changed and nothing blocking is left out.
     *
     * @param array<string, mixed> $r the recalculated result of one person
     * @param array<string, array{amount: int|string, kind: string, component_id: int|string}> $orig component code => paid so far
     * @param array<int, array<string, mixed>> $comps
     * @return array<string, mixed>|null
     */
    public static function difference(array $r, array $orig, array $comps, string $ref): ?array
    {
        $now = [];
        foreach ((array) $r['lines'] as $l) {
            $now[(string) $l['code']] ??= ['amount' => 0, 'kind' => (string) $l['kind'], 'component_id' => (int) $l['component_id']];
            $now[(string) $l['code']]['amount'] += (int) $l['amount'];
        }
        $lines = [];
        foreach (array_unique(array_merge(array_keys($now), array_keys($orig))) as $code) {
            $code = (string) $code;
            $was = (int) ($orig[$code]['amount'] ?? 0);
            $is = (int) ($now[$code]['amount'] ?? 0);
            if ($is === $was) {
                continue;
            }
            $cid = (int) ($now[$code]['component_id'] ?? $orig[$code]['component_id']);
            $kind = (string) ($now[$code]['kind'] ?? $orig[$code]['kind']);
            $kind = (string) ($comps[$cid]['kind'] ?? $kind);
            $f = static fn (int $u): string => \Blue\Core\Support\Money::format($u, 2, false);
            $lines[] = ['component_id' => $cid, 'code' => $code, 'kind' => $kind, 'quantity' => null, 'rate' => null, 'amount' => $is - $was,
                'source_type' => 'correction', 'source_ref' => $ref !== '' ? $ref : null, 'note' => 'was:' . $f($was) . ';now:' . $f($is)];
        }
        $warn = array_values(array_filter((array) $r['warnings'], static fn (array $w): bool => (bool) $w['blocking']));
        if ($lines === [] && $warn === []) {
            return null;
        }
        $gross = $ded = $emp = 0;
        foreach ($lines as $l) {
            match ($l['kind']) {
                'earning' => $gross += $l['amount'],
                'deduction' => $ded += $l['amount'],
                default => $emp += $l['amount'],
            };
        }
        if ($gross - $ded < 0) {
            $warn[] = ['key' => 'payroll.warn.correction_negative', 'blocking' => false, 'params' => []];
        }
        return ['employee_id' => $r['employee_id'], 'code' => $r['code'], 'lines' => $lines, 'gross' => $gross, 'deductions' => $ded, 'net' => $gross - $ded, 'employer' => $emp, 'warnings' => $warn];
    }

    /**
     * Withholding tax of this run (DEC-85): yearly income → expenses, allowances, brackets → this month's share.
     *
     * @param list<array<string, mixed>> $lines lines worked out so far (social security included)
     * @param array<int, array<string, mixed>> $comps
     * @param array<string, mixed> $wht payroll.wht
     * @param array<string, mixed> $tax ytd_taxable, ytd_wht, ytd_sso (units, before this run), month (1–12), declared [{code, value}], declared_ok
     * @return array{amount: int, note: string, warnings: list<string>}
     */
    public static function tax(array $lines, array $comps, array $wht, array $tax): array
    {
        $warnings = [];
        $taxable = 0;
        $ssoNow = 0;
        foreach ($lines as $l) {
            $c = $comps[$l['component_id']];
            if ((int) $c['taxable'] === 1) {
                $taxable += $l['kind'] === 'earning' ? $l['amount'] : ($l['kind'] === 'deduction' ? -$l['amount'] : 0);
            }
            if ($c['statutory'] === 'sso_employee') {
                $ssoNow += $l['amount'];
            }
        }
        $taxable = max(0, $taxable);
        $month = max(1, min(12, (int) ($tax['month'] ?? 12)));
        if ($wht['method'] === 'annualize_current') {
            [$left, $yearIncome, $yearSso, $withheld] = [12, $taxable * 12, $ssoNow * 12, 0];
        } else {
            $left = 13 - $month;
            $yearIncome = (int) ($tax['ytd_taxable'] ?? 0) + $taxable * $left;
            $yearSso = (int) ($tax['ytd_sso'] ?? 0) + $ssoNow * $left;
            $withheld = (int) ($tax['ytd_wht'] ?? 0);
        }
        $m = static fn (string $v): int => \Blue\Core\Support\Money::parse($v, 2) ?? 0;
        $expense = min(self::div($yearIncome * self::hundredths((string) $wht['expense_rate']), 10000, 1), $m((string) $wht['expense_cap']));
        $allow = $m((string) $wht['personal_allowance']);
        $declared = [];
        foreach ((array) ($tax['declared'] ?? []) as $d) {
            $declared[(string) $d['code']] = (string) $d['value'];
        }
        if (!(bool) ($tax['declared_ok'] ?? false)) {
            $warnings[] = 'payroll.warn.no_declaration';
        }
        foreach ((array) $wht['allowances'] as $a) {
            if (!isset($declared[$a['code']])) {
                continue;
            }
            $v = $a['unit'] === 'count' ? intdiv(self::hundredths($declared[$a['code']]), 100) * $m((string) $a['per_unit']) : $m($declared[$a['code']]);
            $allow += $a['cap'] !== null ? min($v, $m((string) $a['cap'])) : $v;
        }
        if (($wht['sso_allowance_cap'] ?? null) !== null) {
            $allow += min($yearSso, $m((string) $wht['sso_allowance_cap']));
        }
        $net = max(0, $yearIncome - $expense - $allow);
        $yearTax = TaxRules::progressive($net, (array) $wht['brackets']);
        $due = max(0, $yearTax - $withheld);
        $amount = match ((string) $wht['rounding']) {
            'down_baht' => intdiv(intdiv($due, $left), self::BAHT) * self::BAHT,
            'half_up_baht' => self::div($due, $left, self::BAHT),
            default => self::div($due, $left, self::SATANG),
        };
        $f = static fn (int $u): string => \Blue\Core\Support\Money::format($u, 2, false);
        return ['amount' => $amount, 'warnings' => $warnings,
            'note' => 'year_income:' . $f($yearIncome) . ';expense:' . $f($expense) . ';allowances:' . $f($allow) . ';net:' . $f($net) . ';year_tax:' . $f($yearTax) . ';withheld:' . $f($withheld) . ';months_left:' . $left];
    }

    /** num ÷ den rounded half up to a multiple of $step (all non-negative integers). */
    public static function div(int $num, int $den, int $step): int
    {
        if ($den <= 0) {
            return 0;
        }
        return intdiv(2 * $num + $den * $step, 2 * $den * $step) * $step;
    }

    private static function round(int $units, int $den, int $step): int
    {
        return self::div($units, $den, $step);
    }

    /** "7.5" → 750 (two decimals at most) */
    public static function hundredths(string $v): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($v), $m) !== 1) {
            return 0;
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    public static function fromHundredths(int $h): string
    {
        return intdiv($h, 100) . '.' . str_pad((string) ($h % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function units(string $money): int
    {
        return \Blue\Core\Support\Money::parse($money, 4) ?? 0;
    }

    private static function money(int $units): string
    {
        return \Blue\Core\Support\Money::format($units, 2, false);
    }
}
