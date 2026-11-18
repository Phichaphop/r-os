<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 9A (DEC-89): M04 meeting rooms — rooms with their booking rules (hours, days, buffer, longest booking, how far
 * ahead, check-in), closures, bookings first-come-first-served without approval (one-off or weekly), check-in and
 * no-show release, the booking history.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Meeting rooms: rooms, closures, series, bookings, events';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS rm_rooms (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            code VARCHAR(20) NOT NULL,
            name VARCHAR(120) NOT NULL,
            capacity SMALLINT UNSIGNED NOT NULL,
            equipment VARCHAR(500) NULL,
            location VARCHAR(200) NULL,
            open_from TIME NOT NULL,
            open_to TIME NOT NULL,
            open_days VARCHAR(20) NOT NULL,
            buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            max_minutes SMALLINT UNSIGNED NOT NULL,
            advance_days SMALLINT UNSIGNED NOT NULL,
            checkin_required TINYINT(1) NOT NULL DEFAULT 0,
            release_minutes SMALLINT UNSIGNED NULL,
            status ENUM('active','closed') NOT NULL DEFAULT 'active',
            closed_note VARCHAR(300) NULL,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_rm_rooms_public (public_id), UNIQUE KEY uq_rm_rooms_code (legal_entity_id, code), KEY ix_rm_rooms_site (legal_entity_id, site_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rm_closures (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            room_id BIGINT UNSIGNED NOT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            reason VARCHAR(300) NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            removed_at DATETIME(6) NULL,
            PRIMARY KEY (id), KEY ix_rm_closures_room (room_id, starts_at)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rm_series (
            {$std},
            room_id BIGINT UNSIGNED NOT NULL,
            booked_by BIGINT UNSIGNED NOT NULL,
            every_weeks TINYINT UNSIGNED NOT NULL,
            until_date DATE NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_rm_series_public (public_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rm_bookings (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            room_id BIGINT UNSIGNED NOT NULL,
            series_id BIGINT UNSIGNED NULL,
            booked_by BIGINT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            attendee_count SMALLINT UNSIGNED NOT NULL,
            attendees_json TEXT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            status ENUM('confirmed','checked_in','completed','cancelled','no_show') NOT NULL DEFAULT 'confirmed',
            checked_in_at DATETIME(6) NULL,
            cancelled_at DATETIME(6) NULL,
            cancelled_by BIGINT UNSIGNED NULL,
            cancel_reason VARCHAR(300) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_rm_bookings_public (public_id), KEY ix_rm_bookings_room (room_id, starts_at), KEY ix_rm_bookings_user (booked_by, starts_at),
            KEY ix_rm_bookings_status (status, starts_at)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rm_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(30) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            note VARCHAR(300) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_rm_events_booking (booking_id)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $missing = [];
        foreach (['rm_rooms', 'rm_closures', 'rm_series', 'rm_bookings', 'rm_events'] as $t) {
            if (!$db->tableExists($t)) {
                $missing[] = $t . ' missing';
            }
        }
        return $missing;
    }
};
