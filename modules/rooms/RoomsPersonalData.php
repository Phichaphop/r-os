<?php

declare(strict_types=1);

namespace Blue\Modules\Rooms;

use Blue\Core\App;
use Blue\Core\Privacy\PersonalDataProvider;

/**
 * Meeting room personal data (DEC-89): the bookings I made (room, time, title, how many people, status). Erasing
 * cancels my future bookings and blanks the titles and invited people of the past ones — the room use counts stay.
 */
final class RoomsPersonalData implements PersonalDataProvider
{
    public static function export(App $app, int $userId, ?int $employeeId): array
    {
        $rows = $app->db()->all(
            'SELECT b.title, b.starts_at, b.ends_at, b.attendee_count, b.status, r.code AS room FROM rm_bookings b JOIN rm_rooms r ON r.id = b.room_id WHERE b.booked_by = ? ORDER BY b.starts_at',
            [$userId]
        );
        return [['key' => 'bookings', 'label' => 'rooms.privacy.bookings', 'rows' => $rows]];
    }

    public static function erase(App $app, int $userId, ?int $employeeId, int $actorId, bool $dryRun): array
    {
        $db = $app->db();
        $now = RoomService::of($app)->nowLocal()->format('Y-m-d H:i:s');
        $future = (int) $db->scalar("SELECT COUNT(*) FROM rm_bookings WHERE booked_by = ? AND status = 'confirmed' AND starts_at > ?", [$userId, $now]);
        $past = (int) $db->scalar("SELECT COUNT(*) FROM rm_bookings WHERE booked_by = ? AND (starts_at <= ? OR status <> 'confirmed') AND title <> '-'", [$userId, $now]);
        if (!$dryRun) {
            $stamp = $app->clock->now()->format('Y-m-d H:i:s.u');
            $db->run("UPDATE rm_bookings SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?, cancel_reason = 'DSR', updated_at = ? WHERE booked_by = ? AND status = 'confirmed' AND starts_at > ?", [$stamp, $actorId, $stamp, $userId, $now]);
            $db->run("UPDATE rm_bookings SET title = '-', attendees_json = NULL, updated_at = ? WHERE booked_by = ?", [$stamp, $userId]);
        }
        return ['done' => array_filter(['rooms.privacy.cancelled' => $future, 'rooms.privacy.blanked' => $past]), 'kept' => $past > 0 ? ['rooms.privacy.bookings' => 'rooms.privacy.kept'] : []];
    }
}
