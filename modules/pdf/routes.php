<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Pdf\PdfController;

/** S01 routes — all need sign-in; jobs and their files are private to the person who made them. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $op = '{op:[a-z_]{3,20}}';
    $c = new PdfController($app);
    $r->get('/pdf', [$c, 'index'], 'pdf.index');
    $r->get('/pdf/admin', [$c, 'admin'], 'pdf.admin');
    $r->post('/pdf/admin', [$c, 'saveAdmin']);
    $r->get('/pdf/sign', [$c, 'sign'], 'pdf.sign');
    $r->get('/pdf/tool/' . $op, [$c, 'tool']);
    $r->post('/pdf/tool/' . $op, [$c, 'stage']);
    $r->get('/pdf/job/' . $id, [$c, 'job']);
    $r->post('/pdf/job/' . $id . '/run', [$c, 'run']);
    $r->get('/pdf/job/' . $id . '/file/{n:[0-9]{1,3}}', [$c, 'file']);
    $r->post('/pdf/job/' . $id . '/file/{n:[0-9]{1,3}}/save', [$c, 'save']);
    $r->get('/pdf/job/' . $id . '/zip', [$c, 'zip']);
    $r->get('/pdf/job/' . $id . '/preview/{k:in|out}/{n:[0-9]{1,3}}', [$c, 'preview']);
    $r->post('/pdf/job/' . $id . '/discard', [$c, 'discard']);
};
