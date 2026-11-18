<?php

declare(strict_types=1);

namespace Blue\Modules\Recruit;

use Blue\Core\App;
use Blue\Core\Kpi\MeasureProvider;
use Blue\Core\Kpi\MeasureQuery;
use Blue\Core\Kpi\MeasureResult;

/** M46 measure from requisitions (DEC-80): open positions now (approved heads not yet hired). */
final class RecruitMeasures implements MeasureProvider
{
    public static function measures(): array
    {
        return ['recruit.open' => ['label' => 'recruit.measure.open', 'formula' => 'recruit.measure.open_formula', 'source' => 'recruit.measure.source', 'unit' => 'count',
            'point' => true, 'dims' => ['site', 'department'], 'drill' => ['/recruit', 'recruit.manage']]];
    }

    public static function compute(App $app, string $code, MeasureQuery $q): MeasureResult
    {
        [$w, $p] = $q->where('r.site_id', 'r.department_id');
        $open = 0;
        $reqs = 0;
        foreach (
            $app->db()->all(
                "SELECT r.headcount, (SELECT COUNT(*) FROM rec_candidates c JOIN rec_postings x ON x.id = c.posting_id WHERE x.requisition_id = r.id AND c.stage = 'hired') AS hired
                   FROM rec_requisitions r WHERE r.legal_entity_id = ? AND r.status = 'approved' AND {$w}",
                [$q->legalEntityId, ...$p]
            ) as $r
        ) {
            $left = max(0, (int) $r['headcount'] - (int) $r['hired']);
            $open += $left;
            $reqs += $left > 0 ? 1 : 0;
        }
        return new MeasureResult((float) $open, ['recruit.measure.part.requisitions' => $reqs]);
    }
}
