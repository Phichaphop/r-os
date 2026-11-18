<?php

declare(strict_types=1);

require __DIR__ . '/../core/bootstrap.php';

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}
require_once dirname(__DIR__) . '/collector/bootstrap.php'; // the simulated Time Collector (separate program, own autoloader)
