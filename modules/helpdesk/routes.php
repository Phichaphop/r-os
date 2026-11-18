<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Helpdesk\HelpdeskController;

/** Helpdesk (M15 · 9B-1): my tickets · a new ticket · one ticket (conversation, work) · the work queue · categories and teams · report. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new HelpdeskController($app);
    $r->get('/helpdesk', [$c, 'index'], 'helpdesk.index');
    $r->get('/helpdesk/new', [$c, 'form'], 'helpdesk.new');
    $r->post('/helpdesk/new', [$c, 'open']);
    $r->get('/helpdesk/t/' . $id, [$c, 'ticket']);
    $r->post('/helpdesk/t/' . $id . '/{action:message|assign|priority|transfer|status|resolve|confirm|rate|reopen|cancel}', [$c, 'act']);
    $r->get('/helpdesk/t/' . $id . '/f/{fid:[0-9]{1,12}}', [$c, 'file']);
    $r->get('/helpdesk/queue', [$c, 'queue'], 'helpdesk.queue');
    $r->get('/helpdesk/admin', [$c, 'admin'], 'helpdesk.admin');
    $r->post('/helpdesk/admin/{what:category|policy}', [$c, 'adminPost']);
    $r->post('/helpdesk/c/' . $id . '/{what:edit|member|remove}', [$c, 'categoryPost']);
    $r->get('/helpdesk/report', [$c, 'report'], 'helpdesk.report');
    $r->get('/helpdesk/report/export', [$c, 'export']);
};
