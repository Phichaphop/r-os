<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Draw\DrawController;

/** M29 routes — each organizer sees and runs only their own draws (0.6.1). */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new DrawController($app);
    $r->get('/draw', [$c, 'index'], 'draw.index');
    $r->post('/draw/spin', [$c, 'spin']);
    $r->get('/draw/template', [$c, 'template']);
    $r->get('/draw/quick', [$c, 'quick'], 'draw.quick');
    $r->post('/draw/quick', [$c, 'quick']);
    $r->get('/draw/' . $id, [$c, 'edit']);
    $r->post('/draw/' . $id, [$c, 'update']);
    $r->post('/draw/' . $id . '/participants', [$c, 'import']);
    $r->post('/draw/' . $id . '/participants/clear', [$c, 'clear']);
    $r->post('/draw/' . $id . '/prizes', [$c, 'prizes']);
    $r->post('/draw/' . $id . '/lock', [$c, 'lock']);
    $r->post('/draw/' . $id . '/unlock', [$c, 'unlock']);
    $r->get('/draw/' . $id . '/show', [$c, 'show']);
    $r->post('/draw/' . $id . '/round', [$c, 'round']);
    $r->post('/draw/' . $id . '/redraw', [$c, 'redraw']);
    $r->post('/draw/' . $id . '/mode', [$c, 'mode']);
    $r->post('/draw/' . $id . '/confirm', [$c, 'confirm']);
    $r->post('/draw/' . $id . '/publish', [$c, 'publish']);
    $r->post('/draw/' . $id . '/archive', [$c, 'archive']);
    $r->post('/draw/' . $id . '/reset', [$c, 'reset']);
    $r->get('/draw/' . $id . '/results', [$c, 'results']);
    $r->get('/draw/' . $id . '/export', [$c, 'export']);
};
