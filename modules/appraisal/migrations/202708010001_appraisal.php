<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M38 Performance appraisal (phase 5E-4, DEC-79): forms (scale, rating bands, weighted KPI/competency items),
 * cycles (form frozen into the cycle when it opens, target group, deadlines, results date), one appraisal per person
 * (self → manager → optional final review → finalized → acknowledged / appeal), item scores and the change history.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M38 Performance appraisal: forms, cycles, appraisals, scores, history';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS apr_forms (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(30) NOT NULL,
            name VARCHAR(150) NOT NULL,
            scale_max TINYINT UNSIGNED NOT NULL DEFAULT 5,
            bands_json TEXT NULL,
            items_json MEDIUMTEXT NOT NULL,
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            PRIMARY KEY (id), UNIQUE KEY uq_apr_form_public (public_id), UNIQUE KEY uq_apr_form_code (legal_entity_id, code)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS apr_cycles (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(30) NOT NULL,
            name VARCHAR(150) NOT NULL,
            period ENUM('mid_year','year_end','probation','other') NOT NULL,
            form_id BIGINT UNSIGNED NOT NULL,
            form_snapshot MEDIUMTEXT NULL,
            audience_json TEXT NULL,
            self_due DATE NOT NULL,
            manager_due DATE NOT NULL,
            final_due DATE NULL,
            results_on DATE NOT NULL,
            final_review TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
            opened_at DATETIME(6) NULL,
            closed_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_apr_cycle_public (public_id), UNIQUE KEY uq_apr_cycle_code (legal_entity_id, code)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS apr_appraisals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            cycle_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            manager_user_id BIGINT UNSIGNED NULL,
            reviewer_user_id BIGINT UNSIGNED NULL,
            status ENUM('self_review','manager_review','final_review','finalized','acknowledged') NOT NULL DEFAULT 'self_review',
            self_score DECIMAL(6,3) NULL,
            manager_score DECIMAL(6,3) NULL,
            final_score DECIMAL(6,3) NULL,
            rating_label VARCHAR(80) NULL,
            self_comment TEXT NULL,
            manager_comment TEXT NULL,
            strengths TEXT NULL,
            development_plan TEXT NULL,
            comp_proposal VARCHAR(500) NULL,
            reviewer_comment TEXT NULL,
            calibration_reason VARCHAR(500) NULL,
            employee_comment TEXT NULL,
            appeal TINYINT(1) NOT NULL DEFAULT 0,
            appeal_status ENUM('open','closed') NULL,
            appeal_outcome VARCHAR(500) NULL,
            self_at DATETIME(6) NULL,
            manager_at DATETIME(6) NULL,
            finalized_at DATETIME(6) NULL,
            acknowledged_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            row_version INT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id), UNIQUE KEY uq_apr_public (public_id), UNIQUE KEY uq_apr_person (cycle_id, employee_id), KEY ix_apr_mgr (manager_user_id, status), KEY ix_apr_user (user_id),
            CONSTRAINT fk_apr_cycle FOREIGN KEY (cycle_id) REFERENCES apr_cycles (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS apr_scores (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            appraisal_id BIGINT UNSIGNED NOT NULL,
            item_no SMALLINT UNSIGNED NOT NULL,
            self_score TINYINT UNSIGNED NULL,
            self_note VARCHAR(1000) NULL,
            manager_score TINYINT UNSIGNED NULL,
            manager_note VARCHAR(1000) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_apr_score (appraisal_id, item_no),
            CONSTRAINT fk_apr_score FOREIGN KEY (appraisal_id) REFERENCES apr_appraisals (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS apr_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            appraisal_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(30) NOT NULL,
            note VARCHAR(500) NULL,
            actor_id BIGINT UNSIGNED NULL,
            occurred_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_apr_events (appraisal_id, id),
            CONSTRAINT fk_apr_events FOREIGN KEY (appraisal_id) REFERENCES apr_appraisals (id)
        ) {$o}");
    }

    public function postCheck(Connection $db): array
    {
        return Schema::expectTables($db, ['apr_forms', 'apr_cycles', 'apr_appraisals', 'apr_scores', 'apr_events']);
    }
};
