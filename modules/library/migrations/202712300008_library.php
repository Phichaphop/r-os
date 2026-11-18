<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 9D-3 (DEC-99): M03 online library — categories, items (digital files or physical books), file versions with a
 * per-page text index for searching inside PDFs, each reader's own last page and bookmarks, daily view/download counts,
 * physical copies and the reservation/loan queue.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Library: items, file versions, text index, reading place, bookmarks, copies, loans';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS lb_categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            sort INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_lb_categories_public (public_id), UNIQUE KEY uq_lb_categories_name (legal_entity_id, name)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS lb_items (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NULL,
            kind ENUM('digital','physical') NOT NULL,
            title VARCHAR(200) NOT NULL,
            author VARCHAR(200) NULL,
            publisher VARCHAR(200) NULL,
            pub_year SMALLINT UNSIGNED NULL,
            language ENUM('th','en','other') NOT NULL,
            description TEXT NULL,
            tags VARCHAR(300) NULL,
            rights_holder VARCHAR(200) NULL,
            license VARCHAR(300) NULL,
            audience_json VARCHAR(2000) NOT NULL,
            read_only TINYINT(1) NOT NULL DEFAULT 0,
            loan_days SMALLINT UNSIGNED NULL,
            pickup_days SMALLINT UNSIGNED NULL,
            status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
            archive_reason VARCHAR(500) NULL,
            published_at DATETIME(6) NULL,
            published_by BIGINT UNSIGNED NULL,
            views INT UNSIGNED NOT NULL DEFAULT 0,
            downloads INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_lb_items_public (public_id), KEY ix_lb_items_status (legal_entity_id, status, kind)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS lb_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NOT NULL,
            version_no INT UNSIGNED NOT NULL,
            document_pid CHAR(26) NOT NULL,
            filename VARCHAR(200) NOT NULL,
            file_type VARCHAR(10) NOT NULL,
            mime VARCHAR(120) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL,
            pages INT UNSIGNED NULL,
            text_status ENUM('indexed','partial','no_text','not_pdf') NOT NULL,
            is_current TINYINT(1) NOT NULL DEFAULT 1,
            note VARCHAR(300) NULL,
            uploaded_by BIGINT UNSIGNED NOT NULL,
            uploaded_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_lb_files_version (item_id, version_no), KEY ix_lb_files_sha (sha256)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS lb_text (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NOT NULL,
            page INT UNSIGNED NOT NULL,
            body MEDIUMTEXT NOT NULL,
            squeezed MEDIUMTEXT NOT NULL,
            PRIMARY KEY (id), KEY ix_lb_text_item (item_id, page)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS lb_reads (
            user_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            last_page INT UNSIGNED NULL,
            pages INT UNSIGNED NULL,
            last_opened_at DATETIME(6) NOT NULL,
            PRIMARY KEY (user_id, item_id), KEY ix_lb_reads_recent (user_id, last_opened_at)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS lb_bookmarks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            page INT UNSIGNED NOT NULL,
            note VARCHAR(200) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_lb_bookmarks_page (user_id, item_id, page)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS lb_daily (
            item_id BIGINT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            views INT UNSIGNED NOT NULL DEFAULT 0,
            downloads INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (item_id, day)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS lb_copies (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(40) NOT NULL,
            location VARCHAR(120) NULL,
            status ENUM('available','reserved','loaned','lost','withdrawn') NOT NULL DEFAULT 'available',
            note VARCHAR(300) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_lb_copies_public (public_id), UNIQUE KEY uq_lb_copies_code (item_id, code), KEY ix_lb_copies_status (item_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS lb_loans (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            copy_id BIGINT UNSIGNED NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            status ENUM('waiting','ready','loaned','returned','lost','cancelled','expired') NOT NULL,
            queued_at DATETIME(6) NOT NULL,
            ready_at DATETIME(6) NULL,
            ready_until DATE NULL,
            loaned_at DATETIME(6) NULL,
            due_on DATE NULL,
            closed_at DATETIME(6) NULL,
            handled_by BIGINT UNSIGNED NULL,
            reason VARCHAR(500) NULL,
            reminded TINYINT(1) NOT NULL DEFAULT 0,
            overdue_notified TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_lb_loans_public (public_id), KEY ix_lb_loans_item (item_id, status, id), KEY ix_lb_loans_person (employee_id, status),
            KEY ix_lb_loans_copy (copy_id, status)
        ) {$o}");
    }
};
