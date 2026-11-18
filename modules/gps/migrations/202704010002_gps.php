<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M57 GPS check-in/out (phase 5C, DEC-71):
 *   gps_geofences  check-in points of a company (optionally one branch): centre + radius in metres
 *   gps_notices    the employee acknowledged the company's location notice (one row per policy version)
 *   gps_punches    one check-in or check-out: device time, server time, location + accuracy, the nearest point and
 *                  the distance, flags, review, hand-over to attendance. Coordinates / IP / device are cleared by the
 *                  retention job (record class gps_selfie) — the in/out-of-area result stays.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M57 GPS check-in: geofences, notice acknowledgements, punches (review, hand-over to attendance, retention)';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS gps_geofences (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            name VARCHAR(120) NOT NULL,
            lat DECIMAL(9,6) NOT NULL,
            lng DECIMAL(9,6) NOT NULL,
            radius_m INT UNSIGNED NOT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_gps_geofences_public (public_id),
            KEY ix_gps_geofences_le (legal_entity_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS gps_notices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            policy_version_pid CHAR(26) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            acknowledged_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_gps_notices (employee_id, policy_version_pid)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS gps_punches (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            department_id BIGINT UNSIGNED NULL,
            direction ENUM('in','out') NOT NULL,
            client_event_id VARCHAR(40) NOT NULL,
            captured_at_utc DATETIME NOT NULL,
            received_at_utc DATETIME NOT NULL,
            punch_at_utc DATETIME NOT NULL,
            local_time CHAR(19) NOT NULL,
            work_date DATE NOT NULL,
            offline TINYINT(1) NOT NULL DEFAULT 0,
            lat DECIMAL(9,6) NULL,
            lng DECIMAL(9,6) NULL,
            accuracy_m INT UNSIGNED NULL,
            geofence_id BIGINT UNSIGNED NULL,
            distance_m INT UNSIGNED NULL,
            inside TINYINT(1) NULL,
            flags VARCHAR(120) NOT NULL DEFAULT '',
            status ENUM('validated','flagged','approved','rejected') NOT NULL,
            employee_note VARCHAR(300) NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME(6) NULL,
            review_note VARCHAR(300) NULL,
            attendance_event_id VARCHAR(80) NULL,
            attendance_sent_at DATETIME(6) NULL,
            ip VARCHAR(45) NULL,
            device VARCHAR(120) NULL,
            policy_version_pid CHAR(26) NULL,
            anonymized_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_gps_punches_public (public_id),
            UNIQUE KEY uq_gps_punches_event (employee_id, client_event_id),
            KEY ix_gps_punches_emp (employee_id, punch_at_utc),
            KEY ix_gps_punches_review (legal_entity_id, status),
            KEY ix_gps_punches_date (legal_entity_id, work_date)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $out = [];
        foreach (['gps_geofences', 'gps_notices', 'gps_punches'] as $t) {
            if (!$db->tableExists($t)) {
                $out[] = $t . ' missing';
            }
        }
        return $out;
    }
};
