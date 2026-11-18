<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M26 Calculator suite: shared results (optional, expiring — 7B item 4). Rates are NOT stored here: they are
 * Config Registry versions (calc.*.rule, effective-dated, maker-checker, source reference required).
 * No row is written unless the person presses "share"; BMI never reaches the server.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M26 calculator suite: expiring shared results';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $db->exec("CREATE TABLE IF NOT EXISTS calc_shares (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token CHAR(12) NOT NULL,
            calculator VARCHAR(20) NOT NULL,
            inputs_json TEXT NOT NULL,
            result_json TEXT NOT NULL,
            rule_version VARCHAR(26) NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            owner_user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_calc_shares_token (token),
            KEY ix_calc_shares_owner (owner_user_id, created_at),
            KEY ix_calc_shares_expiry (expires_at)
        ) {$o}");
    }
};
