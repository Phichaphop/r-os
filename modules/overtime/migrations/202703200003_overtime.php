<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M33 OT Request (phase 5B, DEC-70):
 *   ot_requests        one employee, one stretch of overtime on a work date: requested window, day type (from the
 *                      calendar + shift), approval, actual minutes from the scans, payable minutes certified by the
 *                      verifier, and the payroll batch it went out in. Money is never calculated here.
 *   ot_batches         payroll hand-over per company and date range: frozen lines (JSON) + control totals + hash;
 *                      a line goes out in one batch only
 *   ot_batch_exports   every download of a batch file
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M33 overtime: requests (approve → verify → payroll batch), batches with control totals, export log';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS ot_requests (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            department_id BIGINT UNSIGNED NULL,
            cost_center_id BIGINT UNSIGNED NULL,
            group_pid CHAR(26) NULL,
            work_date DATE NOT NULL,
            start_local CHAR(5) NOT NULL,
            end_local CHAR(5) NOT NULL,
            start_utc DATETIME NOT NULL,
            end_utc DATETIME NOT NULL,
            minutes_requested SMALLINT UNSIGNED NOT NULL,
            day_type ENUM('workday','dayoff','holiday','unknown') NOT NULL,
            shift_code VARCHAR(20) NULL,
            reason VARCHAR(500) NOT NULL,
            status ENUM('pending','returned','approved','rejected','cancelled','disputed','verified','sent') NOT NULL DEFAULT 'pending',
            cap_warning TINYINT(1) NOT NULL DEFAULT 0,
            rules_version_pid CHAR(26) NULL,
            requested_by BIGINT UNSIGNED NOT NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME(6) NULL,
            actual_minutes SMALLINT UNSIGNED NULL,
            payable_minutes SMALLINT UNSIGNED NULL,
            verified_by BIGINT UNSIGNED NULL,
            verified_at DATETIME(6) NULL,
            verify_note VARCHAR(500) NULL,
            batch_id BIGINT UNSIGNED NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ot_requests_public (public_id),
            KEY ix_ot_requests_emp (employee_id, work_date),
            KEY ix_ot_requests_le (legal_entity_id, status, work_date),
            KEY ix_ot_requests_batch (batch_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ot_batches (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            batch_no INT UNSIGNED NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            line_count INT UNSIGNED NOT NULL,
            total_minutes INT UNSIGNED NOT NULL,
            totals_json TEXT NOT NULL,
            lines_json MEDIUMTEXT NOT NULL,
            lines_hash CHAR(64) NOT NULL,
            note VARCHAR(255) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ot_batches_public (public_id),
            UNIQUE KEY uq_ot_batches_no (legal_entity_id, batch_no)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ot_batch_exports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id BIGINT UNSIGNED NOT NULL,
            format ENUM('xlsx','csv') NOT NULL,
            file_sha256 CHAR(64) NOT NULL,
            exported_by BIGINT UNSIGNED NOT NULL,
            exported_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_ot_batch_exports (batch_id)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $out = [];
        foreach (['ot_requests', 'ot_batches', 'ot_batch_exports'] as $t) {
            if (!$db->tableExists($t)) {
                $out[] = $t . ' missing';
            }
        }
        return $out;
    }
};
