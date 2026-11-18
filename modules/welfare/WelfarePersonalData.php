<?php

declare(strict_types=1);

namespace Blue\Modules\Welfare;

use Blue\Core\App;
use Blue\Core\Privacy\PersonalDataProvider;
use Blue\Core\Support\Money;

/**
 * Welfare personal data (DEC-86): my claims (type, dates, what I wrote, amounts, status, decision note) and the names
 * of the receipts I attached (the files themselves stay in the document register). Drafts that were never sent may be
 * erased; claims that were sent are kept — they are accounting and tax evidence of a payment (retention classes
 * accounting_tax / medical_claims), and the retention rule decides when they go.
 */
final class WelfarePersonalData implements PersonalDataProvider
{
    public static function export(App $app, int $userId, ?int $employeeId): array
    {
        if ($employeeId === null) {
            return [];
        }
        $db = $app->db();
        $m = static fn (mixed $v): ?string => $v === null ? null : Money::format(Money::fromDb($v), 2, false);
        $claims = $db->all(
            'SELECT c.*, t.code AS type_code, t.name_th AS type_name, l.code AS company FROM wf_claims c JOIN wf_types t ON t.id = c.type_id JOIN legal_entities l ON l.id = c.legal_entity_id WHERE c.employee_id = ? ORDER BY c.id',
            [$employeeId]
        );
        $rows = array_map(static fn (array $c): array => ['company' => $c['company'], 'claim_no' => $c['claim_no'], 'type' => $c['type_code'] . ' · ' . $c['type_name'], 'expense_date' => $c['expense_date'],
            'description' => $c['description'], 'amount_claimed' => $m($c['amount_claimed']), 'receipt_no' => $c['receipt_no'], 'receipt_from' => $c['receipt_from'], 'payee' => $c['payee_name'],
            'status' => $c['status'], 'approved_amount' => $m($c['approved_amount']), 'decision_note' => $c['decision_note'], 'paid_at' => $c['paid_at'], 'paid_ref' => $c['paid_ref']], $claims);
        $files = $db->all(
            'SELECT c.claim_no, f.filename, f.added_at FROM wf_claim_files f JOIN wf_claims c ON c.id = f.claim_id WHERE c.employee_id = ? AND f.removed_at IS NULL ORDER BY f.id',
            [$employeeId]
        );
        return [
            ['key' => 'claims', 'label' => 'welfare.privacy.claims', 'rows' => $rows],
            ['key' => 'receipts', 'label' => 'welfare.privacy.receipts', 'rows' => array_map(static fn (array $f): array => ['claim_no' => $f['claim_no'], 'file' => $f['filename'], 'added_at' => $f['added_at']], $files)],
        ];
    }

    public static function erase(App $app, int $userId, ?int $employeeId, int $actorId, bool $dryRun): array
    {
        if ($employeeId === null) {
            return ['done' => [], 'kept' => []];
        }
        $db = $app->db();
        $drafts = array_map('intval', array_column($db->all("SELECT id FROM wf_claims WHERE employee_id = ? AND status = 'draft'", [$employeeId]), 'id'));
        if (!$dryRun && $drafts !== []) {
            $in = implode(',', $drafts);
            $db->run("DELETE FROM wf_claim_events WHERE claim_id IN ({$in})");
            $db->run("UPDATE wf_claim_files SET removed_at = COALESCE(removed_at, ?) WHERE claim_id IN ({$in})", [$app->clock->now()->format('Y-m-d H:i:s.u')]);
            $db->run("UPDATE wf_claims SET description = '-', receipt_no = NULL, receipt_from = NULL, receipt_key = NULL, payee_name = NULL, status = 'cancelled' WHERE id IN ({$in})");
        }
        $kept = (int) $db->scalar("SELECT COUNT(*) FROM wf_claims WHERE employee_id = ? AND status NOT IN ('draft','cancelled')", [$employeeId]);
        return ['done' => $drafts !== [] ? ['welfare.privacy.drafts' => count($drafts)] : [], 'kept' => $kept > 0 ? ['welfare.privacy.claims' => 'welfare.privacy.kept'] : []];
    }
}
