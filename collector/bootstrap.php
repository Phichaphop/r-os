<?php

declare(strict_types=1);

/*
 * Blue OS Time Collector — bootstrap (no Composer needed). Runs in the office, next to the time clocks (DEC-30/33).
 * Settings come from environment variables:
 *   COLLECTOR_DB   path of the SQLite file (default: collector/data/collector.sqlite — outside any web folder)
 */

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'BlueCollector\\')) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 13)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

function collector_db_path(): string
{
    $p = getenv('COLLECTOR_DB');
    return is_string($p) && $p !== '' ? $p : __DIR__ . '/data/collector.sqlite';
}
