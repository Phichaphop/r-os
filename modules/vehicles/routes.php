<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Vehicles\VehiclesController;

/**
 * Vehicle booking (M05 · 9A-2): my requests and trips · a new request (map) · one booking (approval, assignment, trip
 * log) · the admin board · vehicles, drivers, places and policy · the month's report · map settings (system admin).
 */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new VehiclesController($app);
    $r->get('/vehicles', [$c, 'index'], 'vehicles.index');
    $r->get('/vehicles/new', [$c, 'form'], 'vehicles.new');
    $r->post('/vehicles/new', [$c, 'save']);
    $r->get('/vehicles/b/' . $id, [$c, 'booking']);
    $r->get('/vehicles/b/' . $id . '/edit', [$c, 'form']);
    $r->post('/vehicles/b/' . $id . '/edit', [$c, 'save']);
    $r->post('/vehicles/b/' . $id . '/{action:submit|cancel|assign|start|finish|confirm|noshow}', [$c, 'act']);
    $r->get('/vehicles/board', [$c, 'board'], 'vehicles.board');
    $r->get('/vehicles/admin', [$c, 'admin'], 'vehicles.admin');
    $r->post('/vehicles/admin/{what:vehicle|driver|place|type|policy}', [$c, 'adminPost']);
    $r->get('/vehicles/v/' . $id, [$c, 'vehicle']);
    $r->post('/vehicles/v/' . $id . '/{what:edit|status}', [$c, 'vehiclePost']);
    $r->get('/vehicles/d/' . $id, [$c, 'driver']);
    $r->post('/vehicles/d/' . $id . '/edit', [$c, 'driverPost']);
    $r->post('/vehicles/p/' . $id . '/{state:show|hide}', [$c, 'place']);
    $r->get('/vehicles/report', [$c, 'report'], 'vehicles.report');
    $r->get('/vehicles/report/export', [$c, 'export']);
    $r->get('/vehicles/settings', [$c, 'settings'], 'vehicles.settings');
    $r->post('/vehicles/settings', [$c, 'saveSettings']);
};
