<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Lockers\LockersController;

/** Lockers (M07 · 9A): my lockers and the zones · a zone's grid · a locker · zones and work to do (admin). */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new LockersController($app);
    $r->get('/lockers', [$c, 'index'], 'lockers.index');
    $r->get('/lockers/z/' . $id, [$c, 'zone']);
    $r->get('/lockers/l/' . $id, [$c, 'locker']);
    $r->post('/lockers/l/' . $id . '/{action:reserve|cancel|ack|ask_return|issue|return|status}', [$c, 'act']);
    $r->get('/lockers/admin', [$c, 'admin'], 'lockers.admin');
    $r->post('/lockers/admin/zones', [$c, 'createZone']);
    $r->get('/lockers/admin/export', [$c, 'export']);
};
