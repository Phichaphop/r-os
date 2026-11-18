<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Forms\FormsController;

/** M02 routes — browse / preview / download, star · managers: forms, versions, archive. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new FormsController($app);
    $r->get('/forms', [$c, 'index'], 'forms.index');
    $r->get('/forms/f/' . $id, [$c, 'show']);
    $r->get('/forms/f/' . $id . '/file', [$c, 'file']);
    $r->get('/forms/f/' . $id . '/v/{v:[0-9A-HJKMNP-TV-Z]{26}}', [$c, 'file']);
    $r->post('/forms/f/' . $id . '/star', [$c, 'star']);
    $r->get('/forms/admin', [$c, 'admin'], 'forms.admin');
    $r->post('/forms/admin', [$c, 'create']);
    $r->get('/forms/admin/f/' . $id, [$c, 'manage']);
    $r->post('/forms/admin/f/' . $id, [$c, 'update']);
    $r->post('/forms/admin/f/' . $id . '/upload', [$c, 'upload']);
    $r->post('/forms/admin/f/' . $id . '/archive', [$c, 'archive']);
    $r->post('/forms/admin/v/' . $id, [$c, 'versionAction']);
};
