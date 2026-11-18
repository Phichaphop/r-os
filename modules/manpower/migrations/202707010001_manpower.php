<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M11 Manpower budget (phase 5E-3, DEC-78): yearly plans per company with versions and revisions (draft → review →
 * approved/active → superseded/closed), plan lines per unit/position (heads, monthly cost per head, months),
 * the check made when a requisition asks for new people, the over-budget setting, and monthly headcount snapshots.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M11 Manpower budget: plans, lines, requisition checks, settings, snapshots';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS mp_plans (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            fiscal_year SMALLINT UNSIGNED NOT NULL,
            version_no INT UNSIGNED NOT NULL,
            title VARCHAR(150) NOT NULL,
            assumptions TEXT NULL,
            status ENUM('draft','review','active','superseded','closed','rejected','withdrawn') NOT NULL DEFAULT 'draft',
            revision_of BIGINT UNSIGNED NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME(6) NULL,
            closed_at DATETIME(6) NULL,
            snapshot_json MEDIUMTEXT NULL,
            snapshot_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_mp_plan_public (public_id), UNIQUE KEY uq_mp_plan_ver (legal_entity_id, fiscal_year, version_no), KEY ix_mp_plan_status (legal_entity_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS mp_lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_id BIGINT UNSIGNED NOT NULL,
            department_id BIGINT UNSIGNED NULL,
            position_title VARCHAR(120) NOT NULL DEFAULT '',
            title_key VARCHAR(120) NOT NULL DEFAULT '',
            headcount DECIMAL(7,1) NOT NULL,
            monthly_cost DECIMAL(14,2) NULL,
            months TINYINT UNSIGNED NOT NULL DEFAULT 12,
            note VARCHAR(300) NULL,
            PRIMARY KEY (id), KEY ix_mp_lines (plan_id),
            CONSTRAINT fk_mp_lines_plan FOREIGN KEY (plan_id) REFERENCES mp_plans (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS mp_checks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            requisition_id BIGINT UNSIGNED NOT NULL,
            plan_id BIGINT UNSIGNED NULL,
            line_id BIGINT UNSIGNED NULL,
            planned DECIMAL(7,1) NULL,
            actual INT NULL,
            open_heads INT NULL,
            requested INT NOT NULL,
            result ENUM('within','over','no_plan','no_line') NOT NULL,
            mode ENUM('warn','block') NOT NULL,
            checked_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_mp_check_req (requisition_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS mp_settings (
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            over_budget ENUM('warn','block') NOT NULL DEFAULT 'warn',
            updated_at DATETIME(6) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (legal_entity_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS mp_snapshots (
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            snap_month CHAR(7) NOT NULL,
            department_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            headcount INT NOT NULL,
            taken_at DATETIME(6) NOT NULL,
            PRIMARY KEY (legal_entity_id, snap_month, department_id)
        ) {$o}");
    }

    public function postCheck(Connection $db): array
    {
        return Schema::expectTables($db, ['mp_plans', 'mp_lines', 'mp_checks', 'mp_settings', 'mp_snapshots']);
    }
};
