<?php

declare(strict_types=1);

namespace Blue\Modules\Payroll;

use Blue\Core\App;
use Blue\Core\Support\Money;

/**
 * The two Config Registry values payroll reads (DEC-83). NOTHING is shipped — Payroll (payroll.prepare) enters them with
 * the reference they come from and another person (payroll.approve) approves; until then a run lists them as missing
 * and cannot be submitted.
 *
 *  payroll.policy (COMPANY_POLICY) — the company's own pay rules:
 *    { "monthly_divisor": 30,                  days a monthly salary is divided by for a day's pay (absence, part month, OT)
 *      "hours_per_day": "8",                   hours in a working day (hourly rate for OT)
 *      "proration": "calendar_days"|"divisor_days",   part month for joiners/leavers: salary × days ÷ days in period, or ÷ divisor × days
 *      "daily_paid": ["worked_days", "holiday_days", "leave_days"] }   which day counts of the time period a daily-paid person is paid for
 *
 *  payroll.sso (LAW) — social security contribution parameters from the official source (the method — rate × wage kept
 *  between a floor and a cap, rounded — is the engine's; the numbers are not):
 *    { "rate_employee": "5", "rate_employer": "5", "wage_floor": "1650", "wage_cap": "15000", "rounding": "half_up_baht" }
 *    (the numbers in this comment only show the shape — they are NOT values the system uses)
 */
final class PayrollRules
{
    public const PRORATION = ['calendar_days', 'divisor_days'];
    public const DAILY_PAID = ['worked_days', 'holiday_days', 'leave_days'];
    public const ROUNDING = ['half_up_baht', 'down_baht', 'half_up_satang'];

    /**
     * @param array<string, mixed> $in
     * @return array{0: array<string, mixed>|null, 1: array<string, string>}
     */
    public static function validatePolicy(array $in): array
    {
        $errors = [];
        $div = trim((string) ($in['monthly_divisor'] ?? ''));
        if (!ctype_digit($div) || (int) $div < 1 || (int) $div > 31) {
            $errors['monthly_divisor'] = 'payroll.rules_error.divisor';
        }
        $hours = trim((string) ($in['hours_per_day'] ?? ''));
        if (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $hours) !== 1 || (float) $hours <= 0 || (float) $hours > 24) {
            $errors['hours_per_day'] = 'payroll.rules_error.hours';
        }
        $pro = (string) ($in['proration'] ?? '');
        if (!in_array($pro, self::PRORATION, true)) {
            $errors['proration'] = 'form.invalid_choice';
        }
        $paid = array_values(array_intersect(self::DAILY_PAID, array_map('strval', (array) ($in['daily_paid'] ?? []))));
        if ($paid === []) {
            $errors['daily_paid'] = 'payroll.rules_error.daily_paid';
        }
        if ($errors !== []) {
            return [null, $errors];
        }
        return [['monthly_divisor' => (int) $div, 'hours_per_day' => $hours, 'proration' => $pro, 'daily_paid' => $paid], []];
    }

    /**
     * @param array<string, mixed> $in
     * @return array{0: array<string, mixed>|null, 1: array<string, string>}
     */
    public static function validateSso(array $in): array
    {
        $errors = [];
        $out = [];
        foreach (['rate_employee', 'rate_employer'] as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            if (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $v) !== 1 || (float) $v > 100) {
                $errors[$k] = 'payroll.rules_error.percent';
            }
            $out[$k] = $v;
        }
        foreach (['wage_floor', 'wage_cap'] as $k) {
            $u = Money::parse((string) ($in[$k] ?? ''), 2);
            if ($u === null || $u < 0) {
                $errors[$k] = 'payroll.rules_error.money';
            } else {
                $out[$k] = Money::format($u, 2, false);
            }
        }
        if (!isset($errors['wage_floor']) && !isset($errors['wage_cap']) && Money::parse($out['wage_cap'], 2) <= Money::parse($out['wage_floor'], 2)) {
            $errors['wage_cap'] = 'payroll.rules_error.cap';
        }
        $round = (string) ($in['rounding'] ?? '');
        if (!in_array($round, self::ROUNDING, true)) {
            $errors['rounding'] = 'form.invalid_choice';
        }
        $out['rounding'] = $round;
        return $errors !== [] ? [null, $errors] : [$out, []];
    }

    /** @return list<array{0: string, 1: string}> approval page rows */
    public static function describePolicy(App $app, mixed $value): array
    {
        $t = $app->translator();
        $v = is_array($value) ? $value : [];
        return [
            [$t->get('payroll.rules.monthly_divisor'), (string) ($v['monthly_divisor'] ?? '—')],
            [$t->get('payroll.rules.hours_per_day'), (string) ($v['hours_per_day'] ?? '—')],
            [$t->get('payroll.rules.proration'), isset($v['proration']) ? $t->get('payroll.proration.' . $v['proration']) : '—'],
            [$t->get('payroll.rules.daily_paid'), implode(', ', array_map(static fn ($d): string => $t->get('payroll.daily_paid.' . $d), array_intersect(self::DAILY_PAID, (array) ($v['daily_paid'] ?? []))))],
        ];
    }

    /** @return list<array{0: string, 1: string}> */
    public static function describeSso(App $app, mixed $value): array
    {
        $t = $app->translator();
        $v = is_array($value) ? $value : [];
        return [
            [$t->get('payroll.rules.rate_employee'), (string) ($v['rate_employee'] ?? '—') . ' %'],
            [$t->get('payroll.rules.rate_employer'), (string) ($v['rate_employer'] ?? '—') . ' %'],
            [$t->get('payroll.rules.wage_floor'), (string) ($v['wage_floor'] ?? '—')],
            [$t->get('payroll.rules.wage_cap'), (string) ($v['wage_cap'] ?? '—')],
            [$t->get('payroll.rules.rounding'), isset($v['rounding']) ? $t->get('payroll.rounding.' . $v['rounding']) : '—'],
        ];
    }
}
