<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 5 gate (DEC-82, P0-04 P-04): data-subject requests handled by the privacy officer, with their history and
 * the erasure plan / result kept as evidence.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Privacy: data-subject requests and their history';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS privacy_requests (
            {$std},
            request_no VARCHAR(30) NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            request_type ENUM('access','correction','deletion','objection','other') NOT NULL,
            details VARCHAR(2000) NOT NULL,
            status ENUM('received','verified','erasure_pending','erasure_done','completed','rejected') NOT NULL DEFAULT 'received',
            due_on DATE NULL,
            identity_note VARCHAR(500) NULL,
            erasure_plan TEXT NULL,
            prepared_by BIGINT UNSIGNED NULL,
            erasure_result TEXT NULL,
            approved_by BIGINT UNSIGNED NULL,
            erased_at DATETIME(6) NULL,
            answer VARCHAR(2000) NULL,
            closed_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_privacy_request_public (public_id), KEY ix_privacy_request_user (user_id), KEY ix_privacy_request_status (status, due_on)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS privacy_request_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(30) NOT NULL,
            note VARCHAR(1000) NULL,
            actor_id BIGINT UNSIGNED NULL,
            occurred_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_privacy_events (request_id, id),
            CONSTRAINT fk_privacy_events FOREIGN KEY (request_id) REFERENCES privacy_requests (id)
        ) {$o}");
    }

    public function postCheck(Connection $db): array
    {
        return Schema::expectTables($db, ['privacy_requests', 'privacy_request_events']);
    }
};
