<?php

declare(strict_types=1);

namespace Blue\Modules\Leave;

/**
 * The leave policy stored in the Config Registry key leave.policy (JSON, per company, effective-dated, maker-checker).
 * NOTHING is shipped: entitlements are legal or company decisions that HR enters with a source reference and another
 * HR approver checks (DEC-63). The statutory minimum and the company entitlement are separate fields and the company
 * entitlement may never be lower than the statutory minimum (TH 6.21.2 — the legal minimum is not a cap).
 *
 * {"types": {"<CODE>": {
 *    "legal_minimum_days": number|null,       statutory minimum per leave year (null = not a statutory entitlement)
 *    "entitlement_days": number|null,         company entitlement per leave year; null = no quota is tracked (e.g. unpaid)
 *    "group_entitlements": {"GROUP": number}, per employee group (optional, each ≥ legal minimum)
 *    "min_service_months": int,               service needed on the first leave day (0 = from the hire date)
 *    "proration": "none"|"monthly",           first year: full grant, or by remaining months from the hire month
 *    "carry_forward_max_days": number,        unused days carried into the next leave year (0 = none)
 *    "allow_negative": bool,                  may the balance go below zero
 *    "half_day": bool,                        morning/afternoon requests allowed
 *    "min_notice_days": int|null,             request at least N days before the first day
 *    "max_backdate_days": int|null,           first day at most N days in the past (null = no limit)
 *    "document_from_days": number|null        a supporting document is required from N days (null = never)
 * }}}
 * Leave year = calendar year (January–December).
 */
final class LeavePolicy
{
    public const PRORATION = ['none', 'monthly'];

    /**
     * Validates a policy for the given type codes.
     *
     * @param array<string, mixed> $in       raw form/JSON input: types => code => fields
     * @param list<string>         $typeCodes codes that exist (active) in the company
     * @return array{0: ?array<string, mixed>, 1: array<string, string>} normalized policy, errors keyed "CODE.field"
     */
    public static function validate(array $in, array $typeCodes): array
    {
        $errors = [];
        $out = ['types' => []];
        foreach ((array) ($in['types'] ?? []) as $code => $t) {
            $code = (string) $code;
            if (!in_array($code, $typeCodes, true)) {
                $errors[$code . '.code'] = 'leave.policy_error.type';
                continue;
            }
            if (!is_array($t) || !self::anyValue($t)) {
                continue; // left empty in the form = no rule for this type (requests are refused)
            }
            $legal = self::days($t['legal_minimum_days'] ?? null, $errors, $code . '.legal_minimum_days');
            $ent = self::days($t['entitlement_days'] ?? null, $errors, $code . '.entitlement_days');
            if ($legal !== null && $ent !== null && $ent < $legal) {
                $errors[$code . '.entitlement_days'] = 'leave.policy_error.below_legal';
            }
            if ($legal !== null && $ent === null) {
                $errors[$code . '.entitlement_days'] = 'leave.policy_error.legal_needs_quota';
            }
            $groups = [];
            if (isset($t['group_text']) && trim((string) $t['group_text']) !== '') { // form input "DAILY=6, PART_TIME=3"
                $t['group_entitlements'] = [];
                foreach (preg_split('/[,;\n]+/', (string) $t['group_text']) ?: [] as $pair) {
                    if (trim($pair) === '') {
                        continue;
                    }
                    if (preg_match('/^\s*([A-Za-z0-9_]+)\s*[=:]\s*([0-9.]+)\s*$/', $pair, $mm) !== 1) {
                        $errors[$code . '.group_entitlements'] = 'leave.policy_error.group';
                        continue;
                    }
                    $t['group_entitlements'][$mm[1]] = $mm[2];
                }
            }
            foreach ((array) ($t['group_entitlements'] ?? []) as $g => $v) {
                $g = strtoupper(trim((string) $g));
                if ($g === '' && ($v === '' || $v === null)) {
                    continue;
                }
                if (preg_match('/^[A-Z0-9_]{1,20}$/', $g) !== 1) {
                    $errors[$code . '.group_entitlements'] = 'leave.policy_error.group';
                    continue;
                }
                $d = self::days($v, $errors, $code . '.group_entitlements');
                if ($d === null) {
                    $errors[$code . '.group_entitlements'] ??= 'leave.policy_error.days';
                    continue;
                }
                if ($legal !== null && $d < $legal) {
                    $errors[$code . '.group_entitlements'] = 'leave.policy_error.below_legal';
                }
                $groups[$g] = $d;
            }
            $svc = self::int($t['min_service_months'] ?? 0, 0, 600, $errors, $code . '.min_service_months') ?? 0;
            $pro = (string) ($t['proration'] ?? 'none');
            if (!in_array($pro, self::PRORATION, true)) {
                $errors[$code . '.proration'] = 'form.invalid_choice';
            }
            $carry = self::days($t['carry_forward_max_days'] ?? 0, $errors, $code . '.carry_forward_max_days') ?? 0.0;
            if ($carry > 0 && $ent === null) {
                $errors[$code . '.carry_forward_max_days'] = 'leave.policy_error.carry_needs_quota';
            }
            $out['types'][$code] = [
                'legal_minimum_days' => $legal,
                'entitlement_days' => $ent,
                'group_entitlements' => $groups,
                'min_service_months' => $svc,
                'proration' => $pro,
                'carry_forward_max_days' => $carry,
                'allow_negative' => self::bool($t['allow_negative'] ?? false),
                'half_day' => self::bool($t['half_day'] ?? false),
                'min_notice_days' => self::int($t['min_notice_days'] ?? null, 0, 365, $errors, $code . '.min_notice_days'),
                'max_backdate_days' => self::int($t['max_backdate_days'] ?? null, 0, 366, $errors, $code . '.max_backdate_days'),
                'document_from_days' => self::days($t['document_from_days'] ?? null, $errors, $code . '.document_from_days'),
            ];
        }
        if ($out['types'] === [] && $errors === []) {
            $errors['_form'] = 'leave.policy_error.empty';
        }
        return [$errors === [] ? $out : null, $errors];
    }

