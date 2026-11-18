<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Projects\ProjectsController;

/** M24 routes — my projects, new project, project (board, table, timeline, team, updates), team, tasks (plan, submit, decide, comment). */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new ProjectsController($app);
    $r->get('/projects', [$c, 'index'], 'projects.index');
    $r->get('/projects/new', [$c, 'newForm']);
    $r->post('/projects/new', [$c, 'create']);
    $r->get('/projects/p/' . $id, [$c, 'show']);
    $r->post('/projects/p/' . $id . '/{action:edit|invite|accept|decline|start|status|complete|close|reopen|task}', [$c, 'projectPost']);
    $r->post('/projects/p/' . $id . '/member/{uid:[0-9]{1,12}}/{action:remove|lead|member}', [$c, 'memberPost']);
    $r->get('/projects/t/' . $id, [$c, 'task']);
    $r->get('/projects/t/' . $id . '/f/{fid:[0-9]{1,12}}', [$c, 'file']);
    $r->post('/projects/t/' . $id . '/{action:edit|mine|submit|accept|return|cancel|comment}', [$c, 'taskPost']);
};
