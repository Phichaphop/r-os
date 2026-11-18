<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Doccontrol\DocControlController;

/** M40 routes — my documents to acknowledge, read (current version) + acknowledge · managers: documents, versions, report. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new DocControlController($app);
    $r->get('/policies', [$c, 'index'], 'doccontrol.index');
    $r->get('/policies/d/' . $id, [$c, 'show']);
    $r->get('/policies/d/' . $id . '/file', [$c, 'file']);
    $r->get('/policies/d/' . $id . '/v/{v:[0-9A-HJKMNP-TV-Z]{26}}', [$c, 'file']);
    $r->post('/policies/d/' . $id . '/ack', [$c, 'acknowledge']);
    $r->get('/policies/admin', [$c, 'admin'], 'doccontrol.admin');
    $r->post('/policies/admin', [$c, 'create']);
    $r->get('/policies/admin/d/' . $id, [$c, 'manage']);
    $r->post('/policies/admin/d/' . $id, [$c, 'update']);
    $r->post('/policies/admin/d/' . $id . '/upload', [$c, 'upload']);
    $r->post('/policies/admin/d/' . $id . '/action', [$c, 'action']);
    $r->get('/policies/admin/d/' . $id . '/report', [$c, 'export']);
    $r->post('/policies/admin/v/' . $id, [$c, 'versionAction']);
};
