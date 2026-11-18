<?php

declare(strict_types=1);

namespace Blue\Modules\Gps;

use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** M57 screens: check in/out (the only page allowed to ask for the location) · punch detail + review · HR: points, policy, report. */
final class GpsController extends Controller
{
    private function svc(): GpsService
    {
        return GpsService::of($this->app);
    }

    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('gps.use')) {
            throw HttpException::forbidden();
        }
        $svc = $this->svc();
        $s = $svc->state($az);
        return $this->page('@gps/index', [
            'title' => $this->app->translator()->get('gps.title'), 'state' => $s, 'punches' => $s['me'] !== null ? $svc->forEmployee((int) $s['me']['id'], 30) : [],
            'toReview' => count($svc->reviewList($az, 500)), 'canAdmin' => !$az->scope('gps.manage')->isEmpty(), 'canReport' => !$az->scope('gps.report')->isEmpty(),
        ])->withHeader('Permissions-Policy', 'geolocation=(self), camera=(), microphone=(), payment=()');
    }

    public function acknowledge(Request $r): Response
    {
        $this->svc()->acknowledge($this->ctx($r)->az());
        $this->flash($r, 'success', 'gps.flash.acknowledged');
        return $this->redirect($r, '/gps');
    }

    public function punch(Request $r): Response
    {
        $json = ($r->body['_ajax'] ?? '') === '1';
        $in = ['direction' => $this->str($r, 'direction', 3), 'lat' => $this->str($r, 'lat', 20), 'lng' => $this->str($r, 'lng', 20), 'accuracy' => $this->str($r, 'accuracy', 12),
            'captured' => $this->str($r, 'captured', 15), 'event' => $this->str($r, 'event', 40), 'offline' => $this->str($r, 'offline', 1), 'note' => $this->str($r, 'note', 300)];
        $t = $this->app->translator();
        try {
            $res = $this->svc()->punch($this->ctx($r)->az(), $in, $r->ip, (string) $r->header('user-agent'));
        } catch (ValidationException $e) {
            $msg = $t->get($this->firstError($e));
            if ($json) {
                return Response::json(['ok' => false, 'retry' => false, 'message' => $msg], 422);
            }
            $this->store($r)->set('flash', ['tone' => 'danger', 'text' => $msg]);
            return $this->redirect($r, '/gps');
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $msg = $t->get($e->messageKey);
            if ($json) {
                return Response::json(['ok' => false, 'retry' => false, 'message' => $msg], 422);
            }
            $this->store($r)->set('flash', ['tone' => 'danger', 'text' => $msg]);
            return $this->redirect($r, '/gps');
        }
        $key = $res['status'] === 'validated' ? (in_array('outside', $res['flags'], true) ? 'gps.flash.saved_outside' : 'gps.flash.saved') : 'gps.flash.flagged';
        if ($json) {
            return Response::json(['ok' => true, 'status' => $res['status'], 'message' => $t->get($key)]);
        }
        $this->flash($r, $res['status'] === 'validated' && !in_array('outside', $res['flags'], true) ? 'success' : 'warning', $key);
        return $this->redirect($r, '/gps');
    }

    /** @param array<string, string> $errors */
    public function show(Request $r, array $errors = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $p = $this->svc()->get($az, (string) $r->params['id']);
        return $this->page('@gps/show', ['title' => $this->app->translator()->get('gps.punch_title'), 'p' => $p, 'errors' => $errors], $status);
    }

    public function review(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $action = $r->input('action') === 'reject' ? 'reject' : 'approve';
        try {
            $this->svc()->review($this->ctx($r)->az(), $pid, $action, $this->str($r, 'review_note', 300));
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->show($r, ['review_note' => $this->app->translator()->get($e->messageKey)], 422);
        }
        $this->flash($r, 'success', 'gps.flash.' . $action);
        return $this->redirect($r, $r->input('back') === 'list' ? '/gps/review' : '/gps/p/' . $pid);
    }

    public function reviewList(Request $r): Response
    {
        $rows = $this->svc()->reviewList($this->ctx($r)->az());
        return $this->page('@gps/review', ['title' => $this->app->translator()->get('gps.review_title'), 'rows' => $rows]);
    }

    // ---- HR ---------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function admin(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $companies = $this->companies($az);
        if ($companies === []) {
            throw HttpException::forbidden();
        }
        $lePid = (string) ($old['legal_entity'] ?? $r->query['le'] ?? '');
        if (!isset($companies[$lePid])) {
            $lePid = (string) array_key_first($companies);
        }
        $db = $this->app->db();
        $le = (int) $db->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        $svc = $this->svc();
        $sites = [];
        foreach ($db->all("SELECT public_id, code FROM sites WHERE legal_entity_id = ? ORDER BY code", [$le]) as $s) {
            $sites[(string) $s['public_id']] = (string) $s['code'];
        }
        $edit = null;
        $editPid = (string) ($old['point'] ?? $r->query['point'] ?? '');
        if ($editPid !== '') {
            $edit = $db->one('SELECT g.*, s.public_id AS site_pid FROM gps_geofences g LEFT JOIN sites s ON s.id = g.site_id WHERE g.public_id = ? AND g.legal_entity_id = ?', [$editPid, $le]);
        }
        return $this->page('@gps/admin', [
            'title' => $this->app->translator()->get('gps.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'points' => $svc->geofences($le), 'sites' => $sites, 'edit' => $edit,
            'policy' => $svc->policy($le), 'canPolicy' => $az->scope('gps.policy')->coversLegalEntity($le), 'retention' => $this->app->retention()->current('gps_selfie'),
            'errors' => $errors, 'old' => $old, 'form' => $form, 'today' => $svc->today(),
        ], $status);
    }

    public function savePoint(Request $r): Response
    {
        $in = ['legal_entity' => $this->str($r, 'legal_entity', 26), 'point' => $this->str($r, 'point', 26), 'name' => $this->str($r, 'name', 120), 'site' => $this->str($r, 'site', 26),
            'lat' => $this->str($r, 'lat', 20), 'lng' => $this->str($r, 'lng', 20), 'radius_m' => $this->str($r, 'radius_m', 5), 'status' => $this->str($r, 'status', 8), 'reason' => $this->str($r, 'reason', 255)];
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$in['legal_entity']]);
        try {
            $this->svc()->saveGeofence($this->ctx($r)->az(), $le, $in, $in['point'] !== '' ? $in['point'] : null);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $in, 'point', 422);
        }
        $this->flash($r, 'success', 'gps.flash.point_saved');
        return $this->redirect($r, '/gps/admin?le=' . rawurlencode($in['legal_entity']));
    }

    public function proposePolicy(Request $r): Response
    {
        $in = ['legal_entity' => $this->str($r, 'legal_entity', 26), 'outside' => $this->str($r, 'outside', 5), 'max_accuracy_m' => $this->str($r, 'max_accuracy_m', 5),
            'offline_hours' => $this->str($r, 'offline_hours', 3), 'groups' => $this->str($r, 'groups', 300), 'notice' => $this->str($r, 'notice', 3000),
            'effective_from' => $this->str($r, 'effective_from', 10), 'source_reference' => $this->str($r, 'source_reference', 255), 'reason' => $this->str($r, 'reason', 255)];
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$in['legal_entity']]);
        try {
            $this->svc()->proposePolicy($this->ctx($r)->az(), $le, $in, $in['effective_from'], $in['source_reference'], $in['reason']);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $in, 'policy', 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->admin($r, ['source_reference' => $this->app->translator()->get($e->messageKey)], $in, 'policy', 422);
        }
        $this->flash($r, 'success', 'gps.flash.policy_proposed');
        return $this->redirect($r, '/gps/admin?le=' . rawurlencode($in['legal_entity']));
    }

    public function report(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if ($az->scope('gps.report')->isEmpty()) {
            throw HttpException::forbidden();
        }
        $svc = $this->svc();
        $ym = (string) ($r->query['month'] ?? substr($svc->today(), 0, 7));
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym) !== 1) {
            $ym = substr($svc->today(), 0, 7);
        }
        $rows = $svc->report($az, $ym);
        if (($r->query['format'] ?? '') !== '') {
            $headers = ['label', 'punches', 'inside', 'outside', 'waiting', 'rejected', 'offline'];
            return \Blue\Core\Files\TableExport::response(
                \Blue\Core\Files\TableExport::format($r->query['format']),
                'gps-' . $ym,
                $headers,
                array_map(static fn (array $x): array => array_map(static fn (string $h) => $x[$h], $headers), $rows),
                range(1, 6)
            );
        }
        return $this->page('@gps/report', ['title' => $this->app->translator()->get('gps.report_title'), 'rows' => $rows, 'month' => $ym]);
    }

    /** @return array<string, string> companies (public id => code) for gps.manage */
    private function companies(Authorizer $az): array
    {
        $scope = $az->scope('gps.manage');
        $out = [];
        foreach ($this->app->db()->all('SELECT id, public_id, code FROM legal_entities ORDER BY code') as $l) {
            if ($scope->coversLegalEntity((int) $l['id'])) {
                $out[(string) $l['public_id']] = (string) $l['code'];
            }
        }
        return $out;
    }
}
