<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 9A-2 (DEC-90): M05 vehicle booking — vehicle types, vehicles, drivers (licence expiry and working hours, no
 * licence number), saved places, the company policy (requester confirms the trip, how long locations are kept), the
 * map settings (tiles, optional road-distance service with an encrypted key), bookings with route, passengers, the
 * dispatch, the trip log and the history.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Vehicle booking: types, vehicles, drivers, places, policies, map settings, bookings, passengers, events';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS vh_types (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(80) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_vh_types_public (public_id), UNIQUE KEY uq_vh_types_name (legal_entity_id, name)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS vh_vehicles (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            type_id BIGINT UNSIGNED NULL,
            plate VARCHAR(20) NOT NULL,
            name VARCHAR(120) NOT NULL,
            seats SMALLINT UNSIGNED NOT NULL,
            odometer_km INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('available','maintenance','inactive') NOT NULL DEFAULT 'available',
            status_note VARCHAR(300) NULL,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_vh_vehicles_public (public_id), UNIQUE KEY uq_vh_vehicles_plate (legal_entity_id, plate)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS vh_drivers (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            name VARCHAR(120) NOT NULL,
            phone VARCHAR(30) NULL,
            user_id BIGINT UNSIGNED NULL,
            licence_class VARCHAR(60) NULL,
            licence_expiry DATE NOT NULL,
            work_from TIME NOT NULL,
            work_to TIME NOT NULL,
            work_days VARCHAR(20) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_vh_drivers_public (public_id), KEY ix_vh_drivers_user (user_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS vh_places (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(150) NOT NULL,
            address VARCHAR(300) NULL,
            lat DECIMAL(9,6) NOT NULL,
            lng DECIMAL(9,6) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), UNIQUE KEY uq_vh_places_public (public_id), KEY ix_vh_places_le (legal_entity_id, active)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS vh_policies (
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            confirm_required TINYINT(1) NOT NULL DEFAULT 0,
            location_keep_days SMALLINT UNSIGNED NULL,
            updated_at DATETIME(6) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (legal_entity_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS vh_settings (
            id TINYINT UNSIGNED NOT NULL,
            map_enabled TINYINT(1) NOT NULL DEFAULT 1,
            tile_url VARCHAR(300) NOT NULL,
            tile_attribution VARCHAR(300) NOT NULL,
            routing ENUM('none','ors') NOT NULL DEFAULT 'none',
            ors_key_enc VARBINARY(1024) NULL,
            updated_at DATETIME(6) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS vh_bookings (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NOT NULL,
            ref VARCHAR(20) NOT NULL,
            requester_id BIGINT UNSIGNED NOT NULL,
            status ENUM('draft','pending','approved','assigned','in_progress','completed','rejected','cancelled','no_show') NOT NULL DEFAULT 'draft',
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            purpose VARCHAR(300) NOT NULL,
            passenger_count SMALLINT UNSIGNED NOT NULL,
            from_name VARCHAR(150) NOT NULL,
            from_lat DECIMAL(9,6) NULL,
            from_lng DECIMAL(9,6) NULL,
            from_link VARCHAR(500) NULL,
            to_name VARCHAR(150) NOT NULL,
            to_lat DECIMAL(9,6) NULL,
            to_lng DECIMAL(9,6) NULL,
            to_link VARCHAR(500) NULL,
            round_trip TINYINT(1) NOT NULL DEFAULT 0,
            note VARCHAR(500) NULL,
            est_km DECIMAL(8,1) NULL,
            est_minutes INT UNSIGNED NULL,
            est_source ENUM('straight','road','manual') NULL,
            straight_km DECIMAL(8,1) NULL,
            workflow_id CHAR(26) NULL,
            vehicle_id BIGINT UNSIGNED NULL,
            driver_id BIGINT UNSIGNED NULL,
            self_drive TINYINT(1) NOT NULL DEFAULT 0,
            assigned_at DATETIME(6) NULL,
            assigned_by BIGINT UNSIGNED NULL,
            trip_started_at DATETIME NULL,
            trip_ended_at DATETIME NULL,
            odo_start INT UNSIGNED NULL,
            odo_end INT UNSIGNED NULL,
            fuel_litres DECIMAL(8,2) NULL,
            fuel_cost DECIMAL(14,2) NULL,
            other_cost DECIMAL(14,2) NULL,
            incident VARCHAR(500) NULL,
            trip_by BIGINT UNSIGNED NULL,
            confirmed_at DATETIME(6) NULL,
            cancel_reason VARCHAR(300) NULL,
            location_purged_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_vh_bookings_public (public_id), UNIQUE KEY uq_vh_bookings_ref (legal_entity_id, ref),
            KEY ix_vh_bookings_req (requester_id, starts_at), KEY ix_vh_bookings_vehicle (vehicle_id, starts_at), KEY ix_vh_bookings_driver (driver_id, starts_at),
            KEY ix_vh_bookings_status (legal_entity_id, status, starts_at)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS vh_passengers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NULL,
            name VARCHAR(120) NOT NULL,
            PRIMARY KEY (id), KEY ix_vh_passengers_booking (booking_id), KEY ix_vh_passengers_emp (employee_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS vh_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(30) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            note VARCHAR(300) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_vh_events_booking (booking_id)
        ) {$o}");
        if ($db->scalar('SELECT id FROM vh_settings WHERE id = 1') === null) {
            // the map the person chose (DEC-90): OpenStreetMap tiles with their attribution, distance as a straight line
            $db->run(
                "INSERT INTO vh_settings (id, map_enabled, tile_url, tile_attribution, routing, updated_at) VALUES (1, 1, ?, ?, 'none', UTC_TIMESTAMP(6))",
                ['https://tile.openstreetmap.org/{z}/{x}/{y}.png', '© OpenStreetMap contributors']
            );
        }
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $missing = [];
        foreach (['vh_types', 'vh_vehicles', 'vh_drivers', 'vh_places', 'vh_policies', 'vh_settings', 'vh_bookings', 'vh_passengers', 'vh_events'] as $t) {
            if (!$db->tableExists($t)) {
                $missing[] = $t . ' missing';
            }
        }
        return $missing;
    }
};
