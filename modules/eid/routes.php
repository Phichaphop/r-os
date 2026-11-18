<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Eid\EidController;

/** M13 routes — my card + wallet, show card (rotating QR), verify, HR card admin, wallet admin and top-ups. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new EidController($app);
    $r->get('/eid', [$c, 'index'], 'eid.index');
    $r->get('/eid/card', [$c, 'card']);
    $r->get('/eid/card/qr', [$c, 'cardQr']);
    $r->post('/eid/lost', [$c, 'lost']);
    $r->get('/eid/photo/' . $id, [$c, 'photo']);
    $r->get('/eid/verify', [$c, 'verifyForm']);
    $r->post('/eid/verify', [$c, 'verifyPost']);
    $r->get('/eid/v/{token:[A-Za-z0-9_.-]{20,80}}', [$c, 'verify']);
    $r->get('/eid/admin', [$c, 'admin'], 'eid.admin');
    $r->post('/eid/admin/settings', [$c, 'saveSettings']);
    $r->post('/eid/admin/issue-missing', [$c, 'issueMissing']);
    $r->get('/eid/admin/e/' . $id, [$c, 'employee']);
    $r->post('/eid/admin/e/' . $id . '/issue', [$c, 'issue']);
    $r->post('/eid/admin/c/' . $id, [$c, 'cardAction']);
    $r->get('/eid/wallet/admin', [$c, 'walletAdmin'], 'eid.wallet');
    $r->post('/eid/wallet/admin/rules', [$c, 'proposeRules']);
    $r->post('/eid/wallet/admin/topup', [$c, 'createTopup']);
    $r->get('/eid/wallet/topup/' . $id, [$c, 'topup']);
};
