<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Leave\LeaveController;

/** M32 routes — employees request; managers decide in the central inbox; HR keeps types, policy and balances. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new LeaveController($app);
    $r->get('/leave', [$c, 'index'], 'leave.index');
    $r->get('/leave/new', [$c, 'form']);
    $r->post('/leave', [$c, 'submit']);
    $r->post('/leave/preview', [$c, 'preview']);
    $r->get('/leave/team', [$c, 'team']);
    $r->get('/leave/r/' . $id, [$c, 'show']);
    $r->get('/leave/r/' . $id . '/edit', [$c, 'edit']);
    $r->post('/leave/r/' . $id, [$c, 'resubmit']);
    $r->post('/leave/r/' . $id . '/cancel', [$c, 'cancel']);
    $r->get('/leave/r/' . $id . '/document', [$c, 'document']);
    $r->get('/leave/admin', [$c, 'admin']);
    $r->post('/leave/admin/types', [$c, 'saveType']);
    $r->post('/leave/admin/policy', [$c, 'proposePolicy']);
    $r->get('/leave/admin/employee/' . $id, [$c, 'employee']);
    $r->post('/leave/admin/employee/' . $id . '/adjust', [$c, 'adjust']);
};
