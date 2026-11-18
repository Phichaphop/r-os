<?php

declare(strict_types=1);

namespace Blue\Core\Payments;

use Blue\Core\Authz\Authorizer;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Import\CalendarDaysImport;
use Blue\Core\Import\ImportDefinition;
use Blue\Core\Support\Money;

/**
 * Bank results for a SENT batch (6.18.5 reconciliation): each row says whether a transfer to an account succeeded.
 * Rows are matched by account number + amount (no bank is chosen yet, so no bank reference format is assumed);
 * a row that matches nothing — or matches an item already matched — is an error shown on the preview.
 */
final class PaymentResultsImport implements ImportDefinition
{
    private const RESULTS = ['paid' => 'paid', 'success' => 'paid', 'สำเร็จ' => 'paid', 'โอนสำเร็จ' => 'paid',
        'failed' => 'failed', 'fail' => 'failed', 'ไม่สำเร็จ' => 'failed', 'returned' => 'returned', 'คืนเงิน' => 'returned', 'ตีกลับ' => 'returned'];

    public function __construct(private readonly Connection $db, private readonly PaymentBatches $batches)
    {
    }

    public function code(): string
    {
        return 'payment.results';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function titleKey(): string
    {
        return 'payment.import.results_title';
    }

    public function columns(): array
    {
        return [
            ['key' => 'account_no', 'label' => 'payment.field.account_no', 'required' => true, 'example' => '0012345678'],
            ['key' => 'amount', 'label' => 'payment.field.amount', 'required' => true, 'example' => '1500.50'],
            ['key' => 'result', 'label' => 'payment.field.result', 'required' => true, 'example' => 'สำเร็จ', 'help' => 'payment.import.help_result'],
            ['key' => 'date', 'label' => 'payment.field.result_date', 'required' => false, 'example' => '2026-12-25'],
            ['key' => 'bank_ref', 'label' => 'payment.field.bank_ref', 'required' => false, 'example' => 'REF123'],
            ['key' => 'reason', 'label' => 'payment.field.failure_reason', 'required' => false, 'example' => ''],
        ];
    }

    public function prepare(Authorizer $az, array $params): array
    {
        $b = $this->db->one('SELECT * FROM pmt_batches WHERE public_id = ?', [(string) ($params['batch'] ?? '')]) ?? throw HttpException::notFound();
        $le = (int) $b['legal_entity_id'];
        if (!$az->scope('core.payment.reconcile')->coversLegalEntity($le)) {
            throw $az->scope('core.payment.view')->coversLegalEntity($le) ? HttpException::forbidden() : HttpException::notFound();
        }
        if ($b['status'] !== 'sent') {
            throw new BusinessException('payment.error.not_sent', [], 409);
        }
        return ['batch' => (string) $b['public_id'], 'batch_id' => (int) $b['id'], 'legal_entity_id' => $le];
    }

    public function validateRow(array $row, array $params, array &$seen): array
    {
        $errors = [];
        $acc = PaymentBatches::normalizeAccount((string) ($row['account_no'] ?? ''));
        $amount = Money::parse((string) ($row['amount'] ?? ''), 2);
        $result = self::RESULTS[mb_strtolower(trim((string) ($row['result'] ?? '')))] ?? null;
        if ($acc === null) {
            $errors['account_no'] = 'payment.error.account';
        }
        if ($amount === null || $amount <= 0) {
            $errors['amount'] = 'payment.error.amount';
        }
        if ($result === null) {
            $errors['result'] = 'payment.error.result';
        }
        $date = null;
        if (trim((string) ($row['date'] ?? '')) !== '') {
            $date = CalendarDaysImport::parseDate((string) $row['date']);
            if ($date === null) {
                $errors['date'] = 'records.error.date';
            }
        }
        $item = null;
        if ($errors === []) {
            $taken = [];
            foreach (array_keys($seen) as $k) {
                if (str_starts_with((string) $k, 'item:')) {
                    $taken[(int) substr((string) $k, 5)] = 1;
                }
            }
            /** @var string $acc */
            /** @var int $amount */
            $item = $this->batches->matchResult((int) $params['batch_id'], $acc, $amount, $taken);
            if ($item === null) {
                $errors['account_no'] = 'payment.error.no_match';
            } else {
                $seen['item:' . $item] = 1;
            }
        }
        if ($result !== null && $result !== 'paid' && trim((string) ($row['reason'] ?? '')) === '') {
            $errors['reason'] = 'payment.error.reason_needed';
        }
        return ['data' => ['item_id' => $item, 'status' => $result, 'ref' => mb_substr(trim((string) ($row['bank_ref'] ?? '')), 0, 80), 'date' => $date,
            'reason' => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 200)], 'errors' => $errors, 'action' => 'update'];
    }

    public function apply(Authorizer $az, array $params, array $rows, int $batchId): int
    {
        /** @var list<array{item_id: int, status: string, ref: string, date: ?string, reason: string}> $rows */
        return $this->batches->applyResults($az, (string) $params['batch'], $rows);
    }

    public function sensitiveColumns(): array
    {
        return ['account_no'];
    }

    public function backLink(array $params): string
    {
        return '/payments/' . $params['batch'];
    }
}
