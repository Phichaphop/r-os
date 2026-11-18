<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Announcements\AnnouncementsController;

/** M19 routes — board, read / acknowledge / react / counted links, attachments · publishers: write, submit, engagement. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new AnnouncementsController($app);
    $r->get('/announcements', [$c, 'index'], 'announcements.index');
    $r->get('/announcements/p/' . $id, [$c, 'show']);
    $r->post('/announcements/p/' . $id . '/ack', [$c, 'acknowledge']);
    $r->post('/announcements/p/' . $id . '/react', [$c, 'react']);
    $r->get('/announcements/p/' . $id . '/go/{which:video|link}', [$c, 'go']);
    $r->get('/announcements/p/' . $id . '/file/{doc:[0-9A-HJKMNP-TV-Z]{26}}', [$c, 'attachment']);
    $r->get('/announcements/admin', [$c, 'admin'], 'announcements.admin');
    $r->post('/announcements/admin', [$c, 'create']);
    $r->get('/announcements/admin/p/' . $id, [$c, 'manage']);
    $r->post('/announcements/admin/p/' . $id, [$c, 'update']);
    $r->post('/announcements/admin/p/' . $id . '/attach', [$c, 'attach']);
    $r->post('/announcements/admin/p/' . $id . '/action', [$c, 'action']);
};
