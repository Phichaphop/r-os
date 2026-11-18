<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Esign\EsignController;

/** M20 routes — my signing inbox · new request · a request (sign, decline, send, cancel, void, files) · company list · verify a file. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new EsignController($app);
    $r->get('/esign', [$c, 'index'], 'esign.index');
    $r->get('/esign/new', [$c, 'form']);
    $r->post('/esign/new', [$c, 'save']);
    $r->get('/esign/r/' . $id, [$c, 'show']);
    $r->get('/esign/r/' . $id . '/edit', [$c, 'form']);
    $r->post('/esign/r/' . $id . '/edit', [$c, 'save']);
    $r->post('/esign/r/' . $id . '/{action:send|sign|decline|cancel|void}', [$c, 'act']);
    $r->get('/esign/r/' . $id . '/file/{which:source|signed}', [$c, 'file']);
    $r->get('/esign/admin', [$c, 'admin'], 'esign.admin');
    $r->get('/esign/verify', [$c, 'verifyForm']);
    $r->post('/esign/verify', [$c, 'verify']);
};
