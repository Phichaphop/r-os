<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 9D-2 (DEC-98): M54 discussion board — categories, threads, replies, likes, attachments, earlier versions of edited
 * text (kept as moderation evidence) and reports to the moderators.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Forum: categories, threads, posts, likes, files, edits, reports';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS fm_categories (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(80) NOT NULL,
            sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), UNIQUE KEY uq_fm_categories_public (public_id), UNIQUE KEY uq_fm_categories_name (legal_entity_id, name)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS fm_threads (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            author_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(160) NOT NULL,
            body TEXT NOT NULL,
            pinned TINYINT(1) NOT NULL DEFAULT 0,
            locked TINYINT(1) NOT NULL DEFAULT 0,
            hidden TINYINT(1) NOT NULL DEFAULT 0,
            hidden_reason VARCHAR(500) NULL,
            hidden_by BIGINT UNSIGNED NULL,
            edited_at DATETIME(6) NULL,
            last_post_at DATETIME(6) NOT NULL,
            reply_count INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_fm_threads_public (public_id), KEY ix_fm_threads_list (legal_entity_id, hidden, pinned, last_post_at), KEY ix_fm_threads_author (author_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS fm_posts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            thread_id BIGINT UNSIGNED NOT NULL,
            author_id BIGINT UNSIGNED NOT NULL,
            body TEXT NOT NULL,
            hidden TINYINT(1) NOT NULL DEFAULT 0,
            hidden_reason VARCHAR(500) NULL,
            hidden_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            edited_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_fm_posts_public (public_id), KEY ix_fm_posts_thread (thread_id, id), KEY ix_fm_posts_author (author_id, created_at)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS fm_likes (
            target ENUM('thread','post') NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (target, target_id, user_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS fm_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL,
            document_pid CHAR(26) NOT NULL,
            filename VARCHAR(200) NOT NULL,
            mime VARCHAR(100) NOT NULL,
            PRIMARY KEY (id), KEY ix_fm_files_thread (thread_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS fm_edits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            target ENUM('thread','post') NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL,
            old_title VARCHAR(160) NULL,
            old_body TEXT NOT NULL,
            edited_by BIGINT UNSIGNED NOT NULL,
            edited_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_fm_edits_target (target, target_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS fm_reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            target ENUM('thread','post') NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL,
            reporter_id BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(500) NOT NULL,
            status ENUM('open','upheld','dismissed') NOT NULL DEFAULT 'open',
            handled_by BIGINT UNSIGNED NULL,
            handled_at DATETIME(6) NULL,
            note VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_fm_reports_public (public_id), UNIQUE KEY uq_fm_reports_once (target, target_id, reporter_id), KEY ix_fm_reports_status (legal_entity_id, status)
        ) {$o}");
    }
};
