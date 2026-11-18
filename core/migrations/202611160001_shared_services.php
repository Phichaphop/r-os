<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 3A — shared business services (P0-03 §3, 03 §6.9, 6.25–6.28, 6.30.4, 6.30.7, 6.30.10, 6.30.13, 6.10.1).
 *
 * Config Registry:   config_definitions, config_versions (effective-dated, maker-checker, never edited after a decision)
 * Calculation Ledger: calculation_runs, calculation_snapshots (encrypted payloads)
 * Locks:             locks (+ lock_events history, append-only)
 * Amendments:        amendment_requests, amendment_changes
 * Workflow:          workflow_definitions, workflow_versions, workflow_instances, workflow_tasks, workflow_decisions, workflow_delegations
 * Access requests:   access_requests (6.4 "ขอเข้าใช้งาน" — first real workflow subject)
 * Events:            outbox_events, processed_events, idempotency_keys
 * Numbering:         number_sequences, number_issues
 * Notifications:     notifications (in-app)
 * Preferences:       user_preferences (page size per list — 6.10.1)
 * Every index stays under 767 bytes.
 */

return new class extends Migration {
    private const TABLES = [
        'config_definitions', 'config_versions', 'calculation_runs', 'calculation_snapshots', 'locks', 'lock_events',
        'amendment_requests', 'amendment_changes', 'workflow_definitions', 'workflow_versions', 'workflow_instances',
        'workflow_tasks', 'workflow_decisions', 'workflow_delegations', 'access_requests', 'outbox_events',
        'processed_events', 'idempotency_keys', 'number_sequences', 'number_issues', 'notifications', 'user_preferences',
    ];

    public function description(): string
    {
        return 'Config registry, calculation ledger, locks, amendments, workflow, outbox, numbering, notifications, preferences';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();

        // ---- Config Registry (6.25) ------------------------------------------------------------
        $db->exec("CREATE TABLE IF NOT EXISTS config_definitions (
            {$std},
            config_key VARCHAR(100) NOT NULL,
            module_code VARCHAR(40) NOT NULL DEFAULT 'core',
            category VARCHAR(40) NOT NULL,
            data_type ENUM('decimal','integer','string','boolean','enum','color','json') NOT NULL,
            unit VARCHAR(20) NULL,
            precision_digits TINYINT UNSIGNED NULL,
            rounding_mode VARCHAR(20) NULL,
            allowed_scope_levels VARCHAR(80) NOT NULL DEFAULT 'organization',
            validation_json TEXT NULL,
            risk_level ENUM('normal','high') NOT NULL DEFAULT 'normal',
            source_type ENUM('LAW','COMPANY_POLICY','BRAND','TECHNICAL','DEMO') NOT NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('active','retired') NOT NULL DEFAULT 'active',
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_config_definitions_public (public_id),
            UNIQUE KEY uq_config_definitions_key (config_key)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS config_versions (
            {$std},
            definition_id BIGINT UNSIGNED NOT NULL,
            scope_level ENUM('organization','legal_entity','site') NOT NULL,
            scope_id BIGINT UNSIGNED NOT NULL,
            value_json TEXT NOT NULL,
            effective_from DATETIME(6) NOT NULL,
            effective_to DATETIME(6) NULL,
            status ENUM('draft','in_review','approved','rejected','returned','cancelled') NOT NULL DEFAULT 'draft',
            source_reference VARCHAR(255) NULL,
            reason VARCHAR(255) NOT NULL,
            proposed_by BIGINT UNSIGNED NOT NULL,
            submitted_at DATETIME(6) NULL,
            decided_by BIGINT UNSIGNED NULL,
            decided_at DATETIME(6) NULL,
            decision_comment VARCHAR(255) NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_config_versions_public (public_id),
            KEY ix_config_versions_lookup (definition_id, scope_level, scope_id, status, effective_from),
            CONSTRAINT fk_config_versions_def FOREIGN KEY (definition_id) REFERENCES config_definitions (id)
        ) {$o}");

        // ---- Calculation Ledger (6.26) ---------------------------------------------------------
        $db->exec("CREATE TABLE IF NOT EXISTS calculation_runs (
            {$std},
            calc_type VARCHAR(60) NOT NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            period_ref VARCHAR(40) NULL,
            as_of DATETIME(6) NOT NULL,
            engine_version VARCHAR(40) NOT NULL,
            formula_ref VARCHAR(100) NOT NULL,
            status ENUM('preview','calculated','validated','approved','posted','reversed','recalculated') NOT NULL,
            initiated_by BIGINT UNSIGNED NULL,
            initiated_type ENUM('user','system','job') NOT NULL DEFAULT 'user',
            supersedes_run_id BIGINT UNSIGNED NULL,
            source_type VARCHAR(60) NULL,
            source_id VARCHAR(40) NULL,
            source_revision INT UNSIGNED NULL,
            correlation_id VARCHAR(40) NULL,
            idempotency_key VARCHAR(100) NULL,
            input_hash CHAR(64) NOT NULL,
            output_hash CHAR(64) NOT NULL,
            warnings_json TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_calculation_runs_public (public_id),
            UNIQUE KEY uq_calculation_runs_idem (idempotency_key),
            KEY ix_calculation_runs_source (calc_type, source_type, source_id),
            KEY ix_calculation_runs_le (legal_entity_id, period_ref)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS calculation_snapshots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('config_versions','inputs','intermediate','outputs') NOT NULL,
            payload_enc MEDIUMBLOB NOT NULL,
            payload_sha256 CHAR(64) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_calculation_snapshots (run_id, kind),
            CONSTRAINT fk_calc_snap_run FOREIGN KEY (run_id) REFERENCES calculation_runs (id)
        ) {$o}");

        // ---- Locks (6.27) ----------------------------------------------------------------------
        $db->exec("CREATE TABLE IF NOT EXISTS locks (
            {$std},
            lock_type VARCHAR(60) NOT NULL,
            scope_level ENUM('organization','legal_entity','site','department') NOT NULL,
            scope_id BIGINT UNSIGNED NOT NULL,
            period_ref VARCHAR(40) NOT NULL,
            mode ENUM('soft','hard') NOT NULL DEFAULT 'hard',
            status ENUM('open','locked','reopen_requested','reopened','closed') NOT NULL DEFAULT 'open',
            revision_no INT UNSIGNED NOT NULL DEFAULT 0,
            locked_by BIGINT UNSIGNED NULL,
            locked_at DATETIME(6) NULL,
            reason VARCHAR(255) NULL,
            reopen_requested_by BIGINT UNSIGNED NULL,
            reopen_reason VARCHAR(255) NULL,
            snapshot_ref VARCHAR(100) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_locks_public (public_id),
            UNIQUE KEY uq_locks_scope (lock_type, scope_level, scope_id, period_ref)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS lock_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lock_id BIGINT UNSIGNED NOT NULL,
            event ENUM('created','locked','reopen_requested','reopen_approved','reopen_rejected','closed','blocked_write') NOT NULL,
            revision_no INT UNSIGNED NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            channel VARCHAR(20) NULL,
            reason VARCHAR(255) NULL,
            occurred_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_lock_events_lock (lock_id, occurred_at),
            CONSTRAINT fk_lock_events_lock FOREIGN KEY (lock_id) REFERENCES locks (id)
        ) {$o}");

        // ---- Workflow (6.9, 6.30.4) ------------------------------------------------------------
        $db->exec("CREATE TABLE IF NOT EXISTS workflow_definitions (
            {$std},
            code VARCHAR(60) NOT NULL,
            module_code VARCHAR(40) NOT NULL DEFAULT 'core',
            subject_type VARCHAR(60) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_workflow_definitions_public (public_id),
            UNIQUE KEY uq_workflow_definitions_code (code)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS workflow_versions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            definition_id BIGINT UNSIGNED NOT NULL,
            version_no INT UNSIGNED NOT NULL,
            steps_json TEXT NOT NULL,
            status ENUM('active','retired') NOT NULL DEFAULT 'active',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            change_reason VARCHAR(255) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_workflow_versions (definition_id, version_no),
            CONSTRAINT fk_wf_versions_def FOREIGN KEY (definition_id) REFERENCES workflow_definitions (id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS workflow_instances (
            {$std},
            version_id BIGINT UNSIGNED NOT NULL,
            subject_type VARCHAR(60) NOT NULL,
            subject_id VARCHAR(40) NOT NULL,
            title VARCHAR(200) NOT NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            requester_id BIGINT UNSIGNED NOT NULL,
            current_step SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('pending','approved','rejected','returned','cancelled','needs_attention') NOT NULL DEFAULT 'pending',
            execution_status ENUM('not_started','succeeded','failed') NOT NULL DEFAULT 'not_started',
            execution_error VARCHAR(255) NULL,
            execution_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            submitted_at DATETIME(6) NOT NULL,
            finished_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_workflow_instances_public (public_id),
            KEY ix_workflow_instances_subject (subject_type, subject_id),
            KEY ix_workflow_instances_requester (requester_id, status, submitted_at),
            CONSTRAINT fk_wf_instances_version FOREIGN KEY (version_id) REFERENCES workflow_versions (id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS workflow_tasks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            instance_id BIGINT UNSIGNED NOT NULL,
            step_no SMALLINT UNSIGNED NOT NULL,
            assignee_id BIGINT UNSIGNED NOT NULL,
            via_delegation_from BIGINT UNSIGNED NULL,
            is_escalation TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('open','done','void') NOT NULL DEFAULT 'open',
            due_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_workflow_tasks (instance_id, step_no, assignee_id),
            KEY ix_workflow_tasks_inbox (assignee_id, status, created_at),
            CONSTRAINT fk_wf_tasks_instance FOREIGN KEY (instance_id) REFERENCES workflow_instances (id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS workflow_decisions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            instance_id BIGINT UNSIGNED NOT NULL,
            step_no SMALLINT UNSIGNED NOT NULL,
            version_id BIGINT UNSIGNED NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            on_behalf_of_id BIGINT UNSIGNED NULL,
            decision ENUM('submit','approve','reject','return','cancel','escalate','resubmit','execute_failed','execute_succeeded') NOT NULL,
            comment VARCHAR(500) NULL,
            correlation_id VARCHAR(40) NULL,
            decided_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_workflow_decisions_instance (instance_id, decided_at),
            CONSTRAINT fk_wf_decisions_instance FOREIGN KEY (instance_id) REFERENCES workflow_instances (id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS workflow_delegations (
            {$std},
            from_user_id BIGINT UNSIGNED NOT NULL,
            to_user_id BIGINT UNSIGNED NOT NULL,
            workflow_code VARCHAR(60) NULL,
            valid_from DATETIME(6) NOT NULL,
            valid_to DATETIME(6) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            status ENUM('active','revoked') NOT NULL DEFAULT 'active',
            PRIMARY KEY (id),
            UNIQUE KEY uq_workflow_delegations_public (public_id),
            KEY ix_workflow_delegations_from (from_user_id, status, valid_from)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS access_requests (
            {$std},
            user_id BIGINT UNSIGNED NOT NULL,
            role_code VARCHAR(20) NOT NULL,
            scope_level ENUM('organization','legal_entity','site','department') NOT NULL,
            scope_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            reason VARCHAR(255) NOT NULL,
            status ENUM('pending','approved','rejected','returned','cancelled') NOT NULL DEFAULT 'pending',
            assignment_id BIGINT UNSIGNED NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_access_requests_public (public_id),
            KEY ix_access_requests_user (user_id, status)
        ) {$o}");

        // ---- Amendments (6.28) -----------------------------------------------------------------
        $db->exec("CREATE TABLE IF NOT EXISTS amendment_requests (
            {$std},
            target_type VARCHAR(60) NOT NULL,
            target_id VARCHAR(40) NOT NULL,
            target_revision INT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            action ENUM('amend','cancel','reverse','reopen') NOT NULL DEFAULT 'amend',
            reason_code VARCHAR(40) NOT NULL,
            reason VARCHAR(500) NOT NULL,
            requester_id BIGINT UNSIGNED NOT NULL,
            status ENUM('draft','submitted','approved','rejected','returned','withdrawn','executed','execution_failed','closed') NOT NULL DEFAULT 'draft',
            impact_json TEXT NULL,
            execution_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            execution_error VARCHAR(255) NULL,
            execution_ref VARCHAR(100) NULL,
            idempotency_key VARCHAR(100) NOT NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_amendment_requests_public (public_id),
            UNIQUE KEY uq_amendment_requests_idem (idempotency_key),
            KEY ix_amendment_requests_target (target_type, target_id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS amendment_changes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id BIGINT UNSIGNED NOT NULL,
            field VARCHAR(60) NOT NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            PRIMARY KEY (id),
            KEY ix_amendment_changes_request (request_id),
            CONSTRAINT fk_amend_changes_request FOREIGN KEY (request_id) REFERENCES amendment_requests (id)
        ) {$o}");

        // ---- Outbox / idempotency (P0-02 §6, 6.30.7) ------------------------------------------
        $db->exec("CREATE TABLE IF NOT EXISTS outbox_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id CHAR(26) NOT NULL,
            event_name VARCHAR(80) NOT NULL,
            schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            legal_entity_id BIGINT UNSIGNED NULL,
            site_id BIGINT UNSIGNED NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            correlation_id VARCHAR(40) NULL,
            idempotency_key VARCHAR(100) NOT NULL,
            payload_json TEXT NOT NULL,
            occurred_at DATETIME(6) NOT NULL,
            status ENUM('pending','delivered','failed','dead') NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME(6) NOT NULL,
            last_error VARCHAR(255) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_outbox_events_event (event_id),
            UNIQUE KEY uq_outbox_events_idem (idempotency_key),
            KEY ix_outbox_events_due (status, next_attempt_at)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS processed_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id CHAR(26) NOT NULL,
            consumer VARCHAR(80) NOT NULL,
            processed_at DATETIME(6) NOT NULL,
            result VARCHAR(20) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_processed_events (event_id, consumer)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS idempotency_keys (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope VARCHAR(60) NOT NULL,
            idem_key VARCHAR(100) NOT NULL,
            request_hash CHAR(64) NOT NULL,
            response_ref VARCHAR(100) NULL,
            created_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_idempotency_keys (scope, idem_key)
        ) {$o}");

        // ---- Numbering (6.30.10) ---------------------------------------------------------------
        $db->exec("CREATE TABLE IF NOT EXISTS number_sequences (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            doc_type VARCHAR(40) NOT NULL,
            period_key VARCHAR(10) NOT NULL,
            prefix_pattern VARCHAR(60) NOT NULL,
            next_value BIGINT UNSIGNED NOT NULL DEFAULT 1,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_number_sequences (legal_entity_id, site_id, doc_type, period_key)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS number_issues (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sequence_id BIGINT UNSIGNED NOT NULL,
            doc_no VARCHAR(80) NOT NULL,
            seq_value BIGINT UNSIGNED NOT NULL,
            status ENUM('issued','voided') NOT NULL DEFAULT 'issued',
            ref_type VARCHAR(60) NULL,
            ref_id VARCHAR(40) NULL,
            issued_by BIGINT UNSIGNED NULL,
            issued_at DATETIME(6) NOT NULL,
            void_reason VARCHAR(255) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_number_issues (sequence_id, seq_value),
            CONSTRAINT fk_number_issues_seq FOREIGN KEY (sequence_id) REFERENCES number_sequences (id)
        ) {$o}");

        // ---- Notifications (6.30.13) + preferences (6.10.1) ----------------------------------
        $db->exec("CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(60) NOT NULL,
            title_key VARCHAR(120) NOT NULL,
            params_json TEXT NULL,
            link VARCHAR(255) NULL,
            channel ENUM('in_app') NOT NULL DEFAULT 'in_app',
            dedupe_key VARCHAR(120) NULL,
            status ENUM('queued','read') NOT NULL DEFAULT 'queued',
            created_at DATETIME(6) NOT NULL,
            read_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_notifications_public (public_id),
            UNIQUE KEY uq_notifications_dedupe (user_id, dedupe_key),
            KEY ix_notifications_user (user_id, status, created_at)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS user_preferences (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            pref_key VARCHAR(80) NOT NULL,
            pref_value VARCHAR(255) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_preferences (user_id, pref_key)
        ) {$o}");
    }

    public function postCheck(Connection $db): array
    {
        return Schema::expectTables($db, self::TABLES);
    }

    public function recovery(): string
    {
        return 'rollback';
    }

    public function down(Connection $db): void
    {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $db->exec("DROP TABLE IF EXISTS `{$t}`");
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
};
