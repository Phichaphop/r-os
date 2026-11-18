<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Events\EventsController;

/** M50 routes — events for me (join / cancel) · organisers (create, publish, cancel) · check-in desk · report and CSV. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new EventsController($app);
    $r->get('/events', [$c, 'index'], 'events.index');
    $r->get('/events/e/' . $id, [$c, 'event']);
    $r->post('/events/e/' . $id . '/{action:join|leave}', [$c, 'eventPost']);
    $r->get('/events/manage', [$c, 'manageList'], 'events.manage');
    $r->get('/events/manage/new', [$c, 'newForm']);
    $r->post('/events/manage/new', [$c, 'create']);
    $r->get('/events/m/' . $id, [$c, 'manage']);
    $r->post('/events/m/' . $id . '/{action:edit|publish|cancel}', [$c, 'managePost']);
    $r->get('/events/m/' . $id . '/desk', [$c, 'desk']);
    $r->post('/events/m/' . $id . '/desk', [$c, 'checkIn']);
    $r->get('/events/m/' . $id . '/report', [$c, 'report']);
    $r->get('/events/m/' . $id . '/export', [$c, 'export']);
};
