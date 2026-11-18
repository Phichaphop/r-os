<?php

declare(strict_types=1);

namespace Blue\Modules\Overtime;

use Blue\Core\App;
use Blue\Core\Kpi\MeasureProvider;
use Blue\Core\Kpi\MeasureQuery;
use Blue\Core\Kpi\MeasureResult;

/** M46 measure from OT requests (DEC-80): approved OT hours in the period. */
final class OvertimeMeasures implements MeasureProvider
{
    public static function measures(): array
    {
        return ['overtime.hours' => ['label' => 'overtime.measure.hours', 'formula' => 'overtime.measure.hours_formula', 'source' => 'overtime.measure.source', 'unit' => 'hours',
            'dims' => ['site', 'department'], 'drill' => ['/overtime/admin', 'overtime.export']]];
    }

    public static function compute(App $app, string $code, MeasureQuery $q): MeasureResult
    {
        [$w, $p] = $q->where('r.site_id', 'r.department_id');
        $row = $app->db()->one(
            "SELECT COUNT(*) AS n, COALESCE(SUM(COALESCE(r.payable_minutes, r.minutes_requested)), 0) AS m FROM ot_requests r
              WHERE r.legal_entity_id = ? AND r.status IN ('approved','verified','sent') AND r.work_date BETWEEN ? AND ? AND {$w}",
            [$q->legalEntityId, $q->from, $q->to, ...$p]
        );
        return new MeasureResult(round((float) ($row['m'] ?? 0) / 60, 2), ['overtime.measure.part.requests' => (int) ($row['n'] ?? 0)]);
    }
}
