<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M20 Online signing (phase 5F, DEC-81): a signing request bound to one exact PDF (hash), its signers in order with
 * the box each one's signature goes in, and the history. Signatures are typed names confirmed with the signer's
 * password — no drawn or uploaded signature pictures are stored.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M20 Online signing: requests, signers, history';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS sig_requests (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            request_no VARCHAR(30) NULL,
            title VARCHAR(200) NOT NULL,
            category VARCHAR(60) NULL,
            message VARCHAR(1000) NULL,
            sequential TINYINT(1) NOT NULL DEFAULT 1,
            expires_on DATE NOT NULL,
            source_document_pid CHAR(26) NOT NULL,
            source_sha256 CHAR(64) NOT NULL,
            source_name VARCHAR(200) NOT NULL,
            pages INT UNSIGNED NOT NULL,
            status ENUM('draft','sent','partial','completed','declined','expired','cancelled','voided') NOT NULL DEFAULT 'draft',
            signed_document_pid CHAR(26) NULL,
            signed_sha256 CHAR(64) NULL,
            sent_at DATETIME(6) NULL,
            completed_at DATETIME(6) NULL,
            closed_reason VARCHAR(500) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_sig_request_public (public_id), KEY ix_sig_request (legal_entity_id, status), KEY ix_sig_signed_hash (signed_sha256), KEY ix_sig_source_hash (source_sha256)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS sig_signers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            request_id BIGINT UNSIGNED NOT NULL,
            seq SMALLINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role_label VARCHAR(80) NOT NULL,
            page INT UNSIGNED NOT NULL,
            anchor VARCHAR(20) NOT NULL,
            status ENUM('waiting','pending','signed','declined') NOT NULL DEFAULT 'waiting',
            typed_name VARCHAR(150) NULL,
            auth_method VARCHAR(30) NULL,
            signed_sha256 CHAR(64) NULL,
            signed_at DATETIME(6) NULL,
            ip VARCHAR(45) NULL,
            decline_reason VARCHAR(500) NULL,
            reminded_on DATE NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_sig_signer_public (public_id), UNIQUE KEY uq_sig_signer (request_id, user_id), KEY ix_sig_signer_user (user_id, status),
            CONSTRAINT fk_sig_signer FOREIGN KEY (request_id) REFERENCES sig_requests (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS sig_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(30) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            note VARCHAR(500) NULL,
            sha256 CHAR(64) NULL,
            occurred_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_sig_events (request_id, id),
            CONSTRAINT fk_sig_events FOREIGN KEY (request_id) REFERENCES sig_requests (id)
        ) {$o}");
    }

    public function postCheck(Connection $db): array
    {
        return Schema::expectTables($db, ['sig_requests', 'sig_signers', 'sig_events']);
    }
};
