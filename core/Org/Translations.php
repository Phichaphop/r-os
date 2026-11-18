<?php

declare(strict_types=1);

namespace Blue\Core\Org;

use Blue\Core\Database\Connection;

/** Display names per locale for master data (P0-03 §0 — never use the display name as a key). */
final class Translations
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string, string> $byLocale locale => value */
    public function put(string $type, int $id, string $field, array $byLocale): void
    {
        foreach ($byLocale as $locale => $value) {
            $value = trim($value);
            if ($value === '') {
                $this->db->run('DELETE FROM translations WHERE entity_type = ? AND entity_id = ? AND locale = ? AND field = ?', [$type, $id, $locale, $field]);
                continue;
            }
            $this->db->run(
                'INSERT INTO translations (entity_type, entity_id, locale, field, value) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$type, $id, $locale, $field, mb_substr($value, 0, 255)]
            );
        }
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, string>> id => [locale => value]
     */
    public function many(string $type, array $ids, string $field = 'name'): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT entity_id, locale, value FROM translations WHERE entity_type = ? AND field = ? AND entity_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            [$type, $field, ...$ids]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['entity_id']][(string) $r['locale']] = (string) $r['value'];
        }
        return $out;
    }

    /** @param array<string, string> $names */
    public static function pick(array $names, string $locale, string $fallback = 'en'): string
    {
        return $names[$locale] ?? $names[$fallback] ?? (reset($names) ?: '—');
    }
}
