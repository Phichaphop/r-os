<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Qr\QrController;

/** M27 routes. /q/<token> is public (declared in module.json "public_paths"): a scanned code works without signing in. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new QrController($app);
    $r->get('/qr', [$c, 'index'], 'qr.index');
    $r->post('/qr/preview', [$c, 'preview']);
    $r->post('/qr/download', [$c, 'download']);
    $r->post('/qr/save', [$c, 'save']);
    $r->get('/qr/batch', [$c, 'batchForm']);
    $r->post('/qr/batch', [$c, 'batch']);
    $r->get('/qr/batch/template', [$c, 'batchTemplate']);
    $r->get('/qr/' . $id, [$c, 'show']);
    $r->get('/qr/' . $id . '/file', [$c, 'file']);
    $r->get('/qr/' . $id . '/edit', [$c, 'edit']);
    $r->post('/qr/' . $id . '/edit', [$c, 'update']);
    $r->post('/qr/' . $id, [$c, 'manage']);
    $r->get('/q/{token:[0-9A-Za-z]{10}}', [$c, 'go']);
};
