<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Talent\TalentController;

/** M49 routes — the resume builder is open to visitors when the organization allows it; the pool is HR only. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new TalentController($app);
    $r->get('/talent/builder', [$c, 'builder'], 'talent.builder');
    $r->post('/talent/builder', [$c, 'builder']);

    $r->get('/talent', [$c, 'index'], 'talent.index');
    $r->get('/talent/new', [$c, 'add']);
    $r->post('/talent/new', [$c, 'create']);
    $r->post('/talent/from/' . $id, [$c, 'fromCandidate']);
    $r->get('/talent/p/' . $id, [$c, 'profile']);
    $r->post('/talent/p/' . $id, [$c, 'update']);
    $r->post('/talent/p/' . $id . '/status', [$c, 'status']);
    $r->post('/talent/p/' . $id . '/star', [$c, 'star']);
    $r->post('/talent/p/' . $id . '/contact', [$c, 'contact']);
    $r->post('/talent/p/' . $id . '/cv', [$c, 'cvUpload']);
    $r->get('/talent/p/' . $id . '/cv', [$c, 'cv']);
    $r->post('/talent/p/' . $id . '/link', [$c, 'link']);
    $r->post('/talent/p/' . $id . '/erase', [$c, 'erase']);
};
