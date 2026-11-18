<?php

declare(strict_types=1);

namespace Blue\Modules\Requisition;

use Blue\Core\App;
use Blue\Core\Privacy\PersonalDataProvider;

/**
 * Office supply personal data (DEC-93): my requests, their items and my favourites. Erasing cancels my cart and my
 * requests that still hold stock (the reservation is released), blanks my notes and forgets my favourites; issued
 * quantities and the stock ledger stay — they are stock records (the request keeps its number, not my notes).
 */
final class RequisitionPersonalData implements PersonalDataProvider
{
    public static function export(App $app, int $userId, ?int $employeeId): array
    {
        $db = $app->db();
        return [
            ['key' => 'requests', 'label' => 'requisition.privacy.requests', 'rows' => $db->all("SELECT ref, status, note, submitted_at, approved_at, issued_at, closed_at, reason FROM rq_requests WHERE requester_id = ? AND status <> 'draft' ORDER BY id", [$userId])],
            ['key' => 'lines', 'label' => 'requisition.privacy.lines', 'rows' => $db->all('SELECT r.ref, i.code, i.name, l.qty, l.qty_issued FROM rq_lines l JOIN rq_requests r ON r.id = l.request_id JOIN rq_items i ON i.id = l.item_id WHERE r.requester_id = ? ORDER BY r.id, i.code', [$userId])],
            ['key' => 'favorites', 'label' => 'requisition.privacy.favorites', 'rows' => $db->all('SELECT i.code, i.name FROM rq_favorites f JOIN rq_items i ON i.id = f.item_id WHERE f.user_id = ? ORDER BY i.code', [$userId])],
        ];
    }

    public static function erase(App $app, int $userId, ?int $employeeId, int $actorId, bool $dryRun): array
    {
        $db = $app->db();
        $open = (int) $db->scalar("SELECT COUNT(*) FROM rq_requests WHERE requester_id = ? AND status IN ('draft','pending','approved')", [$userId]);
        $notes = (int) $db->scalar("SELECT COUNT(*) FROM rq_requests WHERE requester_id = ? AND note IS NOT NULL AND note <> '-'", [$userId]);
        $favs = (int) $db->scalar('SELECT COUNT(*) FROM rq_favorites WHERE user_id = ?', [$userId]);
        $kept = (int) $db->scalar("SELECT COUNT(*) FROM rq_requests WHERE requester_id = ? AND status IN ('partial','fulfilled','closed')", [$userId]);
        if (!$dryRun) {
            $stamp = $app->clock->now()->format('Y-m-d H:i:s.u');
            $local = RequisitionService::of($app)->nowLocal()->format('Y-m-d H:i:s');
            $db->transactional(function () use ($db, $userId, $actorId, $stamp, $local): void {
                foreach ($db->all("SELECT * FROM rq_requests WHERE requester_id = ? AND status IN ('pending','approved') FOR UPDATE", [$userId]) as $r) {
                    foreach ($db->all('SELECT item_id, qty FROM rq_lines WHERE request_id = ?', [$r['id']]) as $l) {
                        $db->run('UPDATE rq_stock SET reserved = GREATEST(reserved - ?, 0), updated_at = ? WHERE item_id = ? AND site_id = ?', [$l['qty'], $stamp, $l['item_id'], $r['site_id']]);
                    }
                    $db->run("UPDATE rq_requests SET status = 'cancelled', pickup_code = NULL, closed_at = ?, updated_at = ?, updated_by = ? WHERE id = ?", [$local, $stamp, $actorId, $r['id']]);
                }
                $db->run("UPDATE rq_requests SET status = 'cancelled', updated_at = ?, updated_by = ? WHERE requester_id = ? AND status = 'draft'", [$stamp, $actorId, $userId]);
                $db->run("UPDATE rq_requests SET note = '-', reason = NULL, updated_at = ? WHERE requester_id = ? AND note IS NOT NULL", [$stamp, $userId]);
                $db->run('DELETE FROM rq_favorites WHERE user_id = ?', [$userId]);
                $db->run(
                    "UPDATE workflow_instances SET status = 'cancelled', finished_at = ?, updated_at = ? WHERE subject_type = 'rq_request' AND requester_id = ? AND status IN ('pending','returned','needs_attention')",
                    [$stamp, $stamp, $userId]
                );
            });
        }
        return ['done' => array_filter(['requisition.privacy.cancelled' => $open, 'requisition.privacy.blanked' => $notes, 'requisition.privacy.favorites_removed' => $favs]),
            'kept' => $kept > 0 ? ['requisition.privacy.requests' => 'requisition.privacy.kept'] : []];
    }
}
