<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Recruit\RecruitController;

/** M08 routes — public job pages, applying, status check and evaluator links are open to visitors when the organization allows it. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new RecruitController($app);
    $r->get('/recruit/jobs', [$c, 'jobs'], 'recruit.jobs');
    $r->get('/recruit/jobs/' . $id, [$c, 'job']);
    $r->get('/recruit/jobs/' . $id . '/apply', [$c, 'apply']);
    $r->post('/recruit/jobs/' . $id . '/apply', [$c, 'submit']);
    $r->get('/recruit/status', [$c, 'status']);
    $r->post('/recruit/status', [$c, 'status']);
    $r->get('/recruit/eval/{token:[A-Za-z0-9_-]{32}}', [$c, 'evaluate']);
    $r->post('/recruit/eval/{token:[A-Za-z0-9_-]{32}}', [$c, 'evaluateSubmit']);

    $r->get('/recruit', [$c, 'index'], 'recruit.index');
    $r->get('/recruit/requisitions', [$c, 'requisitions'], 'recruit.requisitions');
    $r->post('/recruit/requisitions', [$c, 'createRequisition']);
    $r->get('/recruit/r/' . $id, [$c, 'requisition']);
    $r->post('/recruit/r/' . $id . '/postings', [$c, 'createPosting']);
    $r->get('/recruit/p/' . $id, [$c, 'posting']);
    $r->post('/recruit/p/' . $id, [$c, 'updatePosting']);
    $r->post('/recruit/p/' . $id . '/status', [$c, 'postingStatus']);
    $r->get('/recruit/c/' . $id, [$c, 'candidate']);
    $r->post('/recruit/c/' . $id . '/move', [$c, 'move']);
    $r->post('/recruit/c/' . $id . '/note', [$c, 'note']);
    $r->post('/recruit/c/' . $id . '/file', [$c, 'upload']);
    $r->get('/recruit/c/' . $id . '/file/{f:[0-9]{1,12}}', [$c, 'file']);
    $r->post('/recruit/c/' . $id . '/file/{f:[0-9]{1,12}}/verify', [$c, 'verify']);
    $r->post('/recruit/c/' . $id . '/schedule', [$c, 'schedule']);
    $r->post('/recruit/c/' . $id . '/i/{i:[0-9A-HJKMNP-TV-Z]{26}}/evaluator', [$c, 'evaluator']);
    $r->post('/recruit/c/' . $id . '/offer', [$c, 'offer']);
    $r->post('/recruit/c/' . $id . '/answer', [$c, 'answer']);
    $r->post('/recruit/c/' . $id . '/hire', [$c, 'hire']);
    $r->post('/recruit/c/' . $id . '/erase', [$c, 'erase']);
    $r->get('/recruit/settings', [$c, 'settings'], 'recruit.settings');
    $r->post('/recruit/settings', [$c, 'saveSettings']);
};
