<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 9E-2 (DEC-101): M24 project management — projects (charter, dates, budget, approver, status, closure review),
 * members (lead / member, invitation), tasks and subtasks (assignee, dates, acceptance criteria, progress, risk),
 * dependencies, submissions as revisions with files and the lead's decision, comments, status updates and history.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Projects: projects, members, tasks, dependencies, submissions, comments, status updates, history';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS pj_projects (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            ref VARCHAR(20) NOT NULL,
            name VARCHAR(160) NOT NULL,
            goal TEXT NOT NULL,
            scope TEXT NULL,
            starts_on DATE NOT NULL,
            ends_on DATE NOT NULL,
            budget DECIMAL(15,2) NULL,
            approver_id BIGINT UNSIGNED NULL,
            workflow_id CHAR(26) NULL,
            approved_by BIGINT UNSIGNED NULL,
            status ENUM('planning','pending_approval','active','at_risk','on_hold','completed','closed') NOT NULL DEFAULT 'planning',
            summary TEXT NULL,
            lessons TEXT NULL,
            close_reason VARCHAR(1000) NULL,
            closed_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_pj_projects_public (public_id), UNIQUE KEY uq_pj_projects_ref (legal_entity_id, ref), KEY ix_pj_projects_status (legal_entity_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pj_members (
            project_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role ENUM('lead','member') NOT NULL,
            status ENUM('invited','active','declined','removed') NOT NULL,
            invited_by BIGINT UNSIGNED NULL,
            invited_at DATETIME(6) NOT NULL,
            joined_at DATETIME(6) NULL,
            PRIMARY KEY (project_id, user_id), KEY ix_pj_members_user (user_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pj_tasks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT NULL,
            criteria TEXT NULL,
            assignee_id BIGINT UNSIGNED NULL,
            starts_on DATE NULL,
            due_on DATE NULL,
            status ENUM('todo','in_progress','submitted','accepted','returned','cancelled') NOT NULL DEFAULT 'todo',
            progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
            risk VARCHAR(500) NULL,
            sort INT NOT NULL DEFAULT 0,
            reminded TINYINT(1) NOT NULL DEFAULT 0,
            overdue_notified TINYINT(1) NOT NULL DEFAULT 0,
            accepted_at DATETIME(6) NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_pj_tasks_public (public_id), KEY ix_pj_tasks_project (project_id, status), KEY ix_pj_tasks_assignee (assignee_id, status, due_on)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pj_deps (
            task_id BIGINT UNSIGNED NOT NULL,
            depends_on_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (task_id, depends_on_id), KEY ix_pj_deps_on (depends_on_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pj_subs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT UNSIGNED NOT NULL,
            rev INT UNSIGNED NOT NULL,
            note TEXT NULL,
            link VARCHAR(500) NULL,
            submitted_by BIGINT UNSIGNED NOT NULL,
            submitted_at DATETIME(6) NOT NULL,
            decision ENUM('accepted','returned') NULL,
            feedback TEXT NULL,
            decided_by BIGINT UNSIGNED NULL,
            decided_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_pj_subs_rev (task_id, rev)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pj_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            sub_id BIGINT UNSIGNED NULL,
            document_pid CHAR(26) NOT NULL,
            filename VARCHAR(200) NOT NULL,
            mime VARCHAR(120) NOT NULL,
            uploaded_by BIGINT UNSIGNED NOT NULL,
            uploaded_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_pj_files_project (project_id), KEY ix_pj_files_sub (sub_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pj_comments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_pj_comments_task (task_id, id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pj_updates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            health ENUM('on_track','at_risk','off_track') NOT NULL,
            status_from VARCHAR(20) NULL,
            status_to VARCHAR(20) NULL,
            note TEXT NOT NULL,
            progress TINYINT UNSIGNED NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_pj_updates_project (project_id, id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS pj_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            task_id BIGINT UNSIGNED NULL,
            event VARCHAR(40) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            note VARCHAR(1000) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_pj_events_project (project_id, id)
        ) {$o}");
    }
};
