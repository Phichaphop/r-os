<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Payroll engine (phase 6A, DEC-83): pay components per company, effective-dated pay profiles of employees
 * (maker-checker, bank account encrypted), payroll runs with their sources, per-employee results and lines, and
 * manual entries. Every calculation is also recorded in the Calculation Ledger (6.26); legal rates live in the
 * Config Registry (payroll.sso) and company rules in payroll.policy — nothing is seeded.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Payroll engine: components, pay profiles, runs, results, manual entries';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS pay_components (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(20) NOT NULL,
            name_th VARCHAR(120) NOT NULL,
            name_en VARCHAR(120) NULL,
            kind ENUM('earning','deduction','employer') NOT NULL,
            method ENUM('base','fixed','ot','absence','manual','sso_employee','sso_employer') NOT NULL,
            taxable TINYINT(1) NOT NULL DEFAULT 0,
            sso_base TINYINT(1) NOT NULL DEFAULT 0,
            statutory ENUM('none','wht','sso_employee','sso_employer') NOT NULL DEFAULT 'none',
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
            status ENUM('draft','active','retired') NOT NULL DEFAULT 'draft',
            activated_by BIGINT UNSIGNED NULL,
            activated_at DATETIME(6) NULL,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_pay_component_public (public_id), UNIQUE KEY uq_pay_component_code (legal_entity_id, code), KEY ix_pay_component (legal_entity_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pay_profiles (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            effective_from DATE NOT NULL,
            pay_basis ENUM('monthly','daily') NOT NULL,
            base_amount DECIMAL(19,4) NOT NULL,
            bank_code VARCHAR(20) NULL,
            account_enc VARBINARY(512) NULL,
            account_last4 VARCHAR(4) NULL,
            items_json TEXT NULL,
            status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            reason VARCHAR(500) NOT NULL,
            proposed_by BIGINT UNSIGNED NOT NULL,
            decided_by BIGINT UNSIGNED NULL,
            decided_at DATETIME(6) NULL,
            decision_note VARCHAR(500) NULL,
            workflow_instance_pid CHAR(26) NULL,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_pay_profile_public (public_id), KEY ix_pay_profile (employee_id, legal_entity_id, status, effective_from),
            CONSTRAINT fk_pay_profile_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pay_runs (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            run_no INT UNSIGNED NOT NULL,
            label VARCHAR(120) NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            pay_date DATE NOT NULL,
            status ENUM('draft','calculated','pending','approved','cancelled') NOT NULL DEFAULT 'draft',
            att_period_id BIGINT UNSIGNED NULL,
            att_revision INT UNSIGNED NULL,
            ot_batch_ids VARCHAR(500) NULL,
            calc_run_pid CHAR(26) NULL,
            calc_hash CHAR(64) NULL,
            calculated_at DATETIME(6) NULL,
            calculated_by BIGINT UNSIGNED NULL,
            employee_count INT UNSIGNED NOT NULL DEFAULT 0,
            gross_total DECIMAL(19,4) NOT NULL DEFAULT 0,
            deduction_total DECIMAL(19,4) NOT NULL DEFAULT 0,
            net_total DECIMAL(19,4) NOT NULL DEFAULT 0,
            employer_total DECIMAL(19,4) NOT NULL DEFAULT 0,
            warning_count INT UNSIGNED NOT NULL DEFAULT 0,
            blocking_count INT UNSIGNED NOT NULL DEFAULT 0,
            submitted_by BIGINT UNSIGNED NULL,
            submitted_at DATETIME(6) NULL,
            workflow_instance_pid CHAR(26) NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME(6) NULL,
            lock_pid CHAR(26) NULL,
            payment_batch_pid CHAR(26) NULL,
            posting_request_pid CHAR(26) NULL,
            cancel_reason VARCHAR(500) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_pay_run_public (public_id), UNIQUE KEY uq_pay_run_no (legal_entity_id, run_no), KEY ix_pay_run (legal_entity_id, status, period_start)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pay_run_employees (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            run_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            snapshot_json TEXT NOT NULL,
            gross DECIMAL(19,4) NOT NULL DEFAULT 0,
            deductions DECIMAL(19,4) NOT NULL DEFAULT 0,
            net DECIMAL(19,4) NOT NULL DEFAULT 0,
            employer DECIMAL(19,4) NOT NULL DEFAULT 0,
            warnings_json TEXT NULL,
            blocking TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_pay_re_public (public_id), UNIQUE KEY uq_pay_re (run_id, employee_id),
            CONSTRAINT fk_pay_re_run FOREIGN KEY (run_id) REFERENCES pay_runs (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pay_result_lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_employee_id BIGINT UNSIGNED NOT NULL,
            component_id BIGINT UNSIGNED NOT NULL,
            component_code VARCHAR(20) NOT NULL,
            kind ENUM('earning','deduction','employer') NOT NULL,
            quantity DECIMAL(19,4) NULL,
            rate DECIMAL(19,4) NULL,
            amount DECIMAL(19,4) NOT NULL,
            source_type VARCHAR(20) NOT NULL,
            source_ref VARCHAR(120) NULL,
            note VARCHAR(255) NULL,
            PRIMARY KEY (id), KEY ix_pay_line (run_employee_id),
            CONSTRAINT fk_pay_line_re FOREIGN KEY (run_employee_id) REFERENCES pay_run_employees (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pay_manual_entries (
            {$std},
            run_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            component_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(19,4) NOT NULL,
            note VARCHAR(255) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_pay_manual_public (public_id), KEY ix_pay_manual (run_id, employee_id),
            CONSTRAINT fk_pay_manual_run FOREIGN KEY (run_id) REFERENCES pay_runs (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pay_run_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(40) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            note VARCHAR(500) NULL,
            occurred_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_pay_run_event (run_id),
            CONSTRAINT fk_pay_event_run FOREIGN KEY (run_id) REFERENCES pay_runs (id)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $missing = [];
        foreach (['pay_components', 'pay_profiles', 'pay_runs', 'pay_run_employees', 'pay_result_lines', 'pay_manual_entries', 'pay_run_events'] as $t) {
            if (!$db->tableExists($t)) {
                $missing[] = $t . ' missing';
            }
        }
        return $missing;
    }
};
