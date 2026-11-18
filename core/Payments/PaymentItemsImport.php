<?php

declare(strict_types=1);

namespace Blue\Core\Payments;

use Blue\Core\Authz\Authorizer;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Import\ImportDefinition;

/**
 * Items of a draft payment batch from an Excel/CSV file (6.10 — template, preview, row errors, confirm).
 * Account numbers stay encrypted in the import rows (ImportService) and in the batch items.
 */
final class PaymentItemsImport implements ImportDefinition
{
    public function __construct(private readonly Connection $db, private readonly PaymentBatches $batches)
    {
    }

    public function code(): string
    {
        return 'payment.items';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function titleKey(): string
    {
        return 'payment.import.items_title';
    }

    public function columns(): array
    {
        return [
            ['key' => 'payee_type', 'label' => 'payment.field.payee_type', 'required' => true, 'example' => 'vendor', 'help' => 'payment.import.help_payee_type'],
            ['key' => 'payee_code', 'label' => 'payment.field.payee_code', 'required' => true, 'example' => 'V-0001'],
            ['key' => 'payee_name', 'label' => 'payment.field.payee_name', 'required' => true, 'example' => 'ร้านตัวอย่าง (DEMO)'],
            ['key' => 'site', 'label' => 'payment.field.site', 'required' => false, 'example' => 'HQ', 'help' => 'payment.import.help_site'],
            ['key' => 'bank_code', 'label' => 'payment.field.bank', 'required' => true, 'example' => 'BANK-A', 'help' => 'payment.import.help_bank'],
            ['key' => 'account_no', 'label' => 'payment.field.account_no', 'required' => true, 'example' => '0012345678', 'help' => 'payment.import.help_account'],
            ['key' => 'amount', 'label' => 'payment.field.amount', 'required' => true, 'example' => '1500.50', 'help' => 'payment.import.help_amount'],
        ];
    }

    public function prepare(Authorizer $az, array $params): array
    {
        $b = $this->db->one('SELECT * FROM pmt_batches WHERE public_id = ?', [(string) ($params['batch'] ?? '')]) ?? throw HttpException::notFound();
        $le = (int) $b['legal_entity_id'];
        if (!$az->scope('core.payment.prepare')->coversLegalEntity($le)) {
            throw $az->scope('core.payment.view')->coversLegalEntity($le) ? HttpException::forbidden() : HttpException::notFound();
        }
        if (!in_array($b['status'], ['draft', 'returned'], true) || $b['source_type'] !== 'import') {
            throw new BusinessException('payment.error.not_editable', [], 409);
        }
        return ['batch' => (string) $b['public_id'], 'batch_id' => (int) $b['id'], 'legal_entity_id' => $le];
    }

    public function validateRow(array $row, array $params, array &$seen): array
    {
        $v = $this->batches->validateItem((int) $params['legal_entity_id'], $row, $seen, true);
        return ['data' => $v['data'], 'errors' => $v['errors'], 'action' => 'create'];
    }

    public function apply(Authorizer $az, array $params, array $rows, int $batchId): int
    {
        return $this->batches->addItems($az, (string) $params['batch'], $rows);
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
