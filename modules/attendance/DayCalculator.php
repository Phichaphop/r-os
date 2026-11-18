<?php

declare(strict_types=1);

namespace Blue\Modules\Attendance;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One employee-day from its inputs — pure rules, no database (DEC-64). The same inputs always give the same result,
 * so att_days can be rebuilt at any time.
 *
 *  in / out   first punch and last punch of the work date; ONE punch counts as "in" when it is before the middle of the
 *             working day (12:00 without a schedule), otherwise as "out". An approved correction replaces in and/or out.
 *  worked     out − in − break (break only with a schedule).
 *  late       in after start; within the grace minutes it is not late, beyond them it counts from the start time.
 *  early      out before end, same grace rule.
 *  status     ok · late · early · late_early · missing_in · missing_out · absent · in_progress · leave · half_leave
 *             · off · off_worked · no_calendar · no_data (no scans and no schedule in force — never called absent)
 */
final class DayCalculator
{
    public const VERSION = 'att-calc-2'; // 2: overnight shifts (M35)

    /**
     * @param array{working: ?bool, type: ?string, name: ?string} $day calendar answer for the date
     * @param array{part: string, name: string}|null $leave approved leave on the date
     * @param list<DateTimeImmutable> $punches UTC, any order
     * @param array{in: ?DateTimeImmutable, out: ?DateTimeImmutable}|null $correction approved correction (UTC)
     * @param array{start: string, end: string, break_minutes: int, late_grace_minutes: int, early_grace_minutes: int}|null $rule
     * @return array{first_in: ?DateTimeImmutable, last_out: ?DateTimeImmutable, in_source: ?string, out_source: ?string, punch_count: int,
     *               worked_minutes: ?int, late_minutes: ?int, early_minutes: ?int, status: string}
     */
    public static function calculate(string $workDate, DateTimeZone $tz, array $day, ?array $leave, array $punches, ?array $correction, ?array $rule, string $today): array
    {
        usort($punches, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
        $midnight = DateTimeImmutable::createFromFormat('!Y-m-d', $workDate, $tz);
        $base = $midnight !== false ? $midnight->getTimestamp() : 0;
        $minuteOf = static fn (DateTimeImmutable $t): int => intdiv($t->getTimestamp() - $base, 60);
        $in = null;
        $out = null;
        if (count($punches) >= 2) {
            $in = $punches[0];
            $out = $punches[count($punches) - 1];
        } elseif (count($punches) === 1) {
            $mid = $rule !== null ? intdiv((int) WorkSchedule::minutes($rule['start']) + self::endMinutes($rule), 2) : 12 * 60;
            if ($minuteOf($punches[0]) < $mid) {
                $in = $punches[0];
            } else {
                $out = $punches[0];
            }
        }
        $inSrc = $in !== null ? 'punch' : null;
        $outSrc = $out !== null ? 'punch' : null;
        if ($correction !== null) {
            if ($correction['in'] !== null) {
                $in = $correction['in'];
                $inSrc = 'correction';
            }
            if ($correction['out'] !== null) {
                $out = $correction['out'];
                $outSrc = 'correction';
            }
        }
        $worked = null;
        $late = null;
        $early = null;
        if ($in !== null && $out !== null && $out > $in) {
            $span = intdiv($out->getTimestamp() - $in->getTimestamp(), 60);
            $worked = max(0, $span - ($rule !== null && $span > $rule['break_minutes'] ? $rule['break_minutes'] : 0));
        }
        $working = $day['working'];
        $halfLeave = $leave !== null && $leave['part'] !== 'full';
        if ($rule !== null && $working === true && $leave === null) {
            $start = (int) WorkSchedule::minutes($rule['start']);
            $end = self::endMinutes($rule);
            if ($in !== null) {
                $l = $minuteOf($in) - $start;
                $late = $l > $rule['late_grace_minutes'] ? $l : 0;
            }
            if ($out !== null) {
                $e = $end - $minuteOf($out);
                $early = $e > $rule['early_grace_minutes'] ? $e : 0;
            }
        }
        $has = $in !== null || $out !== null;
        if ($working === null) {
            $status = 'no_calendar';
        } elseif ($working === false) {
            $status = $has ? 'off_worked' : 'off';
        } elseif ($leave !== null && !$halfLeave) {
            $status = 'leave';
        } elseif (!$has) {
            // "absent" needs an approved schedule in force — before it (e.g. before go-live) a day without scans is only "no data"
            $status = $workDate >= $today ? 'in_progress' : ($rule !== null ? 'absent' : 'no_data');
        } elseif ($halfLeave) {
            $status = 'half_leave';
        } elseif ($in === null) {
            $status = 'missing_in';
        } elseif ($out === null) {
            $status = $workDate >= $today ? 'in_progress' : 'missing_out';
        } elseif (($late ?? 0) > 0 && ($early ?? 0) > 0) {
            $status = 'late_early';
        } elseif (($late ?? 0) > 0) {
            $status = 'late';
        } elseif (($early ?? 0) > 0) {
            $status = 'early';
        } else {
            $status = 'ok';
        }
        return ['first_in' => $in, 'last_out' => $out, 'in_source' => $inSrc, 'out_source' => $outSrc, 'punch_count' => count($punches),
            'worked_minutes' => $worked, 'late_minutes' => $late, 'early_minutes' => $early, 'status' => $status];
    }

    /**
     * End of the working time in minutes after the work date's midnight — past 1440 for an overnight shift (M35, end ≤ start).
     *
     * @param array{start: string, end: string} $rule
     */
    public static function endMinutes(array $rule): int
    {
        $s = (int) WorkSchedule::minutes($rule['start']);
        $e = (int) WorkSchedule::minutes($rule['end']);
        return $e <= $s ? $e + 1440 : $e;
    }

    /** Work date of a punch: local date after moving back by the day start (punches before 04:00 belong to yesterday). */
    public static function workDate(DateTimeImmutable $utc, DateTimeZone $tz, int $dayStartMinutes): string
    {
        return $utc->setTimezone($tz)->modify('-' . $dayStartMinutes . ' minutes')->format('Y-m-d');
    }

    /** Statuses HR must look at (exceptions). */
    public const EXCEPTIONS = ['missing_in', 'missing_out', 'absent', 'late', 'early', 'late_early', 'off_worked', 'no_calendar'];
}
