<?php

declare(strict_types=1);

namespace Blue\Modules\Attendance;

use Blue\Core\App;
use Blue\Core\Kpi\MeasureProvider;
use Blue\Core\Kpi\MeasureQuery;
use Blue\Core\Kpi\MeasureResult;

/** M46 measures from calculated attendance days (DEC-80): late days and absent days. */
final class AttendanceMeasures implements MeasureProvider
{
    public static function measures(): array
    {
        $d = ['source' => 'attendance.measure.source', 'unit' => 'count', 'dims' => ['site', 'department'], 'drill' => ['/attendance/admin', 'attendance.manage']];
        return [
            'attendance.late' => ['label' => 'attendance.measure.late', 'formula' => 'attendance.measure.late_formula'] + $d,
            'attendance.absent' => ['label' => 'attendance.measure.absent', 'formula' => 'attendance.measure.absent_formula'] + $d,
        ];
    }

    public static function compute(App $app, string $code, MeasureQuery $q): MeasureResult
    {
        [$w, $p] = $q->where('d.site_id', 'd.department_id');
        $cond = $code === 'attendance.late' ? 'd.late_minutes > 0' : "d.status = 'absent'";
        $row = $app->db()->one(
            "SELECT COUNT(*) AS n, COUNT(DISTINCT d.employee_id) AS people FROM att_days d WHERE d.legal_entity_id = ? AND d.work_date BETWEEN ? AND ? AND {$cond} AND {$w}",
            [$q->legalEntityId, $q->from, $q->to, ...$p]
        );
        return new MeasureResult((float) ($row['n'] ?? 0), ['attendance.measure.part.people' => (int) ($row['people'] ?? 0)]);
    }
}
