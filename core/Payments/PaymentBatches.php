<?php

declare(strict_types=1);

namespace Blue\Core\Payments;

use DateTimeImmutable;
use Blue\Core\Audit\AuditLogger;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Events\Outbox;
use Blue\Core\Finance\PostingService;
use Blue\Core\Numbering\NumberingService;
use Blue\Core\Records\DocumentService;
use Blue\Core\Registry\ConfigRegistry;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Crypto;
use Blue\Core\Support\Money;
use Blue\Core\Support\Ulid;
use Blue\Core\Workflow\WorkflowEngine;
use Blue\Core\Workflow\WorkflowSubject;

/**
 * Bank Payment File Service (DEC-26, DEC-31, GAP-07): payment batches for payroll, claims and payables.
 *
 *   draft (maker adds items) → in_review → approved (a different checker) → file_generated (BLUE_STANDARD_XLSX_v1, hash kept)
 *   → sent (someone uploaded it to the bank themselves — no bank API in rollout 1) → reconciled (bank results imported).
 *
 * Controls: the file total must equal the approved batch total or no file is made; one active file per batch — a new file
 * needs the previous one superseded with a reason, and never after the batch was sent; account numbers are encrypted
 * (only the last 4 digits are shown) and appear only inside the Restricted file; failed items are paid again only through
 * a NEW batch that goes through approval again. Paid totals are posted through the posting interface (DEC-12).
 */
