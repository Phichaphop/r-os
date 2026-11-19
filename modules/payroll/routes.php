<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Payroll\PayrollController;
use Blue\Modules\Payroll\PayslipController;
use Blue\Modules\Payroll\TaxController;

/** Payroll routes (6A) — runs, a run's sources / manual amounts / actions / register, a person's lines, components, pay profiles, rules. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $emp = '{emp:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new PayrollController($app);
    $r->get('/payroll', [$c, 'index'], 'payroll.index');
    $r->post('/payroll/runs', [$c, 'create']);
    $r->get('/payroll/runs/' . $id, [$c, 'show']);
    $r->post('/payroll/runs/' . $id . '/sources', [$c, 'sources']);
    $r->post('/payroll/runs/' . $id . '/manual', [$c, 'addManual']);
    $r->post('/payroll/runs/' . $id . '/manual/{entry:[0-9A-HJKMNP-TV-Z]{26}}/delete', [$c, 'removeManual']);
    $r->post('/payroll/runs/' . $id . '/{action:calculate|submit|cancel|payment|posting}', [$c, 'act']);
    $r->get('/payroll/runs/' . $id . '/register', [$c, 'register']);
    $r->post('/payroll/runs/' . $id . '/correction', [$c, 'correction']);
    $r->get('/payroll/runs/' . $id . '/e/' . $emp, [$c, 'result']);
    $r->get('/payroll/components', [$c, 'components'], 'payroll.components');
    $r->post('/payroll/components', [$c, 'saveComponent']);
    $r->post('/payroll/components/' . $id . '/{action:activate|retire}', [$c, 'decideComponent']);
    $r->get('/payroll/people', [$c, 'people'], 'payroll.people');
    $r->get('/payroll/people/' . $emp, [$c, 'person']);
    $r->post('/payroll/people/' . $emp, [$c, 'propose']);
    $r->get('/payroll/settings', [$c, 'settings'], 'payroll.settings');
    $r->post('/payroll/settings/{key:policy|sso|wht|wht_payer|sso_employer|filing_due|rd_file}', [$c, 'proposeRules']);
    // 6B — payslips and year-to-date
    $s = new PayslipController($app);
    $r->get('/me/payslips', [$s, 'mine'], 'payroll.mine');
    $r->get('/me/payslips/' . $id, [$s, 'show']);
    $r->get('/me/payslips/' . $id . '/pdf', [$s, 'pdf']);
    $r->post('/payroll/runs/' . $id . '/publish', [$s, 'publish']);
    $r->get('/payroll/payslips/' . $id . '/pdf', [$s, 'staffPdf']);
    $r->post('/payroll/people/' . $emp . '/opening', [$s, 'opening']);
    $r->post('/payroll/people/' . $emp . '/opening/' . $id . '/{action:approve|reject}', [$s, 'decideOpening']);
    // 6C — tax allowances and withholding tax certificates
    $x = new TaxController($app);
    $r->get('/me/tax', [$x, 'mine'], 'payroll.my_tax');
    $r->post('/me/tax', [$x, 'submit']);
    $r->get('/me/tax/certificates/' . $id . '/pdf', [$x, 'myPdf']);
    $r->get('/payroll/tax', [$x, 'index'], 'payroll.tax');
    $r->post('/payroll/tax/declarations/' . $id . '/{action:approve|reject}', [$x, 'decide']);
    $r->post('/payroll/tax/issue', [$x, 'issue']);
    $r->post('/payroll/tax/certificates/' . $id . '/reissue', [$x, 'reissue']);
    $r->get('/payroll/tax/certificates/' . $id . '/pdf', [$x, 'staffPdf']);
};
