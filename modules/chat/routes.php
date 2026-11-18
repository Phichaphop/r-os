<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Chat\ChatController;

/** M17 routes — conversation list and search, new chat, a conversation (send, poll, edit/delete/report, files, members), report queue. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new ChatController($app);
    $r->get('/chat', [$c, 'index'], 'chat.index');
    $r->get('/chat/new', [$c, 'newForm']);
    $r->post('/chat/new', [$c, 'create']);
    $r->get('/chat/c/' . $id, [$c, 'show']);
    $r->post('/chat/c/' . $id . '/send', [$c, 'send']);
    $r->get('/chat/c/' . $id . '/poll', [$c, 'poll']);
    $r->post('/chat/c/' . $id . '/m/{mid:[0-9A-HJKMNP-TV-Z]{26}}/{action:edit|delete|report}', [$c, 'message']);
    $r->get('/chat/c/' . $id . '/f/{fid:[0-9]{1,12}}', [$c, 'file']);
    $r->post('/chat/c/' . $id . '/members/{action:add|remove|leave|rename}', [$c, 'members']);
    $r->get('/chat/moderate', [$c, 'moderate'], 'chat.moderate');
    $r->post('/chat/moderate/r/' . $id . '/{decision:uphold|dismiss}', [$c, 'handle']);
    $r->get('/chat/moderate/r/' . $id . '/f/{fid:[0-9]{1,12}}', [$c, 'reportFile']);
};
