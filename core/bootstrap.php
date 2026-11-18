<?php

declare(strict_types=1);

/**
 * Blue OS bootstrap — used by public/index.php, bin/blue and tests.
 *
 * Runtime does NOT require Composer's vendor/ (keeps InfinityFree/FTP deploys small);
 * a tiny PSR-4 autoloader covers core/ and modules/.
 */

const BLUE_ROOT = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
    $map = [
        'Blue\\Core\\' => BLUE_ROOT . '/core/',
        'Blue\\Modules\\' => BLUE_ROOT . '/modules/',
        'Blue\\Tests\\' => BLUE_ROOT . '/tests/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
            if ($prefix === 'Blue\\Modules\\') {
                // module folders are lower-case codes (modules/qr/…) while namespaces are StudlyCase (Blue\Modules\Qr\…)
                $slash = strpos($rel, '/');
                $rel = $slash === false ? $rel : strtolower(substr($rel, 0, $slash)) . substr($rel, $slash);
            }
            $file = $dir . $rel . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
