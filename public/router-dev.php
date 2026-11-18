<?php

// Development only: `php -S localhost:8080 -t public public/router-dev.php`
// Serves real files directly, everything else through the front controller.
$file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (PHP_SAPI === 'cli-server' && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}
// The built-in server reports a dotted path (/admin/config/key/brand.app_name) as the script name — Apache
// does not. Pin it to the front controller so the base path is detected the same way as on real hosting.
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
require __DIR__ . '/index.php';
