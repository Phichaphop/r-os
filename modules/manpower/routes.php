<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Manpower\ManpowerController;

/** M11 routes — plans of a company, one plan (lines, plan vs actual, export), settings. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new ManpowerController($app);
    $r->get('/manpower', [$c, 'index'], 'manpower.index');
    $r->post('/manpower', [$c, 'create']);
    $r->post('/manpower/settings', [$c, 'settings']);
    $r->get('/manpower/p/' . $id, [$c, 'show']);
    $r->post('/manpower/p/' . $id, [$c, 'save']);
    $r->post('/manpower/p/' . $id . '/{action:submit|withdraw|revise|close}', [$c, 'action']);
    $r->get('/manpower/p/' . $id . '/export', [$c, 'export']);
};
