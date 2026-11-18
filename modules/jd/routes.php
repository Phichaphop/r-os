<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Jd\JdController;

/** M56 routes — my JD (read + acknowledge, history) · HR: positions, versions, acknowledgement report. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new JdController($app);
    $r->get('/jd', [$c, 'index'], 'jd.index');
    $r->get('/jd/v/' . $id, [$c, 'show']);
    $r->post('/jd/v/' . $id . '/ack', [$c, 'acknowledge']);
    $r->get('/jd/admin', [$c, 'admin'], 'jd.admin');
    $r->post('/jd/admin', [$c, 'create']);
    $r->get('/jd/admin/p/' . $id, [$c, 'manage']);
    $r->post('/jd/admin/p/' . $id . '/draft', [$c, 'saveDraft']);
    $r->post('/jd/admin/p/' . $id . '/action', [$c, 'action']);
    $r->post('/jd/admin/p/' . $id . '/exception', [$c, 'exception']);
    $r->post('/jd/admin/v/' . $id, [$c, 'versionAction']);
};
