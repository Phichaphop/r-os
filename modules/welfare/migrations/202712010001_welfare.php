<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 6D (DEC-86): M37 welfare claims — welfare types with limits and eligibility (versioned, another person
 * activates), claims with receipts (duplicate checks), review → approval through the central workflow, payout to
 * Payroll (a frozen batch a payroll run picks up) or to a payment batch, and the claim history.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Welfare: types, claims, receipts, payout batches';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS wf_types (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(20) NOT NULL,
            version_no INT UNSIGNED NOT NULL DEFAULT 1,
            name_th VARCHAR(120) NOT NULL,
            name_en VARCHAR(120) NULL,
            category ENUM('medical','assistance','other') NOT NULL,
            description VARCHAR(1000) NULL,
            limit_year DECIMAL(19,4) NULL,
            limit_event DECIMAL(19,4) NULL,
            max_claims SMALLINT UNSIGNED NULL,
            window_days SMALLINT UNSIGNED NULL,
            min_service_days SMALLINT UNSIGNED NULL,
            employment_types VARCHAR(120) NULL,
            receipt_required TINYINT(1) NOT NULL DEFAULT 1,
            payout ENUM('payroll','payment') NOT NULL,
            component_code VARCHAR(20) NULL,
            policy_ref VARCHAR(300) NOT NULL,
            effective_from DATE NOT NULL,
            effective_to DATE NULL,
            status ENUM('draft','active','retired','discarded') NOT NULL DEFAULT 'draft',
            supersedes_id BIGINT UNSIGNED NULL,
            change_note VARCHAR(500) NULL,
            activated_by BIGINT UNSIGNED NULL,
            activated_at DATETIME(6) NULL,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_wf_types_public (public_id), UNIQUE KEY uq_wf_types_version (legal_entity_id, code, version_no),
            KEY ix_wf_types_le (legal_entity_id, status, code)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS wf_batches (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            batch_no VARCHAR(30) NOT NULL,
            method ENUM('payroll','payment') NOT NULL,
            status ENUM('ready','sent','paid','closed') NOT NULL DEFAULT 'ready',
            claim_count INT UNSIGNED NOT NULL DEFAULT 0,
            total_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
            lines_json MEDIUMTEXT NOT NULL,
            lines_hash CHAR(64) NOT NULL,
            payment_batch_pid CHAR(26) NULL,
            pay_run_pid CHAR(26) NULL,
            paid_at DATETIME(6) NULL,
            note VARCHAR(300) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_wf_batches_public (public_id), UNIQUE KEY uq_wf_batches_no (legal_entity_id, batch_no), KEY ix_wf_batches (legal_entity_id, method, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS wf_claims (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            type_id BIGINT UNSIGNED NOT NULL,
            claim_no VARCHAR(30) NULL,
            expense_date DATE NOT NULL,
            description VARCHAR(500) NOT NULL,
            amount_claimed DECIMAL(19,4) NOT NULL,
            receipt_no VARCHAR(60) NULL,
            receipt_from VARCHAR(160) NULL,
            receipt_key CHAR(64) NULL,
            payee_name VARCHAR(160) NULL,
            status ENUM('draft','submitted','reviewing','need_info','approved','partially_approved','rejected','cancelled','paid') NOT NULL DEFAULT 'draft',
            check_json TEXT NULL,
            proposed_amount DECIMAL(19,4) NULL,
            approved_amount DECIMAL(19,4) NULL,
            workflow_pid CHAR(26) NULL,
            submitted_at DATETIME(6) NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME(6) NULL,
            decided_by BIGINT UNSIGNED NULL,
            decided_at DATETIME(6) NULL,
            decision_note VARCHAR(500) NULL,
            batch_id BIGINT UNSIGNED NULL,
            paid_at DATETIME(6) NULL,
            paid_ref VARCHAR(80) NULL,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_wf_claims_public (public_id), UNIQUE KEY uq_wf_claims_no (legal_entity_id, claim_no),
            KEY ix_wf_claims_emp (employee_id, status), KEY ix_wf_claims_le (legal_entity_id, status), KEY ix_wf_claims_receipt (legal_entity_id, receipt_key), KEY ix_wf_claims_batch (batch_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS wf_claim_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            claim_id BIGINT UNSIGNED NOT NULL,
            document_pid CHAR(26) NOT NULL,
            sha256 CHAR(64) NOT NULL,
            filename VARCHAR(200) NOT NULL,
            added_by BIGINT UNSIGNED NOT NULL,
            added_at DATETIME(6) NOT NULL,
            removed_at DATETIME(6) NULL,
            PRIMARY KEY (id), KEY ix_wf_files_claim (claim_id), KEY ix_wf_files_hash (sha256)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS wf_claim_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            claim_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(30) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            note VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_wf_events_claim (claim_id)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $missing = [];
        foreach (['wf_types', 'wf_batches', 'wf_claims', 'wf_claim_files', 'wf_claim_events'] as $t) {
            if (!$db->tableExists($t)) {
                $missing[] = $t . ' missing';
            }
        }
        return $missing;
    }
};