final class PaymentBatches implements WorkflowSubject
{
    public const TYPES = ['payroll', 'claim', 'ap'];
    public const PAYEE_TYPES = ['employee', 'vendor', 'other'];
    public const DEFAULT_PATTERN = 'PAY-{YYYY}{MM}-{SEQ:4}';
    public const SOURCE_MODULE = 'core.payment';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly WorkflowEngine $workflow,
        private readonly Crypto $crypto,
        private readonly NumberingService $numbering,
        private readonly DocumentService $documents,
        private readonly Outbox $outbox,
        private readonly ConfigRegistry $registry,
        private readonly PostingService $posting,
    ) {
    }

    // ---- reading ------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function row(string $pid): array
    {
        return $this->db->one('SELECT * FROM pmt_batches WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /** @return array<string, mixed> batch + names + active file + history files, for someone who may view the company */
    public function get(Authorizer $az, string $pid): array
    {
        $b = $this->row($pid);
        $this->requireLe($az, 'core.payment.view', (int) $b['legal_entity_id']);
        $b['files'] = $this->db->all(
            'SELECT f.*, u.display_name AS generated_name FROM pmt_files f LEFT JOIN users u ON u.id = f.generated_by WHERE f.batch_id = ? ORDER BY f.id DESC',
            [$b['id']]
        );
        $b['names'] = $this->db->one(
            'SELECT (SELECT display_name FROM users WHERE id = ?) AS prepared, (SELECT display_name FROM users WHERE id = ?) AS approved, (SELECT display_name FROM users WHERE id = ?) AS sent',
            [$b['prepared_by'], $b['approved_by'], $b['sent_by']]
        );
        $b['counts'] = $this->db->one(
            "SELECT SUM(status = 'pending') AS pending, SUM(status = 'paid') AS paid, SUM(status IN ('failed','returned')) AS failed,
                    SUM(status IN ('failed','returned') AND reissued_in_batch_id IS NULL) AS failed_open FROM pmt_batch_items WHERE batch_id = ?",
            [$b['id']]
        );
        $b['parent'] = $b['parent_batch_id'] !== null ? $this->db->one('SELECT public_id, batch_no FROM pmt_batches WHERE id = ?', [$b['parent_batch_id']]) : null;
        $b['children'] = $this->db->all('SELECT public_id, batch_no, status FROM pmt_batches WHERE parent_batch_id = ? ORDER BY id', [$b['id']]);
        $b['posting'] = $this->db->one(
            "SELECT r.public_id, r.status, j.public_id AS journal_pid, j.journal_no FROM fin_posting_requests r LEFT JOIN fin_journals j ON j.id = r.journal_id
              WHERE r.source_module = ? AND r.source_document_id = ? ORDER BY r.id DESC LIMIT 1",
            [self::SOURCE_MODULE, $b['batch_no']]
        );
        return $b;
    }

    /**
     * Items for screens: masked account (last 4 digits) only.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function items(Authorizer $az, string $pid, string $status, int $page, int $per): array
    {
        $b = $this->row($pid);
        $this->requireLe($az, 'core.payment.view', (int) $b['legal_entity_id']);
        $where = 'i.batch_id = ?';
        $params = [$b['id']];
        if ($status !== '') {
            $where .= ' AND i.status = ?';
            $params[] = $status;
        }
        return [
            'total' => (int) $this->db->scalar("SELECT COUNT(*) FROM pmt_batch_items i WHERE {$where}", $params),
            'rows' => $this->db->all(
                "SELECT i.id, i.seq, i.payee_type, i.payee_code, i.payee_name, i.bank_code, i.account_last4, i.amount, i.status, i.result_ref, i.result_date, i.failure_reason,
                        s.code AS site_code, nb.public_id AS reissued_pid, nb.batch_no AS reissued_no
                   FROM pmt_batch_items i LEFT JOIN sites s ON s.id = i.site_id LEFT JOIN pmt_batches nb ON nb.id = i.reissued_in_batch_id
                  WHERE {$where} ORDER BY i.seq LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per),
                $params
            ),
        ];
    }

    /**
     * @param array{legal_entity: int, status: string, type: string, q: string} $f
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(Authorizer $az, array $f, int $page, int $per): array
    {
        [$sql, $params] = $az->scope('core.payment.view')->sql('b.legal_entity_id');
        $where = [$sql];
        foreach (['legal_entity' => 'b.legal_entity_id', 'status' => 'b.status', 'type' => 'b.payment_type'] as $k => $col) {
            if ($f[$k] !== '' && $f[$k] !== 0) {
                $where[] = $col . ' = ?';
                $params[] = $f[$k];
            }
        }
        if ($f['q'] !== '') {
            $where[] = '(b.batch_no LIKE ? OR b.period_label LIKE ? OR b.note LIKE ?)';
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        $w = implode(' AND ', $where);
        return [
            'total' => (int) $this->db->scalar("SELECT COUNT(*) FROM pmt_batches b WHERE {$w}", $params),
            'rows' => $this->db->all(
                "SELECT b.*, le.code AS le_code FROM pmt_batches b JOIN legal_entities le ON le.id = b.legal_entity_id WHERE {$w}
                  ORDER BY b.pay_date DESC, b.id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per),
                $params
            ),
        ];
    }

    /** @return list<string> payment types a maker may create by importing a file (company policy, payment.manual_import) */
    public function manualTypes(int $legalEntityId): array
    {
        $policy = (string) ($this->registry->resolveOrNull('payment.manual_import', $legalEntityId)?->value ?? 'ap_claim');
        return match ($policy) {
            'all' => self::TYPES,
            'none' => [],
            default => ['claim', 'ap'],
        };
    }

    // ---- creating -----------------------------------------------------------------------------------

    /**
     * Public service for source modules (payroll run, approved claims, AP): one call creates the batch with its items.
     * The items must add up to the source's approved total; the same idempotency key returns the same batch.
     *
     * @param array{legal_entity_id: int, payment_type: string, source_type: string, source_id: string, idempotency_key: string, pay_date: string,
     *              period_label?: ?string, currency_code: string, expected_total: string, actor_user_id: int,
     *              items: list<array{payee_type: string, payee_code: string, payee_name: string, site_id?: ?int, bank_code: string, account_no: string, amount: string}>} $in
     */
    public function createFromSource(array $in): string
    {
        $existing = $this->db->scalar('SELECT public_id FROM pmt_batches WHERE idempotency_key = ?', [$in['idempotency_key']]);
        if ($existing !== null) {
            return (string) $existing;
        }
        $expected = Money::parse($in['expected_total'], 2);
        $errors = $this->headerErrors($in['payment_type'], $in['pay_date'], $in['currency_code']);
        if ($expected === null || $expected <= 0) {
            $errors['expected_total'] = 'payment.error.amount';
        }
        if (!preg_match('/^[a-z][a-z0-9_.]{1,39}$/', $in['source_type']) || $in['source_id'] === '' || strlen($in['idempotency_key']) < 8) {
            $errors['source'] = 'payment.error.source';
        }
        $items = [];
        $sum = 0;
        $seen = [];
        foreach ($in['items'] as $i => $it) {
            $v = $this->validateItem($in['legal_entity_id'], $it, $seen, false);
            if ($v['errors'] !== []) {
                $errors['items.' . $i] = (string) array_values($v['errors'])[0];
                continue;
            }
            $items[] = $v['data'];
            $sum += (int) $v['data']['amount_units'];
        }
        if ($items === [] && !isset($errors['items'])) {
            $errors['items'] = 'payment.error.no_items';
        }
        if ($errors === [] && $sum !== $expected) {
            $errors['expected_total'] = 'payment.error.total_mismatch';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $this->db->transactional(function () use ($in, $items, $expected): string {
            $pid = $this->insertBatch(
                $in['legal_entity_id'],
                $in['payment_type'],
                $in['source_type'],
                $in['source_id'],
                $in['idempotency_key'],
                $in['pay_date'],
                $in['period_label'] ?? null,
                $in['currency_code'],
                $expected,
                null,
                $in['actor_user_id'],
                null
            );
            $this->addItemsTo((int) $this->db->scalar('SELECT id FROM pmt_batches WHERE public_id = ?', [$pid]), $items);
            return $pid;
        });
    }

    /** A maker starts a batch whose items will come from an imported file (payment types allowed by company policy). */
    public function createManual(Authorizer $az, int $legalEntityId, string $type, string $payDate, string $periodLabel, string $note): string
    {
        $this->requireLe($az, 'core.payment.prepare', $legalEntityId);
        $errors = $this->headerErrors($type, $payDate, 'XXX');
        if (!isset($errors['payment_type']) && !in_array($type, $this->manualTypes($legalEntityId), true)) {
            $errors['payment_type'] = 'payment.error.type_not_manual';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $currency = (string) $this->db->scalar('SELECT base_currency FROM legal_entities WHERE id = ?', [$legalEntityId]);
        return $this->insertBatch(
            $legalEntityId,
            $type,
            'import',
            null,
            null,
            $payDate,
            $periodLabel !== '' ? mb_substr($periodLabel, 0, 40) : null,
            $currency,
            null,
            $note !== '' ? mb_substr($note, 0, 500) : null,
            $az->userId,
            null
        );
    }

    /**
     * Validates one item (shared by the import and the service API). Returns normalized data with the account still in clear
     * — callers keep it only in memory / in the encrypted import row.
     *
     * @param array<string, mixed> $it
     * @param array<string, int> $seen
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    public function validateItem(int $legalEntityId, array $it, array &$seen, bool $siteByCode): array
    {
        $errors = [];
        $type = self::payeeType((string) ($it['payee_type'] ?? ''));
        if ($type === null) {
            $errors['payee_type'] = 'payment.error.payee_type';
        }
        $code = trim((string) ($it['payee_code'] ?? ''));
        if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z\-_.\/]{0,39}$/', $code)) {
            $errors['payee_code'] = 'payment.error.payee_code';
        }
        $name = trim((string) ($it['payee_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 200) {
            $errors['payee_name'] = 'payment.error.payee_name';
        }
        $site = null;
        if ($siteByCode) {
            $sc = trim((string) ($it['site'] ?? ''));
            if ($sc !== '') {
                $site = $this->db->scalar('SELECT id FROM sites WHERE legal_entity_id = ? AND code = ?', [$legalEntityId, $sc]);
                if ($site === null) {
                    $errors['site'] = 'payment.error.site';
                }
            }
        } elseif (isset($it['site_id']) && $it['site_id'] !== null) {
            $site = (int) $it['site_id'];
            if ((int) $this->db->scalar('SELECT legal_entity_id FROM sites WHERE id = ?', [$site]) !== $legalEntityId) {
                $errors['site'] = 'payment.error.site';
            }
        }
        $bank = trim((string) ($it['bank_code'] ?? ''));
        if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z _\-.]{0,19}$/', $bank)) {
            $errors['bank_code'] = 'payment.error.bank';
        }
        $acc = self::normalizeAccount((string) ($it['account_no'] ?? ''));
        if ($acc === null) {
            $errors['account_no'] = 'payment.error.account';
        }
        $amount = Money::parse((string) ($it['amount'] ?? ''), 2);
        if ($amount === null || $amount <= 0) {
            $errors['amount'] = 'payment.error.amount';
        }
        if ($errors === []) {
            $key = $code . '|' . $acc . '|' . $amount;
            if (isset($seen[$key])) {
                $errors['payee_code'] = 'import.error.duplicate_in_file';
            }
            $seen[$key] = 1;
        }
        return ['data' => ['payee_type' => $type, 'payee_code' => $code, 'payee_name' => $name, 'site_id' => $site !== null ? (int) $site : null,
            'bank_code' => $bank, 'account_no' => $acc, 'amount_units' => $amount], 'errors' => $errors];
    }

    /**
     * Adds validated items to a draft / returned batch (called by the import, inside its transaction).
     *
     * @param list<array<string, mixed>> $items
     */
    public function addItems(Authorizer $az, string $pid, array $items): int
    {
        $b = $this->db->one('SELECT * FROM pmt_batches WHERE public_id = ? FOR UPDATE', [$pid]) ?? throw HttpException::notFound();
        $this->requireLe($az, 'core.payment.prepare', (int) $b['legal_entity_id']);
        if (!in_array($b['status'], ['draft', 'returned'], true) || $b['source_type'] !== 'import') {
            throw new BusinessException('payment.error.not_editable', [], 409);
        }
        $n = $this->addItemsTo((int) $b['id'], $items);
        $this->audit->record('payment.items_added', 'success', 'payment_batch', $pid, (int) $b['legal_entity_id'], null, ['items' => $n]);
        return $n;
    }

    /** Removes every item of a draft batch made by import (start the file again). */
    public function clearItems(Authorizer $az, string $pid): void
    {
        $b = $this->row($pid);
        $this->requireLe($az, 'core.payment.prepare', (int) $b['legal_entity_id']);
        if (!in_array($b['status'], ['draft', 'returned'], true) || $b['source_type'] !== 'import') {
            throw new BusinessException('payment.error.not_editable', [], 409);
        }
        $this->db->transactional(function () use ($b, $az): void {
            $this->db->run('DELETE FROM pmt_batch_items WHERE batch_id = ?', [$b['id']]);
            $this->db->run('UPDATE pmt_batches SET item_count = 0, total_amount = 0, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$this->now(), $az->userId, $b['id']]);
            $this->audit->record('payment.items_cleared', 'success', 'payment_batch', (string) $b['public_id'], (int) $b['legal_entity_id']);
        });
    }

    // ---- approval ------------------------------------------------------------------------------------

    public function submit(Authorizer $az, string $pid): string
    {
        $b = $this->row($pid);
        $le = (int) $b['legal_entity_id'];
        $this->requireLe($az, 'core.payment.prepare', $le);
        if ($b['status'] !== 'draft') {
            throw new BusinessException('payment.error.not_draft', [], 409);
        }
        $this->assertTotals($b);
        $now = $this->now();
        $this->db->run(
            "UPDATE pmt_batches SET status = 'in_review', prepared_by = COALESCE(prepared_by, ?), submitted_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'draft'",
            [$az->userId, $now, $now, $az->userId, $b['id']]
        );
        $title = $b['batch_no'] . ' · ' . Money::format(Money::fromDb($b['total_amount'])) . ' ' . $b['currency_code'] . ' · ' . $b['item_count'];
        $inst = $this->workflow->start('core.payment_batch', $pid, $title, $le, $az->userId);
        $this->db->run('UPDATE pmt_batches SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE id = ?', [$inst, $b['id']]);
        $this->audit->record('payment.batch_submitted', 'success', 'payment_batch', $pid, $le, null, ['total' => (string) $b['total_amount'], 'items' => (int) $b['item_count']]);
        return $inst;
    }

    public function resubmit(Authorizer $az, string $pid): void
    {
        $b = $this->row($pid);
        $this->requireLe($az, 'core.payment.prepare', (int) $b['legal_entity_id']);
        if ($b['status'] !== 'returned' || $b['workflow_instance_id'] === null) {
            throw new BusinessException('payment.error.not_returned', [], 409);
        }
        $this->workflow->resubmit((string) $this->db->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$b['workflow_instance_id']]), $az->userId);
    }

    public function type(): string
    {
        return 'payment_batch';
    }

    public function link(string $subjectId): string
    {
        return '/payments/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        $b = $this->row((string) $instance['subject_id']);
        if (!in_array($b['status'], ['in_review'], true)) {
            if (in_array($b['status'], ['approved', 'file_generated', 'sent', 'reconciled'], true)) {
                return;
            }
            throw new BusinessException('payment.error.not_in_review', [], 409);
        }
        $le = (int) $b['legal_entity_id'];
        if ($approverId === (int) $b['prepared_by'] || $approverId === (int) $instance['requester_id']) {
            throw new BusinessException('workflow.error.self_approval', [], 403);
        }
        if (!(new Authorizer($this->db, $this->clock, $approverId))->scope('core.payment.approve')->coversLegalEntity($le)) {
            throw new BusinessException('payment.error.checker_right', [], 403);
        }
        $this->assertTotals($b);
        $now = $this->now();
        $this->db->run("UPDATE pmt_batches SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$approverId, $now, $now, $approverId, $b['id']]);
        $this->audit->record('payment.batch_approved', 'success', 'payment_batch', (string) $b['public_id'], $le, null, ['total' => (string) $b['total_amount'], 'items' => (int) $b['item_count']], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        $status = ['rejected' => 'cancelled', 'returned' => 'returned', 'cancelled' => 'draft'][$outcome] ?? null;
        if ($status !== null) {
            $this->db->run(
                "UPDATE pmt_batches SET status = ?, note = IF(? = 'cancelled', CONCAT_WS(' · ', note, 'rejected by checker'), note), updated_at = ? WHERE public_id = ? AND status IN ('in_review','returned')",
                [$status, $status, $this->now(), $instance['subject_id']]
            );
        }
    }

    public function onResubmitted(array $instance): void
    {
        $b = $this->row((string) $instance['subject_id']);
        $this->assertTotals($b);
        $this->db->run("UPDATE pmt_batches SET status = 'in_review', updated_at = ? WHERE id = ? AND status = 'returned'", [$this->now(), $b['id']]);
    }

    public function cancel(Authorizer $az, string $pid, string $reason): void
    {
        $b = $this->row($pid);
        $this->requireLe($az, 'core.payment.prepare', (int) $b['legal_entity_id']);
        if (!in_array($b['status'], ['draft', 'returned', 'approved'], true)) {
            throw new BusinessException('payment.error.cannot_cancel', [], 409);
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $this->db->run("UPDATE pmt_batches SET status = 'cancelled', note = CONCAT_WS(' · ', note, ?), updated_at = ?, updated_by = ? WHERE id = ?", [mb_substr($reason, 0, 200), $this->now(), $az->userId, $b['id']]);
        $this->audit->record('payment.batch_cancelled', 'success', 'payment_batch', $pid, (int) $b['legal_entity_id'], $reason);
    }

    // ---- bank file ----------------------------------------------------------------------------------

    /** Builds the bank file from the APPROVED batch; refuses when the totals differ or an active file exists. */
    public function generateFile(Authorizer $az, string $pid): string
    {
        $b = $this->row($pid);
        $le = (int) $b['legal_entity_id'];
        $this->requireLe($az, 'core.payment.download', $le);
        return $this->db->transactional(function () use ($az, $pid, $le): string {
            $b = $this->db->one('SELECT * FROM pmt_batches WHERE public_id = ? FOR UPDATE', [$pid]) ?? throw HttpException::notFound();
            if ($b['status'] !== 'approved') {
                throw new BusinessException('payment.error.not_approved', [], 409);
            }
            if ($this->db->scalar("SELECT id FROM pmt_files WHERE batch_id = ? AND status = 'active'", [$b['id']]) !== null) {
                throw new BusinessException('payment.error.file_exists', [], 409);
            }
            $this->assertTotals($b);
            $items = [];
            $sum = 0;
            foreach ($this->db->all('SELECT i.*, s.code AS site_code FROM pmt_batch_items i LEFT JOIN sites s ON s.id = i.site_id WHERE i.batch_id = ? ORDER BY i.seq', [$b['id']]) as $it) {
                $acc = $this->crypto->decrypt((string) $it['account_no_enc']);
                if (!hash_equals((string) $it['account_no_hash'], $this->crypto->keyedHash($acc))) {
                    throw new BusinessException('payment.error.integrity', [], 500);
                }
                $sum += Money::fromDb($it['amount']);
                $items[] = ['seq' => (int) $it['seq'], 'payee_code' => (string) $it['payee_code'], 'payee_name' => (string) $it['payee_name'], 'site' => (string) ($it['site_code'] ?? ''),
                    'bank' => (string) $it['bank_code'], 'account' => $acc, 'amount' => Money::fromDb($it['amount'])];
            }
            if ($sum !== Money::fromDb($b['total_amount']) || count($items) !== (int) $b['item_count']) {
                throw new BusinessException('payment.error.total_mismatch', [], 409);
            }
            $le = (int) $b['legal_entity_id'];
            $company = (string) $this->db->scalar(
                "SELECT CONCAT(l.code, ' · ', COALESCE((SELECT value FROM translations WHERE entity_type = 'legal_entity' AND entity_id = l.id AND field = 'name' AND locale = 'th'), '')) FROM legal_entities l WHERE l.id = ?",
                [$le]
            );
            $names = $this->db->one('SELECT (SELECT display_name FROM users WHERE id = ?) AS prepared, (SELECT display_name FROM users WHERE id = ?) AS approved', [$b['prepared_by'], $b['approved_by']]) ?? [];
            $bytes = BankFile::standard([
                'company' => $company, 'batch_no' => (string) $b['batch_no'], 'payment_type' => (string) $b['payment_type'], 'period' => (string) ($b['period_label'] ?? ''),
                'pay_date' => (string) $b['pay_date'], 'prepared' => (string) ($names['prepared'] ?? ''), 'approved' => (string) ($names['approved'] ?? ''),
                'generated_at' => $this->clock->now()->format('Y-m-d H:i:s') . ' UTC', 'currency' => (string) $b['currency_code'],
            ], $items);
            // read the file back: the totals written must equal the batch (the file is what the bank will see)
            $check = BankFile::readTotals($bytes);
            if ($check['count'] !== count($items) || $check['total'] !== $sum) {
                throw new BusinessException('payment.error.total_mismatch', [], 500);
            }
            $doc = $this->documents->storeGenerated($az->userId, 'core.payment_file', $bytes, $b['batch_no'] . '_' . BankFile::FORMAT . '.xlsx', [
                'title' => 'Bank file ' . $b['batch_no'], 'legal_entity_id' => $le, 'related_type' => 'payment_batch', 'related_id' => (string) $b['public_id'],
                'document_number' => (string) $b['batch_no'], 'is_demo' => (int) $b['is_demo'],
            ], 'system', true);
            $fpid = Ulid::generate($this->clock);
            $sha = hash('sha256', $bytes);
            $now = $this->now();
            $this->db->run(
                "INSERT INTO pmt_files (public_id, batch_id, format_code, format_version, document_id, sha256, item_count, total_amount, status, generated_by, generated_at)
                 VALUES (?, ?, ?, ?, (SELECT id FROM documents WHERE public_id = ?), ?, ?, ?, 'active', ?, ?)",
                [$fpid, $b['id'], BankFile::FORMAT, BankFile::VERSION, $doc['document'], $sha, count($items), Money::toDb($sum), $az->userId, $now]
            );
            $this->db->run("UPDATE pmt_batches SET status = 'file_generated', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$now, $az->userId, $b['id']]);
            $this->outbox->record('payment.file.generated', ['batch_public_id' => $b['public_id'], 'format_code' => BankFile::FORMAT, 'sha256' => $sha,
                'item_count' => count($items), 'total_amount' => Money::toDb($sum)], 'payment.file.generated:' . $fpid, $le);
            $this->audit->record('payment.file_generated', 'success', 'payment_batch', (string) $b['public_id'], $le, null, ['sha256' => $sha, 'items' => count($items), 'total' => Money::toDb($sum)]);
            return $fpid;
        });
    }

    /** Withdraws the active file (wrong file, lost, re-format) so a new one can be made — never after the batch was sent. */
    public function supersedeFile(Authorizer $az, string $pid, string $reason): void
    {
        $b = $this->row($pid);
        $le = (int) $b['legal_entity_id'];
        $this->requireLe($az, 'core.payment.download', $le);
        if ($b['status'] !== 'file_generated') {
            throw new BusinessException('payment.error.cannot_supersede', [], 409);
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $this->db->transactional(function () use ($b, $az, $reason, $le): void {
            $now = $this->now();
            $this->db->run(
                "UPDATE pmt_files SET status = 'superseded', superseded_by = ?, superseded_at = ?, supersede_reason = ? WHERE batch_id = ? AND status = 'active'",
                [$az->userId, $now, mb_substr($reason, 0, 500), $b['id']]
            );
            $this->db->run("UPDATE pmt_batches SET status = 'approved', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'file_generated'", [$now, $az->userId, $b['id']]);
            $this->audit->record('payment.file_superseded', 'success', 'payment_batch', (string) $b['public_id'], $le, $reason);
        });
    }

    /** @return array{bytes: string, filename: string, mime: string} the active file (Restricted — audited, counted) */
    public function downloadFile(Authorizer $az, string $pid): array
    {
        $b = $this->row($pid);
        $le = (int) $b['legal_entity_id'];
        $this->requireLe($az, 'core.payment.download', $le);
        $f = $this->db->one("SELECT f.*, d.public_id AS doc_pid FROM pmt_files f JOIN documents d ON d.id = f.document_id WHERE f.batch_id = ? AND f.status = 'active'", [$b['id']])
            ?? throw new BusinessException('payment.error.no_file', [], 404);
        $file = $this->documents->download($az, (string) $f['doc_pid']);
        if (!hash_equals((string) $f['sha256'], hash('sha256', $file['bytes']))) {
            throw new BusinessException('document.error.integrity', [], 500);
        }
        $this->db->run('UPDATE pmt_files SET download_count = download_count + 1 WHERE id = ?', [$f['id']]);
        $this->audit->record('payment.file_downloaded', 'success', 'payment_batch', (string) $b['public_id'], $le, null, ['sha256' => $f['sha256']]);
        return ['bytes' => $file['bytes'], 'filename' => $file['filename'], 'mime' => $file['mime']];
    }

    /** The person who uploaded the file to the bank confirms it (manual step — DEC-26). */
    public function markSent(Authorizer $az, string $pid): void
    {
        $b = $this->row($pid);
        $le = (int) $b['legal_entity_id'];
        $this->requireLe($az, 'core.payment.download', $le);
        if ($b['status'] !== 'file_generated') {
            throw new BusinessException('payment.error.not_generated', [], 409);
        }
        if ((int) $this->db->scalar("SELECT download_count FROM pmt_files WHERE batch_id = ? AND status = 'active'", [$b['id']]) < 1) {
            throw new BusinessException('payment.error.not_downloaded', [], 409);
        }
        $now = $this->now();
        $this->db->run("UPDATE pmt_batches SET status = 'sent', sent_by = ?, sent_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'file_generated'", [$az->userId, $now, $now, $az->userId, $b['id']]);
        $this->audit->record('payment.batch_sent', 'success', 'payment_batch', $pid, $le);
    }

    // ---- bank results & reconciliation -------------------------------------------------------------------

    /**
     * Matches a bank result row to an item of a SENT batch: same account (keyed hash) and same amount, pending items first,
     * lowest sequence first. Returns the item id or null.
     *
     * @param array<int, int> $taken item ids already matched by earlier rows of the same file
     */
    public function matchResult(int $batchId, string $account, int $amount, array $taken): ?int
    {
        $rows = $this->db->all(
            "SELECT id, amount FROM pmt_batch_items WHERE batch_id = ? AND account_no_hash = ? AND status = 'pending' ORDER BY seq",
            [$batchId, $this->crypto->keyedHash($account)]
        );
        foreach ($rows as $r) {
            if (Money::fromDb($r['amount']) === $amount && !isset($taken[(int) $r['id']])) {
                return (int) $r['id'];
            }
        }
        return null;
    }

    /**
     * Applies imported bank results (inside the import transaction). When no item is pending any more the batch is
     * reconciled: `payment.reconciled` is published and the paid total is sent to the posting interface.
     *
     * @param list<array{item_id: int, status: string, ref: string, date: ?string, reason: string}> $results
     */
    public function applyResults(Authorizer $az, string $pid, array $results): int
    {
        $b = $this->db->one('SELECT * FROM pmt_batches WHERE public_id = ? FOR UPDATE', [$pid]) ?? throw HttpException::notFound();
        $le = (int) $b['legal_entity_id'];
        $this->requireLe($az, 'core.payment.reconcile', $le);
        if ($b['status'] !== 'sent') {
            throw new BusinessException('payment.error.not_sent', [], 409);
        }
        $n = 0;
        foreach ($results as $r) {
            $n += $this->db->run(
                "UPDATE pmt_batch_items SET status = ?, result_ref = ?, result_date = ?, failure_reason = ? WHERE id = ? AND batch_id = ? AND status = 'pending'",
                [$r['status'], mb_substr($r['ref'], 0, 80) ?: null, $r['date'], $r['status'] === 'paid' ? null : (mb_substr($r['reason'], 0, 200) ?: null), $r['item_id'], $b['id']]
            )->rowCount();
        }
        $paid = Money::fromDb($this->db->scalar("SELECT COALESCE(SUM(amount), 0) FROM pmt_batch_items WHERE batch_id = ? AND status = 'paid'", [$b['id']]));
        $now = $this->now();
        $this->db->run('UPDATE pmt_batches SET paid_amount = ?, updated_at = ?, updated_by = ? WHERE id = ?', [Money::toDb($paid), $now, $az->userId, $b['id']]);
        $pending = (int) $this->db->scalar("SELECT COUNT(*) FROM pmt_batch_items WHERE batch_id = ? AND status = 'pending'", [$b['id']]);
        $this->audit->record('payment.results_applied', 'success', 'payment_batch', $pid, $le, null, ['rows' => $n, 'pending' => $pending]);
        if ($pending === 0) {
            $this->reconcile($b, $paid, $az->userId);
        }
        return $n;
    }

    /** @param array<string, mixed> $b */
    private function reconcile(array $b, int $paid, int $actorId): void
    {
        $le = (int) $b['legal_entity_id'];
        $now = $this->now();
        $counts = $this->db->one("SELECT SUM(status = 'paid') AS paid, SUM(status IN ('failed','returned')) AS failed FROM pmt_batch_items WHERE batch_id = ?", [$b['id']]) ?? [];
        $this->db->run("UPDATE pmt_batches SET status = 'reconciled', reconciled_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$now, $now, $b['id']]);
        $this->outbox->record('payment.reconciled', ['batch_public_id' => $b['public_id'], 'paid_count' => (int) ($counts['paid'] ?? 0), 'failed_count' => (int) ($counts['failed'] ?? 0),
            'paid_amount' => Money::toDb($paid)], 'payment.reconciled:' . $b['public_id'], $le);
        if ($paid > 0) {
            $this->posting->request([
                'source_module' => self::SOURCE_MODULE, 'source_document_type' => 'payment_batch', 'source_document_id' => (string) $b['batch_no'],
                'legal_entity_id' => $le, 'period_date' => (string) $b['pay_date'], 'currency_code' => (string) $b['currency_code'],
                'lines' => [['line_type' => 'payment.' . $b['payment_type'] . '.paid', 'amount' => Money::toDb($paid), 'description' => 'Paid ' . $b['batch_no']]],
                'description' => 'Payment ' . $b['batch_no'] . ' (' . $b['payment_type'] . ')', 'idempotency_key' => 'payment-post:' . $b['public_id'], 'actor_user_id' => $actorId,
            ]);
            $this->db->run("UPDATE pmt_batches SET posting_status = 'requested' WHERE id = ?", [$b['id']]);
        }
        $this->audit->record('payment.batch_reconciled', 'success', 'payment_batch', (string) $b['public_id'], $le, null, ['paid' => Money::toDb($paid), 'failed' => (int) ($counts['failed'] ?? 0)], $actorId);
    }

    /** Failed / returned items go into a NEW draft batch (approval again) — never re-sent from the old batch. */
    public function reissueFailed(Authorizer $az, string $pid, string $payDate): string
    {
        $b = $this->row($pid);
        $le = (int) $b['legal_entity_id'];
        $this->requireLe($az, 'core.payment.prepare', $le);
        if ($b['status'] !== 'reconciled') {
            throw new BusinessException('payment.error.not_reconciled', [], 409);
        }
        $errors = $this->headerErrors((string) $b['payment_type'], $payDate, (string) $b['currency_code']);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $this->db->transactional(function () use ($b, $az, $payDate, $le): string {
            $failed = $this->db->all("SELECT * FROM pmt_batch_items WHERE batch_id = ? AND status IN ('failed','returned') AND reissued_in_batch_id IS NULL ORDER BY seq FOR UPDATE", [$b['id']]);
            if ($failed === []) {
                throw new BusinessException('payment.error.nothing_to_reissue', [], 409);
            }
            $sum = 0;
            foreach ($failed as $f) {
                $sum += Money::fromDb($f['amount']);
            }
            $new = $this->insertBatch(
                $le,
                (string) $b['payment_type'],
                'reissue',
                (string) $b['batch_no'],
                null,
                $payDate,
                $b['period_label'],
                (string) $b['currency_code'],
                $sum,
                'Reissue of ' . $b['batch_no'],
                $az->userId,
                (int) $b['id']
            );
            $newId = (int) $this->db->scalar('SELECT id FROM pmt_batches WHERE public_id = ?', [$new]);
            $seq = 0;
            foreach ($failed as $f) {
                $seq++;
                $this->db->run(
                    'INSERT INTO pmt_batch_items (batch_id, seq, payee_type, payee_code, payee_name, site_id, bank_code, account_no_enc, account_no_hash, account_last4, amount)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$newId, $seq, $f['payee_type'], $f['payee_code'], $f['payee_name'], $f['site_id'], $f['bank_code'], $f['account_no_enc'], $f['account_no_hash'], $f['account_last4'], $f['amount']]
                );
                $this->db->run('UPDATE pmt_batch_items SET reissued_in_batch_id = ? WHERE id = ?', [$newId, $f['id']]);
            }
            $this->db->run('UPDATE pmt_batches SET item_count = ?, total_amount = ? WHERE id = ?', [$seq, Money::toDb($sum), $newId]);
            $this->audit->record('payment.reissued', 'success', 'payment_batch', (string) $b['public_id'], $le, null, ['new_batch' => $new, 'items' => $seq, 'total' => Money::toDb($sum)]);
            return $new;
        });
    }

    /**
     * Outbox consumer: posting results for payment batches.
     *
     * @param array<string, mixed> $envelope
     */
    public function onPostingEvent(array $envelope): void
    {
        $p = $envelope['payload'] ?? [];
        if (($p['source_module'] ?? '') !== self::SOURCE_MODULE) {
            return;
        }
        $status = ['posting.posted' => 'posted', 'posting.reversed' => 'reversed', 'posting.rejected' => 'rejected'][(string) $envelope['event']] ?? null;
        if ($status !== null) {
            $this->db->run('UPDATE pmt_batches SET posting_status = ?, updated_at = ? WHERE batch_no = ? AND legal_entity_id = ?', [$status, $this->now(), $p['source_document_id'] ?? '', $envelope['legal_entity_id']]);
        }
    }

    // ---- helpers --------------------------------------------------------------------------------------

    public static function payeeType(string $v): ?string
    {
        $map = ['employee' => 'employee', 'พนักงาน' => 'employee', 'vendor' => 'vendor', 'ผู้ขาย' => 'vendor', 'เจ้าหนี้' => 'vendor', 'other' => 'other', 'อื่น ๆ' => 'other', 'อื่นๆ' => 'other'];
        return $map[mb_strtolower(trim($v))] ?? null;
    }

    /** Digits only (dashes/spaces removed, leading zeros kept); 5–34 digits; refuses Excel scientific notation. */
    public static function normalizeAccount(string $v): ?string
    {
        $d = str_replace(['-', ' ', "\u{00A0}"], '', trim($v));
        return preg_match('/^\d{5,34}$/', $d) ? $d : null;
    }

    /** @return array<string, string> */
    private function headerErrors(string $type, string $payDate, string $currency): array
    {
        $errors = [];
        if (!in_array($type, self::TYPES, true)) {
            $errors['payment_type'] = 'payment.error.type';
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $payDate);
        if ($d === false || $d->format('Y-m-d') !== $payDate) {
            $errors['pay_date'] = 'records.error.date';
        }
        if ($currency !== 'XXX' && !preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors['currency_code'] = 'finance.error.currency';
        }
        return $errors;
    }

    private function insertBatch(
        int $le,
        string $type,
        string $sourceType,
        ?string $sourceId,
        ?string $idem,
        string $payDate,
        ?string $period,
        string $currency,
        ?int $expected,
        ?string $note,
        int $actor,
        ?int $parent
    ): string {
        $d = new DateTimeImmutable($payDate);
        $pattern = (string) ($this->registry->resolveOrNull('payment.batch_number_pattern', $le)?->value ?? self::DEFAULT_PATTERN);
        $pid = Ulid::generate($this->clock);
        $no = $this->numbering->next($le, null, 'payment.batch', $d->format('Ym'), $pattern, 'payment_batch', $pid, $actor);
        $now = $this->now();
        $this->db->run(
            "INSERT INTO pmt_batches (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, batch_no, payment_type, source_type, source_id, idempotency_key,
                period_label, pay_date, currency_code, expected_total, status, note, prepared_by, parent_batch_id, is_demo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, (SELECT is_demo FROM legal_entities WHERE id = ?))",
            [$pid, $now, $actor, $now, $actor, $le, $no, $type, $sourceType, $sourceId, $idem, $period, $payDate, $currency, $expected !== null ? Money::toDb($expected) : null,
                $note, $actor, $parent, $le]
        );
        $this->audit->record('payment.batch_created', 'success', 'payment_batch', $pid, $le, null, ['batch_no' => $no, 'type' => $type, 'source' => $sourceType], $actor);
        return $pid;
    }

    /** @param list<array<string, mixed>> $items validated (account in clear) */
    private function addItemsTo(int $batchId, array $items): int
    {
        $seq = (int) $this->db->scalar('SELECT COALESCE(MAX(seq), 0) FROM pmt_batch_items WHERE batch_id = ?', [$batchId]);
        foreach ($items as $it) {
            $seq++;
            $acc = (string) $it['account_no'];
            $this->db->run(
                'INSERT INTO pmt_batch_items (batch_id, seq, payee_type, payee_code, payee_name, site_id, bank_code, account_no_enc, account_no_hash, account_last4, amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$batchId, $seq, $it['payee_type'], $it['payee_code'], $it['payee_name'], $it['site_id'], $it['bank_code'], $this->crypto->encrypt($acc),
                    $this->crypto->keyedHash($acc), substr($acc, -4), Money::toDb((int) $it['amount_units'])]
            );
        }
        $this->db->run(
            'UPDATE pmt_batches SET item_count = (SELECT COUNT(*) FROM pmt_batch_items WHERE batch_id = ?), total_amount = (SELECT COALESCE(SUM(amount), 0) FROM pmt_batch_items WHERE batch_id = ?),
                updated_at = ?, row_version = row_version + 1 WHERE id = ?',
            [$batchId, $batchId, $this->now(), $batchId]
        );
        return count($items);
    }

    /** @param array<string, mixed> $b */
    private function assertTotals(array $b): void
    {
        $agg = $this->db->one('SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM pmt_batch_items WHERE batch_id = ?', [$b['id']]) ?? ['n' => 0, 'total' => 0];
        if ((int) $agg['n'] === 0) {
            throw new BusinessException('payment.error.no_items', [], 409);
        }
        $sum = Money::fromDb($agg['total']);
        if ($sum !== Money::fromDb($b['total_amount']) || (int) $agg['n'] !== (int) $b['item_count']) {
            throw new BusinessException('payment.error.total_mismatch', [], 409);
        }
        if ($b['expected_total'] !== null && $sum !== Money::fromDb($b['expected_total'])) {
            throw new BusinessException('payment.error.total_mismatch', [], 409);
        }
    }

    /** In scope with the right → ok; can see the batch but not do this → 403; another company's batch → 404 (never confirm it exists). */
    private function requireLe(Authorizer $az, string $permission, int $le): void
    {
        if ($az->scope($permission)->coversLegalEntity($le)) {
            return;
        }
        if ($az->scope('core.payment.view')->coversLegalEntity($le) || (!$az->can($permission) && !$az->can('core.payment.view'))) {
            throw HttpException::forbidden();
        }
        throw HttpException::notFound();
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
