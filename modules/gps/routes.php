<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Gps\GpsController;

/** M57 routes — check in/out, punch detail + review, HR: check-in points, policy, report. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new GpsController($app);
    $r->get('/gps', [$c, 'index'], 'gps.index');
    $r->post('/gps/notice', [$c, 'acknowledge']);
    $r->post('/gps/punch', [$c, 'punch']);
    $r->get('/gps/review', [$c, 'reviewList']);
    $r->get('/gps/p/' . $id, [$c, 'show']);
    $r->post('/gps/p/' . $id . '/review', [$c, 'review']);
    $r->get('/gps/admin', [$c, 'admin'], 'gps.admin');
    $r->post('/gps/admin/point', [$c, 'savePoint']);
    $r->post('/gps/admin/policy', [$c, 'proposePolicy']);
    $r->get('/gps/report', [$c, 'report']);
};
