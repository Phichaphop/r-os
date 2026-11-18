<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Forum\ForumController;

/** M54 routes — board, new thread, thread (reply, like, report, edit, moderate), replies, files, moderation queue and categories. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new ForumController($app);
    $r->get('/forum', [$c, 'index'], 'forum.index');
    $r->get('/forum/new', [$c, 'newForm']);
    $r->post('/forum/new', [$c, 'create']);
    $r->get('/forum/t/' . $id, [$c, 'thread']);
    $r->get('/forum/t/' . $id . '/f/{fid:[0-9]{1,12}}', [$c, 'file']);
    $r->post('/forum/t/' . $id . '/{action:reply|edit|like|report|hide|unhide|lock|unlock|pin|unpin}', [$c, 'threadPost']);
    $r->post('/forum/p/' . $id . '/{action:edit|like|report|remove|hide|unhide}', [$c, 'postPost']);
    $r->get('/forum/moderate', [$c, 'moderate'], 'forum.moderate');
    $r->post('/forum/moderate/r/' . $id . '/{decision:uphold|dismiss}', [$c, 'handle']);
    $r->post('/forum/moderate/category', [$c, 'categoryPost']);
    $r->post('/forum/moderate/category/' . $id, [$c, 'categoryPost']);
};
