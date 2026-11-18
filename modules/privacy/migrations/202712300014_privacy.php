<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;

/**
 * 0.42.0 (R6-1): privacy centre — versioned privacy notices per audience with acknowledgements (P-01, Q-07), the record
 * of processing activities, and the personal-data breach log (Q-08, Q-13). Drafts written by AI are flagged (ai_draft).
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Privacy centre: notices, acknowledgements, processing activities, breaches';
    }

    public function up(Connection $db): void
    {
        $o = \Blue\Core\Database\Schema::TABLE_OPTIONS;
        $std = \Blue\Core\Database\Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS pv_notices (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            audience VARCHAR(20) NOT NULL,
            version INT UNSIGNED NOT NULL,
            status ENUM('draft','pending','published','superseded','withdrawn') NOT NULL DEFAULT 'draft',
            title VARCHAR(160) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            body_en MEDIUMTEXT NULL,
            requires_ack TINYINT(1) NOT NULL DEFAULT 0,
            ai_draft TINYINT(1) NOT NULL DEFAULT 0,
            effective_from DATE NULL,
            sha256 CHAR(64) NULL,
            submitted_by BIGINT UNSIGNED NULL,
            submitted_at DATETIME(6) NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME(6) NULL,
            legal_checked_note VARCHAR(300) NULL,
            return_note VARCHAR(500) NULL,
            withdrawn_reason VARCHAR(500) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_pv_notices_public (public_id), UNIQUE KEY uq_pv_notices_version (legal_entity_id, audience, version),
            KEY ix_pv_notices_status (legal_entity_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pv_acks (
            notice_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            acked_at DATETIME(6) NOT NULL,
            sha256 CHAR(64) NOT NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            PRIMARY KEY (notice_id, user_id), KEY ix_pv_acks_user (user_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pv_activities (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(40) NOT NULL,
            name VARCHAR(160) NOT NULL,
            module VARCHAR(40) NULL,
            purpose TEXT NOT NULL,
            legal_basis VARCHAR(30) NULL,
            basis_note VARCHAR(500) NULL,
            subjects VARCHAR(300) NOT NULL,
            data_categories VARCHAR(600) NOT NULL,
            is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
            sensitive_note VARCHAR(500) NULL,
            recipients VARCHAR(600) NULL,
            transfer_abroad VARCHAR(300) NULL,
            retention VARCHAR(300) NULL,
            security VARCHAR(600) NULL,
            owner VARCHAR(160) NULL,
            status ENUM('draft','active','retired') NOT NULL DEFAULT 'draft',
            ai_draft TINYINT(1) NOT NULL DEFAULT 0,
            activated_by BIGINT UNSIGNED NULL,
            activated_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_pv_activities_public (public_id), UNIQUE KEY uq_pv_activities_code (legal_entity_id, code)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pv_breaches (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            ref VARCHAR(20) NOT NULL,
            discovered_at DATETIME(6) NOT NULL,
            summary TEXT NOT NULL,
            data_categories VARCHAR(600) NOT NULL,
            people_estimate INT UNSIGNED NULL,
            risk ENUM('unknown','low','medium','high') NOT NULL DEFAULT 'unknown',
            risk_reason VARCHAR(1000) NULL,
            containment TEXT NULL,
            decision ENUM('pending','notify','no_notify') NOT NULL DEFAULT 'pending',
            decision_reason VARCHAR(1000) NULL,
            decided_by BIGINT UNSIGNED NULL,
            authority_notified_at DATETIME(6) NULL,
            authority_ref VARCHAR(120) NULL,
            subjects_notified_at DATETIME(6) NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            lessons TEXT NULL,
            closed_at DATETIME(6) NULL,
            closed_by BIGINT UNSIGNED NULL,
            overdue_notified TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_pv_breaches_public (public_id), UNIQUE KEY uq_pv_breaches_ref (legal_entity_id, ref),
            KEY ix_pv_breaches_status (status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pv_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity ENUM('notice','activity','breach') NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(40) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            note VARCHAR(1000) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_pv_events_entity (entity, entity_id)
        ) {$o}");
    }
};
