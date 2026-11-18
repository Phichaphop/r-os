<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 9B-1 (DEC-91): M15 helpdesk — work categories with their SLA hours (per priority, working days) and their
 * team (lead / agent), the company policy (reopen window, auto-close when the requester does not answer), tickets,
 * the conversation (messages and internal work notes), attachments (central document service) and the history.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Helpdesk: categories, members, policies, tickets, messages, files, events';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS hd_categories (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(500) NULL,
            response_hours SMALLINT UNSIGNED NULL,
            hours_low SMALLINT UNSIGNED NULL,
            hours_normal SMALLINT UNSIGNED NULL,
            hours_high SMALLINT UNSIGNED NULL,
            hours_urgent SMALLINT UNSIGNED NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), UNIQUE KEY uq_hd_categories_public (public_id), UNIQUE KEY uq_hd_categories_name (legal_entity_id, name)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS hd_members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role ENUM('lead','agent') NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_hd_members (category_id, user_id), KEY ix_hd_members_user (user_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS hd_policies (
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            reopen_days SMALLINT UNSIGNED NULL,
            auto_close_days SMALLINT UNSIGNED NULL,
            updated_at DATETIME(6) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (legal_entity_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS hd_tickets (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            ref VARCHAR(20) NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            requester_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT NOT NULL,
            location VARCHAR(200) NULL,
            asset VARCHAR(200) NULL,
            priority ENUM('low','normal','high','urgent') NOT NULL,
            status ENUM('open','assigned','in_progress','waiting_user','waiting_parts','resolved','closed','cancelled') NOT NULL DEFAULT 'open',
            opened_at DATETIME NOT NULL,
            assignee_id BIGINT UNSIGNED NULL,
            due_at DATETIME NULL,
            response_due DATETIME NULL,
            resolve_due DATETIME NULL,
            first_response_at DATETIME NULL,
            resolved_at DATETIME NULL,
            closed_at DATETIME NULL,
            close_kind ENUM('confirmed','auto','cancelled') NULL,
            resolution TEXT NULL,
            rating TINYINT UNSIGNED NULL,
            rating_note VARCHAR(500) NULL,
            rated_at DATETIME NULL,
            reopen_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            sla_breached TINYINT(1) NOT NULL DEFAULT 0,
            source ENUM('user','inspection') NOT NULL DEFAULT 'user',
            source_ref VARCHAR(60) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_hd_tickets_public (public_id), UNIQUE KEY uq_hd_tickets_ref (legal_entity_id, ref),
            KEY ix_hd_tickets_req (requester_id, status), KEY ix_hd_tickets_cat (category_id, status), KEY ix_hd_tickets_assignee (assignee_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS hd_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            author_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('message','note') NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_hd_messages_ticket (ticket_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS hd_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NULL,
            internal TINYINT(1) NOT NULL DEFAULT 0,
            document_pid CHAR(26) NOT NULL,
            sha256 CHAR(64) NOT NULL,
            filename VARCHAR(200) NOT NULL,
            added_by BIGINT UNSIGNED NOT NULL,
            added_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_hd_files_ticket (ticket_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS hd_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(30) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            note VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_hd_events_ticket (ticket_id)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $missing = [];
        foreach (['hd_categories', 'hd_members', 'hd_policies', 'hd_tickets', 'hd_messages', 'hd_files', 'hd_events'] as $t) {
            if (!$db->tableExists($t)) {
                $missing[] = $t . ' missing';
            }
        }
        return $missing;
    }
};
