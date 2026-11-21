<?php

declare(strict_types=1);

namespace Blue\Modules\Calc;

use DateTimeImmutable;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Money;

/**
 * The legal calculators (M26): pure functions over an approved rate table + the person's input.
 * Exact money arithmetic (integers of 1/10 000 baht), every step returned so the person can check the working (7B item 3).
 * Results are ESTIMATES — the rules come from the table the company approved, not from this code.
 *
 * Step = ['key' => translation key, 'p' => params for the key, 'v' => value shown].
 */
final class CalcEngine
{
    private const MAX_AMOUNT = 100_000_000; // baht — keeps integer arithmetic far from overflow

    /**
     * @param array<string, mixed> $rule validated sso table
     * @param array<string, string> $in wage
     * @return array{outputs: array<string, string>, steps: list<array{key: string, p: array<string, string>, v: string}>}
     */
    public static function sso(array $rule, array $in): array
    {
        $wage = self::money($in, 'wage');
        $floor = Money::parse((string) $rule['wage_floor'], 2) ?? 0;
        $ceil = Money::parse((string) $rule['wage_ceiling'], 2) ?? 0;
        $base = max($floor, min($ceil, $wage));
        $steps = [self::step('calc.step.wage', [], $wage), self::step('calc.step.sso_base', ['floor' => Money::format($floor), 'ceiling' => Money::format($ceil)], $base)];
        $emp = self::round(self::pct($base, (string) $rule['employee_rate']), (string) $rule['rounding']);
        $er = self::round(self::pct($base, (string) $rule['employer_rate']), (string) $rule['rounding']);
        $steps[] = self::step('calc.step.employee_part', ['rate' => self::rate((string) $rule['employee_rate'])], $emp);
        $steps[] = self::step('calc.step.employer_part', ['rate' => self::rate((string) $rule['employer_rate'])], $er);
        return ['outputs' => ['employee' => Money::format($emp), 'employer' => Money::format($er), 'total' => Money::format($emp + $er), 'base' => Money::format($base)], 'steps' => $steps];
    }

    /**
     * @param array<string, mixed> $rule validated ewf table
     * @param array<string, string> $in wage
     * @return array{outputs: array<string, string>, steps: list<array{key: string, p: array<string, string>, v: string}>}
     */
    public static function ewf(array $rule, array $in): array
    {
        $wage = self::money($in, 'wage');
        $base = $wage;
        $steps = [self::step('calc.step.wage', [], $wage)];
        if ($rule['wage_ceiling'] !== null) {
            $base = min($wage, Money::parse((string) $rule['wage_ceiling'], 2) ?? $wage);
            $steps[] = self::step('calc.step.ewf_base', ['ceiling' => (string) $rule['wage_ceiling']], $base);
        }
        $emp = self::round(self::pct($base, (string) $rule['employee_rate']), (string) $rule['rounding']);
        $er = self::round(self::pct($base, (string) $rule['employer_rate']), (string) $rule['rounding']);
        $steps[] = self::step('calc.step.employee_part', ['rate' => self::rate((string) $rule['employee_rate'])], $emp);
        $steps[] = self::step('calc.step.employer_part', ['rate' => self::rate((string) $rule['employer_rate'])], $er);
        return ['outputs' => ['employee' => Money::format($emp), 'employer' => Money::format($er), 'total' => Money::format($emp + $er), 'base' => Money::format($base)], 'steps' => $steps];
    }

