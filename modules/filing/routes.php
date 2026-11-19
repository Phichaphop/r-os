<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Filing\FilingController;

/** Government filing of payroll (M58 · 6E): months × filings · generate · a revision · review · export · submission · result. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new FilingController($app);
    $r->get('/filing', [$c, 'index'], 'filing.index');
    $r->post('/filing/generate', [$c, 'generate']);
    $r->get('/filing/r/' . $id, [$c, 'show']);
    $r->post('/filing/r/' . $id . '/submit', [$c, 'submit']);
    $r->get('/filing/r/' . $id . '/export', [$c, 'export']);
    $r->get('/filing/r/' . $id . '/official', [$c, 'official']);
    $r->post('/filing/r/' . $id . '/submission', [$c, 'submission']);
    $r->post('/filing/r/' . $id . '/result', [$c, 'result']);
    $r->get('/filing/r/' . $id . '/receipt', [$c, 'receipt']);
};
