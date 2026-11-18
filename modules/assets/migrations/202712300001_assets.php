<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 9C-1 (DEC-94): M42 asset register — asset types (class, code prefix), assets (permanent code, serial, purchase
 * facts, site/location, cost centre, custodian, status, condition, QR token), custody (assign → acknowledge → return),
 * the append-only movement history, files (central document service) and disposal requests (approval).
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Asset register: types, assets, custody, moves, files, disposals';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS as_types (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            asset_class ENUM('fixed','it','tool','machine','facility','vehicle','low_value') NOT NULL,
            code_prefix VARCHAR(10) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), UNIQUE KEY uq_as_types_public (public_id), UNIQUE KEY uq_as_types_name (legal_entity_id, name)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS as_assets (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            type_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(40) NOT NULL,
            name VARCHAR(160) NOT NULL,
            serial_no VARCHAR(80) NULL,
            manufacturer VARCHAR(80) NULL,
            model VARCHAR(80) NULL,
            acquired_on DATE NULL,
            cost DECIMAL(14,2) NULL,
            supplier VARCHAR(160) NULL,
            purchase_ref VARCHAR(80) NULL,
            warranty_until DATE NULL,
            site_id BIGINT UNSIGNED NOT NULL,
            location VARCHAR(160) NULL,
            department_id BIGINT UNSIGNED NULL,
            custodian_id BIGINT UNSIGNED NULL,
            status ENUM('available','assigned','maintenance','lost','disposal_pending','disposed') NOT NULL DEFAULT 'available',
            asset_condition ENUM('good','fair','poor','broken') NOT NULL DEFAULT 'good',
            qr_token CHAR(32) NOT NULL,
            notes VARCHAR(1000) NULL,
            disposed_on DATE NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_as_assets_public (public_id), UNIQUE KEY uq_as_assets_code (legal_entity_id, code),
            UNIQUE KEY uq_as_assets_serial (legal_entity_id, serial_no), UNIQUE KEY uq_as_assets_qr (qr_token),
            KEY ix_as_assets_site (site_id, status), KEY ix_as_assets_custodian (custodian_id), KEY ix_as_assets_type (type_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS as_custody (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            asset_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            assigned_at DATETIME NOT NULL,
            assigned_by BIGINT UNSIGNED NOT NULL,
            condition_out VARCHAR(10) NOT NULL,
            acknowledged_at DATETIME NULL,
            returned_at DATETIME NULL,
            returned_by BIGINT UNSIGNED NULL,
            condition_in VARCHAR(10) NULL,
            note VARCHAR(500) NULL,
            leaver_notified TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), KEY ix_as_custody_asset (asset_id, returned_at), KEY ix_as_custody_user (user_id, returned_at)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS as_moves (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            asset_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(30) NOT NULL,
            before_json TEXT NULL,
            after_json TEXT NULL,
            reason VARCHAR(500) NULL,
            actor_id BIGINT UNSIGNED NULL,
            at_local DATETIME NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_as_moves_asset (asset_id, id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS as_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            asset_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('purchase','warranty','photo','handover','repair','disposal','other') NOT NULL,
            document_pid CHAR(26) NOT NULL,
            sha256 CHAR(64) NOT NULL,
            filename VARCHAR(200) NOT NULL,
            added_by BIGINT UNSIGNED NOT NULL,
            added_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_as_files_asset (asset_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS as_disposals (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            asset_id BIGINT UNSIGNED NOT NULL,
            method ENUM('sell','scrap','donate','write_off') NOT NULL,
            reason VARCHAR(1000) NOT NULL,
            proceeds DECIMAL(14,2) NULL,
            prior_status VARCHAR(20) NOT NULL,
            status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            workflow_id CHAR(26) NULL,
            requested_by BIGINT UNSIGNED NOT NULL,
            decided_at DATETIME NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_as_disposals_public (public_id), KEY ix_as_disposals_asset (asset_id, status)
        ) {$o}");
    }
};
