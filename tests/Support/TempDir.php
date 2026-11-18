<?php

declare(strict_types=1);

namespace Blue\Tests\Support;

final class TempDir
{
    public static function make(string $prefix = 'blue'): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(5));
        mkdir($dir, 0777, true);
        return $dir;
    }

    public static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            $p = $dir . '/' . $n;
            is_dir($p) && !is_link($p) ? self::remove($p) : unlink($p);
        }
        rmdir($dir);
    }
}
