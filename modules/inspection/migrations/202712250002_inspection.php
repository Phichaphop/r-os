<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 9B-1 (DEC-91): M18 inspection — checklists with versions (a change applies to rounds from its date), inspection
 * points (site, place, equipment, checklist, how often, the inspector, the helpdesk category for failures, a QR token),
 * rounds (scheduled → in_progress → submitted → reviewed → closed; overdue), results per item and revision (photos via
 * the document service), corrective actions (own or a helpdesk ticket) and the history.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Inspection: checklists, versions, points, rounds, results, actions, events';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS in_checklists (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(150) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_in_checklists_public (public_id), UNIQUE KEY uq_in_checklists_name (legal_entity_id, name)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS in_checklist_versions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            checklist_id BIGINT UNSIGNED NOT NULL,
            version_no SMALLINT UNSIGNED NOT NULL,
            items_json TEXT NOT NULL,
            effective_from DATE NOT NULL,
            note VARCHAR(300) NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_in_versions (checklist_id, version_no)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS in_points (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            code VARCHAR(30) NOT NULL,
            name VARCHAR(150) NOT NULL,
            location VARCHAR(200) NULL,
            checklist_id BIGINT UNSIGNED NOT NULL,
            frequency ENUM('daily','weekly','monthly') NOT NULL,
            start_date DATE NOT NULL,
            inspector_id BIGINT UNSIGNED NOT NULL,
            ticket_category_pid CHAR(26) NULL,
            ticket_priority ENUM('low','normal','high','urgent') NULL,
            qr_token CHAR(32) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), UNIQUE KEY uq_in_points_public (public_id), UNIQUE KEY uq_in_points_code (legal_entity_id, code), UNIQUE KEY uq_in_points_qr (qr_token)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS in_rounds (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            point_id BIGINT UNSIGNED NOT NULL,
            due_date DATE NOT NULL,
            version_id BIGINT UNSIGNED NOT NULL,
            inspector_id BIGINT UNSIGNED NOT NULL,
            status ENUM('scheduled','in_progress','submitted','reviewed','closed','overdue') NOT NULL DEFAULT 'scheduled',
            revision SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            result ENUM('pass','fail') NULL,
            fail_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            late TINYINT(1) NOT NULL DEFAULT 0,
            started_at DATETIME NULL,
            submitted_at DATETIME NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            closed_at DATETIME NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_in_rounds_public (public_id), UNIQUE KEY uq_in_rounds_due (point_id, due_date), KEY ix_in_rounds_inspector (inspector_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS in_results (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            round_id BIGINT UNSIGNED NOT NULL,
            revision SMALLINT UNSIGNED NOT NULL,
            item_no SMALLINT UNSIGNED NOT NULL,
            result ENUM('pass','fail','na') NOT NULL,
            value DECIMAL(12,2) NULL,
            note VARCHAR(500) NULL,
            document_pid CHAR(26) NULL,
            filename VARCHAR(200) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_in_results (round_id, revision, item_no)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS in_actions (
            {$std},
            round_id BIGINT UNSIGNED NOT NULL,
            item_no SMALLINT UNSIGNED NOT NULL,
            description VARCHAR(500) NOT NULL,
            assignee_id BIGINT UNSIGNED NULL,
            due_date DATE NULL,
            ticket_pid CHAR(26) NULL,
            status ENUM('open','done') NOT NULL DEFAULT 'open',
            done_note VARCHAR(500) NULL,
            done_at DATETIME NULL,
            done_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_in_actions_public (public_id), KEY ix_in_actions_round (round_id), KEY ix_in_actions_assignee (assignee_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS in_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            round_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(30) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            note VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_in_events_round (round_id)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $missing = [];
        foreach (['in_checklists', 'in_checklist_versions', 'in_points', 'in_rounds', 'in_results', 'in_actions', 'in_events'] as $t) {
            if (!$db->tableExists($t)) {
                $missing[] = $t . ' missing';
            }
        }
        return $missing;
    }
};
