<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M12 Certificate requests (phase 5E-2, DEC-77): certificate types (Thai/English template from the core template
 * service, signer, copies), requests without approval (submitted → preparing → ready → received, or need info /
 * cancelled), issued documents (number, frozen copy, voided with a reason) and the request log.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M12 Certificate requests: types, requests, issued documents, log';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS cert_types (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(30) NOT NULL,
            name_th VARCHAR(150) NOT NULL,
            name_en VARCHAR(150) NULL,
            description VARCHAR(500) NULL,
            template_th_id BIGINT UNSIGNED NULL,
            template_en_id BIGINT UNSIGNED NULL,
            max_copies TINYINT UNSIGNED NOT NULL DEFAULT 3,
            signer_name VARCHAR(150) NULL,
            signer_title VARCHAR(150) NULL,
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            PRIMARY KEY (id), UNIQUE KEY uq_cert_type_public (public_id), UNIQUE KEY uq_cert_type_code (legal_entity_id, code)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS cert_requests (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            request_no VARCHAR(30) NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            type_id BIGINT UNSIGNED NOT NULL,
            language ENUM('th','en') NOT NULL DEFAULT 'th',
            copies TINYINT UNSIGNED NOT NULL DEFAULT 1,
            purpose VARCHAR(300) NOT NULL,
            status ENUM('submitted','need_info','preparing','ready','received','cancelled') NOT NULL DEFAULT 'submitted',
            info_note VARCHAR(500) NULL,
            handled_by BIGINT UNSIGNED NULL,
            pickup_code CHAR(6) NULL,
            ready_at DATETIME(6) NULL,
            received_at DATETIME(6) NULL,
            receiver_name VARCHAR(150) NULL,
            receiver_relation ENUM('self','proxy') NULL,
            proxy_note VARCHAR(300) NULL,
            handed_over_by BIGINT UNSIGNED NULL,
            rating TINYINT UNSIGNED NULL,
            rating_comment VARCHAR(500) NULL,
            rated_at DATETIME(6) NULL,
            rating_waived_by BIGINT UNSIGNED NULL,
            rating_waive_reason VARCHAR(300) NULL,
            cancel_reason VARCHAR(300) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_cert_req_public (public_id), UNIQUE KEY uq_cert_req_no (request_no),
            KEY ix_cert_req_emp (employee_id, status), KEY ix_cert_req_le (legal_entity_id, status),
            CONSTRAINT fk_cert_req_type FOREIGN KEY (type_id) REFERENCES cert_types (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS cert_issues (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id BIGINT UNSIGNED NOT NULL,
            document_pid CHAR(26) NOT NULL,
            document_number VARCHAR(60) NULL,
            issued_at DATETIME(6) NOT NULL,
            issued_by BIGINT UNSIGNED NOT NULL,
            status ENUM('issued','void') NOT NULL DEFAULT 'issued',
            void_reason VARCHAR(300) NULL,
            voided_by BIGINT UNSIGNED NULL,
            voided_at DATETIME(6) NULL,
            PRIMARY KEY (id), KEY ix_cert_issue_req (request_id, status),
            CONSTRAINT fk_cert_issue_req FOREIGN KEY (request_id) REFERENCES cert_requests (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS cert_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(30) NOT NULL,
            note VARCHAR(500) NULL,
            actor_id BIGINT UNSIGNED NULL,
            occurred_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_cert_events (request_id, id),
            CONSTRAINT fk_cert_events_req FOREIGN KEY (request_id) REFERENCES cert_requests (id)
        ) {$o}");
    }

    public function postCheck(Connection $db): array
    {
        return Schema::expectTables($db, ['cert_types', 'cert_requests', 'cert_issues', 'cert_events']);
    }
};
