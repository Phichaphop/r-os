<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M02 Central forms (phase 5D, DEC-72):
 *   frm_forms     one official form of a company: code, title, category, language, owner, target group, dates
 *   frm_versions  each uploaded file is a version (stored in Documents, class forms.file); draft → review (another
 *                 person approves) → published; the previous published version becomes superseded and is never edited
 *   frm_events    download / preview / send-to-sign of a version by a user (the file URL is never exposed)
 *   frm_stars     forms a user starred (and gets told about new versions)
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M02 central forms: forms, versions with approval, download log, stars';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS frm_forms (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(30) NOT NULL,
            title VARCHAR(200) NOT NULL,
            description VARCHAR(500) NULL,
            category VARCHAR(60) NOT NULL,
            language ENUM('th','en','both') NOT NULL DEFAULT 'th',
            owner_user_id BIGINT UNSIGNED NOT NULL,
            audience_json TEXT NOT NULL,
            starts_on DATE NULL,
            ends_on DATE NULL,
            status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
            current_version_id BIGINT UNSIGNED NULL,
            download_count INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_frm_forms_public (public_id),
            UNIQUE KEY uq_frm_forms_code (legal_entity_id, code),
            KEY ix_frm_forms_status (legal_entity_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS frm_versions (
            {$std},
            form_id BIGINT UNSIGNED NOT NULL,
            version_no SMALLINT UNSIGNED NOT NULL,
            document_pid CHAR(26) NOT NULL,
            file_name VARCHAR(200) NOT NULL,
            mime VARCHAR(100) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL,
            change_note VARCHAR(500) NULL,
            status ENUM('draft','review','published','superseded','rejected') NOT NULL DEFAULT 'draft',
            workflow_instance_id BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            published_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_frm_versions_public (public_id),
            UNIQUE KEY uq_frm_versions_no (form_id, version_no)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS frm_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT UNSIGNED NOT NULL,
            version_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            event ENUM('download','preview','sign') NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_frm_events_form (form_id, created_at),
            KEY ix_frm_events_user (user_id, created_at)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS frm_stars (
            user_id BIGINT UNSIGNED NOT NULL,
            form_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (user_id, form_id)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $out = [];
        foreach (['frm_forms', 'frm_versions', 'frm_events', 'frm_stars'] as $t) {
            if (!$db->tableExists($t)) {
                $out[] = $t . ' missing';
            }
        }
        return $out;
    }
};
