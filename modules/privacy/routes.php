<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Privacy\PrivacyCenterController;

/** Privacy centre routes (R6-1). /privacy-notice/<company code>/<audience> is public (module.json "public_paths"). */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new PrivacyCenterController($app);
    $r->get('/privacy-notice/{code:[A-Za-z0-9_-]{1,20}}/{audience:[a-z]{3,12}}', [$c, 'publicNotice']);
    $r->get('/privacy/notices', [$c, 'mine'], 'privacy.mine');
    $r->get('/privacy/notices/' . $id, [$c, 'mineOne']);
    $r->post('/privacy/notices/' . $id . '/ack', [$c, 'ack']);
    $r->get('/privacy/center', [$c, 'center'], 'privacy.center');
    $r->post('/privacy/center/notices', [$c, 'startNotice']);
    $r->get('/privacy/center/n/' . $id, [$c, 'notice']);
    $r->post('/privacy/center/n/' . $id . '/{action:save|submit|publish|return|withdraw|delete}', [$c, 'noticeAction']);
    $r->post('/privacy/center/activities/seed', [$c, 'seed']);
    $r->get('/privacy/center/activities/new', [$c, 'activityForm']);
    $r->post('/privacy/center/activities/new', [$c, 'activitySave']);
    $r->get('/privacy/center/a/' . $id, [$c, 'activityForm']);
    $r->post('/privacy/center/a/' . $id, [$c, 'activitySave']);
    $r->post('/privacy/center/a/' . $id . '/{action:activate|retire}', [$c, 'activityAction']);
    $r->post('/privacy/center/breaches', [$c, 'breachNew']);
    $r->get('/privacy/center/b/' . $id, [$c, 'breach']);
    $r->post('/privacy/center/b/' . $id . '/{step:contain|assess|decide|notified|close|reopen}', [$c, 'breachAction']);
};
