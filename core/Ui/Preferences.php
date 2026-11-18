<?php

declare(strict_types=1);

namespace Blue\Core\Ui;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;

/** Per-user preferences kept in the database (6.8, 6.10.1 — e.g. the page size of each list). */
final class Preferences
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    public function get(int $userId, string $key): ?string
    {
        $v = $this->db->scalar('SELECT pref_value FROM user_preferences WHERE user_id = ? AND pref_key = ?', [$userId, $key]);
        return $v === null ? null : (string) $v;
    }

    public function set(int $userId, string $key, string $value): void
    {
        $this->db->run(
            'INSERT INTO user_preferences (user_id, pref_key, pref_value, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = VALUES(updated_at)',
            [$userId, mb_substr($key, 0, 80), mb_substr($value, 0, 255), $this->clock->now()->format('Y-m-d H:i:s.u')]
        );
    }
}