    /**
     * Severance / retirement pay: service length → the tier whose minimum days is the largest one reached →
     * last wage per day × days of pay of that tier.
     *
     * @param array<string, mixed> $rule validated severance table
     * @param array<string, string> $in start, end, wage, wage_type (daily|monthly)
     * @return array{outputs: array<string, string>, steps: list<array{key: string, p: array<string, string>, v: string}>}
     */
    public static function severance(array $rule, array $in): array
    {
        $start = self::parseDate($in, 'start');
        $end = self::parseDate($in, 'end');
        if ($end <= $start) {
            throw new ValidationException(['end' => 'calc.error.end_before_start']);
        }
        $days = (int) $start->diff($end)->days + (!empty($rule['count_end_day']) ? 1 : 0);
        $diff = $start->diff($end);
        $wage = self::money($in, 'wage');
        if ($wage > 10_000_000 * Money::SCALE) { // wage × days of pay stays inside 64-bit integers
            throw new ValidationException(['wage' => 'calc.error.amount']);
        }
        $monthly = ($in['wage_type'] ?? 'monthly') === 'monthly';
        $divisor = (int) $rule['monthly_divisor'];
        $daily = $monthly ? intdiv($wage * 2 + $divisor, 2 * $divisor) : $wage; // half up
        $steps = [
            self::stepText('calc.step.service', ['y' => (string) $diff->y, 'm' => (string) $diff->m, 'd' => (string) $diff->d], number_format($days)),
            $monthly ? self::step('calc.step.daily_from_monthly', ['monthly' => Money::format($wage), 'divisor' => (string) $divisor], $daily) : self::step('calc.step.daily_wage', [], $daily),
        ];
        $tier = null;
        foreach ((array) $rule['tiers'] as $t) {
            if ($days >= (int) $t['min_days']) {
                $tier = $t;
            }
        }
        if ($tier === null) {
            $steps[] = self::stepText('calc.step.no_tier', ['min' => (string) ($rule['tiers'][0]['min_days'] ?? 0)], '0.00');
            return ['outputs' => ['amount' => Money::format(0), 'days' => (string) $days, 'days_pay' => '0'], 'steps' => $steps];
        }
        $payDays = Money::parse((string) $tier['days_pay'], 2) ?? 0; // units of 1/10 000 day
        $amount = self::round(intdiv($daily * $payDays * 2 + Money::SCALE, 2 * Money::SCALE), (string) $rule['rounding']);
        $steps[] = self::stepText('calc.step.tier', ['min' => (string) $tier['min_days']], (string) $tier['days_pay']);
        $steps[] = self::step('calc.step.severance_amount', ['days' => (string) $tier['days_pay']], $amount);
        return ['outputs' => ['amount' => Money::format($amount), 'days' => (string) $days, 'days_pay' => (string) $tier['days_pay']], 'steps' => $steps];
    }

    /**
     * Personal income tax estimate: income − expense deduction (rate, capped) − personal allowance − other allowances
     * (each capped by the table) = net income → progressive brackets.
     *
     * @param array<string, mixed> $rule validated pit table
     * @param array<string, mixed> $in income, allowance[i]
     * @return array{outputs: array<string, string>, steps: list<array{key: string, p: array<string, string>, v: string}>}
     */
    public static function pit(array $rule, array $in): array
    {
        $income = self::money($in, 'income');
        $expense = min(self::pct($income, (string) $rule['expense_rate']), Money::parse((string) $rule['expense_cap'], 2) ?? 0);
        $personal = Money::parse((string) $rule['personal_allowance'], 2) ?? 0;
        $steps = [
            self::step('calc.step.income', [], $income),
            self::step('calc.step.expense', ['rate' => self::rate((string) $rule['expense_rate']), 'cap' => (string) $rule['expense_cap']], -$expense),
            self::step('calc.step.personal', [], -$personal),
        ];
        $other = 0;
        foreach ((array) $rule['allowances'] as $i => $a) {
            $raw = trim((string) (($in['allowance'] ?? [])[$i] ?? ''));
            if ($raw === '') {
                continue;
            }
            $v = Money::parse(str_replace(',', '', $raw), 2);
            if ($v === null || $v < 0) {
                throw new ValidationException(['allowance' => 'calc.error.amount']);
            }
            $use = min($v, Money::parse((string) $a['cap'], 2) ?? 0);
            $other += $use;
            $steps[] = self::step('calc.step.allowance', ['label' => (string) $a['label'], 'cap' => (string) $a['cap']], -$use);
        }
        $net = max(0, $income - $expense - $personal - $other);
        $steps[] = self::step('calc.step.net', [], $net);
        $tax = 0;
        $lower = 0;
        foreach ((array) $rule['brackets'] as $b) {
            $upper = $b['up_to'] === null ? null : (Money::parse((string) $b['up_to'], 2) ?? 0);
            if ($net <= $lower) {
                break;
            }
            $slice = ($upper === null ? $net : min($net, $upper)) - $lower;
            $part = self::pct($slice, (string) $b['rate']);
            $tax += $part;
            $steps[] = self::step('calc.step.bracket', ['from' => Money::format($lower, 0), 'to' => $upper === null ? '∞' : Money::format($upper, 0), 'rate' => self::rate((string) $b['rate'])], $part);
            if ($upper === null) {
                break;
            }
            $lower = $upper;
        }
        $tax = self::round($tax, 'none');
        $effective = $income > 0 ? Money::format(intdiv($tax * 100 * Money::SCALE * 2 + $income, 2 * $income), 2) : '0.00';
        return ['outputs' => ['tax' => Money::format($tax), 'monthly' => Money::format(intdiv($tax * 2 + 12, 24)), 'net' => Money::format($net), 'effective_rate' => $effective], 'steps' => $steps];
    }

