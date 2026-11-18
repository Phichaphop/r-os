<?php

declare(strict_types=1);

namespace Blue\Modules\Gps;

use Blue\Core\App;

/**
 * Company GPS check-in policy — Config Registry key `gps.policy` (COMPANY_POLICY, high risk, DEC-71). Nothing is
 * shipped: collecting locations needs the company's own notice and privacy review (SP-05), so HR (gps.policy) writes
 * it with the reference and another HR (gps.policy_approve) approves. No policy = check-in is off for the company.
 *
 * Value:
 *   { "outside": "flag"|"warn"|"block",   out of every check-in point: hold for review / accept with a warning / refuse
 *     "max_accuracy_m": 100,              a reading less precise than this is held for review
 *     "offline_hours": 0..72,             0 = offline check-in not allowed; otherwise how old a queued reading may be
 *     "groups": ["FIELD", …],             employee groups that may use it ([] = everyone in the company)
 *     "notice": "…" }                     what is collected, why, who sees it, how long — shown before the first use
 */
final class GpsPolicy
{
    public const OUTSIDE = ['flag', 'warn', 'block'];

    /**
     * @param array<string, mixed> $in
     * @return array{0: array<string, mixed>|null, 1: array<string, string>}
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $outside = (string) ($in['outside'] ?? '');
        if (!in_array($outside, self::OUTSIDE, true)) {
            $errors['outside'] = 'form.invalid_choice';
        }
        $acc = trim((string) ($in['max_accuracy_m'] ?? ''));
        if (!ctype_digit($acc) || (int) $acc < 10 || (int) $acc > 1000) {
            $errors['max_accuracy_m'] = 'gps.policy_error.accuracy';
        }
        $off = trim((string) ($in['offline_hours'] ?? ''));
        if (!ctype_digit($off) || (int) $off > 72) {
            $errors['offline_hours'] = 'gps.policy_error.offline';
        }
        $groups = [];
        foreach (preg_split('/[\s,]+/', strtoupper(trim((string) ($in['groups'] ?? '')))) ?: [] as $g) {
            if ($g === '') {
                continue;
            }
            if (preg_match('/^[A-Z0-9_-]{1,20}$/', $g) !== 1) {
                $errors['groups'] = 'gps.policy_error.groups';
                break;
            }
            $groups[$g] = $g;
        }
        $notice = trim((string) ($in['notice'] ?? ''));
        if (mb_strlen($notice) < 30 || mb_strlen($notice) > 3000) {
            $errors['notice'] = 'gps.policy_error.notice';
        }
        if ($errors !== []) {
            return [null, $errors];
        }
        return [['outside' => $outside, 'max_accuracy_m' => (int) $acc, 'offline_hours' => (int) $off, 'groups' => array_values($groups), 'notice' => $notice], []];
    }

    /** @return list<array{0: string, 1: string}> approval page rows */
    public static function describe(App $app, mixed $value): array
    {
        $t = $app->translator();
        $v = is_array($value) ? $value : [];
        $groups = (array) ($v['groups'] ?? []);
        return [
            [$t->get('gps.policy.outside'), $t->get('gps.outside.' . (in_array($v['outside'] ?? '', self::OUTSIDE, true) ? $v['outside'] : 'flag'))],
            [$t->get('gps.policy.max_accuracy_m'), (string) ($v['max_accuracy_m'] ?? '—')],
            [$t->get('gps.policy.offline_hours'), (int) ($v['offline_hours'] ?? 0) === 0 ? $t->get('gps.offline_off') : (string) $v['offline_hours']],
            [$t->get('gps.policy.groups'), $groups === [] ? $t->get('gps.groups_all') : implode(', ', array_map('strval', $groups))],
            [$t->get('gps.policy.notice'), (string) ($v['notice'] ?? '')],
        ];
    }
}
