<?php

declare(strict_types=1);

namespace Blue\Modules\Calc;

use Blue\Core\Support\Money;

/**
 * Shapes of the rate tables the legal calculators read (M26 A ★ — "ตั้งค่าได้จากหลังบ้าน ห้าม hardcode").
 * The system ships NO values: the responsible person enters them from the official source, with an effective date and
 * a source reference, and another person approves (Config Registry, LAW source, high risk).
 * The same validation runs when a table is proposed and every time a calculator reads one.
 */
final class CalcRules
{
    public const TYPES = ['sso', 'ewf', 'severance', 'pit'];
    public const KEYS = ['sso' => 'calc.sso.rule', 'ewf' => 'calc.ewf.rule', 'severance' => 'calc.severance.rule', 'pit' => 'calc.pit.rule'];
    public const ROUNDING = ['none', 'nearest', 'down', 'up'];
    public const MAX_ROWS = 12;

    /**
     * @param array<string, mixed> $in form fields or a stored table
     * @return array{0: array<string, mixed>|null, 1: array<string, string>} [normalized table, field => error key]
     */
    public static function validate(string $type, array $in): array
    {
        $e = [];
        $out = [];
        $rate = static function (string $f, bool $required = true) use ($in, &$e, &$out): void {
            $v = trim((string) ($in[$f] ?? ''));
            if ($v === '' && !$required) {
                $out[$f] = null;
                return;
            }
            $u = Money::parse($v, 4);
            if ($u === null || $u < 0 || $u > 100 * 10000) {
                $e[$f] = 'calc.rule_error.rate';
                return;
            }
            $out[$f] = Money::format($u, 4, false);
        };
        $money = static function (string $f, bool $required = true) use ($in, &$e, &$out): void {
            $v = trim((string) ($in[$f] ?? ''));
            if ($v === '' && !$required) {
                $out[$f] = null;
                return;
            }
            $u = Money::parse(str_replace(',', '', $v), 2);
            if ($u === null || $u < 0 || $u > Money::parse('1000000000', 0)) {
                $e[$f] = 'calc.rule_error.money';
                return;
            }
            $out[$f] = Money::format($u, 2, false);
        };
        $round = static function () use ($in, &$e, &$out): void {
            $r = (string) ($in['rounding'] ?? '');
            if (!in_array($r, self::ROUNDING, true)) {
                $e['rounding'] = 'calc.rule_error.rounding';
                return;
            }
            $out['rounding'] = $r;
        };
        switch ($type) {
            case 'sso':
                $rate('employee_rate');
                $rate('employer_rate');
                $money('wage_floor');
                $money('wage_ceiling');
                $round();
                if (!isset($e['wage_floor']) && !isset($e['wage_ceiling']) && Money::parse((string) $out['wage_ceiling'], 2) <= Money::parse((string) $out['wage_floor'], 2)) {
                    $e['wage_ceiling'] = 'calc.rule_error.ceiling';
                }
                break;
            case 'ewf':
                $rate('employee_rate');
                $rate('employer_rate');
                $money('wage_ceiling', false);
                $round();
                break;
            case 'severance':
                $tiers = [];
                foreach (self::rows($in['tiers'] ?? []) as $i => $row) {
                    $min = trim((string) ($row['min_days'] ?? ''));
                    $pay = trim((string) ($row['days_pay'] ?? ''));
                    if ($min === '' && $pay === '') {
                        continue;
                    }
                    $payU = Money::parse($pay, 2);
                    if (!ctype_digit($min) || (int) $min < 1 || (int) $min > 36500 || $payU === null || $payU <= 0 || $payU > 3650 * 10000) {
                        $e['tiers'] = 'calc.rule_error.tiers';
                        break;
                    }
                    $tiers[] = ['min_days' => (int) $min, 'days_pay' => Money::format($payU, 2, false)];
                    unset($i);
                }
                usort($tiers, static fn (array $a, array $b): int => $a['min_days'] <=> $b['min_days']);
                if (!isset($e['tiers']) && ($tiers === [] || count(array_unique(array_column($tiers, 'min_days'))) !== count($tiers))) {
                    $e['tiers'] = 'calc.rule_error.tiers';
                }
                $out['tiers'] = $tiers;
                $div = trim((string) ($in['monthly_divisor'] ?? ''));
                if (!ctype_digit($div) || (int) $div < 1 || (int) $div > 31) {
                    $e['monthly_divisor'] = 'calc.rule_error.divisor';
                } else {
                    $out['monthly_divisor'] = (int) $div;
                }
                $out['count_end_day'] = in_array($in['count_end_day'] ?? '', ['1', 1, true], true);
                $round();
                break;
            case 'pit':
                $rate('expense_rate');
                $money('expense_cap');
                $money('personal_allowance');
                $brackets = [];
                $prev = 0;
                $rows = array_values(array_filter(self::rows($in['brackets'] ?? []), static fn (array $r): bool => trim((string) ($r['rate'] ?? '')) !== ''));
                foreach ($rows as $i => $row) {
                    $up = trim(str_replace(',', '', (string) ($row['up_to'] ?? '')));
                    $r = Money::parse(trim((string) $row['rate']), 4);
                    $last = $i === count($rows) - 1;
                    $upU = $up === '' ? null : Money::parse($up, 2);
                    if ($r === null || $r < 0 || $r > 100 * 10000 || ($upU === null && !$last) || ($upU !== null && $upU <= $prev) || ($last && $upU !== null)) {
                        $e['brackets'] = 'calc.rule_error.brackets'; // ascending limits; the last row has no upper limit
                        break;
                    }
                    $brackets[] = ['up_to' => $upU === null ? null : Money::format($upU, 2, false), 'rate' => Money::format($r, 4, false)];
                    $prev = (int) $upU;
                }
                if ($brackets === [] && !isset($e['brackets'])) {
                    $e['brackets'] = 'calc.rule_error.brackets';
                }
                $out['brackets'] = $brackets;
                $allow = [];
                foreach (self::rows($in['allowances'] ?? []) as $row) {
                    $label = mb_substr(trim((string) ($row['label'] ?? '')), 0, 80);
                    $cap = trim(str_replace(',', '', (string) ($row['cap'] ?? '')));
                    if ($label === '' && $cap === '') {
                        continue;
                    }
                    $capU = Money::parse($cap, 2);
                    if ($label === '' || $capU === null || $capU <= 0) {
                        $e['allowances'] = 'calc.rule_error.allowances';
                        break;
                    }
                    $allow[] = ['label' => $label, 'cap' => Money::format($capU, 2, false)];
                }
                $out['allowances'] = $allow;
                break;
            default:
                $e['type'] = 'calc.rule_error.type';
        }
        return [$e === [] ? $out : null, $e];
    }

    /**
     * @param mixed $rows list of rows, or field => list (form arrays tiers[min_days][])
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        if (array_is_list($rows)) {
            return array_slice(array_values(array_filter($rows, 'is_array')), 0, self::MAX_ROWS);
        }
        $out = [];
        foreach ($rows as $field => $values) {
            foreach ((array) $values as $i => $v) {
                $out[(int) $i][(string) $field] = is_scalar($v) ? (string) $v : '';
            }
        }
        ksort($out);
        return array_slice(array_values($out), 0, self::MAX_ROWS);
    }
}
