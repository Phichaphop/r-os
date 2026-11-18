<?php

declare(strict_types=1);

namespace Blue\Modules\Employees;

use Blue\Core\App;
use Blue\Core\Kpi\MeasureProvider;
use Blue\Core\Kpi\MeasureQuery;
use Blue\Core\Kpi\MeasureResult;

/** M46 measures from employee records (DEC-80): headcount, hires, leavers, turnover — counted from the job history. */
final class EmployeeMeasures implements MeasureProvider
{
    public static function measures(): array
    {
        $d = ['unit' => 'count', 'dims' => ['site', 'department'], 'drill' => ['/employees', 'employees.view'], 'source' => 'employees.measure.source'];
        return [
            'employees.headcount' => ['label' => 'employees.measure.headcount', 'formula' => 'employees.measure.headcount_formula'] + $d,
            'employees.hires' => ['label' => 'employees.measure.hires', 'formula' => 'employees.measure.hires_formula'] + $d,
            'employees.leavers' => ['label' => 'employees.measure.leavers', 'formula' => 'employees.measure.leavers_formula'] + $d,
            'employees.turnover' => ['label' => 'employees.measure.turnover', 'formula' => 'employees.measure.turnover_formula', 'unit' => 'percent'] + $d,
        ];
    }

    public static function compute(App $app, string $code, MeasureQuery $q): MeasureResult
    {
        return match ($code) {
            'employees.headcount' => new MeasureResult((float) self::headcount($app, $q, $q->to)),
            'employees.hires' => new MeasureResult((float) self::moved($app, $q, 'hire_date')),
            'employees.leavers' => new MeasureResult((float) self::moved($app, $q, 'termination_date')),
            'employees.turnover' => self::turnover($app, $q),
            default => new MeasureResult(null),
        };
    }

    /** People employed on a day: hired on or before it, not yet left (left = after their last day), with a job in the company. */
    public static function headcount(App $app, MeasureQuery $q, string $day): int
    {
        [$w, $p] = $q->where('h.site_id', 'h.department_id');
        return (int) $app->db()->scalar(
            "SELECT COUNT(DISTINCT e.id) FROM employees e JOIN employment_history h ON h.employee_id = e.id
              WHERE h.legal_entity_id = ? AND h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?)
                AND e.status <> 'pre_hire' AND e.hire_date <= ? AND (e.termination_date IS NULL OR e.termination_date >= ?) AND {$w}",
            [$q->legalEntityId, $day, $day, $day, $day, ...$p]
        );
    }

    /** Hires (hire_date) or leavers (termination_date) in the period, by the job they held on that day. */
    private static function moved(App $app, MeasureQuery $q, string $col): int
    {
        [$w, $p] = $q->where('h.site_id', 'h.department_id');
        return (int) $app->db()->scalar(
            "SELECT COUNT(DISTINCT e.id) FROM employees e JOIN employment_history h ON h.employee_id = e.id
              WHERE h.legal_entity_id = ? AND e.{$col} BETWEEN ? AND ? AND e.status <> 'pre_hire'
                AND h.effective_from <= e.{$col} AND (h.effective_to IS NULL OR h.effective_to >= e.{$col}) AND {$w}",
            [$q->legalEntityId, $q->from, $q->to, ...$p]
        );
    }

    private static function turnover(App $app, MeasureQuery $q): MeasureResult
    {
        $start = self::headcount($app, $q, $q->from);
        $end = self::headcount($app, $q, $q->to);
        $leavers = self::moved($app, $q, 'termination_date');
        $avg = ($start + $end) / 2;
        return new MeasureResult($avg > 0 ? round($leavers / $avg * 100, 2) : null, [
            'employees.measure.part.leavers' => $leavers, 'employees.measure.part.start' => $start, 'employees.measure.part.end' => $end,
        ]);
    }
}
