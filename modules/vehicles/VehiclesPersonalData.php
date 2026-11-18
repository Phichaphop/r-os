<?php

declare(strict_types=1);

namespace Blue\Modules\Vehicles;

use Blue\Core\App;
use Blue\Core\Privacy\PersonalDataProvider;

/**
 * Vehicle booking personal data (DEC-90): the requests I made (when, why, the route with its pins and links, how many
 * people), the trips where I am listed as a passenger, and my driver record. Erasing cancels my requests that have not
 * started and removes places, pins, links and passenger names from the rest; distances, odometer and costs stay for
 * the vehicle report. A driver record loses its phone and account link; the name stays on the trips it drove.
 */
final class VehiclesPersonalData implements PersonalDataProvider
{
    public static function export(App $app, int $userId, ?int $employeeId): array
    {
        $db = $app->db();
        $mine = $db->all(
            'SELECT ref, status, starts_at, ends_at, purpose, passenger_count, from_name, from_lat, from_lng, from_link, to_name, to_lat, to_lng, to_link, est_km, odo_start, odo_end FROM vh_bookings WHERE requester_id = ? ORDER BY starts_at',
            [$userId]
        );
        $out = [['key' => 'requests', 'label' => 'vehicles.privacy.requests', 'rows' => $mine]];
        if ($employeeId !== null) {
            $out[] = ['key' => 'passenger', 'label' => 'vehicles.privacy.passenger', 'rows' => $db->all(
                'SELECT b.ref, b.starts_at, b.to_name FROM vh_passengers p JOIN vh_bookings b ON b.id = p.booking_id WHERE p.employee_id = ? ORDER BY b.starts_at',
                [$employeeId]
            )];
        }
        $out[] = ['key' => 'driver', 'label' => 'vehicles.privacy.driver', 'rows' => $db->all('SELECT name, phone, licence_class, licence_expiry, work_from, work_to, work_days, active FROM vh_drivers WHERE user_id = ?', [$userId])];
        return $out;
    }

    public static function erase(App $app, int $userId, ?int $employeeId, int $actorId, bool $dryRun): array
    {
        $db = $app->db();
        $open = (int) $db->scalar("SELECT COUNT(*) FROM vh_bookings WHERE requester_id = ? AND status IN ('draft','pending','approved','assigned')", [$userId]);
        $rest = (int) $db->scalar("SELECT COUNT(*) FROM vh_bookings WHERE requester_id = ? AND from_name <> '-'", [$userId]);
        $rides = $employeeId === null ? 0 : (int) $db->scalar("SELECT COUNT(*) FROM vh_passengers WHERE employee_id = ?", [$employeeId]);
        $driver = (int) $db->scalar('SELECT COUNT(*) FROM vh_drivers WHERE user_id = ?', [$userId]);
        if (!$dryRun) {
            $stamp = $app->clock->now()->format('Y-m-d H:i:s.u');
            $db->run(
                "UPDATE vh_bookings SET status = 'cancelled', cancel_reason = 'DSR', updated_at = ?, updated_by = ? WHERE requester_id = ? AND status IN ('draft','pending','approved','assigned')",
                [$stamp, $actorId, $userId]
            );
            $db->run(
                "UPDATE workflow_instances SET status = 'cancelled', finished_at = ?, updated_at = ? WHERE subject_type = 'vh_booking' AND requester_id = ? AND status IN ('pending','returned','needs_attention')",
                [$stamp, $stamp, $userId]
            );
            $ids = array_map('intval', array_column($db->all('SELECT id FROM vh_bookings WHERE requester_id = ?', [$userId]), 'id'));
            $db->run(
                "UPDATE vh_bookings SET purpose = '-', from_name = '-', from_lat = NULL, from_lng = NULL, from_link = NULL, to_name = '-', to_lat = NULL, to_lng = NULL, to_link = NULL, note = NULL,
                    location_purged_at = COALESCE(location_purged_at, ?), updated_at = ? WHERE requester_id = ?",
                [$stamp, $stamp, $userId]
            );
            if ($ids !== []) {
                $db->run("UPDATE vh_passengers SET name = '-', employee_id = NULL WHERE booking_id IN (" . implode(',', $ids) . ')');
            }
            if ($employeeId !== null) {
                $db->run("UPDATE vh_passengers SET name = '-', employee_id = NULL WHERE employee_id = ?", [$employeeId]);
            }
            $db->run('UPDATE vh_drivers SET phone = NULL, user_id = NULL, active = 0, updated_at = ? WHERE user_id = ?', [$stamp, $userId]);
        }
        return ['done' => array_filter(['vehicles.privacy.cancelled' => $open, 'vehicles.privacy.blanked' => $rest, 'vehicles.privacy.rides' => $rides, 'vehicles.privacy.driver_unlinked' => $driver]),
            'kept' => $rest > 0 || $driver > 0 ? ['vehicles.privacy.requests' => 'vehicles.privacy.kept'] : []];
    }
}
