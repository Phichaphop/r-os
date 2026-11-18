<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Inspection\InspectionController;

/**
 * Inspections (M18 · 9B-1): my rounds and actions · a round (answers, photos, review, close) · scan a point's QR · the
 * admin board · checklists and points (QR label) · report.
 */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new InspectionController($app);
    $r->get('/inspection', [$c, 'index'], 'inspection.index');
    $r->get('/inspection/r/' . $id, [$c, 'round']);
    $r->post('/inspection/r/' . $id . '/{action:save|submit|review|close}', [$c, 'act']);
    $r->get('/inspection/r/' . $id . '/photo/{rid:[0-9]{1,12}}', [$c, 'photo']);
    $r->get('/inspection/q/{token:[a-f0-9]{32}}', [$c, 'qr']);
    $r->post('/inspection/a/' . $id . '/{what:assign|done}', [$c, 'action']);
    $r->get('/inspection/board', [$c, 'board'], 'inspection.board');
    $r->get('/inspection/admin', [$c, 'admin'], 'inspection.admin');
    $r->post('/inspection/admin/{what:checklist|point}', [$c, 'adminPost']);
    $r->get('/inspection/c/' . $id, [$c, 'checklist']);
    $r->post('/inspection/c/' . $id, [$c, 'checklistPost']);
    $r->get('/inspection/p/' . $id, [$c, 'point']);
    $r->post('/inspection/p/' . $id . '/{what:edit|qr}', [$c, 'pointPost']);
    $r->get('/inspection/p/' . $id . '/label', [$c, 'label']);
    $r->get('/inspection/report', [$c, 'report'], 'inspection.report');
    $r->get('/inspection/report/export', [$c, 'export']);
};
