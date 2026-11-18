<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 3B — documents & records, templates, retention, calendars, import (03 §6.10, 6.14, 6.15; 04 §6.17; P0-03 §2–3).
 *
 * Records:   document_classes, documents, document_versions (file metadata per 6.17.3; bytes live in private storage)
 * Templates: document_templates, document_template_versions (published versions are frozen — 6.15)
 * Retention: retention_rules (versioned, approved through the workflow), legal_holds, disposition_batches, disposition_items
 * Calendars: calendars, calendar_days (exceptions to a weekly pattern — 6.14)
 * Import:    import_batches, import_rows (6.10 — preview, validation, confirm)
 * Every index stays under 767 bytes.
 */

return new class extends Migration {
    private const TABLES = [
        'document_classes', 'documents', 'document_versions', 'document_templates', 'document_template_versions',
        'retention_rules', 'legal_holds', 'disposition_batches', 'disposition_items', 'calendars', 'calendar_days',
        'import_batches', 'import_rows',
    ];

    public function description(): string
    {
        return 'Documents & records, templates, retention / legal hold / disposition, work calendars, import batches';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();

        // ---- Records (6.17) ------------------------------------------------------------------------
        $db->exec("CREATE TABLE IF NOT EXISTS document_classes (
            code VARCHAR(60) NOT NULL,
            module_code VARCHAR(40) NOT NULL DEFAULT 'core',
            classification ENUM('P','I','C','R') NOT NULL,
            view_permission VARCHAR(80) NOT NULL,
            manage_permission VARCHAR(80) NOT NULL,
            allowed_types VARCHAR(200) NOT NULL,
            max_mb SMALLINT UNSIGNED NOT NULL DEFAULT 10,
            encrypt_at_rest TINYINT(1) NOT NULL DEFAULT 0,
            record_class VARCHAR(60) NULL,
            retention_trigger VARCHAR(30) NOT NULL DEFAULT 'finalized',
            status ENUM('active','retired') NOT NULL DEFAULT 'active',
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (code)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS documents (
            {$std},
            document_class VARCHAR(60) NOT NULL,
            title VARCHAR(200) NOT NULL,
            description VARCHAR(500) NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            site_id BIGINT UNSIGNED NULL,
            related_type VARCHAR(40) NULL,
            related_id VARCHAR(40) NULL,
            subject_user_id BIGINT UNSIGNED NULL,
            document_number VARCHAR(60) NULL,
            classification ENUM('P','I','C','R') NOT NULL,
            lifecycle_status ENUM('draft','final','superseded','archived','disposed') NOT NULL DEFAULT 'draft',
            current_version_no INT UNSIGNED NOT NULL DEFAULT 0,
            language VARCHAR(10) NULL,
            source_channel ENUM('upload','template','system','import') NOT NULL DEFAULT 'upload',
            template_version_id BIGINT UNSIGNED NULL,
            supersedes_document_id BIGINT UNSIGNED NULL,
            finalized_at DATETIME(6) NULL,
            finalized_by BIGINT UNSIGNED NULL,
            retention_trigger_date DATE NULL,
            scheduled_disposition_date DATE NULL,
            retention_rule_id BIGINT UNSIGNED NULL,
            disposition_status ENUM('none','requested','disposed') NOT NULL DEFAULT 'none',
            disposed_at DATETIME(6) NULL,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_documents_public (public_id),
            KEY ix_documents_scope (legal_entity_id, document_class, lifecycle_status),
            KEY ix_documents_related (related_type, related_id),
            KEY ix_documents_due (scheduled_disposition_date, disposition_status),
            CONSTRAINT fk_documents_class FOREIGN KEY (document_class) REFERENCES document_classes (code)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS document_versions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            document_id BIGINT UNSIGNED NOT NULL,
            version_no INT UNSIGNED NOT NULL,
            storage_key CHAR(40) NULL,
            original_filename VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            file_ext VARCHAR(10) NOT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL,
            page_count INT UNSIGNED NULL,
            encrypted TINYINT(1) NOT NULL DEFAULT 0,
            content_check ENUM('passed') NOT NULL DEFAULT 'passed',
            malware_scan ENUM('not_available','clean') NOT NULL DEFAULT 'not_available',
            change_note VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            purged_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_docver_public (public_id),
            UNIQUE KEY uq_docver_no (document_id, version_no),
            KEY ix_docver_hash (sha256),
            CONSTRAINT fk_docver_doc FOREIGN KEY (document_id) REFERENCES documents (id)
        ) {$o}");

        // ---- Templates (6.15) ------------------------------------------------------------------------
        $db->exec("CREATE TABLE IF NOT EXISTS document_templates (
            {$std},
            code VARCHAR(60) NOT NULL,
            module_code VARCHAR(40) NOT NULL DEFAULT 'core',
            document_class VARCHAR(60) NOT NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            name VARCHAR(150) NOT NULL,
            locale VARCHAR(10) NOT NULL DEFAULT 'th',
            number_pattern VARCHAR(60) NULL,
            status ENUM('active','retired') NOT NULL DEFAULT 'active',
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_templates_public (public_id),
            UNIQUE KEY uq_templates_code (code),
            CONSTRAINT fk_templates_class FOREIGN KEY (document_class) REFERENCES document_classes (code)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS document_template_versions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            template_id BIGINT UNSIGNED NOT NULL,
            version_no INT UNSIGNED NOT NULL,
            status ENUM('draft','published','superseded','discarded') NOT NULL DEFAULT 'draft',
            title VARCHAR(200) NOT NULL,
            body_html MEDIUMTEXT NOT NULL,
            fields_json TEXT NOT NULL,
            font_family VARCHAR(80) NULL,
            show_header TINYINT(1) NOT NULL DEFAULT 1,
            show_footer TINYINT(1) NOT NULL DEFAULT 1,
            body_sha256 CHAR(64) NOT NULL,
            change_reason VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_at DATETIME(6) NOT NULL,
            published_at DATETIME(6) NULL,
            published_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_tplver_public (public_id),
            UNIQUE KEY uq_tplver_no (template_id, version_no),
            CONSTRAINT fk_tplver_tpl FOREIGN KEY (template_id) REFERENCES document_templates (id)
        ) {$o}");

        // ---- Retention, legal hold, disposition (6.17.7) ----------------------------------------------
        $db->exec("CREATE TABLE IF NOT EXISTS retention_rules (
            {$std},
            record_class VARCHAR(60) NOT NULL,
            version_no INT UNSIGNED NOT NULL,
            trigger_event VARCHAR(40) NOT NULL,
            duration_months INT UNSIGNED NULL,
            disposition_action ENUM('review','destroy','anonymize','archive') NOT NULL DEFAULT 'review',
            legal_basis_note VARCHAR(1000) NULL,
            source_reference VARCHAR(255) NULL,
            status ENUM('draft','in_review','returned','approved','superseded','rejected','cancelled') NOT NULL DEFAULT 'draft',
            effective_from DATE NOT NULL,
            is_ai_draft TINYINT(1) NOT NULL DEFAULT 0,
            reason VARCHAR(500) NULL,
            proposed_by BIGINT UNSIGNED NULL,
            decided_by BIGINT UNSIGNED NULL,
            decided_at DATETIME(6) NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_retention_public (public_id),
            UNIQUE KEY uq_retention_ver (record_class, version_no),
            KEY ix_retention_status (record_class, status)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS legal_holds (
            {$std},
            reference_code VARCHAR(60) NOT NULL,
            reason VARCHAR(500) NOT NULL,
            scope_type ENUM('document','related','record_class') NOT NULL,
            document_id BIGINT UNSIGNED NULL,
            related_type VARCHAR(40) NULL,
            related_id VARCHAR(40) NULL,
            record_class VARCHAR(60) NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            status ENUM('active','release_requested','released') NOT NULL DEFAULT 'active',
            review_date DATE NULL,
            placed_by BIGINT UNSIGNED NOT NULL,
            placed_at DATETIME(6) NOT NULL,
            release_reason VARCHAR(500) NULL,
            released_by BIGINT UNSIGNED NULL,
            released_at DATETIME(6) NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_holds_public (public_id),
            KEY ix_holds_doc (document_id, status),
            KEY ix_holds_related (related_type, related_id, status),
            KEY ix_holds_class (record_class, status)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS disposition_batches (
            {$std},
            legal_entity_id BIGINT UNSIGNED NULL,
            status ENUM('in_review','returned','approved','executed','partly_executed','rejected','cancelled') NOT NULL DEFAULT 'in_review',
            reason VARCHAR(500) NOT NULL,
            item_count INT UNSIGNED NOT NULL DEFAULT 0,
            requested_by BIGINT UNSIGNED NOT NULL,
            executed_at DATETIME(6) NULL,
            certificate_json TEXT NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_dispo_public (public_id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS disposition_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id BIGINT UNSIGNED NOT NULL,
            document_id BIGINT UNSIGNED NOT NULL,
            record_class VARCHAR(60) NULL,
            retention_rule_id BIGINT UNSIGNED NULL,
            result ENUM('pending','disposed','skipped_hold','skipped_changed') NOT NULL DEFAULT 'pending',
            PRIMARY KEY (id),
            UNIQUE KEY uq_dispo_item (batch_id, document_id),
            KEY ix_dispo_doc (document_id),
            CONSTRAINT fk_dispo_item_batch FOREIGN KEY (batch_id) REFERENCES disposition_batches (id)
        ) {$o}");

        // ---- Work calendars (6.14) -------------------------------------------------------------------
        $db->exec("CREATE TABLE IF NOT EXISTS calendars (
            {$std},
            code VARCHAR(40) NOT NULL,
            name VARCHAR(120) NOT NULL,
            calendar_type ENUM('work','service') NOT NULL DEFAULT 'work',
            scope_level ENUM('organization','legal_entity','site') NOT NULL,
            scope_id BIGINT UNSIGNED NOT NULL,
            base_calendar_id BIGINT UNSIGNED NULL,
            weekly_pattern CHAR(7) NOT NULL DEFAULT '0111110',
            status ENUM('active','retired') NOT NULL DEFAULT 'active',
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_calendars_public (public_id),
            UNIQUE KEY uq_calendars_code (code),
            KEY ix_calendars_scope (calendar_type, scope_level, scope_id, status)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS calendar_days (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            calendar_id BIGINT UNSIGNED NOT NULL,
            day_date DATE NOT NULL,
            day_type ENUM('working','holiday','weekend') NOT NULL,
            holiday_kind ENUM('public','company','special') NULL,
            name VARCHAR(120) NULL,
            note VARCHAR(255) NULL,
            source ENUM('manual','import') NOT NULL DEFAULT 'manual',
            import_batch_id BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_at DATETIME(6) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_calday (calendar_id, day_date),
            CONSTRAINT fk_calday_cal FOREIGN KEY (calendar_id) REFERENCES calendars (id)
        ) {$o}");

        // ---- Import (6.10) ---------------------------------------------------------------------------
        $db->exec("CREATE TABLE IF NOT EXISTS import_batches (
            {$std},
            definition_code VARCHAR(60) NOT NULL,
            template_version VARCHAR(20) NOT NULL,
            params_json TEXT NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            filename VARCHAR(255) NOT NULL,
            file_sha256 CHAR(64) NOT NULL,
            temp_key CHAR(40) NULL,
            status ENUM('validated','applied','cancelled','failed','expired') NOT NULL DEFAULT 'validated',
            total_rows INT UNSIGNED NOT NULL DEFAULT 0,
            ok_rows INT UNSIGNED NOT NULL DEFAULT 0,
            error_rows INT UNSIGNED NOT NULL DEFAULT 0,
            applied_rows INT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_by BIGINT UNSIGNED NOT NULL,
            applied_by BIGINT UNSIGNED NULL,
            applied_at DATETIME(6) NULL,
            expires_at DATETIME(6) NOT NULL,
            error_summary VARCHAR(500) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_import_public (public_id),
            KEY ix_import_user (uploaded_by, status)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS import_rows (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id BIGINT UNSIGNED NOT NULL,
            row_no INT UNSIGNED NOT NULL,
            status ENUM('ok','error','applied','skipped') NOT NULL,
            action ENUM('create','update','none') NOT NULL DEFAULT 'create',
            data_enc MEDIUMBLOB NULL,
            errors_json TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_import_row (batch_id, row_no),
            CONSTRAINT fk_import_row_batch FOREIGN KEY (batch_id) REFERENCES import_batches (id)
        ) {$o}");
    }

    public function postCheck(Connection $db): array
    {
        return Schema::expectTables($db, self::TABLES);
    }
};
