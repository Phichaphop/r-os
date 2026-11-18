<?php

declare(strict_types=1);

namespace Blue\Modules\Rooms;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** Meeting room screens (M04 · 9A). */
final class RoomsController extends Controller
{
    private function svc(): RoomService
    {
        return RoomService::of($this->app);
    }

    private function t(string $k): string
    {
        return $this->app->translator()->get($k);
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} */
    private function company(Request $r, string $perm = 'rooms.use'): array
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

    public function day(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $az = $this->ctx($r)->az();
        $sites = $this->svc()->sites($le);
        $sitePid = (string) ($r->query['site'] ?? '');
        $siteId = null;
        foreach ($sites as $s) {
            if ($s['public_id'] === $sitePid) {
                $siteId = (int) $s['id'];
            }
        }
        $date = (string) ($r->query['date'] ?? '');
        if (RoomService::ymd($date) === null) {
            $date = $this->svc()->today();
        }
        return $this->page('@rooms/day', ['title' => $this->t('rooms.title'), 'companies' => $companies, 'lePid' => $lePid, 'sites' => $sites, 'sitePid' => $siteId !== null ? $sitePid : '',
            'day' => $date, 'today' => $this->svc()->today(), 'grid' => $this->svc()->day($az, $le, $siteId, $date), 'canManage' => $az->scope('rooms.manage')->coversLegalEntity($le)])
            ->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function mine(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        return $this->page('@rooms/mine', ['title' => $this->t('rooms.mine_title'), 'rows' => $this->svc()->mine($az), 'canManage' => $this->svc()->companies($az, 'rooms.manage') !== []])->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     * @param list<string> $problems
     */
    public function room(Request $r, array $errors = [], array $old = [], string $form = '', array $problems = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $room = $this->svc()->visibleRoom($az, (string) $r->params['id']);
        $date = (string) ($r->query['date'] ?? $old['date'] ?? '');
        if (RoomService::ymd($date) === null) {
            $date = $this->svc()->today();
        }
        $grid = $this->svc()->day($az, (int) $room['legal_entity_id'], null, $date);
        $mineRow = $grid['rows'][(int) $room['id']] ?? [];
        return $this->page('@rooms/room', ['title' => (string) $room['name'], 'room' => $room, 'day' => $date, 'today' => $this->svc()->today(), 'slots' => $grid['slots'], 'cells' => $mineRow,
            'sites' => $room['can_manage'] ? $this->svc()->sites((int) $room['legal_entity_id']) : [], 'errors' => $errors, 'old' => $old, 'form' => $form, 'problems' => $problems, 'startAt' => (string) ($r->query['start'] ?? '')], $status)
            ->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function book(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $res = $this->svc()->book($this->ctx($r)->az(), $pid, $r->body);
        } catch (ValidationException $e) {
            return $this->room($r, $this->errors($e), $r->body, 'book', [], 422);
        } catch (BookingProblem $e) {
            return $this->room($r, [$e->field => $e->messageKey], $r->body, 'book', $e->items, 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/rooms/r/' . $pid, $e);
        }
        $this->flash($r, 'success', $res['skipped'] === [] ? 'rooms.flash.booked' : 'rooms.flash.booked_skipped', ['n' => (string) $res['booked'], 'skipped' => implode(', ', $res['skipped'])]);
        return $this->redirect($r, '/rooms/b/' . $res['first']);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function booking(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $b = $this->svc()->booking($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->page('@rooms/booking', ['title' => (string) $b['title'], 'b' => $b, 'today' => $this->svc()->today(), 'errors' => $errors, 'old' => $old, 'form' => $form], $status)
            ->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function act(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        try {
            if ($action === 'cancel') {
                $n = $this->svc()->cancel($az, $pid, $this->str($r, 'reason', 300), !empty($r->body['series']));
                $this->flash($r, 'success', 'rooms.flash.cancelled', ['n' => (string) $n]);
            } elseif ($action === 'checkin') {
                $this->svc()->checkIn($az, $pid);
                $this->flash($r, 'success', 'rooms.flash.checked_in');
            } else {
                $this->svc()->reschedule($az, $pid, $r->body);
                $this->flash($r, 'success', 'rooms.flash.moved');
            }
        } catch (ValidationException $e) {
            return $this->booking($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/rooms/b/' . $pid, $e);
        }
        return $this->redirect($r, '/rooms/b/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function admin(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r, 'rooms.manage');
        return $this->page('@rooms/admin', ['title' => $this->t('rooms.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'rooms' => $this->svc()->rooms($le),
            'sites' => $this->svc()->sites($le), 'errors' => $errors, 'old' => $old], $status);
    }

    public function create(Request $r): Response
    {
        [, , $le] = $this->company($r, 'rooms.manage');
        try {
            $pid = $this->svc()->saveRoom($this->ctx($r)->az(), $le, $r->body);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $r->body, 422);
        }
        $this->flash($r, 'success', 'rooms.flash.room_saved');
        return $this->redirect($r, '/rooms/r/' . $pid);
    }

    public function update(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $room = $this->svc()->room($pid);
        try {
            $this->svc()->saveRoom($this->ctx($r)->az(), (int) $room['legal_entity_id'], $r->body, $pid);
        } catch (ValidationException $e) {
            return $this->room($r, $this->errors($e), $r->body, 'edit', [], 422);
        }
        $this->flash($r, 'success', 'rooms.flash.room_saved');
        return $this->redirect($r, '/rooms/r/' . $pid);
    }

    public function state(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->setStatus($this->ctx($r)->az(), $pid, $r->params['state'] === 'open', $this->str($r, 'note', 300));
        } catch (ValidationException $e) {
            return $this->room($r, $this->errors($e), $r->body, 'state', [], 422);
        }
        $this->flash($r, 'success', $r->params['state'] === 'open' ? 'rooms.flash.opened' : 'rooms.flash.closed');
        return $this->redirect($r, '/rooms/r/' . $pid);
    }

    public function addClosure(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $n = $this->svc()->addClosure($this->ctx($r)->az(), $pid, $this->str($r, 'closure_from', 20), $this->str($r, 'closure_to', 20), $this->str($r, 'closure_reason', 300));
        } catch (ValidationException $e) {
            return $this->room($r, $this->errors($e), $r->body, 'closure', [], 422);
        }
        $this->flash($r, $n > 0 ? 'warning' : 'success', $n > 0 ? 'rooms.flash.closure_clash' : 'rooms.flash.closure', ['n' => (string) $n]);
        return $this->redirect($r, '/rooms/r/' . $pid);
    }

    public function removeClosure(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $this->svc()->removeClosure($this->ctx($r)->az(), $pid, (int) $r->params['cid']);
        $this->flash($r, 'success', 'rooms.flash.closure_removed');
        return $this->redirect($r, '/rooms/r/' . $pid);
    }

    private function month(Request $r): string
    {
        $m = (string) ($r->query['month'] ?? '');
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) === 1 ? $m : substr($this->svc()->today(), 0, 7);
    }

    public function report(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r, 'rooms.manage');
        $month = $this->month($r);
        return $this->page('@rooms/report', ['title' => $this->t('rooms.report_title'), 'companies' => $companies, 'lePid' => $lePid, 'month' => $month,
            'rows' => $this->svc()->usage($this->ctx($r)->az(), $le, $month)]);
    }

    public function export(Request $r): Response
    {
        [, , $le] = $this->company($r, 'rooms.manage');
        $f = $this->svc()->export($this->ctx($r)->az(), $le, $this->month($r));
        return DocumentsController::fileResponse($f['bytes'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $f['name'], false)->withHeader('Cache-Control', 'no-store, max-age=0');
    }
}
