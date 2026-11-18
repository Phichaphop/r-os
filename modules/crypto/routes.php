<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Crypto\CryptoController;

/** M28 routes — the tools answer POSTs only, so inputs never appear in URLs, history or server access logs. */

return static function (Router $r, App $app): void {
    $c = new CryptoController($app);
    $r->get('/crypto', [$c, 'index'], 'crypto.index');
    $r->post('/crypto', [$c, 'run']);
};
