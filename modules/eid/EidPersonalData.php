<?php

declare(strict_types=1);

namespace Blue\Modules\Eid;

use Blue\Core\App;
use Blue\Core\Privacy\PersonalDataProvider;
use Blue\Core\Privacy\PrivacyService;

/**
 * M13 personal data (DEC-82): my cards, checks of my cards (result + time only) and my wallet movements. Erasure
 * removes the photo on my cards (the file is destroyed unless a legal hold covers it); card numbers, check records and
 * wallet entries are kept — they are security and money records.
 */
final class EidPersonalData implements PersonalDataProvider
{
    public static function export(App $app, int $userId, ?int $employeeId): array
    {
        if ($employeeId === null) {
            return [];
        }
        $db = $app->db();
        $cards = $db->all('SELECT id, card_no, status, issued_at, expires_on, status_reason, photo_document_pid FROM eid_cards WHERE employee_id = ? ORDER BY seq', [$employeeId]);
        $checks = [];
        foreach ($cards as $c) {
            foreach ($db->all('SELECT result, created_at FROM eid_verifications WHERE card_id = ? ORDER BY id', [$c['id']]) as $v) {
                $checks[] = ['card_no' => $c['card_no'], 'result' => $v['result'], 'checked_at' => $v['created_at']];
            }
        }
        $entries = $db->all('SELECT w.currency_code, e.entry_type, e.amount, e.balance_after, e.note, e.created_at FROM eid_wallet_entries e JOIN eid_wallets w ON w.id = e.wallet_id WHERE w.employee_id = ? ORDER BY e.id', [$employeeId]);
        return [
            ['key' => 'cards', 'label' => 'eid.privacy.cards', 'rows' => array_map(static fn (array $c): array => ['card_no' => $c['card_no'], 'status' => $c['status'], 'issued_at' => $c['issued_at'],
                'expires_on' => $c['expires_on'], 'reason' => $c['status_reason'], 'has_photo' => $c['photo_document_pid'] !== null ? 1 : 0], $cards)],
            ['key' => 'checks', 'label' => 'eid.privacy.checks', 'rows' => $checks],
            ['key' => 'wallet', 'label' => 'eid.privacy.wallet', 'rows' => $entries],
        ];
    }

    public static function erase(App $app, int $userId, ?int $employeeId, int $actorId, bool $dryRun): array
    {
        if ($employeeId === null) {
            return ['done' => [], 'kept' => []];
        }
        $db = $app->db();
        $empPid = (string) $db->scalar('SELECT public_id FROM employees WHERE id = ?', [$employeeId]);
        $kept = ['eid.privacy.cards' => 'eid.privacy.kept_cards', 'eid.privacy.checks' => 'eid.privacy.kept_checks', 'eid.privacy.wallet' => 'eid.privacy.kept_wallet'];
        $photos = 0;
        foreach ($db->all('SELECT id, legal_entity_id, photo_document_pid FROM eid_cards WHERE employee_id = ? AND photo_document_pid IS NOT NULL', [$employeeId]) as $c) {
            $doc = $db->one('SELECT * FROM documents WHERE public_id = ?', [$c['photo_document_pid']]);
            if (PrivacyService::held($app, 'employee_file', (int) $c['legal_entity_id'], $empPid) || ($doc !== null && $app->documents()->activeHolds($doc) !== [])) {
                $kept['eid.privacy.photos'] = 'privacy.kept.legal_hold';
                continue;
            }
            $photos++;
            if (!$dryRun) {
                $db->run('UPDATE eid_cards SET photo_document_pid = NULL, updated_at = ?, updated_by = ? WHERE id = ?', [$app->clock->now()->format('Y-m-d H:i:s.u'), $actorId, $c['id']]);
                if ($doc !== null && (int) $db->scalar('SELECT COUNT(*) FROM eid_cards WHERE photo_document_pid = ?', [$c['photo_document_pid']]) === 0) {
                    $app->documents()->purge((int) $doc['id'], $actorId);
                }
            }
        }
        return ['done' => ['eid.privacy.photos' => $photos], 'kept' => $kept];
    }
}
