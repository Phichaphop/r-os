<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M13 Employee e-ID + Wallet (phase 5C, DEC-71):
 *   eid_settings          per company: what the card shows, validity, printing
 *   eid_cards             one card per issue: number, status, random QR id (no personal data in the QR), expiry, photo
 *   eid_card_events       issue / suspend / lost / resume / revoke / reissue / photo — the card's history
 *   eid_verifications     every scan on the verify page (result only, the token is not stored)
 *   eid_wallets           one closed internal benefit balance per employee (DEC-24): cached balance = sum of entries
 *   eid_wallet_entries    append-only ledger (top-up, spend, refund, reversal) with an idempotency key
 *   eid_topups            top-up batch (maker) → approval (checker) → entries + posting request (DEC-12)
 *   eid_topup_lines       one employee and amount per line
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M13 e-ID cards (status, rotating QR, verification log) and the internal wallet ledger with approved top-ups';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS eid_settings (
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            value_json TEXT NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (legal_entity_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS eid_cards (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            seq SMALLINT UNSIGNED NOT NULL,
            card_no VARCHAR(40) NOT NULL,
            qr_id CHAR(22) NOT NULL,
            status ENUM('active','suspended','lost','revoked','replaced') NOT NULL DEFAULT 'active',
            issued_at DATETIME(6) NOT NULL,
            expires_on DATE NULL,
            status_reason VARCHAR(300) NULL,
            photo_document_pid CHAR(26) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_eid_cards_public (public_id),
            UNIQUE KEY uq_eid_cards_no (card_no),
            UNIQUE KEY uq_eid_cards_qr (qr_id),
            KEY ix_eid_cards_emp (employee_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS eid_card_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            card_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(20) NOT NULL,
            reason VARCHAR(300) NULL,
            actor_id BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_eid_card_events_card (card_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS eid_verifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            card_id BIGINT UNSIGNED NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            result ENUM('valid','not_valid','expired_qr','unknown','not_allowed') NOT NULL,
            verifier_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_eid_verifications_card (card_id),
            KEY ix_eid_verifications_at (created_at)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS eid_wallets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            currency_code CHAR(3) NOT NULL,
            balance DECIMAL(19,4) NOT NULL DEFAULT 0,
            status ENUM('active','closed') NOT NULL DEFAULT 'active',
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            row_version INT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_eid_wallets_emp (employee_id),
            KEY ix_eid_wallets_le (legal_entity_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS eid_wallet_entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            wallet_id BIGINT UNSIGNED NOT NULL,
            entry_type ENUM('topup','spend','refund','reversal') NOT NULL,
            amount DECIMAL(19,4) NOT NULL,
            balance_after DECIMAL(19,4) NOT NULL,
            idempotency_key VARCHAR(120) NOT NULL,
            ref_type VARCHAR(40) NULL,
            ref_id VARCHAR(60) NULL,
            reverses_entry_id BIGINT UNSIGNED NULL,
            topup_id BIGINT UNSIGNED NULL,
            note VARCHAR(300) NULL,
            created_at DATETIME(6) NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_eid_wallet_entries_public (public_id),
            UNIQUE KEY uq_eid_wallet_entries_key (idempotency_key),
            KEY ix_eid_wallet_entries_wallet (wallet_id, id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS eid_topups (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            topup_no INT UNSIGNED NOT NULL,
            status ENUM('pending','returned','approved','rejected','cancelled','failed') NOT NULL DEFAULT 'pending',
            reason VARCHAR(300) NOT NULL,
            currency_code CHAR(3) NOT NULL,
            line_count INT UNSIGNED NOT NULL,
            total_amount DECIMAL(19,4) NOT NULL,
            rules_version_pid CHAR(26) NULL,
            workflow_instance_id BIGINT UNSIGNED NULL,
            posting_request_pid CHAR(26) NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME(6) NULL,
            error_code VARCHAR(60) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_eid_topups_public (public_id),
            UNIQUE KEY uq_eid_topups_no (legal_entity_id, topup_no)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS eid_topup_lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            topup_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(19,4) NOT NULL,
            entry_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_eid_topup_lines (topup_id, employee_id)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $out = [];
        foreach (['eid_settings', 'eid_cards', 'eid_card_events', 'eid_verifications', 'eid_wallets', 'eid_wallet_entries', 'eid_topups', 'eid_topup_lines'] as $t) {
            if (!$db->tableExists($t)) {
                $out[] = $t . ' missing';
            }
        }
        return $out;
    }
};
