<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 4A (DEC-63) — the minimal employee master the vertical slice needs (P0-03 §4, M09 is completed in phase 5):
 * employees (code, names, status, hire/termination dates) and effective-dated employment history (company, site,
 * department, position title, line manager, employee group, employment type). The sign-in account links through the
 * existing users.employee_id. No identity numbers, birth dates, bank accounts or pay here (phase 5/6).
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Employees (minimal master for phase 4): employees, employment history';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS employees (
            {$std},
            employee_code VARCHAR(30) NOT NULL,
            name_th VARCHAR(120) NOT NULL,
            name_en VARCHAR(120) NULL,
            preferred_name VARCHAR(60) NULL,
            status ENUM('pre_hire','active','former') NOT NULL DEFAULT 'active',
            hire_date DATE NOT NULL,
            termination_date DATE NULL,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_employees_public (public_id),
            UNIQUE KEY uq_employees_code (employee_code),
            KEY ix_employees_status (status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS employment_history (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            department_id BIGINT UNSIGNED NULL,
            position_title VARCHAR(120) NULL,
            manager_employee_id BIGINT UNSIGNED NULL,
            employee_group VARCHAR(20) NOT NULL,
            employment_type ENUM('permanent','probation','contract','part_time','daily') NOT NULL DEFAULT 'permanent',
            effective_from DATE NOT NULL,
            effective_to DATE NULL,
            change_reason VARCHAR(255) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_employment_public (public_id),
            UNIQUE KEY uq_employment_from (employee_id, effective_from),
            KEY ix_employment_le (legal_entity_id, site_id, department_id),
            KEY ix_employment_manager (manager_employee_id),
            CONSTRAINT fk_employment_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
            CONSTRAINT fk_employment_le FOREIGN KEY (legal_entity_id) REFERENCES legal_entities (id)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $missing = [];
        foreach (['employees', 'employment_history'] as $t) {
            if (!$db->tableExists($t)) {
                $missing[] = $t . ' missing';
            }
        }
        return $missing;
    }
};
