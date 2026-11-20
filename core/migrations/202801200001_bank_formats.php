<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * 0.48.0 (R8-3 · DEC-117): bank transfer file formats a company defines itself — lines of text with variables (see
 * Blue\Core\Payments\BankTemplate), several banks, versioned: a draft is activated by another person who records the
 * bank's specification it was checked against; a new version replaces the active one of the same code. Payment files
 * may now also be text (txt / csv).
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Bank file formats (templates with variables); payment files may be txt/csv';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS pmt_bank_formats (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(30) NOT NULL,
            version INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            bank_code VARCHAR(10) NULL,
            status ENUM('draft','active','retired') NOT NULL DEFAULT 'draft',
            from_sample TINYINT(1) NOT NULL DEFAULT 0,
            extension ENUM('txt','csv') NOT NULL DEFAULT 'txt',
            encoding ENUM('UTF-8','TIS-620') NOT NULL DEFAULT 'UTF-8',
            line_end ENUM('CRLF','LF') NOT NULL DEFAULT 'CRLF',
            final_newline TINYINT(1) NOT NULL DEFAULT 1,
            header_tpl TEXT NULL,
            detail_tpl TEXT NOT NULL,
            footer_tpl TEXT NULL,
            params_json TEXT NOT NULL,
            spec_reference VARCHAR(200) NULL,
            activated_by BIGINT UNSIGNED NULL,
            activated_at DATETIME(6) NULL,
            retired_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pmt_bank_formats_public (public_id),
            UNIQUE KEY uq_pmt_bank_formats_version (legal_entity_id, code, version),
            KEY ix_pmt_bank_formats_status (legal_entity_id, status)
        ) {$o}");
        $db->exec("UPDATE document_classes SET allowed_types = 'xlsx,txt,csv' WHERE code = 'core.payment_file' AND allowed_types = 'xlsx'");
    }
};
