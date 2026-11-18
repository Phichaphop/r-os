<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Training\TrainingController;

/** M10 routes — sessions to enrol in, my training and certificates · HR courses, sessions, check-in, results, matrix. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new TrainingController($app);
    $r->get('/training', [$c, 'index'], 'training.index');
    $r->get('/training/s/' . $id, [$c, 'session']);
    $r->post('/training/s/' . $id . '/enroll', [$c, 'enroll']);
    $r->post('/training/e/' . $id . '/cancel', [$c, 'cancel']);
    $r->get('/training/c/' . $id, [$c, 'certificate']);
    $r->get('/training/admin', [$c, 'admin'], 'training.admin');
    $r->post('/training/admin/sessions', [$c, 'createSession']);
    $r->get('/training/admin/courses', [$c, 'courses']);
    $r->post('/training/admin/courses', [$c, 'saveCourse']);
    $r->get('/training/admin/courses/' . $id, [$c, 'courses']);
    $r->post('/training/admin/courses/' . $id, [$c, 'saveCourse']);
    $r->get('/training/admin/s/' . $id, [$c, 'manage']);
    $r->post('/training/admin/s/' . $id, [$c, 'updateSession']);
    $r->post('/training/admin/s/' . $id . '/{action:status|enroll|checkin|attend|evaluate|certs}', [$c, 'sessionAction']);
    $r->post('/training/admin/cert/' . $id . '/revoke', [$c, 'revoke']);
    $r->get('/training/admin/matrix', [$c, 'matrix']);
    $r->get('/training/admin/e/' . $id, [$c, 'person']);
};
