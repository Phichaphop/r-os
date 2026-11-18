<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M56 Job descriptions (phase 5E-2, DEC-77): a JD per position title of a company (optionally one unit), versions
 * (draft → review → approved → effective → superseded) with Thai/English content, and each employee's
 * acknowledgement of the exact version of their own position.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M56 Job descriptions: positions, versions, acknowledgements';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS jd_profiles (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            department_id BIGINT UNSIGNED NULL,
            code VARCHAR(30) NOT NULL,
            position_title VARCHAR(120) NOT NULL,
            title_key VARCHAR(120) NOT NULL,
            owner_user_id BIGINT UNSIGNED NOT NULL,
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            current_version_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_jd_public (public_id), UNIQUE KEY uq_jd_code (legal_entity_id, code),
            KEY ix_jd_title (legal_entity_id, title_key)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS jd_versions (
            {$std},
            profile_id BIGINT UNSIGNED NOT NULL,
            version_no INT UNSIGNED NOT NULL,
            content_json MEDIUMTEXT NOT NULL,
            content_sha256 CHAR(64) NOT NULL,
            change_summary VARCHAR(500) NOT NULL DEFAULT '',
            effective_from DATE NOT NULL,
            status ENUM('draft','review','approved','effective','superseded','rejected','withdrawn') NOT NULL DEFAULT 'draft',
            workflow_instance_id BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME(6) NULL,
            effective_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_jd_ver_public (public_id), UNIQUE KEY uq_jd_ver_no (profile_id, version_no), KEY ix_jd_ver_status (status, effective_from),
            CONSTRAINT fk_jd_ver_profile FOREIGN KEY (profile_id) REFERENCES jd_profiles (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS jd_acks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            version_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            status ENUM('pending','acknowledged','exception') NOT NULL DEFAULT 'pending',
            assigned_at DATETIME(6) NOT NULL,
            opened_at DATETIME(6) NULL,
            acknowledged_at DATETIME(6) NULL,
            statement VARCHAR(500) NULL,
            content_sha256 CHAR(64) NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(200) NULL,
            exception_reason VARCHAR(300) NULL,
            exception_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_jd_ack (version_id, employee_id), KEY ix_jd_ack_emp (employee_id, status),
            CONSTRAINT fk_jd_ack_version FOREIGN KEY (version_id) REFERENCES jd_versions (id)
        ) {$o}");
    }

    public function postCheck(Connection $db): array
    {
        return Schema::expectTables($db, ['jd_profiles', 'jd_versions', 'jd_acks']);
    }
};
