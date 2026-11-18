<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 6C (DEC-85): withholding tax worked out by the engine (component method "wht"), employees' tax allowance
 * declarations (the person declares, Payroll checks) and withholding tax certificates (section 50 bis form as the
 * system lays it out — Tax must check it against the Revenue Department form). Rates/allowances live in the Config
 * Registry (payroll.wht) with no defaults.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Payroll: tax method, allowance declarations, tax certificates';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("ALTER TABLE pay_components MODIFY method ENUM('base','fixed','ot','absence','manual','sso_employee','sso_employer','wht') NOT NULL");
        $db->exec("CREATE TABLE IF NOT EXISTS pay_tax_declarations (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            tax_year SMALLINT UNSIGNED NOT NULL,
            items_json TEXT NOT NULL,
            status ENUM('pending','approved','rejected','superseded','withdrawn') NOT NULL DEFAULT 'pending',
            submitted_by BIGINT UNSIGNED NOT NULL,
            decided_by BIGINT UNSIGNED NULL,
            decided_at DATETIME(6) NULL,
            decision_note VARCHAR(500) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_pay_decl_public (public_id), KEY ix_pay_decl (employee_id, legal_entity_id, tax_year, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pay_tax_certificates (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            tax_year SMALLINT UNSIGNED NOT NULL,
            cert_no VARCHAR(30) NOT NULL,
            income DECIMAL(19,4) NOT NULL,
            wht DECIMAL(19,4) NOT NULL,
            sso_employee DECIMAL(19,4) NOT NULL,
            ytd_hash CHAR(64) NOT NULL,
            payee_last4 VARCHAR(4) NULL,
            document_pid CHAR(26) NOT NULL,
            sha256 CHAR(64) NOT NULL,
            status ENUM('issued','voided') NOT NULL DEFAULT 'issued',
            supersedes_id BIGINT UNSIGNED NULL,
            reason VARCHAR(500) NULL,
            issued_by BIGINT UNSIGNED NOT NULL,
            issued_at DATETIME(6) NOT NULL,
            voided_by BIGINT UNSIGNED NULL,
            voided_at DATETIME(6) NULL,
            first_viewed_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_pay_cert_public (public_id), UNIQUE KEY uq_pay_cert_no (legal_entity_id, cert_no), KEY ix_pay_cert (employee_id, legal_entity_id, tax_year, status)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $missing = [];
        foreach (['pay_tax_declarations', 'pay_tax_certificates'] as $t) {
            if (!$db->tableExists($t)) {
                $missing[] = $t . ' missing';
            }
        }
        return $missing;
    }
};
