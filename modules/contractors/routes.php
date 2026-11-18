<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Contractors\ContractorsController;

/** Contractors (M44 · 9C-3): companies · one company (workers, documents) · permits · one permit · gate · settings. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new ContractorsController($app);
    $r->get('/contractors', [$c, 'index'], 'contractors.index');
    $r->post('/contractors', [$c, 'createCompany']);
    $r->get('/contractors/c/' . $id, [$c, 'company']);
    $r->post('/contractors/c/' . $id . '/{action:edit|worker|document|suspend|reinstate}', [$c, 'companyPost']);
    $r->post('/contractors/c/' . $id . '/w/{wid:[0-9A-HJKMNP-TV-Z]{26}}', [$c, 'workerPost']);
    $r->get('/contractors/c/' . $id . '/f/{fid:[0-9]{1,12}}', [$c, 'file']);
    $r->get('/contractors/permits', [$c, 'permits'], 'contractors.permits');
    $r->get('/contractors/permits/new', [$c, 'permitForm']);
    $r->post('/contractors/permits/new', [$c, 'permitCreate']);
    $r->get('/contractors/p/' . $id, [$c, 'permit']);
    $r->get('/contractors/p/' . $id . '/edit', [$c, 'permitEdit']);
    $r->post('/contractors/p/' . $id . '/{action:edit|submit|cancel|complete|gate}', [$c, 'permitPost']);
    $r->get('/contractors/gate', [$c, 'gate'], 'contractors.gate');
    $r->get('/contractors/settings', [$c, 'settings'], 'contractors.settings');
    $r->post('/contractors/settings/{what:type|alert}', [$c, 'settingsPost']);
    $r->post('/contractors/settings/type/' . $id, [$c, 'typePost']);
};
