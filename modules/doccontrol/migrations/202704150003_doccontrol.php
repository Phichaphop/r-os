<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M40 Document control & policy acknowledgement (phase 5D, DEC-72):
 *   dc_documents  controlled document of a company: code, title, type, owner, target group, review cycle
 *   dc_versions   immutable PDF per version (Documents class doccontrol.version) + hash: draft → review (another
 *                 person approves) → approved → effective on its date (the former one superseded); rejected / withdrawn
 *   dc_acks       who must acknowledge which version: pending → acknowledged (opened the file first; time, IP, browser,
 *                 the statement and the file hash kept) or exception (HR, with a reason)
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M40 document control: documents, immutable versions with approval and effective date, acknowledgements';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS dc_documents (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(30) NOT NULL,
            title VARCHAR(200) NOT NULL,
            doc_type ENUM('policy','rule','procedure','work_instruction','other') NOT NULL DEFAULT 'policy',
            owner_user_id BIGINT UNSIGNED NOT NULL,
            audience_json TEXT NOT NULL,
            review_months SMALLINT UNSIGNED NULL,
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            current_version_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_dc_documents_public (public_id),
            UNIQUE KEY uq_dc_documents_code (legal_entity_id, code)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS dc_versions (
            {$std},
            document_id BIGINT UNSIGNED NOT NULL,
            version_no SMALLINT UNSIGNED NOT NULL,
            document_pid CHAR(26) NOT NULL,
            file_name VARCHAR(200) NOT NULL,
            sha256 CHAR(64) NOT NULL,
            change_summary VARCHAR(1000) NOT NULL,
            effective_from DATE NOT NULL,
            review_due DATE NULL,
            requires_ack TINYINT(1) NOT NULL DEFAULT 1,
            status ENUM('draft','review','approved','effective','superseded','rejected','withdrawn') NOT NULL DEFAULT 'draft',
            workflow_instance_id BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME(6) NULL,
            effective_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_dc_versions_public (public_id),
            UNIQUE KEY uq_dc_versions_no (document_id, version_no),
            KEY ix_dc_versions_status (status, effective_from)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS dc_acks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            version_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            status ENUM('pending','acknowledged','exception') NOT NULL DEFAULT 'pending',
            assigned_at DATETIME(6) NOT NULL,
            opened_at DATETIME(6) NULL,
            acknowledged_at DATETIME(6) NULL,
            statement VARCHAR(500) NULL,
            file_sha256 CHAR(64) NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(200) NULL,
            exception_reason VARCHAR(300) NULL,
            decided_by BIGINT UNSIGNED NULL,
            reminded_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_dc_acks (version_id, employee_id),
            KEY ix_dc_acks_user (user_id, status)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $out = [];
        foreach (['dc_documents', 'dc_versions', 'dc_acks'] as $t) {
            if (!$db->tableExists($t)) {
                $out[] = $t . ' missing';
            }
        }
        return $out;
    }
};
