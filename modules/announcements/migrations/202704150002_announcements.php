<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M19 Announcements (phase 5D, DEC-72):
 *   ann_posts        announcement of a company: Thai (+ English) text, category, priority, pin, publish window (UTC),
 *                    target group, read-receipt, login pop-up, e-mail, one video / link; draft → review (important and
 *                    critical need another person) → scheduled → published → expired; withdrawn / rejected / archived
 *   ann_attachments  images / files (Documents class announcements.file)
 *   ann_revisions    the content as published, every revision (a published announcement is never silently changed)
 *   ann_reads        per user: first/last view, views, acknowledgement, reaction, link clicks, pop-up shown
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M19 announcements: posts with approval and schedule, attachments, published revisions, read / acknowledge / reaction tracking';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS ann_posts (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            body TEXT NOT NULL,
            title_en VARCHAR(200) NULL,
            body_en TEXT NULL,
            category VARCHAR(60) NOT NULL,
            priority ENUM('normal','important','critical') NOT NULL DEFAULT 'normal',
            pinned TINYINT(1) NOT NULL DEFAULT 0,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NULL,
            audience_json TEXT NOT NULL,
            requires_ack TINYINT(1) NOT NULL DEFAULT 0,
            popup_seconds TINYINT UNSIGNED NOT NULL DEFAULT 0,
            send_email TINYINT(1) NOT NULL DEFAULT 0,
            video_url VARCHAR(500) NULL,
            link_url VARCHAR(500) NULL,
            status ENUM('draft','review','scheduled','published','expired','archived','rejected','withdrawn') NOT NULL DEFAULT 'draft',
            revision SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            workflow_instance_id BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            published_at DATETIME(6) NULL,
            notified_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ann_posts_public (public_id),
            KEY ix_ann_posts_list (legal_entity_id, status, starts_at)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ann_attachments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            document_pid CHAR(26) NOT NULL,
            file_name VARCHAR(200) NOT NULL,
            mime VARCHAR(100) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ann_attachments_doc (document_pid),
            KEY ix_ann_attachments_post (post_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ann_revisions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            revision SMALLINT UNSIGNED NOT NULL,
            snapshot_json MEDIUMTEXT NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ann_revisions (post_id, revision)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ann_reads (
            post_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            first_seen_at DATETIME(6) NOT NULL,
            last_seen_at DATETIME(6) NOT NULL,
            views INT UNSIGNED NOT NULL DEFAULT 1,
            acked_at DATETIME(6) NULL,
            acked_revision SMALLINT UNSIGNED NULL,
            reaction ENUM('like','thanks','celebrate') NULL,
            clicks INT UNSIGNED NOT NULL DEFAULT 0,
            popup_at DATETIME(6) NULL,
            PRIMARY KEY (post_id, user_id),
            KEY ix_ann_reads_user (user_id)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $out = [];
        foreach (['ann_posts', 'ann_attachments', 'ann_revisions', 'ann_reads'] as $t) {
            if (!$db->tableExists($t)) {
                $out[] = $t . ' missing';
            }
        }
        return $out;
    }
};
