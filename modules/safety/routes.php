<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Safety\SafetyController;

/**
 * M39 routes — home board, report, anonymous tracking, incident (triage, investigation, actions, verification), list, figures,
 * settings; 0.45.0: the company safety policy (levels, alert chain, risk bands), printable internal forms and the incident sheet.
 */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new SafetyController($app);
    $r->get('/safety', [$c, 'index'], 'safety.index');
    $r->get('/safety/report', [$c, 'reportForm']);
    $r->post('/safety/report', [$c, 'report']);
    $r->get('/safety/track', [$c, 'track']);
    $r->post('/safety/track', [$c, 'track']);
    $r->get('/safety/i/' . $id, [$c, 'show']);
    $r->get('/safety/i/' . $id . '/f/{fid:[0-9]{1,12}}', [$c, 'file']);
    $r->post('/safety/i/' . $id . '/{action:triage|assign|reject|investigate|witness|medical|action|verify|reopen|comm}', [$c, 'incidentPost']);
    $r->post('/safety/a/' . $id . '/{action:done|cancel}', [$c, 'actionPost']);
    $r->get('/safety/manage', [$c, 'manage'], 'safety.manage');
    $r->get('/safety/stats', [$c, 'stats'], 'safety.stats');
    $r->get('/safety/i/' . $id . '/sheet', [$c, 'sheet']);
    $r->get('/safety/policy', [$c, 'policy'], 'safety.policy');
    $r->post('/safety/policy/{what:draft|save|discard|publish}', [$c, 'policyPost']);
    $r->get('/safety/forms', [$c, 'forms'], 'safety.forms');
    $r->get('/safety/forms/{form:report|near-miss|witness|risk}', [$c, 'form']);
    $r->get('/safety/settings', [$c, 'settings'], 'safety.settings');
    $r->post('/safety/settings', [$c, 'saveSettings']);
};
