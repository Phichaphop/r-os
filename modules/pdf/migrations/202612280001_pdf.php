<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * S01 PDF toolkit jobs: who did what to which files (hashes, never content or passwords), the engine version, the
 * outputs (temporary, encrypted on disk, removed after the TTL) and where an output was saved as a record (6.24.3).
 */

return new class extends Migration {
    public function description(): string
    {
        return 'S01 PDF toolkit: jobs with provenance (inputs/outputs hashes, parameters, engine, warnings)';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS pdf_jobs (
            {$std},
            owner_user_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            operation VARCHAR(20) NOT NULL,
            status ENUM('staged','running','done','failed','expired') NOT NULL,
            inputs_json TEXT NOT NULL,
            params_json TEXT NULL,
            outputs_json TEXT NULL,
            warnings_json TEXT NULL,
            notes_json TEXT NULL,
            error_key VARCHAR(80) NULL,
            engine VARCHAR(40) NOT NULL,
            correlation_id VARCHAR(64) NULL,
            finished_at DATETIME(6) NULL,
            expires_at DATETIME(6) NOT NULL,
            cleaned_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pdf_jobs_public (public_id),
            KEY ix_pdf_jobs_owner (owner_user_id, created_at),
            KEY ix_pdf_jobs_expiry (cleaned_at, expires_at)
        ) {$o}");
    }
};
