<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;

/**
 * Phase 3C follow-up (user requests 2026-10-13): own menu / menu-highlight / page-background colours
 * (theme.menu_color, theme.accent_color, theme.background_color) and automatic shrinking of brand pictures.
 * No table changes — the new Config Registry keys and translations are added by the platform sync that runs
 * after every update; this marker makes an existing install show the "update database" page so that sync runs.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Appearance: own menu, highlight and background colours (config keys via platform sync)';
    }

    public function up(Connection $db): void
    {
        $db->run(
            'INSERT INTO system_info (info_key, info_value, updated_at) VALUES (?, ?, UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE info_value = VALUES(info_value), updated_at = VALUES(updated_at)',
            ['appearance_colours', 'primary,menu,accent,background']
        );
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        return $db->scalar("SELECT info_value FROM system_info WHERE info_key = 'appearance_colours'") === null
            ? ['appearance_colours marker missing'] : [];
    }
};
