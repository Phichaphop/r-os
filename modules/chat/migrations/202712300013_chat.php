<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;

/**
 * 0.41.0 (R5): M17 internal chat — conversations (one-to-one and groups people make themselves), members with what they
 * have read and from which message they may see, messages (sent once per client id, so a retried send is not doubled),
 * earlier text of edited / deleted messages (kept, seen by nobody unless the message is reported — Q-05, Q-06),
 * attachments (images / PDF, at most three per message — Q-03; stored encrypted through the document service) and reports.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Chat: conversations, members, messages, edits, files, reports';
    }

    public function up(Connection $db): void
    {
        $o = \Blue\Core\Database\Schema::TABLE_OPTIONS;
        $std = \Blue\Core\Database\Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS ch_conversations (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('direct','group') NOT NULL,
            title VARCHAR(80) NULL,
            direct_key VARCHAR(41) NULL,
            last_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_message_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_ch_conversations_public (public_id), UNIQUE KEY uq_ch_conversations_direct (direct_key),
            KEY ix_ch_conversations_le (legal_entity_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ch_members (
            conversation_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role ENUM('owner','member') NOT NULL DEFAULT 'member',
            joined_at DATETIME(6) NOT NULL,
            visible_from_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_read_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            left_at DATETIME(6) NULL,
            PRIMARY KEY (conversation_id, user_id), KEY ix_ch_members_user (user_id, left_at)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ch_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            sender_id BIGINT UNSIGNED NOT NULL,
            client_id CHAR(32) NULL,
            kind ENUM('text','system') NOT NULL DEFAULT 'text',
            body TEXT NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            edited_at DATETIME(6) NULL,
            deleted_at DATETIME(6) NULL,
            hidden_at DATETIME(6) NULL,
            hidden_reason VARCHAR(500) NULL,
            purged_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_ch_messages_public (public_id), UNIQUE KEY uq_ch_messages_client (sender_id, client_id),
            KEY ix_ch_messages_conv (conversation_id, id), KEY ix_ch_messages_updated (conversation_id, updated_at), KEY ix_ch_messages_age (created_at)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ch_message_edits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id BIGINT UNSIGNED NOT NULL,
            action ENUM('edit','delete') NOT NULL,
            old_body TEXT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_ch_edits_message (message_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ch_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id BIGINT UNSIGNED NOT NULL,
            document_pid CHAR(26) NULL,
            filename VARCHAR(200) NOT NULL,
            mime VARCHAR(100) NOT NULL,
            size INT UNSIGNED NOT NULL,
            purged_at DATETIME(6) NULL,
            PRIMARY KEY (id), KEY ix_ch_files_message (message_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS ch_reports (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NOT NULL,
            reporter_id BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(500) NOT NULL,
            snapshot_body TEXT NULL,
            status ENUM('open','upheld','dismissed') NOT NULL DEFAULT 'open',
            handled_by BIGINT UNSIGNED NULL,
            handled_at DATETIME(6) NULL,
            note VARCHAR(500) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_ch_reports_public (public_id), UNIQUE KEY uq_ch_reports_once (message_id, reporter_id), KEY ix_ch_reports_queue (legal_entity_id, status)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $missing = [];
        foreach (['ch_conversations', 'ch_members', 'ch_messages', 'ch_message_edits', 'ch_files', 'ch_reports'] as $t) {
            if (!$db->tableExists($t)) {
                $missing[] = $t . ' missing';
            }
        }
        return $missing;
    }
};
