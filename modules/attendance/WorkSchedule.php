<?php

declare(strict_types=1);

namespace Blue\Modules\Attendance;

use Blue\Core\App;

/**
 * Work schedule of a company — Config Registry key `attendance.schedule` (COMPANY_POLICY, high risk, DEC-64).
 * NOTHING is shipped: HR (attendance.policy) proposes it with a reference to the company's work rules and another HR
 * (attendance.policy_approve) approves. Without it, Blue OS still shows punches and worked time but never says "late".
 *
 * Value:
 *   { "day_start": "04:00",            punches before this local time belong to the previous work date
 *     "correction_max_days": 31|null,  how far back an employee may ask for a correction (null = no limit until 4C locks)
 *     "groups": { "*": {start, end, break_minutes, late_grace_minutes, early_grace_minutes}, "<GROUP>": {…} } }
 * One fixed day per employee group ("*" = everyone else). Shifts, rosters and overnight work come with M35 (phase 5).
 */
final class WorkSchedule
{
    public const FIELDS = ['start', 'end', 'break_minutes', 'late_grace_minutes', 'early_grace_minutes'];

    /**
     * @param array<string, mixed> $in day_start, correction_max_days, groups: list<array{group, start, end, break_minutes, late_grace_minutes, early_grace_minutes}>
     * @return array{0: array<string, mixed>|null, 1: array<string, string>}
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $dayStart = trim((string) ($in['day_start'] ?? ''));
        $ds = self::minutes($dayStart);
        if ($ds === null || $ds > 6 * 60) {
            $errors['day_start'] = 'attendance.schedule_error.day_start';
        }
        $maxRaw = trim((string) ($in['correction_max_days'] ?? ''));
        $max = null;
        if ($maxRaw !== '') {
            if (!ctype_digit($maxRaw) || (int) $maxRaw < 1 || (int) $maxRaw > 366) {
                $errors['correction_max_days'] = 'attendance.schedule_error.max_days';
            } else {
                $max = (int) $maxRaw;
            }
        }
        $groups = [];
        foreach (array_values((array) ($in['groups'] ?? [])) as $i => $g) {
            $g = (array) $g;
            $code = strtoupper(trim((string) ($g['group'] ?? '')));
            $empty = $code === '' && trim((string) ($g['start'] ?? '')) === '' && trim((string) ($g['end'] ?? '')) === '';
            if ($empty) {
                continue; // blank spare row of the form
            }
            if ($code !== '*' && preg_match('/^[A-Z][A-Z0-9_]{0,19}$/', $code) !== 1) {
                $errors["groups.{$i}.group"] = 'attendance.schedule_error.group';
                continue;
            }
            if (isset($groups[$code])) {
                $errors["groups.{$i}.group"] = 'attendance.schedule_error.group_twice';
                continue;
            }
            $s = self::minutes(trim((string) ($g['start'] ?? '')));
            $e = self::minutes(trim((string) ($g['end'] ?? '')));
            if ($s === null) {
                $errors["groups.{$i}.start"] = 'attendance.schedule_error.time';
            }
            if ($e === null) {
                $errors["groups.{$i}.end"] = 'attendance.schedule_error.time';
            } elseif ($s !== null && $e <= $s) {
                $errors["groups.{$i}.end"] = 'attendance.schedule_error.end_after_start';
            }
            if ($s !== null && $ds !== null && $s < $ds) {
                $errors["groups.{$i}.start"] = 'attendance.schedule_error.before_day_start';
            }
            $nums = [];
            foreach (['break_minutes' => 240, 'late_grace_minutes' => 120, 'early_grace_minutes' => 120] as $f => $lim) {
                $v = trim((string) ($g[$f] ?? ''));
                $v = $v === '' ? '0' : $v;
                if (!ctype_digit($v) || (int) $v > $lim) {
                    $errors["groups.{$i}.{$f}"] = 'attendance.schedule_error.minutes';
                }
                $nums[$f] = (int) $v;
            }
            if ($s !== null && $e !== null && $e > $s && $nums['break_minutes'] >= $e - $s) {
                $errors["groups.{$i}.break_minutes"] = 'attendance.schedule_error.break';
            }
            $groups[$code] = ['start' => self::hm((int) $s), 'end' => self::hm((int) $e)] + $nums;
        }
        if ($groups === [] && !isset($errors['_form'])) {
            $errors['_form'] = 'attendance.schedule_error.empty';
        }
        if ($errors !== []) {
            return [null, $errors];
        }
        ksort($groups);
        return [['day_start' => self::hm((int) $ds), 'correction_max_days' => $max, 'groups' => $groups], []];
    }

    /**
     * The working day of an employee group (falls back to "*"); null when the schedule has neither.
     *
     * @param array<string, mixed>|null $schedule
     * @return array{start: string, end: string, break_minutes: int, late_grace_minutes: int, early_grace_minutes: int}|null
     */
    public static function forGroup(?array $schedule, string $group): ?array
    {
        if ($schedule === null) {
            return null;
        }
        $g = (array) ($schedule['groups'] ?? []);
        $r = $g[strtoupper($group)] ?? $g['*'] ?? null;
        return is_array($r) ? [
            'start' => (string) $r['start'], 'end' => (string) $r['end'], 'break_minutes' => (int) ($r['break_minutes'] ?? 0),
            'late_grace_minutes' => (int) ($r['late_grace_minutes'] ?? 0), 'early_grace_minutes' => (int) ($r['early_grace_minutes'] ?? 0),
        ] : null;
    }

    /** Minutes after midnight for "HH:MM" (00:00–23:59), else null. */
    public static function minutes(string $hm): ?int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hm, $m) !== 1) {
            return null;
        }
        return (int) $m[1] * 60 + (int) $m[2];
    }

    public static function hm(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Rows for the approval page (config owner "describe").
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function describe(App $app, mixed $value): array
    {
        $t = $app->translator();
        $v = is_array($value) ? $value : [];
        $rows = [[$t->get('attendance.sf.day_start'), (string) ($v['day_start'] ?? '—')],
            [$t->get('attendance.sf.correction_max_days'), ($v['correction_max_days'] ?? null) === null ? $t->get('attendance.no_limit') : (string) $v['correction_max_days']]];
        foreach ((array) ($v['groups'] ?? []) as $code => $g) {
            $rows[] = [
                $code === '*' ? $t->get('attendance.group_all') : $t->get('attendance.group_named', ['group' => (string) $code]),
                $t->get('attendance.schedule_line', [
                    'start' => (string) ($g['start'] ?? ''), 'end' => (string) ($g['end'] ?? ''), 'break' => (string) (int) ($g['break_minutes'] ?? 0),
                    'late' => (string) (int) ($g['late_grace_minutes'] ?? 0), 'early' => (string) (int) ($g['early_grace_minutes'] ?? 0),
                ]),
            ];
        }
        return $rows;
    }
}