    /**
     * Withholding tax on a prize (R8-4 · Q-15) — a stand-alone estimate, not tied to any module.
     * withhold: the winner receives value − tax · grossup: the payer bears the tax, so the taxable value is grossed up
     * until value = gross − tax(gross). Below the table's minimum value no tax is withheld.
     *
     * @param array<string, mixed> $rule validated prize table
     * @param array<string, string> $in value, mode (withhold|grossup)
     * @return array{outputs: array<string, string>, steps: list<array{key: string, p: array<string, string>, v: string}>}
     */
    public static function prize(array $rule, array $in): array
    {
        $value = self::money($in, 'value');
        $mode = ($in['mode'] ?? 'withhold') === 'grossup' ? 'grossup' : 'withhold';
        $r = Money::parse((string) $rule['rate'], 4) ?? 0; // percent × 10 000
        $min = $rule['threshold'] === null ? null : (Money::parse((string) $rule['threshold'], 2) ?? 0);
        $steps = [self::step('calc.step.prize_value', [], $value)];
        $tax = 0;
        $gross = $value;
        if ($mode === 'withhold') {
            if ($min === null || $value >= $min) {
                $tax = self::round(self::pct($value, (string) $rule['rate']), (string) $rule['rounding']);
            }
        } elseif ($r < 1_000_000) {
            $raw = intdiv($value * 1_000_000 * 2 + (1_000_000 - $r), 2 * (1_000_000 - $r)); // value ÷ (1 − rate), half up
            if ($min === null || $raw >= $min) {
                $tax = self::round($raw - $value, (string) $rule['rounding']);
                $gross = $value + $tax;
                $steps[] = self::step('calc.step.prize_gross', ['rate' => self::rate((string) $rule['rate'])], $gross);
            }
        }
        if ($tax === 0 && $min !== null && ($mode === 'withhold' ? $value : $gross) < $min) {
            $steps[] = self::stepText('calc.step.prize_below', ['min' => Money::format($min)], '0.00');
        } else {
            $steps[] = self::step('calc.step.prize_tax', ['rate' => self::rate((string) $rule['rate'])], $tax);
        }
        $net = $mode === 'withhold' ? $value - $tax : $value;
        $steps[] = self::step($mode === 'withhold' ? 'calc.step.prize_net' : 'calc.step.prize_net_grossup', [], $net);
        return ['outputs' => ['value' => Money::format($value), 'tax' => Money::format($tax), 'net' => Money::format($net), 'gross' => Money::format($gross),
            'payer_cost' => Money::format($mode === 'withhold' ? $value : $gross)], 'steps' => $steps];
    }

    // ---- helpers ------------------------------------------------------------------------------------

    /** amount × rate% (rate as decimal text, up to 4 places), half up. */
    public static function pct(int $units, string $rate): int
    {
        $r = Money::parse($rate, 4) ?? 0; // percent × 10 000
        return intdiv($units * $r * 2 + 1_000_000, 2_000_000);
    }

    /** none = to satang (half up) · nearest/down/up = to whole baht. */
    public static function round(int $units, string $mode): int
    {
        return match ($mode) {
            'nearest' => intdiv($units + 5000, 10000) * 10000,
            'down' => intdiv($units, 10000) * 10000,
            'up' => intdiv($units + 9999, 10000) * 10000,
            default => intdiv($units + 50, 100) * 100,
        };
    }

    /** @param array<string, mixed> $in */
    private static function money(array $in, string $field): int
    {
        $u = Money::parse(str_replace(',', '', trim((string) ($in[$field] ?? ''))), 2);
        if ($u === null || $u < 0 || $u > self::MAX_AMOUNT * Money::SCALE) {
            throw new ValidationException([$field => 'calc.error.amount']);
        }
        return $u;
    }

    /** @param array<string, mixed> $in */
    private static function parseDate(array $in, string $field): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) ($in[$field] ?? '')));
        if ($d === false || (int) $d->format('Y') < 1900 || (int) $d->format('Y') > 2200) {
            throw new ValidationException([$field => 'calc.error.date']);
        }
        return $d;
    }

    private static function rate(string $r): string
    {
        return rtrim(rtrim($r, '0'), '.');
    }

    /**
     * @param array<string, string> $p
     * @return array{key: string, p: array<string, string>, v: string}
     */
    private static function step(string $key, array $p, int $units): array
    {
        return ['key' => $key, 'p' => $p, 'v' => Money::format($units)];
    }

    /**
     * @param array<string, string> $p
     * @return array{key: string, p: array<string, string>, v: string}
     */
    private static function stepText(string $key, array $p, string $v): array
    {
        return ['key' => $key, 'p' => $p, 'v' => $v];
    }
}
