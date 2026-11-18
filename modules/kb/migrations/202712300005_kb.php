<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 9D-1 (DEC-97): M55 Q&A / knowledge base — categories, articles with revisions (draft → review → published →
 * superseded / withdrawn; reviewer ≠ author), files, feedback, who has read what (to tell them when an answer is
 * withdrawn), questions from employees, and the company's synonym groups for search.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Knowledge base: categories, articles, revisions, files, feedback, viewers, questions, synonyms';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS kb_categories (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            name_th VARCHAR(120) NOT NULL,
            name_en VARCHAR(120) NULL,
            sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), UNIQUE KEY uq_kb_categories_public (public_id), UNIQUE KEY uq_kb_categories_name (legal_entity_id, name_th)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS kb_articles (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            owner_id BIGINT UNSIGNED NOT NULL,
            status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
            published_rev_id BIGINT UNSIGNED NULL,
            review_due DATE NULL,
            views INT UNSIGNED NOT NULL DEFAULT 0,
            archived_reason VARCHAR(500) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_kb_articles_public (public_id), KEY ix_kb_articles_status (legal_entity_id, status, category_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS kb_revisions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            article_id BIGINT UNSIGNED NOT NULL,
            version SMALLINT UNSIGNED NOT NULL,
            question_th VARCHAR(300) NOT NULL,
            answer_th TEXT NOT NULL,
            question_en VARCHAR(300) NULL,
            answer_en TEXT NULL,
            tags VARCHAR(300) NULL,
            source VARCHAR(500) NULL,
            change_reason VARCHAR(500) NULL,
            review_due DATE NULL,
            status ENUM('draft','review','returned','published','superseded','withdrawn') NOT NULL DEFAULT 'draft',
            author_id BIGINT UNSIGNED NOT NULL,
            submitted_at DATETIME(6) NULL,
            reviewer_id BIGINT UNSIGNED NULL,
            decided_at DATETIME(6) NULL,
            review_note VARCHAR(1000) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_kb_revisions_version (article_id, version), KEY ix_kb_revisions_status (status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS kb_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            article_id BIGINT UNSIGNED NOT NULL,
            document_pid CHAR(26) NOT NULL,
            filename VARCHAR(200) NOT NULL,
            added_by BIGINT UNSIGNED NOT NULL,
            added_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_kb_files_article (article_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS kb_feedback (
            article_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            helpful TINYINT(1) NOT NULL,
            comment VARCHAR(500) NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (article_id, user_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS kb_viewers (
            article_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            last_viewed DATE NOT NULL,
            PRIMARY KEY (article_id, user_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS kb_questions (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            asker_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NULL,
            text VARCHAR(1000) NOT NULL,
            status ENUM('open','answered','declined') NOT NULL DEFAULT 'open',
            article_id BIGINT UNSIGNED NULL,
            handler_id BIGINT UNSIGNED NULL,
            reply VARCHAR(1000) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_kb_questions_public (public_id), KEY ix_kb_questions_status (legal_entity_id, status), KEY ix_kb_questions_article (article_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS kb_synonyms (
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            groups_text TEXT NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (legal_entity_id)
        ) {$o}");
    }
};
