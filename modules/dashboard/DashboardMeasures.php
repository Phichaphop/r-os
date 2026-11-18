<?php

declare(strict_types=1);

namespace Blue\Modules\Dashboard;

use Blue\Core\App;
use Blue\Core\Kpi\MeasureProvider;
use Blue\Core\Kpi\MeasureQuery;
use Blue\Core\Kpi\MeasureResult;

/** Platform measures (DEC-80): approvals waiting now, and those past their due time — from the shared workflow engine. */
final class DashboardMeasures implements MeasureProvider
{
    public static function measures(): array
    {
        $d = ['source' => 'dashboard.measure.source', 'unit' => 'count', 'dims' => [], 'drill' => null, 'point' => true];
        return [
            'dashboard.approvals_open' => ['label' => 'dashboard.measure.approvals_open', 'formula' => 'dashboard.measure.approvals_open_formula'] + $d,
            'dashboard.approvals_overdue' => ['label' => 'dashboard.measure.approvals_overdue', 'formula' => 'dashboard.measure.approvals_overdue_formula'] + $d,
        ];
    }

    public static function compute(App $app, string $code, MeasureQuery $q): MeasureResult
    {
        $now = $app->clock->now()->format('Y-m-d H:i:s.u');
        $overdue = $code === 'dashboard.approvals_overdue' ? ' AND t.due_at IS NOT NULL AND t.due_at < ?' : '';
        $n = (int) $app->db()->scalar(
            "SELECT COUNT(DISTINCT i.id) FROM workflow_instances i JOIN workflow_tasks t ON t.instance_id = i.id AND t.status = 'open'
              WHERE i.legal_entity_id = ? AND i.status = 'pending'" . $overdue,
            $overdue !== '' ? [$q->legalEntityId, $now] : [$q->legalEntityId]
        );
        return new MeasureResult((float) $n);
    }
}
