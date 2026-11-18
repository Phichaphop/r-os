<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M45 Birthdays & work anniversaries (phase 5E-2, DEC-77): company settings (off until the company turns them on
 * with its notice), employee opt-outs, calculated events (day and month only — never a birth year or age) and wishes.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M45 Birthdays and work anniversaries: settings, opt-outs, events, wishes';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $db->exec("CREATE TABLE IF NOT EXISTS cel_settings (
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            birthdays_enabled TINYINT(1) NOT NULL DEFAULT 0,
            anniversaries_enabled TINYINT(1) NOT NULL DEFAULT 0,
            notice_text TEXT NULL,
            notice_version INT UNSIGNED NOT NULL DEFAULT 0,
            notify_manager TINYINT(1) NOT NULL DEFAULT 1,
            notify_hr TINYINT(1) NOT NULL DEFAULT 1,
            board_scope ENUM('company','department') NOT NULL DEFAULT 'company',
            milestones VARCHAR(60) NOT NULL DEFAULT '5,10,15,20,25,30',
            updated_at DATETIME(6) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (legal_entity_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS cel_optouts (
            employee_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('birthday','anniversary') NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (employee_id, kind)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS cel_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('birthday','anniversary') NOT NULL,
            event_date DATE NOT NULL,
            years SMALLINT UNSIGNED NULL,
            status ENUM('scheduled','sent','cancelled','archived') NOT NULL DEFAULT 'scheduled',
            sent_at DATETIME(6) NULL,
            cancel_reason VARCHAR(300) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_cel_public (public_id), UNIQUE KEY uq_cel_event (employee_id, kind, event_date), KEY ix_cel_le (legal_entity_id, event_date, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS cel_wishes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            from_user_id BIGINT UNSIGNED NOT NULL,
            message VARCHAR(300) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_cel_wish (event_id, from_user_id),
            CONSTRAINT fk_cel_wish_event FOREIGN KEY (event_id) REFERENCES cel_events (id)
        ) {$o}");
    }

    public function postCheck(Connection $db): array
    {
        return Schema::expectTables($db, ['cel_settings', 'cel_optouts', 'cel_events', 'cel_wishes']);
    }
};
