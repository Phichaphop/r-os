<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Dashboard\DashboardController;

/** M46 routes — the dashboard (filters, refresh, export, snapshot) · snapshots · KPI dictionary. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new DashboardController($app);
    $r->get('/dashboard', [$c, 'index'], 'dashboard.index');
    $r->post('/dashboard/refresh', [$c, 'refresh']);
    $r->get('/dashboard/export', [$c, 'export']);
    $r->post('/dashboard/snapshots', [$c, 'snapshot']);
    $r->get('/dashboard/snapshots', [$c, 'snapshots']);
    $r->get('/dashboard/snapshots/' . $id, [$c, 'snapshotShow']);
    $r->get('/dashboard/kpis', [$c, 'kpis']);
    $r->post('/dashboard/kpis', [$c, 'saveKpi']);
    $r->get('/dashboard/kpis/' . $id, [$c, 'kpis']);
    $r->post('/dashboard/kpis/' . $id, [$c, 'saveKpi']);
    $r->get('/dashboard/k/' . $id, [$c, 'definition']);
};
