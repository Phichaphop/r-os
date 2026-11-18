<?php

declare(strict_types=1);

namespace Blue\Modules\Events;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Modules\Employees\Audience;

/** Event screens (M50 · 9D-2). */
final class EventsController extends Controller
{
    private function svc(): EventService
    {
        return EventService::of($this->app);
    }

    private function t(string $k): string
    {
        return $this->app->translator()->get($k);
    }

    private function fresh(Response $res): Response
    {
        return $res->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    private function canManage(Request $r): bool
    {
        return $this->svc()->companies($this->ctx($r)->az(), 'events.manage') !== [];
    }

    private function fail(Request $r, string $to, BusinessException $e): Response
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
        $this->flash($r, 'danger', $e->messageKey, $e->params);
        return $this->redirect($r, $to);
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} */
    private function legalEntity(Request $r): array
    {
        $ids = $this->svc()->companies($this->ctx($r)->az(), 'events.manage');
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
        $az = $this->ctx($r)->az();
        return $this->fresh($this->page('@events/index', ['title' => $this->t('events.title'), 'rows' => $this->svc()->forMe($az), 'past' => $this->svc()->myPast($az),
            'canManage' => $this->canManage($r), 'here' => '/events']));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function event(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $ev = $this->svc()->event($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->fresh($this->page('@events/event', ['title' => (string) $ev['title'], 'ev' => $ev, 'errors' => $errors, 'old' => $old, 'canManage' => $this->canManage($r), 'here' => '/events'], $status));
    }

    public function eventPost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $join = $r->params['action'] === 'join';
        try {
            $key = $join ? 'events.flash.' . $this->svc()->register($this->ctx($r)->az(), $pid, $this->str($r, 'choice', 120)) : 'events.flash.left';
        } catch (ValidationException $e) {
            return $this->event($r, $this->errors($e), $r->body, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/events/e/' . $pid, $e);
        }
        $this->flash($r, 'success', $key);
        return $this->redirect($r, '/events/e/' . $pid);
    }

    public function manageList(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r);
        return $this->fresh($this->page('@events/manage_list', ['title' => $this->t('events.manage_title'), 'companies' => $companies, 'lePid' => $lePid,
            'rows' => $this->svc()->manageList($this->ctx($r)->az(), $le), 'canManage' => true, 'here' => '/events/manage']));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function newForm(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->legalEntity($r);
        $audIn = ['sites' => (array) ($old['sites'] ?? []), 'departments' => (array) ($old['departments'] ?? []), 'groups' => (string) ($old['groups'] ?? '')];
        return $this->fresh($this->page('@events/edit', ['title' => $this->t('events.new_event'), 'companies' => $companies, 'lePid' => $lePid, 'ev' => null,
            'audOptions' => Audience::options($this->app, $le), 'audIn' => $audIn, 'errors' => $errors, 'old' => $old, 'canManage' => true, 'here' => '/events/manage'], $status));
    }

    public function create(Request $r): Response
    {
        [, , $le] = $this->legalEntity($r);
        try {
            $pid = $this->svc()->save($this->ctx($r)->az(), $le, $r->body);
        } catch (ValidationException $e) {
            return $this->newForm($r, $this->errors($e), $r->body, 422);
        }
        $this->flash($r, 'success', 'events.flash.saved');
        return $this->redirect($r, '/events/m/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function manage(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $ev = $this->svc()->manage($this->ctx($r)->az(), (string) $r->params['id']);
        $fill = $form === 'edit' ? $old : [
            'title' => $ev['title'], 'kind' => $ev['kind'], 'description' => $ev['description'], 'venue' => $ev['venue'], 'starts_at' => str_replace(' ', 'T', substr((string) $ev['starts_at'], 0, 16)),
            'ends_at' => str_replace(' ', 'T', substr((string) $ev['ends_at'], 0, 16)), 'reg_until' => str_replace(' ', 'T', substr((string) $ev['reg_until'], 0, 16)), 'quota' => $ev['quota'],
            'waitlist' => (string) $ev['waitlist'], 'walk_in' => (string) $ev['walk_in'], 'option_label' => $ev['option_label'], 'options' => $ev['options'], 'cost_note' => $ev['cost_note']];
        $audIn = $form === 'edit' ? ['sites' => (array) ($old['sites'] ?? []), 'departments' => (array) ($old['departments'] ?? []), 'groups' => (string) ($old['groups'] ?? '')] : $ev['audience_in'];
        return $this->fresh($this->page('@events/manage', ['title' => (string) $ev['title'], 'ev' => $ev, 'fill' => $fill, 'audOptions' => Audience::options($this->app, (int) $ev['legal_entity_id']), 'audIn' => $audIn,
            'errors' => $errors, 'old' => $old, 'form' => $form, 'canManage' => true, 'here' => '/events/manage'], $status));
    }

    public function managePost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $action = (string) $r->params['action'];
        $az = $this->ctx($r)->az();
        try {
            if ($action === 'edit') {
                $ev = $this->svc()->manage($az, $pid);
                $this->svc()->save($az, (int) $ev['legal_entity_id'], $r->body, $pid);
            } else {
                $this->svc()->transition($az, $pid, $action === 'publish' ? 'published' : 'cancelled', $this->str($r, 'reason', 500));
            }
        } catch (ValidationException $e) {
            return $this->manage($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/events/m/' . $pid, $e);
        }
        $this->flash($r, 'success', 'events.flash.' . $action);
        return $this->redirect($r, '/events/m/' . $pid);
    }

    /** @param array<string, mixed>|null $result */
    public function desk(Request $r, ?array $result = null): Response
    {
        $ev = $this->svc()->desk($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->fresh($this->page('@events/desk', ['title' => $this->t('events.desk_title'), 'ev' => $ev, 'result' => $result, 'canManage' => $this->canManage($r), 'here' => '/events/manage']));
    }

    public function checkIn(Request $r): Response
    {
        $res = $this->svc()->checkIn($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'who', 200), $this->str($r, 'pair', 30));
        return $this->desk($r, $res);
    }

    public function report(Request $r): Response
    {
        $rep = $this->svc()->report($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->fresh($this->page('@events/report', ['title' => $this->t('events.report_title'), 'ev' => $rep, 'canManage' => true, 'here' => '/events/manage']));
    }

    public function export(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        return new Response($this->svc()->exportCsv($this->ctx($r)->az(), $pid), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="event-' . $pid . '.csv"', 'Cache-Control' => 'no-store']);
    }
}
