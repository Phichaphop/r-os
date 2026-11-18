<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Assets\AssetsController;
use Blue\Modules\Assets\CampaignsController;

/** Asset register (M42 · 9C-1/9C-2): register · new · one asset (custody, status, files, disposal, tag) · my assets · types · verification campaigns · report. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new AssetsController($app);
    $r->get('/assets', [$c, 'index'], 'assets.index');
    $r->get('/assets/new', [$c, 'form'], 'assets.new');
    $r->post('/assets/new', [$c, 'create']);
    $r->get('/assets/a/' . $id, [$c, 'show']);
    $r->post('/assets/a/' . $id . '/{action:edit|assign|ack|return|status|file|dispose|qr}', [$c, 'act']);
    $r->get('/assets/a/' . $id . '/f/{fid:[0-9]{1,12}}', [$c, 'file']);
    $r->get('/assets/q/{token:[0-9a-f]{32}}', [$c, 'qr']);
    $r->get('/assets/labels', [$c, 'labels'], 'assets.labels');
    $r->get('/assets/mine', [$c, 'mine'], 'assets.mine');
    $r->get('/assets/types', [$c, 'types'], 'assets.types');
    $r->post('/assets/types', [$c, 'typePost']);
    $r->post('/assets/types/' . $id, [$c, 'typePost']);
    $k = new CampaignsController($app);
    $r->get('/assets/campaigns', [$k, 'index'], 'assets.campaigns');
    $r->post('/assets/campaigns', [$k, 'create']);
    $r->get('/assets/campaigns/' . $id, [$k, 'show']);
    $r->post('/assets/campaigns/' . $id . '/{action:start|finish|close}', [$k, 'act']);
    $r->get('/assets/campaigns/' . $id . '/count', [$k, 'count']);
    $r->post('/assets/campaigns/' . $id . '/count', [$k, 'record']);
    $r->post('/assets/campaigns/' . $id . '/na/{item:[0-9]{1,12}}', [$k, 'notApplicable']);
    $r->post('/assets/cases/' . $id, [$k, 'resolve']);
    $r->get('/assets/campaigns/' . $id . '/export', [$k, 'export']);
    $r->get('/assets/report', [$c, 'report'], 'assets.report');
    $r->get('/assets/export', [$c, 'export']);
};
