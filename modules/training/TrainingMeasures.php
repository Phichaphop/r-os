<?php

declare(strict_types=1);

namespace Blue\Modules\Training;

use Blue\Core\App;
use Blue\Core\Kpi\MeasureProvider;
use Blue\Core\Kpi\MeasureQuery;
use Blue\Core\Kpi\MeasureResult;

/** M46 measure from training results (DEC-80): training hours completed in the period. */
final class TrainingMeasures implements MeasureProvider
{
    public static function measures(): array
    {
        return ['training.hours' => ['label' => 'training.measure.hours', 'formula' => 'training.measure.hours_formula', 'source' => 'training.measure.source', 'unit' => 'hours',
            'dims' => ['site', 'department'], 'drill' => ['/training/admin', 'training.manage']]];
    }

    public static function compute(App $app, string $code, MeasureQuery $q): MeasureResult
    {
        [$w, $p] = $q->where('h.site_id', 'h.department_id');
        $row = $app->db()->one(
            "SELECT COUNT(*) AS n, COUNT(DISTINCT e.employee_id) AS people, COALESCE(SUM(c.hours), 0) AS hrs
               FROM trn_enrollments e JOIN trn_sessions s ON s.id = e.session_id JOIN trn_courses c ON c.id = s.course_id
               JOIN employment_history h ON h.employee_id = e.employee_id AND h.legal_entity_id = s.legal_entity_id AND h.effective_from <= s.end_date AND (h.effective_to IS NULL OR h.effective_to >= s.end_date)
              WHERE s.legal_entity_id = ? AND e.status IN ('attended','passed') AND s.end_date BETWEEN ? AND ? AND {$w}",
            [$q->legalEntityId, $q->from, $q->to, ...$p]
        );
        return new MeasureResult((float) ($row['hrs'] ?? 0), ['training.measure.part.attendances' => (int) ($row['n'] ?? 0), 'training.measure.part.people' => (int) ($row['people'] ?? 0)]);
    }
}
