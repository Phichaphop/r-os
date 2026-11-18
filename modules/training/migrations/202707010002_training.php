<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M10 Training (phase 5E-3, DEC-78): courses (hours, pass mark, validity, approval, prerequisite, positions that need
 * it), sessions (dates, seats, trainer, cost), enrollments (pending → enrolled/waitlisted → attended → passed/failed,
 * or no-show/cancelled/rejected) and training certificates (number, expiry, revoked with a reason).
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M10 Training: courses, sessions, enrollments, certificates';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS trn_courses (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(30) NOT NULL,
            title VARCHAR(200) NOT NULL,
            category VARCHAR(60) NULL,
            description TEXT NULL,
            objectives TEXT NULL,
            hours DECIMAL(5,1) NOT NULL,
            pass_score TINYINT UNSIGNED NULL,
            valid_months SMALLINT UNSIGNED NULL,
            needs_approval TINYINT(1) NOT NULL DEFAULT 0,
            prerequisite_id BIGINT UNSIGNED NULL,
            required_titles VARCHAR(500) NOT NULL DEFAULT '',
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            PRIMARY KEY (id), UNIQUE KEY uq_trn_course_public (public_id), UNIQUE KEY uq_trn_course_code (legal_entity_id, code)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS trn_sessions (
            {$std},
            course_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            start_time CHAR(5) NOT NULL,
            end_time CHAR(5) NOT NULL,
            location VARCHAR(200) NOT NULL,
            trainer VARCHAR(150) NULL,
            seats SMALLINT UNSIGNED NOT NULL,
            cost DECIMAL(12,2) NULL,
            enroll_until DATE NULL,
            status ENUM('planned','open','closed','completed','cancelled') NOT NULL DEFAULT 'planned',
            cancel_reason VARCHAR(300) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_trn_session_public (public_id), KEY ix_trn_session (legal_entity_id, start_date, status),
            CONSTRAINT fk_trn_session_course FOREIGN KEY (course_id) REFERENCES trn_courses (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS trn_enrollments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            session_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            status ENUM('pending','enrolled','waitlisted','attended','passed','failed','no_show','cancelled','rejected') NOT NULL,
            source ENUM('self','hr') NOT NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            attended_at DATETIME(6) NULL,
            check_method ENUM('qr','code','list') NULL,
            checked_by BIGINT UNSIGNED NULL,
            score DECIMAL(5,1) NULL,
            result_note VARCHAR(300) NULL,
            evaluated_by BIGINT UNSIGNED NULL,
            evaluated_at DATETIME(6) NULL,
            cancel_reason VARCHAR(300) NULL,
            created_at DATETIME(6) NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_trn_enr_public (public_id), UNIQUE KEY uq_trn_enr (session_id, employee_id), KEY ix_trn_enr_emp (employee_id, status),
            CONSTRAINT fk_trn_enr_session FOREIGN KEY (session_id) REFERENCES trn_sessions (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS trn_certificates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            enrollment_id BIGINT UNSIGNED NOT NULL,
            cert_no VARCHAR(40) NOT NULL,
            version_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            issued_at DATETIME(6) NOT NULL,
            issued_by BIGINT UNSIGNED NOT NULL,
            completed_on DATE NOT NULL,
            expires_on DATE NULL,
            status ENUM('valid','revoked') NOT NULL DEFAULT 'valid',
            revoke_reason VARCHAR(300) NULL,
            revoked_by BIGINT UNSIGNED NULL,
            revoked_at DATETIME(6) NULL,
            document_pid CHAR(26) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_trn_cert_public (public_id), KEY ix_trn_cert_enr (enrollment_id, status),
            CONSTRAINT fk_trn_cert_enr FOREIGN KEY (enrollment_id) REFERENCES trn_enrollments (id)
        ) {$o}");
    }

    public function postCheck(Connection $db): array
    {
        return Schema::expectTables($db, ['trn_courses', 'trn_sessions', 'trn_enrollments', 'trn_certificates']);
    }
};
