<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Employees\EmployeesController;

/** M09 Employee Records (DEC-63 minimal master → completed in 5A, DEC-67). Every action checks its own permission. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new EmployeesController($app);
    $r->get('/employees', [$c, 'index'], 'employees.index');
    $r->get('/employees/new', [$c, 'createForm']);
    $r->post('/employees', [$c, 'create']);
    $r->get('/employees/report', [$c, 'report']);
    $r->get('/employees/report/export', [$c, 'export']);
    $r->get('/employees/rules', [$c, 'rules']);
    $r->post('/employees/rules', [$c, 'proposeRules']);
    $r->get('/employees/' . $id, [$c, 'show']);
    $r->post('/employees/' . $id, [$c, 'update']);
    $r->post('/employees/' . $id . '/personal', [$c, 'personal']);
    $r->post('/employees/' . $id . '/contacts', [$c, 'contacts']);
    $r->post('/employees/' . $id . '/identifiers', [$c, 'identifier']);
    $r->post('/employees/' . $id . '/identifiers/{iid:[0-9A-HJKMNP-TV-Z]{26}}/reveal', [$c, 'reveal']);
    $r->post('/employees/' . $id . '/education', [$c, 'education']);
    $r->post('/employees/' . $id . '/prior-jobs', [$c, 'priorJob']);
    $r->post('/employees/' . $id . '/{kind:education|prior_job}/{rid:[0-9A-HJKMNP-TV-Z]{26}}/remove', [$c, 'removeRow']);
    $r->post('/employees/' . $id . '/files', [$c, 'upload']);
    $r->get('/employees/' . $id . '/files/{fid:[0-9A-HJKMNP-TV-Z]{26}}', [$c, 'download']);
    $r->post('/employees/' . $id . '/files/{fid:[0-9A-HJKMNP-TV-Z]{26}}/verify', [$c, 'verify']);
    $r->post('/employees/' . $id . '/files/{fid:[0-9A-HJKMNP-TV-Z]{26}}/archive', [$c, 'archive']);
    $r->post('/employees/' . $id . '/employment', [$c, 'employment']);
    $r->post('/employees/' . $id . '/status', [$c, 'status']);
    $r->post('/employees/' . $id . '/changes/{rid:[0-9A-HJKMNP-TV-Z]{26}}/withdraw', [$c, 'withdraw']);
    $r->post('/employees/' . $id . '/changes/{rid:[0-9A-HJKMNP-TV-Z]{26}}/retry', [$c, 'retry']);
    $r->post('/employees/' . $id . '/offboarding/{oid:[0-9A-HJKMNP-TV-Z]{26}}', [$c, 'offboarding']);
    $r->post('/employees/' . $id . '/user', [$c, 'linkUser']);
    $r->get('/me/profile', [$c, 'me']);
    $r->post('/me/profile/contacts', [$c, 'meContacts']);
    $r->get('/me/profile/files/{fid:[0-9A-HJKMNP-TV-Z]{26}}', [$c, 'meFile']);
};
