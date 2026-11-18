<?php

declare(strict_types=1);

namespace Blue\Modules\Inspection;

use Blue\Core\App;
use Blue\Core\Privacy\PersonalDataProvider;

/**
 * Inspection personal data (DEC-91): the rounds I inspected and the corrective actions given to me. The records are
 * about places and equipment (safety evidence) — erasing does not remove them; my open rounds go back to the point's
 * schedule for the admin to give to someone else.
 */
final class InspectionPersonalData implements PersonalDataProvider
{
    public static function export(App $app, int $userId, ?int $employeeId): array
    {
        $db = $app->db();
        return [
            ['key' => 'rounds', 'label' => 'inspection.privacy.rounds', 'rows' => $db->all('SELECT p.code, p.name, r.due_date, r.status, r.result, r.submitted_at FROM in_rounds r JOIN in_points p ON p.id = r.point_id WHERE r.inspector_id = ? ORDER BY r.due_date', [$userId])],
            ['key' => 'actions', 'label' => 'inspection.privacy.actions', 'rows' => $db->all('SELECT description, due_date, status, done_note, done_at FROM in_actions WHERE assignee_id = ? ORDER BY id', [$userId])],
        ];
    }

    public static function erase(App $app, int $userId, ?int $employeeId, int $actorId, bool $dryRun): array
    {
        $db = $app->db();
        $rounds = (int) $db->scalar('SELECT COUNT(*) FROM in_rounds WHERE inspector_id = ?', [$userId]);
        $actions = (int) $db->scalar("SELECT COUNT(*) FROM in_actions WHERE assignee_id = ? AND status = 'open'", [$userId]);
        if (!$dryRun) {
            $db->run("UPDATE in_actions SET assignee_id = NULL, due_date = NULL WHERE assignee_id = ? AND status = 'open'", [$userId]);
        }
        return ['done' => array_filter(['inspection.privacy.actions_unassigned' => $actions]), 'kept' => $rounds > 0 ? ['inspection.privacy.rounds' => 'inspection.privacy.kept'] : []];
    }
}
