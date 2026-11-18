<?php

declare(strict_types=1);

namespace Blue\Core\I18n;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;

/**
 * Copies seed translations into i18n_messages (run after migrations / on deploy).
 * Rows edited by an admin (source='admin') are never overwritten.
 */
final class MessageSync
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * @param array<string, string> $seedDirs module code => directory with <locale>.php files
     * @param list<string> $locales
     * @return int rows inserted or updated
     */
    public function sync(array $seedDirs, array $locales): int
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $count = 0;
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO i18n_messages (locale, message_key, message, source, module, updated_at)
             VALUES (?, ?, ?, 'seed', ?, ?)
             ON DUPLICATE KEY UPDATE
               message = IF(source = 'seed', VALUES(message), message),
               updated_at = IF(source = 'seed' AND message <> VALUES(message), VALUES(updated_at), updated_at)"
        );
        foreach ($seedDirs as $module => $dir) {
            foreach ($locales as $locale) {
                foreach (Translator::seedMessages([$dir], $locale) as $key => $text) {
                    $stmt->execute([$locale, $key, $text, $module, $now]);
                    $count += $stmt->rowCount() > 0 ? 1 : 0;
                }
            }
        }
        return $count;
    }
}
