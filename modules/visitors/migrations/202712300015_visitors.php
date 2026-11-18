<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;

/**
 * 0.43.0 (R6-2): M43 visitor management — invitations with a time-limited QR, gate check-in/out, blacklist, overdue
 * notices and a company-set retention after which the visitor's identity is removed.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Visitor management: visits, blacklist, settings, events';
    }

    public function up(Connection $db): void
    {
        $o = \Blue\Core\Database\Schema::TABLE_OPTIONS;
        $std = \Blue\Core\Database\Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS vs_visits (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            ref VARCHAR(20) NOT NULL,
            kind ENUM('invited','walk_in') NOT NULL,
            host_user_id BIGINT UNSIGNED NOT NULL,
            visitor_name VARCHAR(160) NULL,
            visitor_company VARCHAR(160) NULL,
            purpose VARCHAR(500) NULL,
            people SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            vehicle_plate VARCHAR(40) NULL,
            items VARCHAR(500) NULL,
            expected_from DATETIME(6) NOT NULL,
            expected_to DATETIME(6) NOT NULL,
            qr_token CHAR(32) NULL,
            status ENUM('invited','checked_in','checked_out','cancelled','denied','expired') NOT NULL DEFAULT 'invited',
            notice_given TINYINT(1) NOT NULL DEFAULT 0,
            badge_no VARCHAR(40) NULL,
            checked_in_at DATETIME(6) NULL,
            checked_in_by BIGINT UNSIGNED NULL,
            checked_out_at DATETIME(6) NULL,
            checked_out_by BIGINT UNSIGNED NULL,
            badge_returned TINYINT(1) NULL,
            blacklist_note VARCHAR(500) NULL,
            closed_reason VARCHAR(500) NULL,
            overdue_notified TINYINT(1) NOT NULL DEFAULT 0,
            purged_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_vs_visits_public (public_id), UNIQUE KEY uq_vs_visits_ref (legal_entity_id, ref), UNIQUE KEY uq_vs_visits_qr (qr_token),
            KEY ix_vs_visits_day (legal_entity_id, expected_from), KEY ix_vs_visits_host (host_user_id), KEY ix_vs_visits_status (status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS vs_blacklist (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            name_key VARCHAR(160) NOT NULL,
            display_name VARCHAR(160) NOT NULL,
            company VARCHAR(160) NULL,
            reason VARCHAR(500) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), UNIQUE KEY uq_vs_blacklist_public (public_id), KEY ix_vs_blacklist_key (legal_entity_id, name_key)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS vs_settings (
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            retention_days INT UNSIGNED NULL,
            overdue_minutes INT UNSIGNED NULL,
            record_vehicle TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME(6) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (legal_entity_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS vs_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            visit_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(40) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            note VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_vs_events_visit (visit_id)
        ) {$o}");
    }
};
