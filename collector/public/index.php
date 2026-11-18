<?php

declare(strict_types=1);

/*
 * Punch API front controller. Serve ONLY this folder, e.g.
 *   php -S 0.0.0.0:8787 -t collector/public          (office PC; put HTTPS in front — tunnel or reverse proxy, ADR-008)
 * The SQLite file lives outside this folder.
 */

require dirname(__DIR__) . '/bootstrap.php';

use BlueCollector\Api;
use BlueCollector\Store;

$headers = [];
foreach ($_SERVER as $k => $v) {
    if (is_string($v) && str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}
try {
    $result = (new Api(new Store(collector_db_path())))->handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string) ($_SERVER['REQUEST_URI'] ?? '/'), $headers, time());
} catch (Throwable $e) {
    error_log('collector: ' . $e->getMessage());
    $result = ['status' => 500, 'body' => ['error' => 'server_error']];
}
http_response_code($result['status']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode($result['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
