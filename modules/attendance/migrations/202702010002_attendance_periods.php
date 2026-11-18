<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * 4C (DEC-66) — closing attendance periods for payroll (P0-03 att_periods, M34 B4/B5, 6.26, 6.27):
 *  att_periods         one row per company and date range: open → pending (sent for close, locked) → finalized
 *                      (snapshot in the Calculation Ledger) → open again only through an approved reopen
 *  att_period_exports  every payroll-input file handed out (who, when, which revision, file hash) — re-exports are
 *                      allowed and always come from the same snapshot
 */
return new class extends Migration {
    public function description(): string
    {
        return 'M34 Attendance: periods (close, snapshot, reopen) and payroll-input exports';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS att_periods (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            label VARCHAR(60) NULL,
            status ENUM('open','pending','finalized','reopen_pending') NOT NULL DEFAULT 'open',
            revision INT UNSIGNED NOT NULL DEFAULT 0,
            lock_pid CHAR(26) NULL,
            review_json TEXT NULL,
            submitted_hash CHAR(64) NULL,
            submitted_by BIGINT UNSIGNED NULL,
            submitted_at DATETIME(6) NULL,
            submit_note VARCHAR(500) NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            snapshot_run_pid CHAR(26) NULL,
            output_hash CHAR(64) NULL,
            employees INT UNSIGNED NULL,
            finalized_by BIGINT UNSIGNED NULL,
            finalized_at DATETIME(6) NULL,
            reopen_reason VARCHAR(500) NULL,
            reopen_requested_by BIGINT UNSIGNED NULL,
            reopen_instance_id BIGINT UNSIGNED NULL,
            first_exported_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_att_periods_public (public_id),
            UNIQUE KEY uq_att_periods_start (legal_entity_id, period_start),
            KEY ix_att_periods_status (legal_entity_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS att_period_exports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            period_id BIGINT UNSIGNED NOT NULL,
            revision INT UNSIGNED NOT NULL,
            snapshot_run_pid CHAR(26) NOT NULL,
            format ENUM('xlsx','csv') NOT NULL,
            file_sha256 CHAR(64) NOT NULL,
            row_count INT UNSIGNED NOT NULL,
            exported_by BIGINT UNSIGNED NOT NULL,
            exported_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_att_period_exports (period_id, id),
            CONSTRAINT fk_att_period_exports FOREIGN KEY (period_id) REFERENCES att_periods (id)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        return Schema::expectTables($db, ['att_periods', 'att_period_exports']);
    }
};
