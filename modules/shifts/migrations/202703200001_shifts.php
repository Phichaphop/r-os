<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M35 Shift Scheduling (phase 5B, DEC-70):
 *   sh_patterns    shift types of a company (start/end/break/grace; end ≤ start = overnight), optionally for one branch
 *   sh_rosters     a schedule for a date range: draft → published; editing a published roster makes a new revision
 *                  (the old one becomes "superseded" and is kept as evidence)
 *   sh_assignments employee × date → pattern (NULL = day off) inside one roster
 *   sh_changes     every change to a published schedule (new revision or approved swap): who, what, from → to
 *   sh_swaps       shift swap between two employees: partner consents, then the approver decides
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M35 shifts: patterns, rosters (revisions), assignments, change log, swaps';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS sh_patterns (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            code VARCHAR(20) NOT NULL,
            name VARCHAR(120) NOT NULL,
            start_time CHAR(5) NOT NULL,
            end_time CHAR(5) NOT NULL,
            break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            late_grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            early_grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            tone ENUM('morning','afternoon','night','other') NOT NULL DEFAULT 'other',
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sh_patterns_public (public_id),
            UNIQUE KEY uq_sh_patterns_code (legal_entity_id, code)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS sh_rosters (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            department_id BIGINT UNSIGNED NULL,
            name VARCHAR(120) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status ENUM('draft','published','superseded','cancelled') NOT NULL DEFAULT 'draft',
            revision SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            supersedes_id BIGINT UNSIGNED NULL,
            published_at DATETIME(6) NULL,
            published_by BIGINT UNSIGNED NULL,
            warnings_json TEXT NULL,
            note VARCHAR(500) NULL,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sh_rosters_public (public_id),
            KEY ix_sh_rosters_range (legal_entity_id, status, start_date, end_date)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS sh_assignments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            roster_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            work_date DATE NOT NULL,
            pattern_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sh_assign (roster_id, employee_id, work_date),
            KEY ix_sh_assign_emp (employee_id, work_date)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS sh_changes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            roster_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            work_date DATE NOT NULL,
            from_pattern_id BIGINT UNSIGNED NULL,
            to_pattern_id BIGINT UNSIGNED NULL,
            from_set TINYINT(1) NOT NULL DEFAULT 1,
            to_set TINYINT(1) NOT NULL DEFAULT 1,
            source ENUM('revision','swap') NOT NULL,
            ref_pid CHAR(26) NULL,
            actor_id BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_sh_changes_emp (employee_id, work_date)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS sh_swaps (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            department_id BIGINT UNSIGNED NULL,
            requester_employee_id BIGINT UNSIGNED NOT NULL,
            partner_employee_id BIGINT UNSIGNED NOT NULL,
            requester_date DATE NOT NULL,
            partner_date DATE NOT NULL,
            requester_pattern_id BIGINT UNSIGNED NULL,
            partner_pattern_id BIGINT UNSIGNED NULL,
            reason VARCHAR(500) NOT NULL,
            status ENUM('pending_partner','declined','pending','approved','rejected','cancelled','failed') NOT NULL DEFAULT 'pending_partner',
            partner_answered_at DATETIME(6) NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            error VARCHAR(255) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sh_swaps_public (public_id),
            KEY ix_sh_swaps_partner (partner_employee_id, status),
            KEY ix_sh_swaps_requester (requester_employee_id, status)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $out = [];
        foreach (['sh_patterns', 'sh_rosters', 'sh_assignments', 'sh_changes', 'sh_swaps'] as $t) {
            if (!$db->tableExists($t)) {
                $out[] = $t . ' missing';
            }
        }
        return $out;
    }
};
