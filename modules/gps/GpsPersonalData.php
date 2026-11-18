<?php

declare(strict_types=1);

namespace Blue\Modules\Gps;

use Blue\Core\App;
use Blue\Core\Privacy\PersonalDataProvider;
use Blue\Core\Privacy\PrivacyService;

/**
 * M57 personal data (DEC-82): my GPS punches (with my own positions) and the notices I acknowledged. Erasure clears
 * the position, accuracy, IP and device of punches that are no longer waiting for review (the time and in / out of
 * the area stay — they are working-time records); flagged punches and legal holds keep everything.
 */
final class GpsPersonalData implements PersonalDataProvider
{
    public static function export(App $app, int $userId, ?int $employeeId): array
    {
        if ($employeeId === null) {
            return [];
        }
        $db = $app->db();
        $punches = array_map(static fn (array $p): array => [
            'work_date' => $p['work_date'], 'local_time' => $p['local_time'], 'direction' => $p['direction'], 'inside_area' => $p['inside'] === null ? null : (int) $p['inside'],
            'status' => $p['status'], 'latitude' => $p['lat'], 'longitude' => $p['lng'], 'accuracy_m' => $p['accuracy_m'], 'flags' => $p['flags'], 'offline' => (int) $p['offline'],
            'note' => $p['employee_note'], 'ip' => $p['ip'], 'device' => $p['device'], 'anonymised_at' => $p['anonymized_at'],
        ], $db->all('SELECT * FROM gps_punches WHERE employee_id = ? ORDER BY punch_at_utc', [$employeeId]));
        $notices = array_map(
            static fn (array $n): array => ['policy_version' => $n['policy_version_pid'], 'acknowledged_at' => $n['acknowledged_at']],
            $db->all('SELECT * FROM gps_notices WHERE employee_id = ? ORDER BY id', [$employeeId])
        );
        return [
            ['key' => 'punches', 'label' => 'gps.privacy.punches', 'rows' => $punches],
            ['key' => 'notices', 'label' => 'gps.privacy.notices', 'rows' => $notices],
        ];
    }

    public static function erase(App $app, int $userId, ?int $employeeId, int $actorId, bool $dryRun): array
    {
        if ($employeeId === null) {
            return ['done' => [], 'kept' => []];
        }
        $db = $app->db();
        $emp = $db->one('SELECT e.public_id, (SELECT h.legal_entity_id FROM employment_history h WHERE h.employee_id = e.id ORDER BY h.effective_from DESC LIMIT 1) AS le FROM employees e WHERE e.id = ?', [$employeeId]);
        if (PrivacyService::held($app, 'gps_selfie', $emp['le'] !== null ? (int) $emp['le'] : null, (string) ($emp['public_id'] ?? ''))) {
            return ['done' => [], 'kept' => ['gps.privacy.punches' => 'privacy.kept.legal_hold']];
        }
        $where = "employee_id = ? AND anonymized_at IS NULL AND status <> 'flagged' AND (lat IS NOT NULL OR ip IS NOT NULL OR device IS NOT NULL)";
        $n = (int) $db->scalar("SELECT COUNT(*) FROM gps_punches WHERE {$where}", [$employeeId]);
        $flagged = (int) $db->scalar("SELECT COUNT(*) FROM gps_punches WHERE employee_id = ? AND status = 'flagged'", [$employeeId]);
        if (!$dryRun && $n > 0) {
            $db->run("UPDATE gps_punches SET lat = NULL, lng = NULL, accuracy_m = NULL, ip = NULL, device = NULL, anonymized_at = ? WHERE {$where}", [$app->clock->now()->format('Y-m-d H:i:s.u'), $employeeId]);
        }
        $kept = ['gps.privacy.times' => 'gps.privacy.kept_times'];
        if ($flagged > 0) {
            $kept['gps.privacy.flagged'] = 'gps.privacy.kept_flagged';
        }
        return ['done' => ['gps.privacy.positions' => $n], 'kept' => $kept];
    }
}
