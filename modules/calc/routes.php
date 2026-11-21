<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Calc\CalcController;

/** M26 routes. */

return static function (Router $r, App $app): void {
    $c = new CalcController($app);
    $types = '{type:sso|ewf|severance|retirement|pit|prize}';
    $r->get('/calc', [$c, 'index'], 'calc.index');
    $r->post('/calc/' . $types, [$c, 'calculate']);
    $r->post('/calc/' . $types . '/share', [$c, 'share']);
    $r->get('/calc/s/{token:[0-9A-Za-z]{12}}', [$c, 'shared']);
    $r->post('/calc/s/{token:[0-9A-Za-z]{12}}/delete', [$c, 'unshare']);
    $r->get('/calc/rates', [$c, 'rates'], 'calc.rates');
    $r->post('/calc/rates/{type:sso|ewf|severance|pit|prize}', [$c, 'propose']);
};
