<?php

declare(strict_types=1);

namespace Blue\Core\I18n;

use Blue\Core\Database\Connection;
use Throwable;

/**
 * t('key', ['name' => …]) — DEC-15:
 * - messages come from the `i18n_messages` table (seeded from core/I18n/seed/*.php and module seeds)
 * - fallback language is EN; a missing key returns the key itself (visible in QA, never a crash)
 * - before installation (no DB yet) the seed files are used directly
 */
final class Translator
{
    /** @var array<string, array<string, string>> locale => key => text */
    private array $loaded = [];

    private string $locale;

    /** @param list<string> $seedDirs */
    public function __construct(
        private readonly array $seedDirs,
        private readonly string $fallback = 'en',
        string $locale = 'th',
        private readonly ?Connection $db = null,
    ) {
        $this->locale = $locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale) ? $locale : $this->fallback;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @param array<string, scalar|null> $params */
    public function get(string $key, array $params = []): string
    {
        $text = $this->messages($this->locale)[$key]
            ?? $this->messages($this->fallback)[$key]
            ?? $key;
        if ($params !== []) {
            $text = strtr($text, array_combine(
                array_map(static fn (string $k): string => '{' . $k . '}', array_keys($params)),
                array_map(static fn ($v): string => (string) $v, array_values($params))
            ));
        }
        return $text;
    }

    public function has(string $key, ?string $locale = null): bool
    {
        return isset($this->messages($locale ?? $this->locale)[$key]);
    }

    /** @return array<string, string> */
    public function messages(string $locale): array
    {
        if (!isset($this->loaded[$locale])) {
            $this->loaded[$locale] = self::seedMessages($this->seedDirs, $locale);
            if ($this->db !== null) {
                try {
                    foreach ($this->db->all('SELECT message_key, message FROM i18n_messages WHERE locale = ?', [$locale]) as $row) {
                        $this->loaded[$locale][(string) $row['message_key']] = (string) $row['message'];
                    }
                } catch (Throwable) {
                    // DB not ready (installer, migration in progress) — seed files are enough.
                }
            }
        }
        return $this->loaded[$locale];
    }

    /**
     * @param list<string> $dirs
     * @return array<string, string>
     */
    public static function seedMessages(array $dirs, string $locale): array
    {
        $out = [];
        foreach ($dirs as $dir) {
            $file = rtrim($dir, '/') . "/{$locale}.php";
            if (is_file($file)) {
                $data = require $file;
                if (is_array($data)) {
                    $out = array_replace($out, $data);
                }
            }
        }
        return $out;
    }
}
