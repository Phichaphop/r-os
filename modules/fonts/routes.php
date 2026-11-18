<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Fonts\FontsController;

/** M30 routes — all need sign-in; file bytes go through FontsService (visibility, counting, audit). */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new FontsController($app);
    $r->get('/fonts', [$c, 'index'], 'fonts.index');
    $r->get('/fonts/manage', [$c, 'manage'], 'fonts.manage');
    $r->post('/fonts/manage', [$c, 'create']);
    $r->get('/fonts/manage/' . $id, [$c, 'edit']);
    $r->post('/fonts/manage/' . $id, [$c, 'update']);
    $r->post('/fonts/manage/' . $id . '/files', [$c, 'upload']);
    $r->post('/fonts/manage/' . $id . '/license', [$c, 'license']);
    $r->get('/fonts/manage/' . $id . '/license', [$c, 'licenseFile']);
    $r->post('/fonts/manage/' . $id . '/publish', [$c, 'publish']);
    $r->post('/fonts/manage/' . $id . '/status', [$c, 'status']);
    $r->get('/fonts/' . $id, [$c, 'show']);
    $r->get('/fonts/' . $id . '/zip', [$c, 'zip']);
    $r->get('/fonts/file/' . $id . '/preview', [$c, 'preview']);
    $r->get('/fonts/file/' . $id, [$c, 'download']);
};
