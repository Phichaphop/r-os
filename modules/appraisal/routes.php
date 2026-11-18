<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Appraisal\AppraisalController;

/** M38 routes — my appraisals and the ones I evaluate · one appraisal · HR forms, cycles, roster, report, export. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new AppraisalController($app);
    $r->get('/appraisal', [$c, 'index'], 'appraisal.index');
    $r->get('/appraisal/a/' . $id, [$c, 'show']);
    $r->post('/appraisal/a/' . $id . '/{action:self|manager|final|ack|evaluator|skip|appeal}', [$c, 'act']);
    $r->get('/appraisal/admin', [$c, 'admin'], 'appraisal.admin');
    $r->post('/appraisal/admin/cycles', [$c, 'saveCycle']);
    $r->get('/appraisal/admin/c/' . $id, [$c, 'cycle']);
    $r->post('/appraisal/admin/c/' . $id, [$c, 'saveCycle']);
    $r->post('/appraisal/admin/c/' . $id . '/{action:open|close}', [$c, 'cycleAction']);
    $r->get('/appraisal/admin/c/' . $id . '/export', [$c, 'export']);
    $r->get('/appraisal/admin/forms', [$c, 'forms']);
    $r->post('/appraisal/admin/forms', [$c, 'saveForm']);
    $r->get('/appraisal/admin/forms/' . $id, [$c, 'forms']);
    $r->post('/appraisal/admin/forms/' . $id, [$c, 'saveForm']);
};
