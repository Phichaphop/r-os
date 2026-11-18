<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M47 Company directory (phase 5D, DEC-72). Contact data is read from the employee master every time — these tables
 * hold only what the master does not have:
 *   dir_settings   per company: which work-contact fields are shown, and whether other companies of the group may find
 *                  its people
 *   dir_profiles   the desk extension an employee publishes about themselves
 *   dir_favorites  people a user pinned
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M47 company directory: visible-field settings, extensions, favourites (contacts come from the employee master)';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $db->exec("CREATE TABLE IF NOT EXISTS dir_settings (
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            value_json TEXT NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (legal_entity_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS dir_profiles (
            employee_id BIGINT UNSIGNED NOT NULL,
            extension VARCHAR(20) NULL,
            updated_at DATETIME(6) NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (employee_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS dir_favorites (
            user_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (user_id, employee_id)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $out = [];
        foreach (['dir_settings', 'dir_profiles', 'dir_favorites'] as $t) {
            if (!$db->tableExists($t)) {
                $out[] = $t . ' missing';
            }
        }
        return $out;
    }
};
