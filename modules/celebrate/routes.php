<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Celebrate\CelebrateController;

/** M45 routes — this month's board + wishes · my celebrations and opt-outs · HR settings, upcoming list. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new CelebrateController($app);
    $r->get('/celebrate', [$c, 'index'], 'celebrate.index');
    $r->post('/celebrate/e/' . $id . '/wish', [$c, 'wish']);
    $r->get('/celebrate/me', [$c, 'me']);
    $r->post('/celebrate/me', [$c, 'optouts']);
    $r->get('/celebrate/admin', [$c, 'admin'], 'celebrate.admin');
    $r->post('/celebrate/admin', [$c, 'saveSettings']);
    $r->post('/celebrate/admin/e/' . $id . '/cancel', [$c, 'cancel']);
};
