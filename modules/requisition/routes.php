<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Requisition\RequisitionController;

/** Office supplies (M01 · 9B-2): catalogue · cart · my requests · one request (QR) · issue desk · items, quotas, rules · stock · report. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new RequisitionController($app);
    $r->get('/requisition', [$c, 'catalog'], 'requisition.index');
    $r->post('/requisition/add', [$c, 'add']);
    $r->post('/requisition/fav/' . $id, [$c, 'favorite']);
    $r->get('/requisition/i/' . $id . '/photo', [$c, 'photo']);
    $r->get('/requisition/cart', [$c, 'cart'], 'requisition.cart');
    $r->post('/requisition/cart/{action:save|submit}', [$c, 'cartPost']);
    $r->get('/requisition/mine', [$c, 'mine'], 'requisition.mine');
    $r->get('/requisition/r/' . $id, [$c, 'request']);
    $r->post('/requisition/r/' . $id . '/{action:cancel|receive|repeat|issue|refuse}', [$c, 'act']);
    $r->get('/requisition/issue', [$c, 'desk'], 'requisition.issue');
    $r->get('/requisition/admin', [$c, 'admin'], 'requisition.admin');
    $r->post('/requisition/admin/{what:category|item|policy|quota}', [$c, 'adminPost']);
    $r->post('/requisition/c/' . $id, [$c, 'categoryPost']);
    $r->post('/requisition/i/' . $id, [$c, 'itemPost']);
    $r->post('/requisition/q/' . $id . '/end', [$c, 'quotaEnd']);
    $r->get('/requisition/stock', [$c, 'stock'], 'requisition.stock');
    $r->post('/requisition/stock/{what:adjust|import}', [$c, 'stockPost']);
    $r->get('/requisition/report', [$c, 'report'], 'requisition.report');
    $r->get('/requisition/report/export', [$c, 'export']);
};
