<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Visitors\VisitorController;

/** M43 visitor routes (R6-2). /visit/<token> is the visitor's own page (module.json "public_paths"). */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new VisitorController($app);
    $r->get('/visit/{token:[0-9a-f]{32}}', [$c, 'visitorPage']);
    $r->get('/visitors', [$c, 'mine'], 'visitors.mine');
    $r->post('/visitors', [$c, 'invite']);
    $r->get('/visitors/v/' . $id, [$c, 'show']);
    $r->post('/visitors/v/' . $id . '/{action:checkin|checkout|deny|cancel}', [$c, 'action']);
    $r->get('/visitors/gate', [$c, 'gate'], 'visitors.gate');
    $r->post('/visitors/gate/walk-in', [$c, 'walkIn']);
    $r->get('/visitors/admin', [$c, 'admin'], 'visitors.admin');
    $r->post('/visitors/admin/{what:settings|blacklist|unlist}', [$c, 'adminPost']);
};
