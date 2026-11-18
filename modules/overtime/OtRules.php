<?php

declare(strict_types=1);

namespace Blue\Modules\Overtime;

use Blue\Core\App;

/**
 * Company OT rules — Config Registry key `overtime.rules` (LAW, high risk, DEC-70). NOTHING is shipped: the multipliers
 * and limits depend on the law and on the company's work rules for each employee category, so HR (overtime.policy)
 * enters them with the reference and another HR (overtime.policy_approve) approves. Blue OS never pays from them — it
 * only hands the rate class / multiplier to payroll with each line and uses the limits for checks.
 *
 * Value:
 *   { "rates": { "workday": {"class": "OT1", "multiplier": "1.5"}, "dayoff": {…}, "holiday": {…} },   class/multiplier may be ""
 *     "monthly_cap_hours": 36|null, "cap_mode": "warn"|"block",
 *     "backdate_days": 7|null,      how many days after the OT date a request may still be filed (null = no limit)
 *     "round_minutes": 1|5|10|15|30, payable minutes are rounded DOWN to this unit
 *     "min_minutes": 30|null }      shorter requests are refused
 */
final class OtRules
{
    public const DAY_TYPES = ['workday', 'dayoff', 'holiday'];
    public const ROUNDS = [1, 5, 10, 15, 30];

    /**
     * @param array<string, mixed> $in
     * @return array{0: array<string, mixed>|null, 1: array<string, string>}
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $rates = [];
        foreach (self::DAY_TYPES as $d) {
            $class = strtoupper(trim((string) ($in['rates'][$d]['class'] ?? '')));
            $mult = trim((string) ($in['rates'][$d]['multiplier'] ?? ''));
            if ($class !== '' && preg_match('/^[A-Z0-9_-]{1,20}$/', $class) !== 1) {
                $errors["rates.{$d}.class"] = 'overtime.rules_error.class';
            }
            if ($mult !== '' && (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $mult) !== 1 || (float) $mult <= 0 || (float) $mult > 10)) {
                $errors["rates.{$d}.multiplier"] = 'overtime.rules_error.multiplier';
            }
            $rates[$d] = ['class' => $class, 'multiplier' => $mult];
        }
        $int = static function (string $raw, int $min, int $max) {
            $raw = trim($raw);
            if ($raw === '') {
                return null;
            }
            return ctype_digit($raw) && (int) $raw >= $min && (int) $raw <= $max ? (int) $raw : false;
        };
        $cap = $int((string) ($in['monthly_cap_hours'] ?? ''), 1, 500);
        if ($cap === false) {
            $errors['monthly_cap_hours'] = 'overtime.rules_error.number';
        }
        $mode = ($in['cap_mode'] ?? 'warn') === 'block' ? 'block' : 'warn';
        $back = $int((string) ($in['backdate_days'] ?? ''), 0, 366);
        if ($back === false) {
            $errors['backdate_days'] = 'overtime.rules_error.number';
        }
        $round = (int) ($in['round_minutes'] ?? 1);
        if (!in_array($round, self::ROUNDS, true)) {
            $errors['round_minutes'] = 'form.invalid_choice';
        }
        $min = $int((string) ($in['min_minutes'] ?? ''), 1, 480);
        if ($min === false) {
            $errors['min_minutes'] = 'overtime.rules_error.number';
        }
        if ($errors !== []) {
            return [null, $errors];
        }
        return [['rates' => $rates, 'monthly_cap_hours' => $cap, 'cap_mode' => $mode, 'backdate_days' => $back, 'round_minutes' => $round, 'min_minutes' => $min], []];
    }

    /** @return list<array{0: string, 1: string}> approval page rows */
    public static function describe(App $app, mixed $value): array
    {
        $t = $app->translator();
        $v = is_array($value) ? $value : [];
        $rows = [];
        foreach (self::DAY_TYPES as $d) {
            $r = (array) ($v['rates'][$d] ?? []);
            $rows[] = [$t->get('overtime.day_type.' . $d), trim(($r['class'] ?? '') . ' × ' . (($r['multiplier'] ?? '') !== '' ? $r['multiplier'] : '—'))];
        }
        $rows[] = [$t->get('overtime.rules.monthly_cap_hours'), ($v['monthly_cap_hours'] ?? null) === null ? '—' : $v['monthly_cap_hours'] . ' · ' . $t->get('overtime.cap_mode.' . (($v['cap_mode'] ?? 'warn') === 'block' ? 'block' : 'warn'))];
        $rows[] = [$t->get('overtime.rules.backdate_days'), ($v['backdate_days'] ?? null) === null ? '—' : (string) $v['backdate_days']];
        $rows[] = [$t->get('overtime.rules.round_minutes'), (string) ($v['round_minutes'] ?? 1)];
        $rows[] = [$t->get('overtime.rules.min_minutes'), ($v['min_minutes'] ?? null) === null ? '—' : (string) $v['min_minutes']];
        return $rows;
    }
}