    /**
     * The rule of one type for an employee group, or null when the policy has no rule for it.
     *
     * @param array<string, mixed>|null $policy
     * @return array<string, mixed>|null rule with 'entitlement' resolved for the group
     */
    public static function rule(?array $policy, string $typeCode, string $group): ?array
    {
        $r = $policy['types'][$typeCode] ?? null;
        if (!is_array($r)) {
            return null;
        }
        $groups = (array) ($r['group_entitlements'] ?? []);
        $r['entitlement'] = array_key_exists($group, $groups) ? (float) $groups[$group] : ($r['entitlement_days'] !== null ? (float) $r['entitlement_days'] : null);
        return $r;
    }

    /**
     * Grant for a leave year: full entitlement, or (proration "monthly", hired in that year) entitlement × remaining
     * months from the hire month / 12, rounded DOWN to half a day. Statutory share = min(grant, legal minimum prorated
     * the same way). Returns null when no quota is tracked.
     *
     * @param array<string, mixed> $rule from rule()
     * @return array{units: float, statutory: ?float}|null
     */
    public static function grant(array $rule, int $year, string $hireDate): ?array
    {
        if ($rule['entitlement'] === null) {
            return null;
        }
        $factor = 1.0;
        $hireYear = (int) substr($hireDate, 0, 4);
        if ($hireYear > $year) {
            $factor = 0.0;
        } elseif ($rule['proration'] === 'monthly' && $hireYear === $year) {
            $factor = (13 - (int) substr($hireDate, 5, 2)) / 12;
        }
        $units = floor((float) $rule['entitlement'] * $factor * 2) / 2;
        $legal = $rule['legal_minimum_days'] !== null ? floor((float) $rule['legal_minimum_days'] * $factor * 2) / 2 : null;
        return ['units' => $units, 'statutory' => $legal !== null ? min($units, $legal) : null];
    }

    /** Whole months of service on $date since $hireDate. */
    public static function serviceMonths(string $hireDate, string $date): int
    {
        [$hy, $hm, $hd] = array_map('intval', explode('-', $hireDate));
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        $months = ($y - $hy) * 12 + ($m - $hm) - ($d < $hd ? 1 : 0);
        return max(0, $months);
    }

