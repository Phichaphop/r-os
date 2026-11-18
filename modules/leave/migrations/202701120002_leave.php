<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M32 Leave (P0-03 §5, DEC-63): leave types per company (entitlements/quotas live in the Config Registry key
 * leave.policy — nothing is shipped), requests (working days counted from the work calendar, half days), and an
 * append-only ledger — balance = sum of entries (grant, opening balance, taken, cancellation reversal, adjustment,
 * carry-forward). One idempotency key per entry stops double posting.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M32 Leave: types, requests, ledger';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS lv_types (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(20) NOT NULL,
            name_th VARCHAR(80) NOT NULL,
            name_en VARCHAR(80) NULL,
            is_statutory TINYINT(1) NOT NULL DEFAULT 0,
            allow_document TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 100,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_lv_types_public (public_id),
            UNIQUE KEY uq_lv_types_code (legal_entity_id, code)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS lv_requests (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            department_id BIGINT UNSIGNED NULL,
            lv_type_id BIGINT UNSIGNED NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            part ENUM('full','am','pm') NOT NULL DEFAULT 'full',
            units DECIMAL(6,2) NOT NULL,
            leave_year SMALLINT UNSIGNED NOT NULL,
            days_json JSON NOT NULL,
            reason VARCHAR(500) NULL,
            document_pid CHAR(26) NULL,
            status ENUM('pending','approved','taken','returned','rejected','cancelled','cancel_pending') NOT NULL DEFAULT 'pending',
            policy_version_id BIGINT UNSIGNED NULL,
            requested_by BIGINT UNSIGNED NOT NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            cancel_instance_id BIGINT UNSIGNED NULL,
            cancel_reason VARCHAR(300) NULL,
            decided_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_lv_requests_public (public_id),
            KEY ix_lv_requests_employee (employee_id, start_date),
            KEY ix_lv_requests_scope (legal_entity_id, site_id, start_date),
            KEY ix_lv_requests_status (status, end_date),
            CONSTRAINT fk_lv_requests_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
            CONSTRAINT fk_lv_requests_type FOREIGN KEY (lv_type_id) REFERENCES lv_types (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS lv_ledger (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            lv_type_id BIGINT UNSIGNED NOT NULL,
            leave_year SMALLINT UNSIGNED NOT NULL,
            entry_date DATE NOT NULL,
            entry_type ENUM('grant','opening_balance','taken','cancel_reversal','adjustment','carry_forward') NOT NULL,
            units DECIMAL(7,2) NOT NULL,
            statutory_units DECIMAL(7,2) NULL,
            source_type VARCHAR(30) NOT NULL,
            source_id VARCHAR(40) NULL,
            policy_version_id BIGINT UNSIGNED NULL,
            note VARCHAR(255) NULL,
            idempotency_key VARCHAR(120) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_lv_ledger_public (public_id),
            UNIQUE KEY uq_lv_ledger_idem (idempotency_key),
            KEY ix_lv_ledger_balance (employee_id, lv_type_id, leave_year),
            CONSTRAINT fk_lv_ledger_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
            CONSTRAINT fk_lv_ledger_type FOREIGN KEY (lv_type_id) REFERENCES lv_types (id)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $missing = [];
        foreach (['lv_types', 'lv_requests', 'lv_ledger'] as $t) {
            if (!$db->tableExists($t)) {
                $missing[] = $t . ' missing';
            }
        }
        return $missing;
    }
};
