<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Welfare\WelfareAdminController;
use Blue\Modules\Welfare\WelfareController;

/** Welfare claims (M37 · 6D): my claims · review queue · welfare types · payout · usage report. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new WelfareController($app);
    $r->get('/welfare', [$c, 'mine'], 'welfare.mine');
    $r->get('/welfare/new', [$c, 'form']);
    $r->post('/welfare/claims', [$c, 'create']);
    $r->get('/welfare/claims/' . $id, [$c, 'show']);
    $r->get('/welfare/claims/' . $id . '/edit', [$c, 'edit']);
    $r->post('/welfare/claims/' . $id, [$c, 'update']);
    $r->post('/welfare/claims/' . $id . '/{action:submit|cancel}', [$c, 'act']);
    $r->post('/welfare/claims/' . $id . '/files/{fid:[0-9]{1,12}}/remove', [$c, 'removeFile']);
    $r->get('/welfare/claims/' . $id . '/files/{fid:[0-9]{1,12}}', [$c, 'file']);
    $r->post('/welfare/claims/' . $id . '/decide', [$c, 'decide']);
    $a = new WelfareAdminController($app);
    $r->get('/welfare/admin', [$a, 'queue'], 'welfare.admin');
    $r->get('/welfare/types', [$a, 'types'], 'welfare.types');
    $r->post('/welfare/types', [$a, 'saveType']);
    $r->post('/welfare/types/' . $id . '/{action:activate|discard|retire}', [$a, 'typeAction']);
    $r->get('/welfare/payout', [$a, 'payout'], 'welfare.payout');
    $r->post('/welfare/payout/batches', [$a, 'createBatch']);
    $r->post('/welfare/payout/batches/' . $id . '/cancel', [$a, 'cancelBatch']);
    $r->get('/welfare/report', [$a, 'report'], 'welfare.report');
    $r->get('/welfare/report/export', [$a, 'export']);
};
