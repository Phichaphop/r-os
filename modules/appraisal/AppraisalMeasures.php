<?php

declare(strict_types=1);

namespace Blue\Modules\Appraisal;

use Blue\Core\App;
use Blue\Core\Kpi\MeasureProvider;
use Blue\Core\Kpi\MeasureQuery;
use Blue\Core\Kpi\MeasureResult;

/** M46 measure from appraisals (DEC-80): share finalized in the cycles that are open now. Numbers only — no scores per person. */
final class AppraisalMeasures implements MeasureProvider
{
    public static function measures(): array
    {
        return ['appraisal.completion' => ['label' => 'appraisal.measure.completion', 'formula' => 'appraisal.measure.completion_formula', 'source' => 'appraisal.measure.source',
            'unit' => 'percent', 'point' => true, 'dims' => ['site', 'department'], 'drill' => ['/appraisal/admin', 'appraisal.manage']]];
    }

    public static function compute(App $app, string $code, MeasureQuery $q): MeasureResult
    {
        [$w, $p] = $q->where('h.site_id', 'h.department_id');
        $row = $app->db()->one(
            "SELECT COUNT(*) AS n, SUM(a.status IN ('finalized','acknowledged')) AS done FROM apr_appraisals a JOIN apr_cycles c ON c.id = a.cycle_id
               JOIN employment_history h ON h.employee_id = a.employee_id AND h.legal_entity_id = c.legal_entity_id AND h.effective_to IS NULL
              WHERE c.legal_entity_id = ? AND c.status = 'open' AND {$w}",
            [$q->legalEntityId, ...$p]
        );
        $n = (int) ($row['n'] ?? 0);
        $done = (int) ($row['done'] ?? 0);
        return new MeasureResult($n > 0 ? round($done / $n * 100, 2) : null, ['appraisal.measure.part.done' => $done, 'appraisal.measure.part.total' => $n]);
    }
}
