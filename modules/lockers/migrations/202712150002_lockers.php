<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 9A (DEC-89): M07 lockers — zones laid out as a grid, lockers with their status, who holds which locker
 * (reserved without approval → key handed over by the workplace admin → returned), what happens when the holder leaves.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Lockers: zones, lockers, assignments, events';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS lk_zones (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            code VARCHAR(20) NOT NULL,
            name VARCHAR(120) NOT NULL,
            grid_rows TINYINT UNSIGNED NOT NULL,
            grid_cols TINYINT UNSIGNED NOT NULL,
            hold_days SMALLINT UNSIGNED NOT NULL,
            note VARCHAR(300) NULL,
            status ENUM('active','closed') NOT NULL DEFAULT 'active',
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_lk_zones_public (public_id), UNIQUE KEY uq_lk_zones_code (legal_entity_id, code)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS lk_lockers (
            {$std},
            zone_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(20) NOT NULL,
            row_no TINYINT UNSIGNED NOT NULL,
            col_no TINYINT UNSIGNED NOT NULL,
            size ENUM('S','M','L') NOT NULL DEFAULT 'M',
            status ENUM('available','reserved','assigned','return_pending','maintenance','blocked') NOT NULL DEFAULT 'available',
            status_note VARCHAR(300) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_lk_lockers_public (public_id), UNIQUE KEY uq_lk_lockers_code (zone_id, code), UNIQUE KEY uq_lk_lockers_pos (zone_id, row_no, col_no)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS lk_assignments (
            {$std},
            locker_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            status ENUM('reserved','assigned','return_pending','returned','cancelled','expired') NOT NULL DEFAULT 'reserved',
            hold_until DATE NOT NULL,
            key_no VARCHAR(40) NULL,
            key_issued_at DATETIME(6) NULL,
            key_issued_by BIGINT UNSIGNED NULL,
            received_ack_at DATETIME(6) NULL,
            return_reason VARCHAR(30) NULL,
            returned_at DATETIME(6) NULL,
            returned_by BIGINT UNSIGNED NULL,
            key_returned TINYINT(1) NULL,
            locker_condition ENUM('good','damaged') NULL,
            return_note VARCHAR(300) NULL,
            cancel_reason VARCHAR(300) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_lk_assign_public (public_id), KEY ix_lk_assign_locker (locker_id, status), KEY ix_lk_assign_emp (employee_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS lk_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            locker_id BIGINT UNSIGNED NOT NULL,
            assignment_id BIGINT UNSIGNED NULL,
            event VARCHAR(30) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            note VARCHAR(300) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_lk_events_locker (locker_id)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $missing = [];
        foreach (['lk_zones', 'lk_lockers', 'lk_assignments', 'lk_events'] as $t) {
            if (!$db->tableExists($t)) {
                $missing[] = $t . ' missing';
            }
        }
        return $missing;
    }
};
