<?php

declare(strict_types=1);

namespace Blue\Modules\Lockers;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** Locker screens (M07 · 9A). */
final class LockersController extends Controller
{
    private function svc(): LockerService
    {
        return LockerService::of($this->app);
    }

    private function t(string $k): string
    {
        return $this->app->translator()->get($k);
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} */
    private function company(Request $r, string $perm = 'lockers.use'): array
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

    public function index(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $az = $this->ctx($r)->az();
        return $this->page('@lockers/index', ['title' => $this->t('lockers.title'), 'companies' => $companies, 'lePid' => $lePid, 'mine' => $this->svc()->mine($az),
            'zones' => array_values(array_filter($this->svc()->zones($le), static fn (array $z): bool => $z['status'] === 'active')), 'canManage' => $az->scope('lockers.manage')->coversLegalEntity($le)])
            ->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function zone(Request $r): Response
    {
        $z = $this->svc()->zone($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->page('@lockers/zone', ['title' => (string) $z['name'], 'zone' => $z])->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function locker(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $l = $this->svc()->locker($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->page('@lockers/locker', ['title' => (string) $l['code'], 'locker' => $l, 'errors' => $errors, 'old' => $old, 'form' => $form], $status)->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function act(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        try {
            match ($action) {
                'reserve' => $this->svc()->reserve($az, $pid),
                'cancel' => $this->svc()->cancelReservation($az, $pid),
                'ack' => $this->svc()->acknowledge($az, $pid),
                'ask_return' => $this->svc()->askReturn($az, $pid),
                'issue' => $this->svc()->issueKey($az, $pid, $this->str($r, 'key_no', 40)),
                'return' => $this->svc()->recordReturn($az, $pid, $r->body),
                default => $this->svc()->setStatus($az, $pid, $this->str($r, 'status', 20), $this->str($r, 'status_note', 300)),
            };
        } catch (ValidationException $e) {
            return $this->locker($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, $e->params);
            return $this->redirect($r, '/lockers/l/' . $pid);
        }
        $this->flash($r, 'success', 'lockers.flash.' . $action);
        return $this->redirect($r, '/lockers/l/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function admin(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r, 'lockers.manage');
        $az = $this->ctx($r)->az();
        return $this->page('@lockers/admin', ['title' => $this->t('lockers.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'zones' => $this->svc()->zones($le),
            'todo' => $this->svc()->todo($az, $le), 'sites' => $this->svc()->sites($le), 'errors' => $errors, 'old' => $old], $status);
    }

    public function createZone(Request $r): Response
    {
        [, , $le] = $this->company($r, 'lockers.manage');
        try {
            $pid = $this->svc()->createZone($this->ctx($r)->az(), $le, $r->body);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $r->body, 422);
        }
        $this->flash($r, 'success', 'lockers.flash.zone');
        return $this->redirect($r, '/lockers/z/' . $pid);
    }

    public function export(Request $r): Response
    {
        [, , $le] = $this->company($r, 'lockers.manage');
        $f = $this->svc()->export($this->ctx($r)->az(), $le);
        return DocumentsController::fileResponse($f['bytes'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $f['name'], false)->withHeader('Cache-Control', 'no-store, max-age=0');
    }
}
