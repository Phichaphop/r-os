<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M34 Attendance (P0-03 §5, DEC-64):
 *  att_devices      time clocks seen through the Time Collector (site assigned by HR/IT)
 *  att_device_users device user code → employee, effective-dated (who is "000104" on the clock)
 *  att_pull_runs    one row per pull from the Punch API (cursor, counts, result)
 *  att_raw_punches  APPEND-ONLY raw punches from the API or the BLUE_PUNCH_IMPORT_XLSX_v1 file — never edited or deleted;
 *                   no biometric data (DEC-25); unique (source, source_event_id) stops duplicates
 *  att_corrections  correction requests (forgot to scan, wrong time) — approved ones change the DAY result, never the raw punch
 *  att_days         daily result (first in, last out, worked/late/early minutes, status) — a projection that can be
 *                   rebuilt at any time from raw punches + approved corrections + work schedule + calendar + leave
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M34 Attendance: devices, device users, pull runs, raw punches, corrections, daily results';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS att_devices (
            {$std},
            device_code VARCHAR(40) NOT NULL,
            serial_no VARCHAR(40) NULL,
            model VARCHAR(40) NULL,
            name VARCHAR(80) NULL,
            site_id BIGINT UNSIGNED NULL,
            timezone VARCHAR(40) NULL,
            status ENUM('new','active','retired') NOT NULL DEFAULT 'new',
            last_seen_at DATETIME(6) NULL,
            clock_drift_seconds INT NULL,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_att_devices_public (public_id),
            UNIQUE KEY uq_att_devices_code (device_code)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS att_device_users (
            {$std},
            device_id BIGINT UNSIGNED NULL,
            device_user_code VARCHAR(32) NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            valid_from DATE NOT NULL,
            valid_to DATE NULL,
            status ENUM('active','ended') NOT NULL DEFAULT 'active',
            note VARCHAR(200) NULL,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_att_device_users_public (public_id),
            KEY ix_att_device_users_code (device_user_code, valid_from),
            KEY ix_att_device_users_employee (employee_id),
            CONSTRAINT fk_att_device_users_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS att_pull_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            started_at DATETIME(6) NOT NULL,
            finished_at DATETIME(6) NULL,
            `trigger` ENUM('schedule','manual') NOT NULL,
            triggered_by BIGINT UNSIGNED NULL,
            cursor_from VARCHAR(200) NULL,
            cursor_to VARCHAR(200) NULL,
            pages INT NOT NULL DEFAULT 0,
            fetched INT NOT NULL DEFAULT 0,
            inserted INT NOT NULL DEFAULT 0,
            duplicates INT NOT NULL DEFAULT 0,
            invalid INT NOT NULL DEFAULT 0,
            unmapped INT NOT NULL DEFAULT 0,
            status ENUM('running','success','partial','failed') NOT NULL DEFAULT 'running',
            http_status SMALLINT NULL,
            error_code VARCHAR(60) NULL,
            error_summary VARCHAR(300) NULL,
            correlation_id CHAR(26) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_att_pull_runs_public (public_id),
            KEY ix_att_pull_runs_started (started_at)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS att_raw_punches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source ENUM('api','file') NOT NULL,
            source_event_id VARCHAR(80) NOT NULL,
            device_id BIGINT UNSIGNED NULL,
            device_code VARCHAR(40) NULL,
            device_user_code VARCHAR(32) NULL,
            employee_id BIGINT UNSIGNED NULL,
            occurred_at_utc DATETIME NOT NULL,
            device_local_time VARCHAR(19) NULL,
            device_timezone VARCHAR(40) NULL,
            verify_method ENUM('finger','face','card','password','file','unknown') NOT NULL,
            direction ENUM('in','out') NULL,
            note VARCHAR(200) NULL,
            pull_run_id BIGINT UNSIGNED NULL,
            import_batch_id BIGINT UNSIGNED NULL,
            collected_at DATETIME NULL,
            received_at DATETIME(6) NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_att_raw_punches_event (source, source_event_id),
            KEY ix_att_raw_punches_code (device_user_code, occurred_at_utc),
            KEY ix_att_raw_punches_employee (employee_id, occurred_at_utc),
            KEY ix_att_raw_punches_run (pull_run_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS att_corrections (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            department_id BIGINT UNSIGNED NULL,
            work_date DATE NOT NULL,
            kind ENUM('missing_in','missing_out','wrong_time','other') NOT NULL,
            proposed_in TIME NULL,
            proposed_out TIME NULL,
            before_in DATETIME NULL,
            before_out DATETIME NULL,
            reason VARCHAR(500) NOT NULL,
            document_pid CHAR(26) NULL,
            status ENUM('pending','approved','returned','rejected','cancelled') NOT NULL DEFAULT 'pending',
            requested_by BIGINT UNSIGNED NOT NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            decided_at DATETIME(6) NULL,
            decided_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_att_corrections_public (public_id),
            KEY ix_att_corrections_day (employee_id, work_date, status),
            KEY ix_att_corrections_scope (legal_entity_id, site_id, work_date),
            CONSTRAINT fk_att_corrections_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS att_days (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            work_date DATE NOT NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            site_id BIGINT UNSIGNED NULL,
            department_id BIGINT UNSIGNED NULL,
            day_type VARCHAR(20) NOT NULL,
            day_name VARCHAR(120) NULL,
            leave_part ENUM('full','am','pm') NULL,
            leave_type VARCHAR(80) NULL,
            first_in DATETIME NULL,
            last_out DATETIME NULL,
            in_source ENUM('punch','correction') NULL,
            out_source ENUM('punch','correction') NULL,
            punch_count INT NOT NULL DEFAULT 0,
            worked_minutes INT NULL,
            late_minutes INT NULL,
            early_minutes INT NULL,
            status VARCHAR(20) NOT NULL,
            schedule_version_id BIGINT UNSIGNED NULL,
            calc_version VARCHAR(20) NOT NULL,
            calculated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_att_days_day (employee_id, work_date),
            KEY ix_att_days_scope (legal_entity_id, site_id, work_date),
            KEY ix_att_days_status (work_date, status),
            CONSTRAINT fk_att_days_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $missing = [];
        foreach (['att_devices', 'att_device_users', 'att_pull_runs', 'att_raw_punches', 'att_corrections', 'att_days'] as $t) {
            if (!$db->tableExists($t)) {
                $missing[] = $t . ' missing';
            }
        }
        return $missing;
    }
};
