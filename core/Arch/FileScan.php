<?php

declare(strict_types=1);

namespace Blue\Core\Arch;

/** Shared file walker for the static checks (skips vendor, node_modules, .git, storage). */
final class FileScan
{
    private const SKIP = ['vendor', 'node_modules', '.git', 'storage', 'build', '.phpunit.cache'];

    /**
     * @param list<string> $extensions
     * @return list<string> paths relative to $root
     */
    public static function files(string $root, string $subdir, array $extensions): array
    {
        $base = rtrim($root, '/') . ($subdir === '' ? '' : '/' . trim($subdir, '/'));
        if (!is_dir($base)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f): bool => !($f->isDir() && in_array($f->getFilename(), self::SKIP, true))
            )
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isFile() && ($extensions === [] || in_array(strtolower($f->getExtension()), $extensions, true))) {
                $out[] = ltrim(substr($f->getPathname(), strlen(rtrim($root, '/'))), '/');
            }
        }
        sort($out);
        return $out;
    }
}
