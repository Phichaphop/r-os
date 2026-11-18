<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Directory\DirectoryController;

/** M47 routes — search / team / pinned, pin, my extension · HR: settings, export. */

return static function (Router $r, App $app): void {
    $c = new DirectoryController($app);
    $r->get('/directory', [$c, 'index'], 'directory.index');
    $r->post('/directory/p/{id:[0-9A-HJKMNP-TV-Z]{26}}/pin', [$c, 'pin']);
    $r->post('/directory/me', [$c, 'extension']);
    $r->get('/directory/admin', [$c, 'admin'], 'directory.admin');
    $r->post('/directory/admin', [$c, 'saveSettings']);
    $r->get('/directory/admin/export', [$c, 'export']);
};
