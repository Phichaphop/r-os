<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 6B (DEC-84): payslips of approved runs (one frozen, encrypted PDF per person — only the owner and payroll see
 * it) and year-to-date totals per person, company and tax year: one row per approved run plus opening amounts typed by
 * Payroll and approved by another person (for a company that starts mid-year — DEC-27).
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Payroll: payslips, year-to-date entries';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS pay_payslips (
            {$std},
            run_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            document_pid CHAR(26) NOT NULL,
            sha256 CHAR(64) NOT NULL,
            status ENUM('published','withdrawn') NOT NULL DEFAULT 'published',
            published_at DATETIME(6) NOT NULL,
            published_by BIGINT UNSIGNED NOT NULL,
            first_viewed_at DATETIME(6) NULL,
            view_count INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_pay_slip_public (public_id), UNIQUE KEY uq_pay_slip (run_id, employee_id), KEY ix_pay_slip_emp (employee_id, status),
            CONSTRAINT fk_pay_slip_run FOREIGN KEY (run_id) REFERENCES pay_runs (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pay_ytd_entries (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            tax_year SMALLINT UNSIGNED NOT NULL,
            source_type ENUM('run','opening') NOT NULL,
            source_ref CHAR(26) NOT NULL,
            as_of DATE NOT NULL,
            gross DECIMAL(19,4) NOT NULL DEFAULT 0,
            taxable_income DECIMAL(19,4) NOT NULL DEFAULT 0,
            wht DECIMAL(19,4) NOT NULL DEFAULT 0,
            sso_employee DECIMAL(19,4) NOT NULL DEFAULT 0,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
            note VARCHAR(500) NULL,
            decided_by BIGINT UNSIGNED NULL,
            decided_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_pay_ytd_public (public_id), UNIQUE KEY uq_pay_ytd_source (source_type, source_ref, employee_id),
            KEY ix_pay_ytd (employee_id, legal_entity_id, tax_year, status)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $missing = [];
        foreach (['pay_payslips', 'pay_ytd_entries'] as $t) {
            if (!$db->tableExists($t)) {
                $missing[] = $t . ' missing';
            }
        }
        return $missing;
    }
};
