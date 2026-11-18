<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Attendance\AttendanceController;

/** M34 routes — employees see their own time and ask for corrections; managers/HR see their people; HR/IT set up. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $date = '{date:[0-9]{4}-[0-9]{2}-[0-9]{2}}';
    $c = new AttendanceController($app);
    $r->get('/attendance', [$c, 'index'], 'attendance.index');
    $r->get('/attendance/day/' . $date, [$c, 'day']);
    $r->post('/attendance/corrections', [$c, 'submit']);
    $r->get('/attendance/c/' . $id, [$c, 'correction']);
    $r->get('/attendance/c/' . $id . '/edit', [$c, 'edit']);
    $r->post('/attendance/c/' . $id, [$c, 'resubmit']);
    $r->post('/attendance/c/' . $id . '/withdraw', [$c, 'withdraw']);
    $r->get('/attendance/c/' . $id . '/evidence', [$c, 'evidence']);
    $r->get('/attendance/team', [$c, 'team']);
    $r->post('/attendance/team/recalc', [$c, 'recalcTeam']);
    $r->get('/attendance/e/' . $id, [$c, 'employee']);
    $r->get('/attendance/e/' . $id . '/day/' . $date, [$c, 'employeeDay']);
    $r->get('/attendance/admin', [$c, 'admin']);
    $r->post('/attendance/admin/schedule', [$c, 'proposeSchedule']);
    $r->post('/attendance/admin/codes', [$c, 'map']);
    $r->post('/attendance/admin/codes/' . $id . '/end', [$c, 'endMapping']);
    // 4C — attendance periods: close for payroll, reopen, payroll-input file (DEC-66)
    $pc = new \Blue\Modules\Attendance\PeriodController($app);
    $r->get('/attendance/periods', [$pc, 'index'], 'attendance.periods');
    $r->post('/attendance/periods', [$pc, 'create']);
    $r->get('/attendance/periods/' . $id, [$pc, 'show']);
    $r->post('/attendance/periods/' . $id . '/submit', [$pc, 'submit']);
    $r->post('/attendance/periods/' . $id . '/reopen', [$pc, 'reopen']);
    $r->get('/attendance/periods/' . $id . '/export', [$pc, 'export']);
    $r->get('/attendance/collector', [$c, 'collector']);
    $r->post('/attendance/collector/pull', [$c, 'pull']);
    $r->post('/attendance/collector/interval', [$c, 'interval']);
    $r->post('/attendance/collector/devices/' . $id, [$c, 'device']);
};
