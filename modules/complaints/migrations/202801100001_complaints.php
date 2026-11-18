<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;

/**
 * 0.44.0 (R6-3): M16 anonymous complaints — cases that keep no link to the sender (a tracking code's SHA-256 only, day-only
 * times), an anonymous two-way message box, restricted investigation notes, evidence pictures stripped of metadata, an
 * optional encrypted "my complaints" list, a per-person daily limit, company settings and an event trail.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Anonymous complaints: cases, messages, notes, files, events, settings, encrypted lists, daily limits';
    }

    public function up(Connection $db): void
    {
        $o = \Blue\Core\Database\Schema::TABLE_OPTIONS;
        $std = \Blue\Core\Database\Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS cp_settings (
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            intake_open TINYINT(1) NOT NULL DEFAULT 0,
            opened_by BIGINT UNSIGNED NULL,
            opened_at DATETIME(6) NULL,
            legal_reviewer VARCHAR(160) NULL,
            legal_reviewed_on DATE NULL,
            policy_text TEXT NULL,
            first_response_days SMALLINT UNSIGNED NULL,
            resolve_days SMALLINT UNSIGNED NULL,
            retention_days INT UNSIGNED NULL,
            min_group TINYINT UNSIGNED NOT NULL DEFAULT 5,
            daily_limit TINYINT UNSIGNED NOT NULL DEFAULT 3,
            updated_at DATETIME(6) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (legal_entity_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS cp_cases (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            ref VARCHAR(20) NOT NULL,
            route ENUM('normal','alternate') NOT NULL DEFAULT 'normal',
            category VARCHAR(30) NOT NULL,
            title VARCHAR(200) NULL,
            body TEXT NULL,
            urgency ENUM('normal','high') NULL,
            status ENUM('submitted','triage','investigating','action_required','resolved','closed','duplicate','out_of_scope') NOT NULL DEFAULT 'submitted',
            duplicate_of BIGINT UNSIGNED NULL,
            track_hash CHAR(64) NULL,
            investigator_id BIGINT UNSIGNED NULL,
            coi_note VARCHAR(500) NULL,
            accepted_at DATETIME(6) NULL,
            first_response_at DATETIME(6) NULL,
            outcome TEXT NULL,
            finding ENUM('substantiated','partly','not_substantiated','inconclusive') NULL,
            staff_score TINYINT UNSIGNED NULL,
            resolved_at DATETIME(6) NULL,
            closed_at DATETIME(6) NULL,
            closed_reason VARCHAR(500) NULL,
            reporter_rating TINYINT UNSIGNED NULL,
            reporter_comment VARCHAR(1000) NULL,
            reopen_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            response_overdue_sent TINYINT(1) NOT NULL DEFAULT 0,
            resolve_overdue_sent TINYINT(1) NOT NULL DEFAULT 0,
            digested TINYINT(1) NOT NULL DEFAULT 0,
            purged_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_cp_cases_public (public_id), UNIQUE KEY uq_cp_cases_ref (legal_entity_id, ref), UNIQUE KEY uq_cp_cases_track (track_hash),
            KEY ix_cp_cases_status (legal_entity_id, route, status), KEY ix_cp_cases_inv (investigator_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS cp_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id BIGINT UNSIGNED NOT NULL,
            side ENUM('reporter','staff') NOT NULL,
            author_id BIGINT UNSIGNED NULL,
            body TEXT NULL,
            asks_reply TINYINT(1) NOT NULL DEFAULT 0,
            digested TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_cp_messages_case (case_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS cp_notes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id BIGINT UNSIGNED NOT NULL,
            author_id BIGINT UNSIGNED NOT NULL,
            body TEXT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_cp_notes_case (case_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS cp_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NULL,
            side ENUM('reporter','staff') NOT NULL,
            filename VARCHAR(60) NOT NULL,
            mime VARCHAR(40) NOT NULL,
            size INT UNSIGNED NOT NULL,
            content MEDIUMBLOB NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_cp_files_case (case_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS cp_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(40) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            note VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_cp_events_case (case_id)
        ) {$o}");
        // the optional "my complaints" list: tag = keyed hash of the account, blob = encrypted tracking codes; no times
        $db->exec("CREATE TABLE IF NOT EXISTS cp_vault (
            owner_tag CHAR(64) NOT NULL,
            blob_enc MEDIUMTEXT NOT NULL,
            PRIMARY KEY (owner_tag)
        ) {$o}");
        // today's submissions per account (keyed hash) for the daily limit; earlier days are deleted by the hourly job
        $db->exec("CREATE TABLE IF NOT EXISTS cp_quota (
            owner_tag CHAR(64) NOT NULL,
            day DATE NOT NULL,
            n SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (owner_tag, day)
        ) {$o}");
    }
};
