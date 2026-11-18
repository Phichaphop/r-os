<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 9C-3 (DEC-96): M44 contractor management — the company's required document types (company or worker, no
 * defaults), contractor companies and their workers, documents with expiry (central document service), work permits
 * (area, work, hazards, time, supervisor, workers; approval), gate check-in/out and the permit history.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Contractors: document types, companies, workers, documents, permits, permit workers, check-ins, events, settings';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS ct_settings (
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            alert_days SMALLINT UNSIGNED NULL,
            updated_at DATETIME(6) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (legal_entity_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ct_doc_types (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            applies_to ENUM('company','worker') NOT NULL,
            required TINYINT(1) NOT NULL DEFAULT 1,
            needs_expiry TINYINT(1) NOT NULL DEFAULT 1,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), UNIQUE KEY uq_ct_doc_types_public (public_id), UNIQUE KEY uq_ct_doc_types_name (legal_entity_id, applies_to, name)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ct_companies (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(160) NOT NULL,
            contact_name VARCHAR(120) NULL,
            contact_phone VARCHAR(40) NULL,
            contact_email VARCHAR(160) NULL,
            owner_id BIGINT UNSIGNED NULL,
            suspended TINYINT(1) NOT NULL DEFAULT 0,
            suspended_reason VARCHAR(500) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_ct_companies_public (public_id), UNIQUE KEY uq_ct_companies_name (legal_entity_id, name)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ct_workers (
            {$std},
            company_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            position VARCHAR(80) NULL,
            phone VARCHAR(40) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), UNIQUE KEY uq_ct_workers_public (public_id), KEY ix_ct_workers_company (company_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ct_documents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NULL,
            doc_type_id BIGINT UNSIGNED NOT NULL,
            valid_from DATE NULL,
            valid_until DATE NULL,
            document_pid CHAR(26) NOT NULL,
            sha256 CHAR(64) NOT NULL,
            filename VARCHAR(200) NOT NULL,
            alerted TINYINT(1) NOT NULL DEFAULT 0,
            added_by BIGINT UNSIGNED NOT NULL,
            added_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_ct_documents_owner (company_id, worker_id, doc_type_id), KEY ix_ct_documents_expiry (valid_until)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ct_permits (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NOT NULL,
            company_id BIGINT UNSIGNED NOT NULL,
            ref VARCHAR(20) NULL,
            work VARCHAR(500) NOT NULL,
            area VARCHAR(160) NOT NULL,
            tools VARCHAR(500) NULL,
            hazards VARCHAR(200) NULL,
            controls VARCHAR(1000) NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            supervisor_id BIGINT UNSIGNED NOT NULL,
            requester_id BIGINT UNSIGNED NOT NULL,
            status ENUM('draft','review','approved','on_site','completed','rejected','cancelled','expired','suspended') NOT NULL DEFAULT 'draft',
            workflow_id CHAR(26) NULL,
            area_checked TINYINT(1) NULL,
            equipment_returned TINYINT(1) NULL,
            rating TINYINT UNSIGNED NULL,
            review_note VARCHAR(1000) NULL,
            completed_at DATETIME NULL,
            overdue_notified TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_ct_permits_public (public_id), UNIQUE KEY uq_ct_permits_ref (legal_entity_id, ref), KEY ix_ct_permits_company (company_id, status), KEY ix_ct_permits_time (status, starts_at, ends_at)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ct_permit_workers (
            permit_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (permit_id, worker_id), KEY ix_ct_pw_worker (worker_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ct_checkins (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            permit_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL,
            in_at DATETIME NOT NULL,
            in_by BIGINT UNSIGNED NOT NULL,
            out_at DATETIME NULL,
            out_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id), KEY ix_ct_checkins_permit (permit_id, worker_id, out_at)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ct_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            permit_id BIGINT UNSIGNED NULL,
            company_id BIGINT UNSIGNED NULL,
            event VARCHAR(30) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            note VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_ct_events_permit (permit_id), KEY ix_ct_events_company (company_id)
        ) {$o}");
    }
};
