<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 9B-2 (DEC-93): M01 office supplies — categories and items (with a picture), stock per site (on hand and
 * reserved), the stock ledger (never deleted), department quotas, the company policy (over-quota rule, request
 * limit, open days/hours, pick-up expiry), requests with their lines, favourites and the request history.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Office supplies: categories, items, stock, ledger, quotas, policies, requests, lines, favourites, events';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS rq_categories (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), UNIQUE KEY uq_rq_categories_public (public_id), UNIQUE KEY uq_rq_categories_name (legal_entity_id, name)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rq_items (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(40) NOT NULL,
            name VARCHAR(160) NOT NULL,
            unit VARCHAR(30) NOT NULL,
            description VARCHAR(500) NULL,
            photo_pid CHAR(26) NULL,
            reorder_level INT UNSIGNED NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), UNIQUE KEY uq_rq_items_public (public_id), UNIQUE KEY uq_rq_items_code (legal_entity_id, code), KEY ix_rq_items_category (category_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rq_stock (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NOT NULL,
            on_hand INT UNSIGNED NOT NULL DEFAULT 0,
            reserved INT UNSIGNED NOT NULL DEFAULT 0,
            low_notified TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_rq_stock (item_id, site_id), KEY ix_rq_stock_site (site_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rq_ledger (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('receive','count','issue') NOT NULL,
            qty INT NOT NULL,
            balance_after INT UNSIGNED NOT NULL,
            request_id BIGINT UNSIGNED NULL,
            note VARCHAR(300) NULL,
            actor_id BIGINT UNSIGNED NULL,
            at_local DATETIME NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_rq_ledger_item (item_id, site_id, id), KEY ix_rq_ledger_le (legal_entity_id, at_local), KEY ix_rq_ledger_request (request_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rq_quotas (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            department_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            qty INT UNSIGNED NOT NULL,
            period ENUM('week','month') NOT NULL,
            valid_from DATE NOT NULL,
            valid_to DATE NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_rq_quotas_public (public_id), KEY ix_rq_quotas_dept (department_id, item_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rq_policies (
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            over_quota ENUM('warn','block','special') NULL,
            max_requests SMALLINT UNSIGNED NULL,
            max_per ENUM('week','month') NULL,
            open_days VARCHAR(20) NULL,
            open_from TIME NULL,
            open_to TIME NULL,
            expire_days SMALLINT UNSIGNED NULL,
            updated_at DATETIME(6) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (legal_entity_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rq_requests (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NOT NULL,
            department_id BIGINT UNSIGNED NULL,
            ref VARCHAR(20) NULL,
            requester_id BIGINT UNSIGNED NOT NULL,
            status ENUM('draft','pending','approved','partial','fulfilled','closed','rejected','cancelled','expired') NOT NULL DEFAULT 'draft',
            note VARCHAR(500) NULL,
            over_quota TINYINT(1) NOT NULL DEFAULT 0,
            route VARCHAR(40) NULL,
            workflow_id CHAR(26) NULL,
            pickup_code CHAR(16) NULL,
            submitted_at DATETIME NULL,
            approved_at DATETIME NULL,
            issued_at DATETIME NULL,
            issued_by BIGINT UNSIGNED NULL,
            issue_note VARCHAR(500) NULL,
            closed_at DATETIME NULL,
            reason VARCHAR(500) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_rq_requests_public (public_id), UNIQUE KEY uq_rq_requests_ref (legal_entity_id, ref), UNIQUE KEY uq_rq_requests_pickup (pickup_code),
            KEY ix_rq_requests_requester (requester_id, status), KEY ix_rq_requests_dept (department_id, submitted_at), KEY ix_rq_requests_status (legal_entity_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rq_lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            qty INT UNSIGNED NOT NULL,
            qty_issued INT UNSIGNED NULL,
            over_qty INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_rq_lines (request_id, item_id), KEY ix_rq_lines_item (item_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rq_favorites (
            user_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (user_id, item_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS rq_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(30) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            note VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_rq_events_request (request_id)
        ) {$o}");
    }
};
