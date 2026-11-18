<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M30 Company fonts: font families (licence/source/proof, usage guidance, Draft → Published → Deprecated/Archived),
 * their files (hash, version, replacement history — never deleted) and an event history (7C ข้อ 30).
 * The files themselves are documents (classes fonts.file / fonts.license) in the shared document store.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M30 Company fonts: families + files (hash/version/replacement) + event history';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS font_families (
            {$std},
            name VARCHAR(120) NOT NULL,
            category VARCHAR(20) NOT NULL,
            description TEXT NULL,
            dos TEXT NULL,
            donts TEXT NULL,
            license_name VARCHAR(120) NULL,
            license_source VARCHAR(255) NULL,
            license_terms TEXT NULL,
            license_doc_pid CHAR(26) NULL,
            restricted TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('draft','published','deprecated','archived') NOT NULL DEFAULT 'draft',
            status_note VARCHAR(300) NULL,
            replacement_family_id BIGINT UNSIGNED NULL,
            published_at DATETIME(6) NULL,
            published_by BIGINT UNSIGNED NULL,
            download_count INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_font_families_public (public_id),
            UNIQUE KEY uq_font_families_name (name),
            KEY ix_font_families_status (status, category)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS font_files (
            {$std},
            family_id BIGINT UNSIGNED NOT NULL,
            document_pid CHAR(26) NOT NULL,
            original_name VARCHAR(200) NOT NULL,
            sha256 CHAR(64) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            format VARCHAR(8) NOT NULL,
            font_family VARCHAR(120) NULL,
            style_name VARCHAR(80) NULL,
            weight SMALLINT UNSIGNED NULL,
            italic TINYINT(1) NOT NULL DEFAULT 0,
            font_version VARCHAR(80) NULL,
            embedding VARCHAR(20) NULL,
            status ENUM('active','replaced') NOT NULL DEFAULT 'active',
            replaced_by_file_id BIGINT UNSIGNED NULL,
            replaced_at DATETIME(6) NULL,
            download_count INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_font_files_public (public_id),
            UNIQUE KEY uq_font_files_sha (sha256),
            KEY ix_font_files_family (family_id, status),
            CONSTRAINT fk_font_files_family FOREIGN KEY (family_id) REFERENCES font_families (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS font_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(30) NOT NULL,
            detail_json TEXT NULL,
            actor_id BIGINT UNSIGNED NULL,
            occurred_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_font_events_family (family_id, id),
            CONSTRAINT fk_font_events_family FOREIGN KEY (family_id) REFERENCES font_families (id)
        ) {$o}");
    }
};
