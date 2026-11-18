<?php

declare(strict_types=1);

namespace Blue\Modules\Helpdesk;

use Blue\Core\App;
use Blue\Core\Privacy\PersonalDataProvider;

/**
 * Helpdesk personal data (DEC-91): the tickets I opened and the messages I wrote. Erasing cancels my open tickets and
 * blanks the titles, descriptions, places and my messages; counts, times and ratings stay for the service report.
 * Attached files stay under the document retention rules (they are disposed of by the records schedule).
 */
final class HelpdeskPersonalData implements PersonalDataProvider
{
    public static function export(App $app, int $userId, ?int $employeeId): array
    {
        $db = $app->db();
        return [
            ['key' => 'tickets', 'label' => 'helpdesk.privacy.tickets', 'rows' => $db->all('SELECT ref, title, description, location, asset, priority, status, opened_at, resolved_at, rating, rating_note FROM hd_tickets WHERE requester_id = ? ORDER BY id', [$userId])],
            ['key' => 'messages', 'label' => 'helpdesk.privacy.messages', 'rows' => $db->all("SELECT t.ref, m.body, m.created_at FROM hd_messages m JOIN hd_tickets t ON t.id = m.ticket_id WHERE m.author_id = ? AND m.kind = 'message' ORDER BY m.id", [$userId])],
        ];
    }

    public static function erase(App $app, int $userId, ?int $employeeId, int $actorId, bool $dryRun): array
    {
        $db = $app->db();
        $open = (int) $db->scalar("SELECT COUNT(*) FROM hd_tickets WHERE requester_id = ? AND status IN ('open','assigned','in_progress','waiting_user','waiting_parts','resolved')", [$userId]);
        $tickets = (int) $db->scalar("SELECT COUNT(*) FROM hd_tickets WHERE requester_id = ? AND title <> '-'", [$userId]);
        $messages = (int) $db->scalar("SELECT COUNT(*) FROM hd_messages WHERE author_id = ? AND body <> '-'", [$userId]);
        if (!$dryRun) {
            $stamp = $app->clock->now()->format('Y-m-d H:i:s.u');
            $local = HelpdeskService::of($app)->nowLocal()->format('Y-m-d H:i:s');
            $db->run(
                "UPDATE hd_tickets SET status = 'cancelled', close_kind = 'cancelled', closed_at = ?, updated_at = ?, updated_by = ? WHERE requester_id = ? AND status IN ('open','assigned','in_progress','waiting_user','waiting_parts','resolved')",
                [$local, $stamp, $actorId, $userId]
            );
            $db->run("UPDATE hd_tickets SET title = '-', description = '-', location = NULL, asset = NULL, rating_note = NULL, updated_at = ? WHERE requester_id = ?", [$stamp, $userId]);
            $db->run("UPDATE hd_messages SET body = '-' WHERE author_id = ?", [$userId]);
        }
        return ['done' => array_filter(['helpdesk.privacy.cancelled' => $open, 'helpdesk.privacy.blanked' => $tickets, 'helpdesk.privacy.messages_blanked' => $messages]),
            'kept' => $tickets > 0 ? ['helpdesk.privacy.tickets' => 'helpdesk.privacy.kept'] : []];
    }
}
