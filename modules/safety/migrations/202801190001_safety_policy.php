<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * 0.45.0 (R7 · DEC-114): the company's safety policy — what each severity level means, who is alerted for each level and
 * when an unanswered report is escalated, the likelihood / impact scales and the risk bands. Versioned: a draft (from the
 * AI template, labelled, or from the policy in use) is published by someone else who records who reviewed it. Incidents
 * remember when they were first alerted and whether the escalation step went out.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Safety policy versions; incident alert time and escalation flag';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS sf_policies (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            version INT UNSIGNED NOT NULL,
            status ENUM('draft','published','superseded') NOT NULL DEFAULT 'draft',
            ai_draft TINYINT(1) NOT NULL DEFAULT 0,
            content_json MEDIUMTEXT NOT NULL,
            reviewer VARCHAR(160) NULL,
            reviewed_on DATE NULL,
            published_by BIGINT UNSIGNED NULL,
            published_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_sf_policies_public (public_id), UNIQUE KEY uq_sf_policies_version (legal_entity_id, version),
            KEY ix_sf_policies_status (legal_entity_id, status)
        ) {$o}");
        if (!Schema::columnExists($db, 'sf_incidents', 'alerted_at')) {
            $db->exec('ALTER TABLE sf_incidents ADD COLUMN alerted_at DATETIME(6) NULL AFTER digested, ADD COLUMN escalated TINYINT(1) NOT NULL DEFAULT 0 AFTER alerted_at');
        }
    }
};
