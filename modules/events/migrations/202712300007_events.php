<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 9D-2 (DEC-98): M50 events — events (audience, quota, waitlist, walk-in, one choice such as T-shirt size),
 * registrations with check-in (method, who, when, walk-in) and RFID card IDs paired to employees at the check-in desk
 * (only a hash of the card ID is kept).
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Events: events, registrations, RFID card pairing';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS ev_events (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(160) NOT NULL,
            kind VARCHAR(60) NULL,
            description TEXT NULL,
            venue VARCHAR(200) NOT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            reg_until DATETIME NOT NULL,
            quota INT UNSIGNED NULL,
            waitlist TINYINT(1) NOT NULL DEFAULT 0,
            walk_in TINYINT(1) NOT NULL DEFAULT 0,
            audience_json VARCHAR(2000) NOT NULL,
            option_label VARCHAR(60) NULL,
            options VARCHAR(1000) NULL,
            cost_note VARCHAR(300) NULL,
            status ENUM('draft','published','cancelled','completed') NOT NULL DEFAULT 'draft',
            cancel_reason VARCHAR(500) NULL,
            reminded TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_ev_events_public (public_id), KEY ix_ev_events_status (legal_entity_id, status, starts_at)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ev_regs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            choice VARCHAR(120) NULL,
            status ENUM('registered','waitlisted','cancelled','checked_in') NOT NULL,
            walk_in TINYINT(1) NOT NULL DEFAULT 0,
            registered_at DATETIME(6) NOT NULL,
            cancelled_at DATETIME(6) NULL,
            checked_in_at DATETIME(6) NULL,
            checked_in_by BIGINT UNSIGNED NULL,
            check_method ENUM('code','name','card','qr') NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_ev_regs_person (event_id, employee_id), KEY ix_ev_regs_status (event_id, status, id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ev_cards (
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            card_hash CHAR(64) NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            paired_by BIGINT UNSIGNED NOT NULL,
            paired_at DATETIME(6) NOT NULL,
            PRIMARY KEY (legal_entity_id, card_hash), KEY ix_ev_cards_employee (employee_id)
        ) {$o}");
    }
};
