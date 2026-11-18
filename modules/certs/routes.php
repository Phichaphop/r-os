<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Certs\CertController;

/** M12 routes — my requests (ask, answer, cancel, rate, own document after receipt) · HR queue, types, preparation, handover. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new CertController($app);
    $r->get('/certs', [$c, 'index'], 'certs.index');
    $r->post('/certs', [$c, 'submit']);
    $r->get('/certs/r/' . $id, [$c, 'show']);
    $r->post('/certs/r/' . $id . '/{action:answer|cancel|rate}', [$c, 'mine']);
    $r->get('/certs/r/' . $id . '/doc', [$c, 'doc']);
    $r->get('/certs/admin', [$c, 'admin'], 'certs.admin');
    $r->get('/certs/admin/types', [$c, 'types']);
    $r->post('/certs/admin/types', [$c, 'saveType']);
    $r->get('/certs/admin/types/' . $id, [$c, 'types']);
    $r->post('/certs/admin/types/' . $id, [$c, 'saveType']);
    $r->get('/certs/admin/r/' . $id, [$c, 'work']);
    $r->post('/certs/admin/r/' . $id . '/{action:take|info|issue|ready|handover|cancel|waive}', [$c, 'hr']);
};
