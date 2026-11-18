<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Overtime\OvertimeController;

/** M33 routes — request (self / team), detail + verify, HR: rules, payroll batches, report. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new OvertimeController($app);
    $r->get('/overtime', [$c, 'index'], 'overtime.index');
    $r->post('/overtime', [$c, 'submit']);
    $r->get('/overtime/verify', [$c, 'verifyList']);
    $r->get('/overtime/r/' . $id, [$c, 'show']);
    $r->post('/overtime/r/' . $id . '/cancel', [$c, 'cancel']);
    $r->post('/overtime/r/' . $id . '/verify', [$c, 'verify']);
    $r->get('/overtime/admin', [$c, 'admin'], 'overtime.admin');
    $r->post('/overtime/admin/rules', [$c, 'proposeRules']);
    $r->post('/overtime/admin/batch', [$c, 'createBatch']);
    $r->get('/overtime/batch/' . $id, [$c, 'batch']);
    $r->get('/overtime/batch/' . $id . '/file', [$c, 'file']);
    $r->get('/overtime/report', [$c, 'report']);
};
