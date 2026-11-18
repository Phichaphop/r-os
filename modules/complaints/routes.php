<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Complaints\ComplaintController;

/**
 * M16 routes (R6-3). Everything that carries a tracking code is a POST, so the code never sits in an address, a browser
 * history or a server log. /speak-up/track is the public follow-up page (module.json "public_paths").
 */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new ComplaintController($app);
    $r->get('/complaints', [$c, 'mine'], 'complaints.mine');
    $r->post('/complaints', [$c, 'submit']);
    $r->post('/complaints/track', [$c, 'track']);
    $r->post('/complaints/track/{action:reply|accept|reopen}', [$c, 'trackAct']);
    $r->post('/complaints/track/file/{fid:[0-9]{1,12}}', [$c, 'trackFile']);
    $r->post('/complaints/{what:remember|forget}', [$c, 'vault']);
    $r->get('/speak-up/track', [$c, 'publicTrack']);
    $r->post('/speak-up/track', [$c, 'publicTrack']);
    $r->post('/speak-up/track/{action:reply|accept|reopen}', [$c, 'publicAct']);
    $r->post('/speak-up/track/file/{fid:[0-9]{1,12}}', [$c, 'publicFile']);
    $r->get('/complaints/inbox', [$c, 'inbox'], 'complaints.inbox');
    $r->get('/complaints/c/' . $id, [$c, 'show']);
    $r->get('/complaints/c/' . $id . '/f/{fid:[0-9]{1,12}}', [$c, 'file']);
    $r->post('/complaints/c/' . $id . '/{action:triage|assign|accept|decline|message|note|resolve|close|duplicate|out_of_scope|reopen}', [$c, 'act']);
    $r->get('/complaints/settings', [$c, 'settings'], 'complaints.settings');
    $r->post('/complaints/settings/{what:save|open|close}', [$c, 'settingsPost']);
    $r->get('/complaints/stats', [$c, 'stats'], 'complaints.stats');
};
