<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Links\LinksController;

/** M31 routes (registered only while the module is switched on). */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new LinksController($app);
    $r->get('/links', [$c, 'index'], 'links.index');
    $r->get('/links/go/' . $id, [$c, 'go']);
    $r->get('/admin/links', [$c, 'manage'], 'links.manage');
    // management moved under Administration in 0.6.1 — old bookmarks still land on the right page
    $r->get('/links/manage', static fn (): \Blue\Core\Http\Response => new \Blue\Core\Http\Response('', 301, ['Location' => $app->view()->url('/admin/links')]));
    $r->get('/admin/links/new', [$c, 'form']);
    $r->post('/admin/links/new', [$c, 'save']);
    $r->get('/admin/links/' . $id, [$c, 'form']);
    $r->post('/admin/links/' . $id, [$c, 'save']);
    $r->post('/admin/links/' . $id . '/publish', [$c, 'publish']);
    $r->post('/admin/links/' . $id . '/status', [$c, 'status']);
    $r->post('/admin/links/' . $id . '/discard', [$c, 'discard']);
};
