<?php

declare(strict_types=1);

namespace Blue\Modules\Vehicles;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** Vehicle booking screens (M05 · 9A-2 · DEC-90). */
final class VehiclesController extends Controller
{
    private function svc(): VehicleService
    {
        return VehicleService::of($this->app);
    }

    private function t(string $k): string
    {
        return $this->app->translator()->get($k);
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} */
    private function company(Request $r, string $perm = 'vehicles.use'): array
    {
        $ids = $this->svc()->companies($this->ctx($r)->az(), $perm);
        if ($ids === []) {
            throw HttpException::forbidden();
        }
        $companies = [];
        foreach ($this->app->db()->all('SELECT public_id, code FROM legal_entities WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY code') as $row) {
            $companies[(string) $row['public_id']] = (string) $row['code'];
        }
        $pid = (string) ($r->query['le'] ?? $r->body['le'] ?? '');
        if (!isset($companies[$pid])) {
            $pid = (string) array_key_first($companies);
        }
        return [$companies, $pid, (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$pid])];
    }

    private function back(Request $r, string $to, BusinessException $e): Response
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
        $this->flash($r, 'danger', $e->messageKey, $e->params);
        return $this->redirect($r, $to);
    }

    /** @return array<string, mixed> the map the page draws (and lets the tile origin through the CSP) */
    private function map(Request $r): array
    {
        $s = $this->svc()->settings();
        if ($s['map_enabled'] && $s['tile_origin'] !== '') {
            $r->attributes['csp_img_origins'] = [$s['tile_origin']];
        }
        return $s;
    }

    private function fresh(Response $res): Response
    {
        return $res->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        return $this->fresh($this->page('@vehicles/index', ['title' => $this->t('vehicles.title'), 'rows' => $this->svc()->mine($az), 'jobs' => $this->svc()->driverJobs($az),
            'canManage' => $this->svc()->companies($az, 'vehicles.manage') !== []]));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function form(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $pid = isset($r->params['id']) ? (string) $r->params['id'] : null;
        if ($pid !== null) {
            $b = $this->svc()->booking($az, $pid);
            if (!$b['can_edit']) {
                throw new BusinessException('vehicles.error.cannot_change', [], 409);
            }
            $le = (int) $b['legal_entity_id'];
            $companies = [(string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]) => (string) $b['le_code']];
            $lePid = (string) array_key_first($companies);
            if ($old === []) {
                $old = ['site' => $b['site_pid'], 'start' => str_replace(' ', 'T', substr((string) $b['starts_at'], 0, 16)), 'end' => str_replace(' ', 'T', substr((string) $b['ends_at'], 0, 16)),
                    'purpose' => $b['purpose'], 'passenger_count' => (string) $b['passenger_count'], 'passengers' => implode("\n", array_map(static fn (array $p): string => (string) ($p['employee_code'] ?? $p['name']), $b['passengers'])),
                    'from_name' => $b['from_name'], 'from_point' => $b['from_lat'] !== null ? $b['from_lat'] . ', ' . $b['from_lng'] : '', 'from_link' => (string) ($b['from_link'] ?? ''),
                    'to_name' => $b['to_name'], 'to_point' => $b['to_lat'] !== null ? $b['to_lat'] . ', ' . $b['to_lng'] : '', 'to_link' => (string) ($b['to_link'] ?? ''),
                    'round_trip' => (int) $b['round_trip'] === 1 ? '1' : '', 'est_km_manual' => $b['est_source'] === 'manual' ? (string) $b['est_km'] : '', 'note' => (string) ($b['note'] ?? '')];
            }
        } else {
            [$companies, $lePid, $le] = $this->company($r);
            if ($old === []) { // the requester's own site first
                $old = ['site' => (string) ($this->app->db()->scalar(
                    'SELECT s.public_id FROM users u JOIN employment_history h ON h.employee_id = u.employee_id AND h.effective_to IS NULL JOIN sites s ON s.id = h.site_id WHERE u.id = ? AND s.legal_entity_id = ?',
                    [$az->userId, $le]
                ) ?? '')];
            }
        }
        return $this->fresh($this->page('@vehicles/form', ['title' => $this->t($pid === null ? 'vehicles.new_title' : 'vehicles.edit_title'), 'companies' => $companies, 'lePid' => $lePid, 'pid' => $pid,
            'sites' => $this->svc()->sites($le), 'places' => $this->svc()->places($le, true), 'map' => $this->map($r), 'errors' => $errors, 'old' => $old, 'now' => $this->svc()->nowLocal()->format('Y-m-d\TH:i')], $status));
    }

    public function save(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $pid = isset($r->params['id']) ? (string) $r->params['id'] : null;
        $le = 0;
        if ($pid === null) {
            [, , $le] = $this->company($r);
        }
        try {
            $res = $this->svc()->save($az, $le, $r->body, ($r->body['go'] ?? '') === 'submit', $pid);
        } catch (ValidationException $e) {
            return $this->form($r, $this->errors($e), $r->body, 422);
        } catch (BusinessException $e) {
            return $this->back($r, $pid === null ? '/vehicles' : '/vehicles/b/' . $pid, $e);
        }
        $this->flash($r, $res['no_vehicle_free'] ? 'warning' : 'success', $res['submitted'] ? ($res['no_vehicle_free'] ? 'vehicles.flash.submitted_none_free' : 'vehicles.flash.submitted') : 'vehicles.flash.saved');
        return $this->redirect($r, '/vehicles/b/' . $res['pid']);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function booking(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $b = $this->svc()->booking($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->fresh($this->page('@vehicles/booking', ['title' => (string) $b['ref'], 'b' => $b, 'options' => $b['can_assign'] ? $this->svc()->options($b) : null, 'map' => $this->map($r),
            'nowAt' => $this->svc()->nowLocal()->format('Y-m-d\TH:i'), 'errors' => $errors, 'old' => $old, 'form' => $form], $status));
    }

    public function act(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        try {
            match ($action) {
                'submit' => $this->svc()->submit($az, $pid),
                'cancel' => $this->svc()->cancel($az, $pid, $this->str($r, 'reason', 300)),
                'assign' => $this->svc()->assign($az, $pid, $r->body),
                'start' => $this->svc()->startTrip($az, $pid, $r->body),
                'finish' => $this->svc()->finishTrip($az, $pid, $r->body),
                'confirm' => $this->svc()->confirm($az, $pid),
                default => $this->svc()->noShow($az, $pid, $this->str($r, 'reason', 300)),
            };
        } catch (ValidationException $e) {
            return $this->booking($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/vehicles/b/' . $pid, $e);
        }
        $this->flash($r, 'success', 'vehicles.flash.' . $action);
        return $this->redirect($r, '/vehicles/b/' . $pid);
    }

    public function board(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r, 'vehicles.manage');
        return $this->fresh($this->page('@vehicles/board', ['title' => $this->t('vehicles.board_title'), 'companies' => $companies, 'lePid' => $lePid, 'board' => $this->svc()->board($this->ctx($r)->az(), $le)]));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function admin(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r, 'vehicles.manage');
        return $this->page('@vehicles/admin', ['title' => $this->t('vehicles.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'vehicles' => $this->svc()->vehicles($le),
            'drivers' => $this->svc()->drivers($le), 'places' => $this->svc()->places($le), 'types' => $this->svc()->types($le), 'sites' => $this->svc()->sites($le), 'policy' => $this->svc()->policy($le),
            'map' => $this->map($r), 'today' => $this->svc()->today(), 'errors' => $errors, 'old' => $old, 'form' => $form], $status);
    }

    public function adminPost(Request $r): Response
    {
        [, $lePid, $le] = $this->company($r, 'vehicles.manage');
        $az = $this->ctx($r)->az();
        $what = (string) $r->params['what'];
        try {
            match ($what) {
                'vehicle' => $this->svc()->saveVehicle($az, $le, $r->body),
                'driver' => $this->svc()->saveDriver($az, $le, $r->body),
                'place' => $this->svc()->savePlace($az, $le, $r->body),
                'type' => $this->svc()->addType($az, $le, $this->str($r, 'type_name', 80)),
                default => $this->svc()->savePolicy($az, $le, $r->body),
            };
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $r->body, $what, 422);
        }
        $this->flash($r, 'success', 'vehicles.flash.saved_' . $what);
        return $this->redirect($r, '/vehicles/admin?le=' . rawurlencode($lePid) . '#va-' . $what);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function vehicle(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $v = $this->svc()->vehicle((string) $r->params['id']);
        $le = (int) $v['legal_entity_id'];
        if (!$this->ctx($r)->az()->scope('vehicles.manage')->coversLegalEntity($le)) {
            throw HttpException::notFound();
        }
        return $this->page('@vehicles/vehicle', ['title' => (string) $v['plate'], 'veh' => $v, 'types' => $this->svc()->types($le), 'sites' => $this->svc()->sites($le), 'errors' => $errors, 'old' => $old, 'form' => $form], $status);
    }

    public function vehiclePost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $v = $this->svc()->vehicle($pid);
        $az = $this->ctx($r)->az();
        $form = (string) $r->params['what'];
        try {
            if ($form === 'edit') {
                $this->svc()->saveVehicle($az, (int) $v['legal_entity_id'], $r->body, $pid);
                $this->flash($r, 'success', 'vehicles.flash.saved_vehicle');
            } else {
                $n = $this->svc()->setVehicleStatus($az, $pid, $this->str($r, 'status', 20), $this->str($r, 'status_note', 300));
                $this->flash($r, $n > 0 ? 'warning' : 'success', $n > 0 ? 'vehicles.flash.status_clash' : 'vehicles.flash.status', ['n' => (string) $n]);
            }
        } catch (ValidationException $e) {
            return $this->vehicle($r, $this->errors($e), $r->body, $form, 422);
        }
        return $this->redirect($r, '/vehicles/v/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function driver(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $d = $this->svc()->driver((string) $r->params['id']);
        $le = (int) $d['legal_entity_id'];
        if (!$this->ctx($r)->az()->scope('vehicles.manage')->coversLegalEntity($le)) {
            throw HttpException::notFound();
        }
        return $this->page('@vehicles/driver', ['title' => (string) $d['name'], 'd' => $d, 'sites' => $this->svc()->sites($le), 'errors' => $errors, 'old' => $old], $status);
    }

    public function driverPost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $d = $this->svc()->driver($pid);
        try {
            $this->svc()->saveDriver($this->ctx($r)->az(), (int) $d['legal_entity_id'], $r->body, $pid);
        } catch (ValidationException $e) {
            return $this->driver($r, $this->errors($e), $r->body, 422);
        }
        $this->flash($r, 'success', 'vehicles.flash.saved_driver');
        return $this->redirect($r, '/vehicles/d/' . $pid);
    }

    public function place(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $this->svc()->setPlaceActive($this->ctx($r)->az(), $pid, $r->params['state'] === 'show');
        $le = (string) $this->app->db()->scalar('SELECT l.public_id FROM vh_places p JOIN legal_entities l ON l.id = p.legal_entity_id WHERE p.public_id = ?', [$pid]);
        $this->flash($r, 'success', 'vehicles.flash.saved_place');
        return $this->redirect($r, '/vehicles/admin?le=' . rawurlencode($le) . '#va-place');
    }

    private function month(Request $r): string
    {
        $m = (string) ($r->query['month'] ?? '');
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) === 1 ? $m : substr($this->svc()->today(), 0, 7);
    }

    public function report(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r, 'vehicles.manage');
        $month = $this->month($r);
        return $this->page('@vehicles/report', ['title' => $this->t('vehicles.report_title'), 'companies' => $companies, 'lePid' => $lePid, 'month' => $month, 'report' => $this->svc()->report($this->ctx($r)->az(), $le, $month)]);
    }

    public function export(Request $r): Response
    {
        [, , $le] = $this->company($r, 'vehicles.manage');
        $f = $this->svc()->export($this->ctx($r)->az(), $le, $this->month($r));
        return DocumentsController::fileResponse($f['bytes'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $f['name'], false)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function settings(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        if (!$this->ctx($r)->az()->can('vehicles.settings')) {
            throw HttpException::forbidden();
        }
        return $this->page('@vehicles/settings', ['title' => $this->t('vehicles.settings_title'), 's' => $this->map($r), 'errors' => $errors, 'old' => $old], $status);
    }

    public function saveSettings(Request $r): Response
    {
        try {
            $this->svc()->saveSettings($this->ctx($r)->az(), $r->body);
        } catch (ValidationException $e) {
            return $this->settings($r, $this->errors($e), $r->body, 422);
        }
        $this->flash($r, 'success', 'vehicles.flash.settings');
        return $this->redirect($r, '/vehicles/settings');
    }
}