    /**
     * Approval page rows (config_owners "describe"): one readable line per leave type instead of raw JSON.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function describe(\Blue\Core\App $app, mixed $value): array
    {
        $t = $app->translator();
        $rows = [];
        foreach ((array) ($value['types'] ?? []) as $code => $r) {
            $names = $app->db()->one('SELECT name_th, name_en FROM lv_types WHERE code = ? ORDER BY id LIMIT 1', [(string) $code]) ?? [];
            $name = $t->locale() === 'en' && ($names['name_en'] ?? '') !== '' ? (string) $names['name_en'] : (string) ($names['name_th'] ?? $code);
            $u = static fn (mixed $x): string => $x === null ? '—' : LeaveService::units((float) $x);
            $parts = [
                $t->get('leave.pf.legal_minimum_days') . ' ' . $u($r['legal_minimum_days'] ?? null),
                $t->get('leave.pf.entitlement_days') . ' ' . (($r['entitlement_days'] ?? null) === null ? $t->get('leave.not_tracked') : $u($r['entitlement_days'])),
            ];
            foreach ((array) ($r['group_entitlements'] ?? []) as $g => $d) {
                $parts[] = $g . ' ' . $u($d);
            }
            if ((int) ($r['min_service_months'] ?? 0) > 0) {
                $parts[] = $t->get('leave.pf.min_service_months') . ' ' . (int) $r['min_service_months'];
            }
            if (($r['proration'] ?? 'none') === 'monthly') {
                $parts[] = $t->get('leave.proration.monthly');
            }
            if ((float) ($r['carry_forward_max_days'] ?? 0) > 0) {
                $parts[] = $t->get('leave.pf.carry_forward_max_days') . ' ' . $u($r['carry_forward_max_days']);
            }
            foreach (['half_day', 'allow_negative'] as $k) {
                if (!empty($r[$k])) {
                    $parts[] = $t->get('leave.pf.' . $k);
                }
            }
            foreach (['min_notice_days', 'max_backdate_days', 'document_from_days'] as $k) {
                if (($r[$k] ?? null) !== null) {
                    $parts[] = $t->get('leave.pf.' . $k) . ' ' . $u($r[$k]);
                }
            }
            $rows[] = [$name . ' (' . $code . ')', implode(' · ', $parts)];
        }
        return $rows;
    }

    // ---- helpers -------------------------------------------------------------------------------

    /** @param array<string, mixed> $t */
    private static function anyValue(array $t): bool
    {
        foreach ($t as $k => $v) {
            if ($k === 'group_text') {
                if (trim((string) $v) !== '') {
                    return true;
                }
                continue;
            }
            if ($k === 'group_entitlements') {
                foreach ((array) $v as $g => $x) {
                    if (trim((string) $g) !== '' || trim((string) $x) !== '') {
                        return true;
                    }
                }
                continue;
            }
            if (in_array($k, ['allow_negative', 'half_day', 'proration'], true)) {
                continue; // checkboxes / select alone do not make a rule
            }
            if (is_bool($v) || (is_scalar($v) && trim((string) $v) !== '' && !($k === 'min_service_months' && (string) $v === '0') && !($k === 'carry_forward_max_days' && (float) $v === 0.0))) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, string> $errors */
    private static function days(mixed $v, array &$errors, string $key): ?float
    {
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return null;
        }
        if (!is_numeric($v)) {
            $errors[$key] = 'leave.policy_error.days';
            return null;
        }
        $f = (float) $v;
        if ($f < 0 || $f > 366 || fmod($f * 2, 1.0) !== 0.0) {
            $errors[$key] = 'leave.policy_error.days';
            return null;
        }
        return $f;
    }

    /** @param array<string, string> $errors */
    private static function int(mixed $v, int $min, int $max, array &$errors, string $key): ?int
    {
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return null;
        }
        if (!is_numeric($v) || (int) $v != $v || (int) $v < $min || (int) $v > $max) {
            $errors[$key] = 'leave.policy_error.whole';
            return null;
        }
        return (int) $v;
    }

    private static function bool(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'on' || $v === 'true';
    }
}
