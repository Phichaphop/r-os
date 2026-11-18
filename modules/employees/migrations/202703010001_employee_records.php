<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;
use Blue\Core\Support\Ulid;

/**
 * Phase 5A (DEC-67) — M09 Employee Records completed on top of the phase-4 minimal master (P0-03 §4, 04 §6.19):
 *  employees (+)                personal fields: title, birth date (encrypted), gender, nationality, work contacts;
 *                               status gains on_leave / suspended
 *  employee_contacts            personal phone / e-mail / addresses — the employee may change them (6.19.3)
 *  employee_emergency_contacts  who to call
 *  employee_identifiers         citizen ID / passport / work permit … — value encrypted + keyed hash (duplicates) + last 4
 *  employee_education / employee_prior_jobs
 *  personnel_file_items         the logical personnel file (categories A–J of 6.19.1) pointing at Records documents;
 *                               verification (6.19.2 ข้อ 4), expiry, visibility to the employee
 *  emp_change_requests          effective-dated changes of employment / status that need a second HR person
 *  employee_status_history      effective-dated status (active, on leave, suspended, former) with the separation details
 *  emp_offboarding_items        the exit checklist started by a separation (6.19.5)
 * Nothing is deleted: documents are superseded or archived, history rows are closed, never rewritten.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M09 Employee Records: personal data, identifiers, personnel file, approved changes, status history, offboarding';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        if (!Schema::columnExists($db, 'employees', 'birth_date_enc')) {
            $db->exec("ALTER TABLE employees
                MODIFY status ENUM('pre_hire','active','on_leave','suspended','former') NOT NULL DEFAULT 'active',
                ADD COLUMN title VARCHAR(20) NULL AFTER employee_code,
                ADD COLUMN birth_date_enc VARBINARY(255) NULL AFTER preferred_name,
                ADD COLUMN gender ENUM('male','female','other','unspecified') NULL AFTER birth_date_enc,
                ADD COLUMN nationality CHAR(2) NULL AFTER gender,
                ADD COLUMN work_email VARCHAR(190) NULL AFTER nationality,
                ADD COLUMN work_phone VARCHAR(40) NULL AFTER work_email,
                ADD COLUMN separation_type VARCHAR(20) NULL AFTER termination_date");
        }
        $db->exec("CREATE TABLE IF NOT EXISTS employee_contacts (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            contact_type ENUM('personal_phone','personal_email','address_current','address_registered') NOT NULL,
            value VARCHAR(500) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_emp_contacts_public (public_id),
            UNIQUE KEY uq_emp_contacts_type (employee_id, contact_type),
            CONSTRAINT fk_emp_contacts_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS employee_emergency_contacts (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            relation VARCHAR(40) NOT NULL,
            phone VARCHAR(40) NOT NULL,
            sort_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_emp_emergency_public (public_id),
            KEY ix_emp_emergency (employee_id, sort_order),
            CONSTRAINT fk_emp_emergency_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS employee_identifiers (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            id_type VARCHAR(30) NOT NULL,
            value_enc VARBINARY(512) NOT NULL,
            value_hash BINARY(32) NOT NULL,
            last4 VARCHAR(4) NOT NULL,
            issue_country CHAR(2) NULL,
            expiry_date DATE NULL,
            status ENUM('current','replaced') NOT NULL DEFAULT 'current',
            PRIMARY KEY (id),
            UNIQUE KEY uq_emp_identifiers_public (public_id),
            KEY ix_emp_identifiers_hash (id_type, value_hash),
            KEY ix_emp_identifiers_employee (employee_id, id_type, status),
            KEY ix_emp_identifiers_expiry (expiry_date),
            CONSTRAINT fk_emp_identifiers_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS employee_education (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            level VARCHAR(20) NOT NULL,
            institution VARCHAR(160) NOT NULL,
            field VARCHAR(160) NULL,
            graduated_year SMALLINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_emp_education_public (public_id),
            KEY ix_emp_education (employee_id),
            CONSTRAINT fk_emp_education_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS employee_prior_jobs (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            company VARCHAR(160) NOT NULL,
            position VARCHAR(120) NULL,
            from_month CHAR(7) NULL,
            to_month CHAR(7) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_emp_prior_public (public_id),
            KEY ix_emp_prior (employee_id),
            CONSTRAINT fk_emp_prior_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS personnel_file_items (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            category CHAR(1) NOT NULL,
            doc_type VARCHAR(40) NOT NULL,
            title VARCHAR(160) NULL,
            document_pid CHAR(26) NOT NULL,
            expiry_date DATE NULL,
            verify_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
            verify_method VARCHAR(20) NULL,
            verify_note VARCHAR(255) NULL,
            verified_by BIGINT UNSIGNED NULL,
            verified_at DATETIME(6) NULL,
            employee_visible TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('current','superseded','archived') NOT NULL DEFAULT 'current',
            superseded_by BIGINT UNSIGNED NULL,
            expiry_notified_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pfi_public (public_id),
            KEY ix_pfi_employee (employee_id, category, status),
            KEY ix_pfi_expiry (status, expiry_date),
            CONSTRAINT fk_pfi_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS emp_change_requests (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('employment','status') NOT NULL,
            payload_json TEXT NOT NULL,
            before_json TEXT NULL,
            effective_from DATE NOT NULL,
            reason VARCHAR(500) NOT NULL,
            retroactive TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('pending','approved','applied','returned','rejected','withdrawn','failed') NOT NULL DEFAULT 'pending',
            requested_by BIGINT UNSIGNED NOT NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            applied_at DATETIME(6) NULL,
            error VARCHAR(255) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_emp_change_public (public_id),
            KEY ix_emp_change_employee (employee_id, status),
            KEY ix_emp_change_due (status, effective_from)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS employee_status_history (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            status ENUM('pre_hire','active','on_leave','suspended','former') NOT NULL,
            effective_from DATE NOT NULL,
            effective_to DATE NULL,
            separation_type VARCHAR(20) NULL,
            last_working_day DATE NULL,
            reason VARCHAR(500) NULL,
            request_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_emp_status_public (public_id),
            UNIQUE KEY uq_emp_status_from (employee_id, effective_from),
            CONSTRAINT fk_emp_status_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS emp_offboarding_items (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            request_id BIGINT UNSIGNED NOT NULL,
            item_code VARCHAR(40) NOT NULL,
            label VARCHAR(160) NOT NULL,
            owner VARCHAR(20) NOT NULL,
            due_date DATE NULL,
            status ENUM('open','done','not_applicable') NOT NULL DEFAULT 'open',
            done_by BIGINT UNSIGNED NULL,
            done_at DATETIME(6) NULL,
            note VARCHAR(255) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_emp_offb_public (public_id),
            UNIQUE KEY uq_emp_offb_item (request_id, item_code),
            KEY ix_emp_offb_employee (employee_id, status),
            CONSTRAINT fk_emp_offb_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
        ) {$o}");
        // one status row per existing employee, from the hire date (former: active until the termination date)
        $now = (string) $db->scalar('SELECT UTC_TIMESTAMP(6)');
        foreach ($db->all('SELECT e.id, e.status, e.hire_date, e.termination_date FROM employees e WHERE NOT EXISTS (SELECT 1 FROM employee_status_history s WHERE s.employee_id = e.id)') as $e) {
            if ($e['status'] === 'former' && $e['termination_date'] !== null) {
                $db->run(
                    "INSERT INTO employee_status_history (public_id, created_at, updated_at, employee_id, status, effective_from, effective_to, reason) VALUES (?, ?, ?, ?, 'active', ?, ?, 'migrated (0.8.0)')",
                    [Ulid::generate(), $now, $now, $e['id'], $e['hire_date'], $e['termination_date']]
                );
                $next = (string) $db->scalar('SELECT DATE_ADD(?, INTERVAL 1 DAY)', [$e['termination_date']]);
                $db->run(
                    "INSERT INTO employee_status_history (public_id, created_at, updated_at, employee_id, status, effective_from, last_working_day, reason) VALUES (?, ?, ?, ?, 'former', ?, ?, 'migrated (0.8.0)')",
                    [Ulid::generate(), $now, $now, $e['id'], $next, $e['termination_date']]
                );
            } else {
                $db->run(
                    'INSERT INTO employee_status_history (public_id, created_at, updated_at, employee_id, status, effective_from, reason) VALUES (?, ?, ?, ?, ?, ?, \'migrated (0.8.0)\')',
                    [Ulid::generate(), $now, $now, $e['id'], $e['status'], $e['hire_date']]
                );
            }
        }
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        return Schema::expectTables($db, ['employee_contacts', 'employee_emergency_contacts', 'employee_identifiers', 'employee_education', 'employee_prior_jobs',
            'personnel_file_items', 'emp_change_requests', 'employee_status_history', 'emp_offboarding_items']);
    }
};
