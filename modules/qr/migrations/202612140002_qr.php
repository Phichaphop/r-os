<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M27 QR codes: saved codes (7C — purpose/type, generator/version, status, expiry/revocation) and their event history.
 * A "managed" code encodes a short link of this system (/q/<token>) so it can expire, be revoked or point elsewhere;
 * scans are counted without storing who scanned (no IP, no device).
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M27 QR codes: saved codes + managed short links + event history';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS qr_codes (
            {$std},
            owner_user_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            name VARCHAR(120) NOT NULL,
            category VARCHAR(60) NULL,
            kind ENUM('static','managed') NOT NULL,
            content TEXT NOT NULL,
            open_external TINYINT(1) NOT NULL DEFAULT 0,
            ec_level CHAR(1) NOT NULL,
            with_logo TINYINT(1) NOT NULL DEFAULT 0,
            token CHAR(10) NULL,
            status ENUM('generated','active','revoked') NOT NULL,
            expires_at DATETIME(6) NULL,
            revoked_at DATETIME(6) NULL,
            revoked_by BIGINT UNSIGNED NULL,
            revoke_reason VARCHAR(300) NULL,
            scan_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_scan_at DATETIME(6) NULL,
            encoded_sha256 CHAR(64) NOT NULL,
            qr_version TINYINT UNSIGNED NOT NULL,
            generator VARCHAR(40) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_qr_codes_public (public_id),
            UNIQUE KEY uq_qr_codes_token (token),
            KEY ix_qr_codes_owner (owner_user_id, created_at),
            KEY ix_qr_codes_kind (kind, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS qr_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            qr_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(30) NOT NULL,
            detail_json TEXT NULL,
            actor_id BIGINT UNSIGNED NULL,
            occurred_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_qr_events_qr (qr_id, id),
            CONSTRAINT fk_qr_events_qr FOREIGN KEY (qr_id) REFERENCES qr_codes (id)
        ) {$o}");
    }
};
