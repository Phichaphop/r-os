<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M46 Executive dashboard (phase 5E-5, DEC-80): the KPI dictionary of each company (definition + versions), computed
 * values with their timestamp and status (no transactions are copied), alerts sent, and snapshots kept as evidence.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M46 Executive dashboard: KPI dictionary, versions, values, alerts, snapshots';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS dash_kpis (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(30) NOT NULL,
            measure VARCHAR(60) NOT NULL,
            name_th VARCHAR(120) NOT NULL,
            name_en VARCHAR(120) NOT NULL,
            purpose VARCHAR(500) NULL,
            direction ENUM('up','down','none') NOT NULL DEFAULT 'none',
            warn_at DECIMAL(14,2) NULL,
            alert_at DECIMAL(14,2) NULL,
            refresh_minutes INT UNSIGNED NOT NULL DEFAULT 60,
            owner_user_id BIGINT UNSIGNED NULL,
            status ENUM('draft','active','retired') NOT NULL DEFAULT 'draft',
            version INT UNSIGNED NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 100,
            PRIMARY KEY (id), UNIQUE KEY uq_dash_kpi_public (public_id), UNIQUE KEY uq_dash_kpi_code (legal_entity_id, code), KEY ix_dash_kpi_measure (legal_entity_id, measure)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS dash_kpi_versions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kpi_id BIGINT UNSIGNED NOT NULL,
            version INT UNSIGNED NOT NULL,
            definition_json TEXT NOT NULL,
            reason VARCHAR(300) NULL,
            changed_by BIGINT UNSIGNED NULL,
            changed_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_dash_kpi_version (kpi_id, version),
            CONSTRAINT fk_dash_kpi_version FOREIGN KEY (kpi_id) REFERENCES dash_kpis (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS dash_values (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kpi_id BIGINT UNSIGNED NOT NULL,
            version INT UNSIGNED NOT NULL,
            filter_key CHAR(40) NOT NULL,
            period_from DATE NOT NULL,
            period_to DATE NOT NULL,
            value DECIMAL(18,4) NULL,
            parts_json TEXT NULL,
            status ENUM('fresh','partial','error') NOT NULL,
            note VARCHAR(200) NULL,
            computed_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_dash_value (kpi_id, filter_key, period_from),
            CONSTRAINT fk_dash_value FOREIGN KEY (kpi_id) REFERENCES dash_kpis (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS dash_alerts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kpi_id BIGINT UNSIGNED NOT NULL,
            period_from DATE NOT NULL,
            level ENUM('warn','alert') NOT NULL,
            value DECIMAL(18,4) NULL,
            notified_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_dash_alert (kpi_id, period_from, level),
            CONSTRAINT fk_dash_alert FOREIGN KEY (kpi_id) REFERENCES dash_kpis (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS dash_snapshots (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            period VARCHAR(7) NOT NULL,
            filter_json TEXT NOT NULL,
            values_json MEDIUMTEXT NOT NULL,
            note VARCHAR(500) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_dash_snapshot_public (public_id), KEY ix_dash_snapshot (legal_entity_id, id)
        ) {$o}");
    }

    public function postCheck(Connection $db): array
    {
        return Schema::expectTables($db, ['dash_kpis', 'dash_kpi_versions', 'dash_values', 'dash_alerts', 'dash_snapshots']);
    }
};
