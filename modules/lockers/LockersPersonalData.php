<?php

declare(strict_types=1);

namespace Blue\Modules\Lockers;

use Blue\Core\App;
use Blue\Core\Privacy\PersonalDataProvider;

/**
 * Locker personal data (DEC-89): the lockers I held (locker, dates, key number, return). A reservation is cancelled on
 * erasure; a locker I still hold — or held, with the key handover and return — is kept as the record of the
 * company's key until the retention rule says otherwise.
 */
final class LockersPersonalData implements PersonalDataProvider
{
    public static function export(App $app, int $userId, ?int $employeeId): array
    {
        $rows = $app->db()->all(
            'SELECT l.code AS locker, a.status, a.created_at, a.key_no, a.key_issued_at, a.received_ack_at, a.returned_at, a.key_returned, a.locker_condition
               FROM lk_assignments a JOIN lk_lockers l ON l.id = a.locker_id WHERE a.user_id = ? ORDER BY a.id',
            [$userId]
        );
        return [['key' => 'lockers', 'label' => 'lockers.privacy.lockers', 'rows' => $rows]];
    }

    public static function erase(App $app, int $userId, ?int $employeeId, int $actorId, bool $dryRun): array
    {
        $db = $app->db();
        $reserved = $db->all("SELECT id, locker_id FROM lk_assignments WHERE user_id = ? AND status = 'reserved'", [$userId]);
        if (!$dryRun) {
            $now = $app->clock->now()->format('Y-m-d H:i:s.u');
            foreach ($reserved as $a) {
                $db->run("UPDATE lk_assignments SET status = 'cancelled', cancel_reason = 'DSR', updated_at = ? WHERE id = ?", [$now, $a['id']]);
                $db->run("UPDATE lk_lockers SET status = 'available', updated_at = ? WHERE id = ? AND status = 'reserved'", [$now, $a['locker_id']]);
            }
        }
        $kept = (int) $db->scalar("SELECT COUNT(*) FROM lk_assignments WHERE user_id = ? AND status NOT IN ('reserved','cancelled','expired')", [$userId]);
        return ['done' => $reserved !== [] ? ['lockers.privacy.reservations' => count($reserved)] : [], 'kept' => $kept > 0 ? ['lockers.privacy.lockers' => 'lockers.privacy.kept'] : []];
    }
}
