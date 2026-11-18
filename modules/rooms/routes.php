<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Rooms\RoomsController;

/** Meeting rooms (M04 · 9A): the day of the rooms · a room and booking · my bookings · rooms and rules (admin) · use report. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new RoomsController($app);
    $r->get('/rooms', [$c, 'day'], 'rooms.day');
    $r->get('/rooms/mine', [$c, 'mine'], 'rooms.mine');
    $r->get('/rooms/r/' . $id, [$c, 'room']);
    $r->post('/rooms/r/' . $id . '/book', [$c, 'book']);
    $r->get('/rooms/b/' . $id, [$c, 'booking']);
    $r->post('/rooms/b/' . $id . '/{action:cancel|checkin|move}', [$c, 'act']);
    $r->get('/rooms/admin', [$c, 'admin'], 'rooms.admin');
    $r->post('/rooms/admin/rooms', [$c, 'create']);
    $r->post('/rooms/r/' . $id . '/edit', [$c, 'update']);
    $r->post('/rooms/r/' . $id . '/{state:open|close}', [$c, 'state']);
    $r->post('/rooms/r/' . $id . '/closures', [$c, 'addClosure']);
    $r->post('/rooms/r/' . $id . '/closures/{cid:[0-9]{1,12}}/remove', [$c, 'removeClosure']);
    $r->get('/rooms/report', [$c, 'report'], 'rooms.report');
    $r->get('/rooms/report/export', [$c, 'export']);
};
