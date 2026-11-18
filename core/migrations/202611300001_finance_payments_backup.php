<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 3C — posting interface + minimal Finance Core, payment batches / bank files, backup & restore, integration registry
 * (DEC-12, DEC-26, DEC-31, DEC-39; P0-03 §7–8; 06 §7A; 03 §6.30.15; M48).
 *
 * Finance:      fin_accounts, fin_fiscal_years, fin_fiscal_periods, fin_posting_rules, fin_posting_requests, fin_journals, fin_journal_lines
 * Payments:     pmt_file_formats, pmt_batches, pmt_batch_items, pmt_files
 * Backup:       backup_runs, restore_drills, restore_requests
 * Integrations: integrations, integration_credentials, integration_logs, integration_nonces
 * Posted journals and their lines cannot be changed (triggers, best effort — like audit_events); integration_logs is append-only.
 * Every index stays under 767 bytes.
 */

return new class extends Migration {
    private const TABLES = [
        'fin_accounts', 'fin_fiscal_years', 'fin_fiscal_periods', 'fin_posting_rules', 'fin_posting_requests', 'fin_journals', 'fin_journal_lines',
        'pmt_file_formats', 'pmt_batches', 'pmt_batch_items', 'pmt_files',
        'backup_runs', 'restore_drills', 'restore_requests',
        'integrations', 'integration_credentials', 'integration_logs', 'integration_nonces',
    ];

    public function description(): string
    {
        return 'Posting interface + minimal Finance Core, payment batches and bank files, backup & restore, integration registry';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();

        // ---- Finance Core (minimal — 7A, DEC-12) ---------------------------------------------------
        $db->exec("CREATE TABLE IF NOT EXISTS fin_accounts (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(20) NOT NULL,
            name VARCHAR(200) NOT NULL,
            account_type ENUM('asset','liability','equity','revenue','expense') NOT NULL,
            parent_id BIGINT UNSIGNED NULL,
            is_postable TINYINT(1) NOT NULL DEFAULT 1,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_fin_account_public (public_id),
            UNIQUE KEY uq_fin_account_code (legal_entity_id, code)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS fin_fiscal_years (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(20) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_fin_fy_public (public_id),
            UNIQUE KEY uq_fin_fy_code (legal_entity_id, code)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS fin_fiscal_periods (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            fiscal_year_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            period_no TINYINT UNSIGNED NOT NULL,
            period_code VARCHAR(30) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_fin_period_code (legal_entity_id, period_code),
            KEY ix_fin_period_dates (legal_entity_id, start_date, end_date),
            CONSTRAINT fk_fin_period_year FOREIGN KEY (fiscal_year_id) REFERENCES fin_fiscal_years (id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS fin_posting_rules (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            source_module VARCHAR(40) NOT NULL,
            line_type VARCHAR(60) NOT NULL,
            version_no INT UNSIGNED NOT NULL,
            debit_account_id BIGINT UNSIGNED NOT NULL,
            credit_account_id BIGINT UNSIGNED NOT NULL,
            effective_from DATE NOT NULL,
            status ENUM('draft','in_review','returned','approved','superseded','rejected','cancelled') NOT NULL DEFAULT 'draft',
            reason VARCHAR(500) NOT NULL,
            source_reference VARCHAR(255) NULL,
            proposed_by BIGINT UNSIGNED NULL,
            decided_by BIGINT UNSIGNED NULL,
            decided_at DATETIME(6) NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_fin_rule_public (public_id),
            UNIQUE KEY uq_fin_rule_version (legal_entity_id, source_module, line_type, version_no),
            KEY ix_fin_rule_lookup (legal_entity_id, source_module, line_type, status)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS fin_posting_requests (
            {$std},
            event_id CHAR(26) NULL,
            idempotency_key VARCHAR(120) NOT NULL,
            source_module VARCHAR(40) NOT NULL,
            source_document_type VARCHAR(40) NOT NULL,
            source_document_id VARCHAR(60) NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            period_date DATE NOT NULL,
            currency_code CHAR(3) NOT NULL,
            lines_json MEDIUMTEXT NOT NULL,
            total_amount DECIMAL(19,4) NOT NULL,
            calculation_id VARCHAR(26) NULL,
            description VARCHAR(300) NULL,
            status ENUM('received','needs_attention','draft_journal','posted','reversed','rejected') NOT NULL DEFAULT 'received',
            journal_id BIGINT UNSIGNED NULL,
            error_key VARCHAR(80) NULL,
            error_detail VARCHAR(500) NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            requested_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_fin_req_public (public_id),
            UNIQUE KEY uq_fin_req_idem (idempotency_key),
            KEY ix_fin_req_source (source_module, source_document_id),
            KEY ix_fin_req_status (legal_entity_id, status)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS fin_journals (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            journal_no VARCHAR(40) NULL,
            journal_date DATE NOT NULL,
            period_id BIGINT UNSIGNED NULL,
            source_module VARCHAR(40) NOT NULL,
            source_ref VARCHAR(80) NULL,
            posting_request_id BIGINT UNSIGNED NULL,
            description VARCHAR(300) NOT NULL,
            currency_code CHAR(3) NOT NULL,
            total_debit DECIMAL(19,4) NOT NULL DEFAULT 0,
            total_credit DECIMAL(19,4) NOT NULL DEFAULT 0,
            status ENUM('draft','in_review','returned','posted','reversed','cancelled') NOT NULL DEFAULT 'draft',
            prepared_by BIGINT UNSIGNED NULL,
            submitted_by BIGINT UNSIGNED NULL,
            submitted_at DATETIME(6) NULL,
            approved_by BIGINT UNSIGNED NULL,
            posted_at DATETIME(6) NULL,
            reversal_of_id BIGINT UNSIGNED NULL,
            reversed_by_id BIGINT UNSIGNED NULL,
            reason VARCHAR(500) NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_fin_journal_public (public_id),
            UNIQUE KEY uq_fin_journal_no (legal_entity_id, journal_no),
            KEY ix_fin_journal_list (legal_entity_id, status, journal_date),
            KEY ix_fin_journal_period (period_id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS fin_journal_lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            journal_id BIGINT UNSIGNED NOT NULL,
            line_no SMALLINT UNSIGNED NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            debit DECIMAL(19,4) NOT NULL DEFAULT 0,
            credit DECIMAL(19,4) NOT NULL DEFAULT 0,
            currency_code CHAR(3) NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            cost_center_code VARCHAR(30) NULL,
            description VARCHAR(300) NULL,
            source_line_type VARCHAR(60) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_fin_line (journal_id, line_no),
            KEY ix_fin_line_account (account_id),
            CONSTRAINT fk_fin_line_journal FOREIGN KEY (journal_id) REFERENCES fin_journals (id)
        ) {$o}");

        // ---- Payments (DEC-26 / DEC-31) ---------------------------------------------------------------
        $db->exec("CREATE TABLE IF NOT EXISTS pmt_file_formats (
            code VARCHAR(40) NOT NULL,
            bank_code VARCHAR(20) NULL,
            version VARCHAR(10) NOT NULL,
            name VARCHAR(120) NOT NULL,
            column_spec_json TEXT NOT NULL,
            status ENUM('active','retired') NOT NULL DEFAULT 'active',
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (code)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS pmt_batches (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            batch_no VARCHAR(40) NOT NULL,
            payment_type ENUM('payroll','claim','ap') NOT NULL,
            source_type VARCHAR(40) NOT NULL,
            source_id VARCHAR(60) NULL,
            idempotency_key VARCHAR(120) NULL,
            period_label VARCHAR(40) NULL,
            pay_date DATE NOT NULL,
            currency_code CHAR(3) NOT NULL,
            item_count INT UNSIGNED NOT NULL DEFAULT 0,
            total_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
            expected_total DECIMAL(19,4) NULL,
            paid_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
            status ENUM('draft','in_review','returned','approved','file_generated','sent','reconciled','cancelled') NOT NULL DEFAULT 'draft',
            note VARCHAR(500) NULL,
            prepared_by BIGINT UNSIGNED NULL,
            submitted_at DATETIME(6) NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME(6) NULL,
            sent_by BIGINT UNSIGNED NULL,
            sent_at DATETIME(6) NULL,
            reconciled_at DATETIME(6) NULL,
            posting_status ENUM('none','requested','posted','rejected','reversed') NOT NULL DEFAULT 'none',
            parent_batch_id BIGINT UNSIGNED NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pmt_batch_public (public_id),
            UNIQUE KEY uq_pmt_batch_no (legal_entity_id, batch_no),
            UNIQUE KEY uq_pmt_batch_idem (idempotency_key),
            KEY ix_pmt_batch_list (legal_entity_id, status, pay_date)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS pmt_batch_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id BIGINT UNSIGNED NOT NULL,
            seq INT UNSIGNED NOT NULL,
            payee_type ENUM('employee','vendor','other') NOT NULL,
            payee_code VARCHAR(40) NOT NULL,
            payee_name VARCHAR(200) NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            bank_code VARCHAR(20) NOT NULL,
            account_no_enc VARBINARY(255) NOT NULL,
            account_no_hash BINARY(32) NOT NULL,
            account_last4 CHAR(4) NOT NULL,
            amount DECIMAL(19,4) NOT NULL,
            status ENUM('pending','paid','failed','returned') NOT NULL DEFAULT 'pending',
            result_ref VARCHAR(80) NULL,
            result_date DATE NULL,
            failure_reason VARCHAR(200) NULL,
            reissued_in_batch_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pmt_item_seq (batch_id, seq),
            KEY ix_pmt_item_account (batch_id, account_no_hash),
            CONSTRAINT fk_pmt_item_batch FOREIGN KEY (batch_id) REFERENCES pmt_batches (id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS pmt_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            batch_id BIGINT UNSIGNED NOT NULL,
            format_code VARCHAR(40) NOT NULL,
            format_version VARCHAR(10) NOT NULL,
            document_id BIGINT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL,
            item_count INT UNSIGNED NOT NULL,
            total_amount DECIMAL(19,4) NOT NULL,
            status ENUM('active','superseded') NOT NULL DEFAULT 'active',
            generated_by BIGINT UNSIGNED NOT NULL,
            generated_at DATETIME(6) NOT NULL,
            superseded_by BIGINT UNSIGNED NULL,
            superseded_at DATETIME(6) NULL,
            supersede_reason VARCHAR(500) NULL,
            download_count INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pmt_file_public (public_id),
            KEY ix_pmt_file_batch (batch_id, status),
            CONSTRAINT fk_pmt_file_batch FOREIGN KEY (batch_id) REFERENCES pmt_batches (id)
        ) {$o}");

        // ---- Backup & restore (M48, DEC-39) -------------------------------------------------------------
        $db->exec("CREATE TABLE IF NOT EXISTS backup_runs (
            {$std},
            kind ENUM('manual','scheduled','pre_restore','pre_update','uploaded') NOT NULL,
            include_files TINYINT(1) NOT NULL DEFAULT 1,
            status ENUM('running','completed','verified','failed','deleted') NOT NULL DEFAULT 'running',
            phase VARCHAR(20) NOT NULL DEFAULT 'db',
            cursor_json TEXT NULL,
            steps INT UNSIGNED NOT NULL DEFAULT 0,
            file_key CHAR(40) NULL,
            format_version VARCHAR(10) NOT NULL DEFAULT 'ROSBAK1',
            size_bytes BIGINT UNSIGNED NULL,
            sha256 CHAR(64) NULL,
            key_fingerprint CHAR(16) NULL,
            consistency ENUM('snapshot','stepwise') NULL,
            db_tables INT UNSIGNED NULL,
            db_rows BIGINT UNSIGNED NULL,
            file_count INT UNSIGNED NULL,
            file_bytes BIGINT UNSIGNED NULL,
            app_version VARCHAR(40) NULL,
            schema_version VARCHAR(40) NULL,
            manifest_json MEDIUMTEXT NULL,
            started_at DATETIME(6) NOT NULL,
            finished_at DATETIME(6) NULL,
            verified_at DATETIME(6) NULL,
            requested_by BIGINT UNSIGNED NULL,
            error VARCHAR(500) NULL,
            offsite_status ENUM('not_configured','copied','failed') NOT NULL DEFAULT 'not_configured',
            offsite_detail VARCHAR(255) NULL,
            keep_until DATE NULL,
            download_count INT UNSIGNED NOT NULL DEFAULT 0,
            deleted_at DATETIME(6) NULL,
            deleted_by BIGINT UNSIGNED NULL,
            delete_reason VARCHAR(255) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_backup_public (public_id),
            KEY ix_backup_status (status, started_at)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS restore_drills (
            {$std},
            backup_run_id BIGINT UNSIGNED NOT NULL,
            target_database VARCHAR(64) NOT NULL,
            status ENUM('running','passed','failed','blocked') NOT NULL DEFAULT 'running',
            started_at DATETIME(6) NOT NULL,
            finished_at DATETIME(6) NULL,
            duration_ms INT UNSIGNED NULL,
            backup_age_minutes INT UNSIGNED NULL,
            tables_checked INT UNSIGNED NOT NULL DEFAULT 0,
            rows_checked BIGINT UNSIGNED NOT NULL DEFAULT 0,
            files_checked INT UNSIGNED NOT NULL DEFAULT 0,
            key_check ENUM('ok','failed','skipped') NOT NULL DEFAULT 'skipped',
            report_json MEDIUMTEXT NULL,
            performed_by BIGINT UNSIGNED NULL,
            error VARCHAR(500) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_drill_public (public_id),
            KEY ix_drill_backup (backup_run_id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS restore_requests (
            {$std},
            backup_run_id BIGINT UNSIGNED NOT NULL,
            drill_id BIGINT UNSIGNED NOT NULL,
            incident_ref VARCHAR(80) NOT NULL,
            reason VARCHAR(500) NOT NULL,
            status ENUM('in_review','returned','approved','executing','done','failed','rejected','cancelled') NOT NULL DEFAULT 'in_review',
            requested_by BIGINT UNSIGNED NOT NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME(6) NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            pre_restore_backup_id BIGINT UNSIGNED NULL,
            executed_by BIGINT UNSIGNED NULL,
            executed_at DATETIME(6) NULL,
            finished_at DATETIME(6) NULL,
            duration_ms INT UNSIGNED NULL,
            result_json TEXT NULL,
            error VARCHAR(500) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_restore_public (public_id),
            KEY ix_restore_status (status)
        ) {$o}");

        // ---- Integration registry (6.30.15) ------------------------------------------------------------
        $db->exec("CREATE TABLE IF NOT EXISTS integrations (
            {$std},
            code VARCHAR(60) NOT NULL,
            name VARCHAR(200) NOT NULL,
            direction ENUM('inbound','outbound','file_exchange') NOT NULL,
            system_type VARCHAR(40) NOT NULL,
            owner_user_id BIGINT UNSIGNED NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            auth_method ENUM('none','api_key','hmac','basic','oauth','manual') NOT NULL,
            scopes VARCHAR(300) NULL,
            data_classification ENUM('P','I','C','R') NOT NULL,
            schema_version VARCHAR(20) NULL,
            endpoint VARCHAR(255) NULL,
            rate_limit_per_min INT UNSIGNED NULL,
            timeout_seconds INT UNSIGNED NULL,
            retry_policy VARCHAR(200) NULL,
            idempotency VARCHAR(200) NULL,
            monitoring VARCHAR(200) NULL,
            incident_contact VARCHAR(200) NULL,
            cost_note VARCHAR(200) NULL,
            privacy_review ENUM('not_required','pending','approved','rejected') NOT NULL DEFAULT 'pending',
            privacy_ref VARCHAR(200) NULL,
            status ENUM('planned','active','suspended','retired') NOT NULL DEFAULT 'planned',
            notes VARCHAR(1000) NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_integration_public (public_id),
            UNIQUE KEY uq_integration_code (code)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS integration_credentials (
            {$std},
            integration_id BIGINT UNSIGNED NOT NULL,
            key_id VARCHAR(40) NOT NULL,
            secret_enc VARBINARY(512) NOT NULL,
            secret_hash BINARY(32) NOT NULL,
            last4 CHAR(4) NOT NULL,
            status ENUM('active','revoked') NOT NULL DEFAULT 'active',
            valid_from DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NULL,
            last_used_at DATETIME(6) NULL,
            revoked_at DATETIME(6) NULL,
            revoked_by BIGINT UNSIGNED NULL,
            revoke_reason VARCHAR(255) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_cred_public (public_id),
            UNIQUE KEY uq_cred_key (key_id),
            KEY ix_cred_integration (integration_id, status),
            CONSTRAINT fk_cred_integration FOREIGN KEY (integration_id) REFERENCES integrations (id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS integration_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            integration_id BIGINT UNSIGNED NOT NULL,
            occurred_at DATETIME(6) NOT NULL,
            direction ENUM('inbound','outbound','file_exchange') NOT NULL,
            operation VARCHAR(80) NOT NULL,
            status ENUM('success','failed','rejected','retrying','dead') NOT NULL,
            correlation_id VARCHAR(64) NULL,
            idempotency_key VARCHAR(120) NULL,
            key_id VARCHAR(40) NULL,
            http_status SMALLINT UNSIGNED NULL,
            duration_ms INT UNSIGNED NULL,
            attempt SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            records INT UNSIGNED NULL,
            error_code VARCHAR(80) NULL,
            error_detail VARCHAR(300) NULL,
            reconciliation_ref VARCHAR(80) NULL,
            PRIMARY KEY (id),
            KEY ix_intlog_integration (integration_id, occurred_at)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS integration_nonces (
            key_id VARCHAR(40) NOT NULL,
            nonce VARCHAR(64) NOT NULL,
            seen_at DATETIME(6) NOT NULL,
            PRIMARY KEY (key_id, nonce),
            KEY ix_nonce_seen (seen_at)
        ) {$o}");

        $this->guards($db);
    }

    /** Posted journals are immutable (reversal only); integration logs are append-only. Best effort like audit_events. */
    private function guards(Connection $db): void
    {
        $mode = 'triggers';
        try {
            $db->exec('DROP TRIGGER IF EXISTS trg_fin_journals_posted_update');
            $db->exec("CREATE TRIGGER trg_fin_journals_posted_update BEFORE UPDATE ON fin_journals FOR EACH ROW
                IF OLD.status IN ('posted','reversed') AND (NEW.status NOT IN ('posted','reversed') OR (OLD.status = 'reversed' AND NEW.status <> 'reversed')
                    OR NOT (NEW.journal_no <=> OLD.journal_no) OR NEW.journal_date <> OLD.journal_date OR NEW.legal_entity_id <> OLD.legal_entity_id
                    OR NEW.total_debit <> OLD.total_debit OR NEW.total_credit <> OLD.total_credit OR NOT (NEW.period_id <=> OLD.period_id)
                    OR NOT (NEW.approved_by <=> OLD.approved_by) OR NOT (NEW.posted_at <=> OLD.posted_at)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'posted journals cannot be changed';
                END IF");
            $db->exec('DROP TRIGGER IF EXISTS trg_fin_journals_posted_delete');
            $db->exec("CREATE TRIGGER trg_fin_journals_posted_delete BEFORE DELETE ON fin_journals FOR EACH ROW
                IF OLD.status IN ('posted','reversed') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'posted journals cannot be deleted'; END IF");
            foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $suffix => $op) {
                $db->exec("DROP TRIGGER IF EXISTS trg_fin_lines_posted_{$suffix}");
                $db->exec("CREATE TRIGGER trg_fin_lines_posted_{$suffix} BEFORE {$op} ON fin_journal_lines FOR EACH ROW
                    IF (SELECT status FROM fin_journals WHERE id = OLD.journal_id) IN ('posted','reversed') THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lines of a posted journal cannot be changed';
                    END IF");
                $db->exec("DROP TRIGGER IF EXISTS trg_integration_logs_no_{$suffix}");
                $db->exec("CREATE TRIGGER trg_integration_logs_no_{$suffix} BEFORE {$op} ON integration_logs FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'integration_logs is append-only'");
            }
            $db->exec('DROP TRIGGER IF EXISTS trg_fin_lines_posted_insert');
            $db->exec("CREATE TRIGGER trg_fin_lines_posted_insert BEFORE INSERT ON fin_journal_lines FOR EACH ROW
                IF (SELECT status FROM fin_journals WHERE id = NEW.journal_id) IN ('posted','reversed') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lines of a posted journal cannot be changed';
                END IF");
        } catch (\PDOException) {
            $mode = 'code_only';
        }
        $db->run(
            "INSERT INTO system_info (info_key, info_value, updated_at) VALUES ('finance_guard', ?, UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE info_value = VALUES(info_value), updated_at = VALUES(updated_at)",
            [$mode]
        );
    }

    public function postCheck(Connection $db): array
    {
        return Schema::expectTables($db, self::TABLES);
    }
};
