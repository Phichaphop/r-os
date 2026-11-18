<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 9C-2 (DEC-95): M42 physical verification campaigns — the frozen scope (a snapshot of what the register expected
 * at the cutoff), what counters observed (scan or code, duplicate scans counted once), and discrepancy cases resolved
 * by someone other than the counter. Closing needs every case resolved or a named approver's documented exception.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Asset verification: campaigns, items, cases';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS as_campaigns (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(160) NOT NULL,
            cutoff_date DATE NOT NULL,
            scope_site_id BIGINT UNSIGNED NULL,
            scope_type_id BIGINT UNSIGNED NULL,
            double_cost DECIMAL(14,2) NULL,
            status ENUM('draft','counting','reviewing','closed') NOT NULL DEFAULT 'draft',
            started_at DATETIME NULL,
            started_by BIGINT UNSIGNED NULL,
            counting_ended_at DATETIME NULL,
            closed_at DATETIME NULL,
            closed_by BIGINT UNSIGNED NULL,
            exception_note VARCHAR(1000) NULL,
            exception_by BIGINT UNSIGNED NULL,
            report_sha256 CHAR(64) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_as_campaigns_public (public_id), KEY ix_as_campaigns_le (legal_entity_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS as_campaign_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL,
            asset_id BIGINT UNSIGNED NULL,
            in_scope TINYINT(1) NOT NULL DEFAULT 1,
            expected_json TEXT NULL,
            double_needed TINYINT(1) NOT NULL DEFAULT 0,
            result ENUM('pending','matched','other_location','custodian_mismatch','condition_mismatch','tag_damaged','not_found','unexpected','not_applicable') NOT NULL DEFAULT 'pending',
            observed_site_id BIGINT UNSIGNED NULL,
            observed_location VARCHAR(160) NULL,
            observed_custodian_id BIGINT UNSIGNED NULL,
            observed_condition VARCHAR(10) NULL,
            tag_ok TINYINT(1) NULL,
            note VARCHAR(500) NULL,
            unexpected_label VARCHAR(160) NULL,
            counted_by BIGINT UNSIGNED NULL,
            counted_at DATETIME NULL,
            second_by BIGINT UNSIGNED NULL,
            second_at DATETIME NULL,
            scans INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id), KEY ix_as_ci_campaign (campaign_id, result), KEY ix_as_ci_asset (asset_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS as_cases (
            {$std},
            campaign_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            asset_id BIGINT UNSIGNED NULL,
            kind VARCHAR(30) NOT NULL,
            status ENUM('open','resolved') NOT NULL DEFAULT 'open',
            resolution ENUM('master_corrected','reported_lost','custody_handled','registered','no_change') NULL,
            resolution_note VARCHAR(1000) NULL,
            before_json TEXT NULL,
            after_json TEXT NULL,
            resolved_by BIGINT UNSIGNED NULL,
            resolved_at DATETIME NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_as_cases_public (public_id), UNIQUE KEY uq_as_cases_item (item_id), KEY ix_as_cases_campaign (campaign_id, status)
        ) {$o}");
    }
};
