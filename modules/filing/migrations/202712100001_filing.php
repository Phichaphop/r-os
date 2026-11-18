<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 6E (DEC-88): M58 government filing of payroll — one filing per company, kind (monthly withholding tax,
 * monthly social security) and month; each generation is a revision (rows kept in the Calculation Ledger, checks,
 * independent review, export hash, submission reference and receipt, the authority's result). A revision is never
 * overwritten: a later one supersedes it, or — once one was submitted — is an additional filing with a reason.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Government filing: filings, revisions, events';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS fl_filings (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('wht_monthly','sso_monthly') NOT NULL,
            period CHAR(7) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_fl_filings_public (public_id), UNIQUE KEY uq_fl_filings_month (legal_entity_id, kind, period)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS fl_revisions (
            {$std},
            filing_id BIGINT UNSIGNED NOT NULL,
            revision_no INT UNSIGNED NOT NULL,
            purpose ENUM('original','additional') NOT NULL DEFAULT 'original',
            status ENUM('validation_failed','ready','pending','returned','approved','exported','submitted','accepted','rejected','superseded') NOT NULL,
            reason VARCHAR(500) NULL,
            schema_version VARCHAR(40) NOT NULL,
            calc_run_pid CHAR(26) NOT NULL,
            source_hash CHAR(64) NOT NULL,
            people INT UNSIGNED NOT NULL DEFAULT 0,
            total_a DECIMAL(19,4) NOT NULL DEFAULT 0,
            total_b DECIMAL(19,4) NOT NULL DEFAULT 0,
            total_c DECIMAL(19,4) NOT NULL DEFAULT 0,
            error_count INT UNSIGNED NOT NULL DEFAULT 0,
            warning_count INT UNSIGNED NOT NULL DEFAULT 0,
            workflow_instance_pid CHAR(26) NULL,
            submitted_for_review_at DATETIME(6) NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME(6) NULL,
            export_sha256 CHAR(64) NULL,
            export_name VARCHAR(120) NULL,
            exported_by BIGINT UNSIGNED NULL,
            exported_at DATETIME(6) NULL,
            submitted_on DATE NULL,
            submission_ref VARCHAR(100) NULL,
            receipt_document_pid CHAR(26) NULL,
            submitted_by BIGINT UNSIGNED NULL,
            submitted_at DATETIME(6) NULL,
            result_note VARCHAR(500) NULL,
            result_by BIGINT UNSIGNED NULL,
            result_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_fl_revisions_public (public_id), UNIQUE KEY uq_fl_revisions_no (filing_id, revision_no),
            KEY ix_fl_revisions_status (status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS fl_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            revision_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(30) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            note VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_fl_events_revision (revision_id)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $missing = [];
        foreach (['fl_filings', 'fl_revisions', 'fl_events'] as $t) {
            if (!$db->tableExists($t)) {
                $missing[] = $t . ' missing';
            }
        }
        return $missing;
    }
};
