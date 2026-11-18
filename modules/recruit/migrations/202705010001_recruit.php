<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M08 Recruitment (phase 5E, DEC-76): requisitions (approved before anything is published), job postings, applications
 * (personal details encrypted, the ID number only as an encrypted value + keyed hash + last 4), application files,
 * stage history, interviews and token-link evaluations, offers (approved), and the company's applicant privacy notice
 * and required documents.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M08 Recruitment: requisitions, postings, applications, files, interviews, evaluations, offers, settings';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS rec_settings (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            notice_text TEXT NULL,
            notice_version INT UNSIGNED NOT NULL DEFAULT 0,
            required_docs VARCHAR(200) NOT NULL DEFAULT 'education',
            PRIMARY KEY (id), UNIQUE KEY uq_rec_settings_public (public_id), UNIQUE KEY uq_rec_settings_le (legal_entity_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rec_requisitions (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            department_id BIGINT UNSIGNED NULL,
            position_title VARCHAR(120) NOT NULL,
            headcount INT UNSIGNED NOT NULL,
            reason ENUM('new','replacement') NOT NULL,
            replaces VARCHAR(500) NULL,
            employment_type VARCHAR(20) NOT NULL,
            salary_min DECIMAL(12,2) NULL,
            salary_max DECIMAL(12,2) NULL,
            needed_by DATE NULL,
            justification VARCHAR(1000) NOT NULL,
            requester_user_id BIGINT UNSIGNED NOT NULL,
            status ENUM('draft','pending','approved','rejected','returned','filled','cancelled') NOT NULL DEFAULT 'draft',
            approved_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_rec_req_public (public_id), KEY ix_rec_req_le (legal_entity_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rec_postings (
            {$std},
            requisition_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(120) NOT NULL,
            description TEXT NOT NULL,
            location VARCHAR(120) NULL,
            closes_on DATE NULL,
            status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
            published_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_rec_post_public (public_id), KEY ix_rec_post_status (status, legal_entity_id),
            CONSTRAINT fk_rec_post_req FOREIGN KEY (requisition_id) REFERENCES rec_requisitions (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rec_candidates (
            {$std},
            posting_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            app_code CHAR(10) NOT NULL,
            name VARCHAR(160) NOT NULL,
            email VARCHAR(160) NULL,
            phone VARCHAR(30) NOT NULL,
            expected_salary DECIMAL(12,2) NULL,
            available_from DATE NULL,
            profile_enc MEDIUMTEXT NULL,
            id_type VARCHAR(20) NULL,
            id_enc TEXT NULL,
            id_hash BINARY(32) NULL,
            id_last4 VARCHAR(4) NULL,
            consent_at DATETIME(6) NULL,
            consent_name VARCHAR(160) NULL,
            consent_ip VARCHAR(45) NULL,
            notice_version INT UNSIGNED NULL,
            talent_consent TINYINT(1) NOT NULL DEFAULT 0,
            source ENUM('web','talent_pool','staff') NOT NULL DEFAULT 'web',
            talent_id BIGINT UNSIGNED NULL,
            stage ENUM('applied','screening','interview','offer','hired','rejected','withdrawn','on_hold') NOT NULL DEFAULT 'applied',
            stage_at DATETIME(6) NOT NULL,
            employee_id BIGINT UNSIGNED NULL,
            closed_at DATETIME(6) NULL,
            anonymized_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_rec_cand_public (public_id), UNIQUE KEY uq_rec_cand_code (app_code),
            KEY ix_rec_cand_post (posting_id, stage), KEY ix_rec_cand_hash (id_hash),
            CONSTRAINT fk_rec_cand_post FOREIGN KEY (posting_id) REFERENCES rec_postings (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rec_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            candidate_id BIGINT UNSIGNED NOT NULL,
            group_code VARCHAR(20) NOT NULL,
            document_pid CHAR(26) NOT NULL,
            filename VARCHAR(200) NOT NULL,
            uploaded_by BIGINT UNSIGNED NULL,
            uploaded_at DATETIME(6) NOT NULL,
            verified_by BIGINT UNSIGNED NULL,
            verified_at DATETIME(6) NULL,
            status ENUM('current','removed') NOT NULL DEFAULT 'current',
            PRIMARY KEY (id), KEY ix_rec_files_cand (candidate_id, group_code),
            CONSTRAINT fk_rec_files_cand FOREIGN KEY (candidate_id) REFERENCES rec_candidates (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rec_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            candidate_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(30) NOT NULL,
            from_stage VARCHAR(20) NULL,
            to_stage VARCHAR(20) NULL,
            note VARCHAR(500) NULL,
            actor_id BIGINT UNSIGNED NULL,
            occurred_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_rec_events_cand (candidate_id, id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rec_interviews (
            {$std},
            candidate_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('interview','practical','external') NOT NULL DEFAULT 'interview',
            scheduled_at DATETIME(6) NOT NULL,
            location VARCHAR(200) NULL,
            status ENUM('scheduled','done','cancelled') NOT NULL DEFAULT 'scheduled',
            PRIMARY KEY (id), UNIQUE KEY uq_rec_int_public (public_id), KEY ix_rec_int_cand (candidate_id),
            CONSTRAINT fk_rec_int_cand FOREIGN KEY (candidate_id) REFERENCES rec_candidates (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rec_evaluations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            interview_id BIGINT UNSIGNED NOT NULL,
            candidate_id BIGINT UNSIGNED NOT NULL,
            evaluator_name VARCHAR(120) NOT NULL,
            evaluator_user_id BIGINT UNSIGNED NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            submitted_at DATETIME(6) NULL,
            score TINYINT UNSIGNED NULL,
            recommendation ENUM('hire','maybe','no') NULL,
            comment VARCHAR(2000) NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_rec_eval_token (token_hash), KEY ix_rec_eval_cand (candidate_id),
            CONSTRAINT fk_rec_eval_int FOREIGN KEY (interview_id) REFERENCES rec_interviews (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rec_offers (
            {$std},
            candidate_id BIGINT UNSIGNED NOT NULL,
            position_title VARCHAR(120) NOT NULL,
            salary DECIMAL(12,2) NOT NULL,
            start_date DATE NOT NULL,
            employment_type VARCHAR(20) NOT NULL,
            note VARCHAR(1000) NULL,
            status ENUM('pending','approved','rejected','returned','accepted','declined','cancelled') NOT NULL DEFAULT 'pending',
            decided_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_rec_offer_public (public_id), KEY ix_rec_offer_cand (candidate_id, status),
            CONSTRAINT fk_rec_offer_cand FOREIGN KEY (candidate_id) REFERENCES rec_candidates (id)
        ) {$o}");
    }

    public function postCheck(Connection $db): array
    {
        return Schema::expectTables($db, ['rec_settings', 'rec_requisitions', 'rec_postings', 'rec_candidates', 'rec_files', 'rec_events', 'rec_interviews', 'rec_evaluations', 'rec_offers']);
    }
};
