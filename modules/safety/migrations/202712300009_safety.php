<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 9E-1 (DEC-100): M39 safety incidents and near misses — company settings (level descriptions, who is alerted at
 * once for serious levels, anonymous reporting on/off), incidents (report, triage, investigation, verification), files,
 * corrective/preventive actions, witness statements and medical details (each restricted), communication with outside
 * parties, and the incident timeline.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Safety: settings, incidents, files, actions, witnesses, medical details, communications, timeline';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS sf_settings (
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            level_notes VARCHAR(2000) NULL,
            escalate_json VARCHAR(2000) NULL,
            anonymous_on TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME(6) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (legal_entity_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS sf_incidents (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            ref VARCHAR(20) NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            kind ENUM('injury','damage','near_miss','hazard') NOT NULL,
            severity TINYINT UNSIGNED NOT NULL,
            occurred_at DATETIME NOT NULL,
            location VARCHAR(200) NOT NULL,
            description TEXT NOT NULL,
            immediate_action TEXT NULL,
            people TEXT NULL,
            anonymous TINYINT(1) NOT NULL DEFAULT 0,
            reporter_id BIGINT UNSIGNED NULL,
            track_hash CHAR(64) NULL,
            status ENUM('reported','triage','investigating','action_assigned','verification','closed','reopened') NOT NULL DEFAULT 'reported',
            acknowledged_at DATETIME(6) NULL,
            acknowledged_by BIGINT UNSIGNED NULL,
            investigator_id BIGINT UNSIGNED NULL,
            related_id BIGINT UNSIGNED NULL,
            facts TEXT NULL,
            root_cause TEXT NULL,
            temp_control TEXT NULL,
            likelihood TINYINT UNSIGNED NULL,
            impact TINYINT UNSIGNED NULL,
            outcome TEXT NULL,
            closed_reason ENUM('done','not_incident','duplicate') NULL,
            closed_at DATETIME(6) NULL,
            verified_by BIGINT UNSIGNED NULL,
            reopen_count INT UNSIGNED NOT NULL DEFAULT 0,
            digested TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_sf_incidents_public (public_id), UNIQUE KEY uq_sf_incidents_ref (legal_entity_id, ref), UNIQUE KEY uq_sf_incidents_track (track_hash),
            KEY ix_sf_incidents_status (legal_entity_id, status), KEY ix_sf_incidents_when (legal_entity_id, kind, occurred_at), KEY ix_sf_incidents_reporter (reporter_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS sf_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            incident_id BIGINT UNSIGNED NOT NULL,
            action_id BIGINT UNSIGNED NULL,
            stage ENUM('report','investigation','action') NOT NULL,
            document_pid CHAR(26) NOT NULL,
            filename VARCHAR(200) NOT NULL,
            mime VARCHAR(80) NOT NULL,
            uploaded_by BIGINT UNSIGNED NOT NULL,
            uploaded_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_sf_files_incident (incident_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS sf_actions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            incident_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('corrective','preventive') NOT NULL,
            description TEXT NOT NULL,
            owner_id BIGINT UNSIGNED NOT NULL,
            due_on DATE NOT NULL,
            status ENUM('open','done','cancelled') NOT NULL DEFAULT 'open',
            external_ref VARCHAR(60) NULL,
            done_note TEXT NULL,
            done_at DATETIME(6) NULL,
            cancel_reason VARCHAR(500) NULL,
            reminded TINYINT(1) NOT NULL DEFAULT 0,
            overdue_notified TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_sf_actions_public (public_id), KEY ix_sf_actions_incident (incident_id, status), KEY ix_sf_actions_owner (owner_id, status, due_on)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS sf_witnesses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            incident_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NULL,
            name VARCHAR(160) NOT NULL,
            statement TEXT NOT NULL,
            taken_by BIGINT UNSIGNED NOT NULL,
            taken_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_sf_witnesses_incident (incident_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS sf_medical (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            incident_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NULL,
            name VARCHAR(160) NOT NULL,
            injury TEXT NOT NULL,
            body_part VARCHAR(160) NULL,
            treatment TEXT NULL,
            days_off SMALLINT UNSIGNED NULL,
            hospital TINYINT(1) NOT NULL DEFAULT 0,
            recorded_by BIGINT UNSIGNED NOT NULL,
            recorded_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_sf_medical_incident (incident_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS sf_comms (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            incident_id BIGINT UNSIGNED NOT NULL,
            sent_on DATE NOT NULL,
            recipient VARCHAR(200) NOT NULL,
            channel VARCHAR(60) NULL,
            reference VARCHAR(120) NULL,
            note TEXT NULL,
            recorded_by BIGINT UNSIGNED NOT NULL,
            recorded_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_sf_comms_incident (incident_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS sf_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            incident_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(40) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            note VARCHAR(1000) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_sf_events_incident (incident_id, id)
        ) {$o}");
    }
};
