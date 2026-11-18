<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 1 platform tables (P0-03 §3):
 * - system_info      install identity / version (no secrets)
 * - i18n_messages    translation store (DEC-15) — key lengths keep every index under 767 bytes
 *                    so it also installs on older MariaDB/XAMPP setups with COMPACT row format
 * - scheduled_jobs   scheduler registry (P0-02 §8)
 * - jobs, job_attempts  job execution + evidence (6.30.6; retry/dead-letter UI in phase 3)
 */

return new class extends Migration {
    private const TABLES = ['system_info', 'i18n_messages', 'scheduled_jobs', 'jobs', 'job_attempts'];

    public function description(): string
    {
        return 'Platform base tables: system_info, i18n_messages, scheduled_jobs, jobs, job_attempts';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;

        $db->exec("CREATE TABLE IF NOT EXISTS system_info (
            info_key VARCHAR(64) NOT NULL,
            info_value VARCHAR(255) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (info_key)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS i18n_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            locale VARCHAR(10) NOT NULL,
            message_key VARCHAR(180) NOT NULL,
            message TEXT NOT NULL,
            source ENUM('seed','admin') NOT NULL DEFAULT 'seed',
            module VARCHAR(40) NOT NULL DEFAULT 'core',
            updated_at DATETIME(6) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_i18n_locale_key (locale, message_key)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS scheduled_jobs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_type VARCHAR(80) NOT NULL,
            interval_minutes INT UNSIGNED NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            next_run_at DATETIME(6) NOT NULL,
            last_run_at DATETIME(6) NULL,
            last_status VARCHAR(20) NULL,
            locked_until DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_scheduled_jobs_type (job_type),
            KEY ix_scheduled_jobs_due (enabled, next_run_at)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS jobs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            job_type VARCHAR(80) NOT NULL,
            payload_json JSON NULL,
            status ENUM('queued','running','succeeded','failed','dead') NOT NULL DEFAULT 'queued',
            progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            trigger_source ENUM('piggyback','cron','manual','cli') NOT NULL,
            correlation_id CHAR(26) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            started_at DATETIME(6) NULL,
            finished_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_jobs_public_id (public_id),
            KEY ix_jobs_type_status (job_type, status)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS job_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id BIGINT UNSIGNED NOT NULL,
            attempt_no INT UNSIGNED NOT NULL,
            status ENUM('succeeded','failed') NOT NULL,
            message TEXT NULL,
            duration_ms INT UNSIGNED NOT NULL,
            started_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_job_attempts_job (job_id),
            CONSTRAINT fk_job_attempts_job FOREIGN KEY (job_id) REFERENCES jobs (id)
        ) {$o}");
    }

    public function postCheck(Connection $db): array
    {
        $problems = Schema::expectTables($db, self::TABLES);
        $collation = $db->scalar(
            "SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'i18n_messages'"
        );
        if (!is_string($collation) || !str_starts_with($collation, 'utf8mb4')) {
            $problems[] = 'i18n_messages is not utf8mb4 (Thai text would break)';
        }
        return $problems;
    }

    public function recovery(): string
    {
        return 'rollback';
    }

    public function down(Connection $db): void
    {
        foreach (array_reverse(self::TABLES) as $t) {
            $db->exec("DROP TABLE IF EXISTS `{$t}`");
        }
    }
};
