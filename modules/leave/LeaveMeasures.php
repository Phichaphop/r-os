<?php

declare(strict_types=1);

namespace Blue\Modules\Leave;

use Blue\Core\App;
use Blue\Core\Kpi\MeasureProvider;
use Blue\Core\Kpi\MeasureQuery;
use Blue\Core\Kpi\MeasureResult;

/** M46 measure from leave requests (DEC-80): leave days taken in the period. */
final class LeaveMeasures implements MeasureProvider
{
    public static function measures(): array
    {
        return ['leave.days' => ['label' => 'leave.measure.days', 'formula' => 'leave.measure.days_formula', 'source' => 'leave.measure.source', 'unit' => 'days',
            'dims' => ['site', 'department'], 'drill' => ['/leave/admin', 'leave.manage']]];
    }

    public static function compute(App $app, string $code, MeasureQuery $q): MeasureResult
    {
        [$w, $p] = $q->where('r.site_id', 'r.department_id');
        $days = 0.0;
        $requests = 0;
        foreach (
            $app->db()->all(
                "SELECT r.part, r.days_json FROM lv_requests r WHERE r.legal_entity_id = ? AND r.status IN ('approved','taken','cancel_pending')
                    AND r.start_date <= ? AND r.end_date >= ? AND {$w}",
                [$q->legalEntityId, $q->to, $q->from, ...$p]
            ) as $r
        ) {
            $n = 0;
            foreach ((array) json_decode((string) $r['days_json'], true) as $d) {
                if (is_string($d) && $d >= $q->from && $d <= $q->to) {
                    $n++;
                }
            }
            if ($n > 0) {
                $requests++;
                $days += $n * ($r['part'] === 'full' ? 1.0 : 0.5);
            }
        }
        return new MeasureResult($days, ['leave.measure.part.requests' => $requests]);
    }
}
