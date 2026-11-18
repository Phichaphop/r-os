<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Shifts\ShiftsController;

/** M35 routes — my shifts and swaps; planning (patterns, rosters, grid); company shift rules. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new ShiftsController($app);
    $r->get('/shifts', [$c, 'index'], 'shifts.index');
    $r->post('/shifts/swap', [$c, 'requestSwap']);
    $r->post('/shifts/swap/' . $id . '/answer', [$c, 'answerSwap']);
    $r->post('/shifts/swap/' . $id . '/cancel', [$c, 'cancelSwap']);
    $r->get('/shifts/admin', [$c, 'admin'], 'shifts.admin');
    $r->post('/shifts/admin/pattern', [$c, 'savePattern']);
    $r->post('/shifts/admin/roster', [$c, 'createRoster']);
    $r->post('/shifts/admin/rules', [$c, 'proposeRules']);
    $r->get('/shifts/roster/' . $id, [$c, 'roster']);
    $r->post('/shifts/roster/' . $id . '/cells', [$c, 'saveCells']);
    $r->post('/shifts/roster/' . $id . '/fill', [$c, 'fill']);
    $r->post('/shifts/roster/' . $id . '/copy', [$c, 'copyWeek']);
    $r->post('/shifts/roster/' . $id . '/publish', [$c, 'publish']);
    $r->post('/shifts/roster/' . $id . '/revise', [$c, 'revise']);
    $r->post('/shifts/roster/' . $id . '/discard', [$c, 'discard']);
};
