<?php

declare(strict_types=1);

namespace Blue\Modules\Draw;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\TableExport;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** M29 screens: event list, set-up (participants, prizes, lock), the full-screen draw, results with verification. */
final class DrawController extends Controller
{
    private const FIELDS = ['name' => 120, 'description' => 1000, 'mode' => 10, 'prize_mode' => 10, 'seq_count' => 6, 'no_repeat' => 1, 'event_date' => 10, 'event_time' => 5];

    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('draw.view')) {
            throw HttpException::forbidden();
        }
        return $this->simplePage($r, null);
    }

    /**
     * The lucky draw page (0.11.2, DEC-74): the quick-draw screen — names beside the wheel, spin — with every spin saved.
     *
     * @param array<string, mixed>|null $ev a simple draw being continued
     */
    private function simplePage(Request $r, ?array $ev): Response
    {
        $az = $this->ctx($r)->az();
        $svc = DrawService::of($this->app);
        $canUse = $az->can('draw.use');
        return $this->page('@draw/simple', ['title' => $ev !== null ? (string) $ev['name'] : $this->app->translator()->get('draw.title'), 'ev' => $ev, 'canUse' => $canUse,
            'names' => $ev !== null ? $svc->lastNames($ev) : [], 'winners' => $ev !== null ? $svc->winners((int) $ev['id']) : [],
            'mine' => $svc->mine($az)])->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    /** JSON: one spin of a simple saved draw (the browser animates the answer). */
    public function spin(Request $r): Response
    {
        $names = preg_split('/\R/u', mb_substr((string) ($r->body['names'] ?? ''), 0, 300000)) ?: [];
        try {
            $res = DrawService::of($this->app)->spin(
                $this->ctx($r)->az(),
                $this->str($r, 'event', 26) ?: null,
                $this->str($r, 'title', 120),
                $names,
                (int) $this->str($r, 'count', 6),
                $this->str($r, 'prize', 120),
                $this->str($r, 'token', 32),
                $this->str($r, 'mode', 10),
                $this->str($r, 'take_out', 1) === '1',
                $this->le($r)
            );
        } catch (ValidationException $e) {
            return Response::json(['error' => array_values($this->errors($e))[0] ?? ''], 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return Response::json(['error' => $this->app->translator()->get($e->messageKey)], 409);
        }
        return Response::json($res);
    }

    /**
     * Quick draw (0.8.1, DEC-68): paste names, pick how many winners, draw once on the server. Nothing is stored or
     * logged — not the names, not the result — so it suits a quick office game, not a prize that needs proof
     * (that is the full event with lock, seed and verification). Open to visitors when the organization allows it.
     */
    public function quick(Request $r): Response
    {
        $ctx = $this->ctx($r);
        if (!$ctx->can('draw.use')) {
            throw HttpException::forbidden();
        }
        $old = ['names' => '', 'count' => '1'];
        $errors = [];
        $winners = null;
        $total = 0;
        if ($r->method === 'POST') {
            $old = ['names' => mb_substr((string) ($r->body['names'] ?? ''), 0, 60000), 'count' => $this->str($r, 'count', 6)];
            try {
                ['winners' => $winners, 'total' => $total] = DrawEngine::quick($old['names'], $old['count']);
            } catch (ValidationException $e) {
                $errors = $this->errors($e);
            }
        }
        return $this->page('@draw/quick', ['title' => $this->app->translator()->get('draw.quick.title'), 'old' => $old, 'errors' => $errors,
            'winners' => $winners, 'total' => $total, 'guest' => $ctx->isGuest()], $errors !== [] ? 422 : 200)
            ->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>|null $report
     */
    public function edit(Request $r, array $errors = [], ?array $report = null, int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $svc = DrawService::of($this->app);
        $e = $svc->event($az, (string) $r->params['id']);
        if (($e['style'] ?? 'classic') === 'simple') {
            // a simple draw is its draw screen while open; its results once confirmed
            return $e['status'] === 'drawing' ? $this->simplePage($r, $e) : $this->redirect($r, '/draw/' . $e['public_id'] . '/results');
        }
        $id = (int) $e['id'];
        return $this->page('@draw/edit', ['title' => (string) $e['name'], 'ev' => $e, 'participants' => $svc->participants($id, 200), 'count' => $svc->participantCount($id),
            'prizes' => $svc->prizes($id), 'winners' => $svc->winners($id), 'log' => $svc->log($id), 'errors' => $errors, 'report' => $report,
            'seed' => $svc->revealedSeed($e), 'verify' => $svc->verify($e), 'eventLocal' => $this->local($e['event_at'])], $status);
    }

    public function update(Request $r): Response
    {
        try {
            DrawService::of($this->app)->update($this->ctx($r)->az(), (string) $r->params['id'], $this->input($r));
        } catch (ValidationException $e) {
            return $this->edit($r, $this->errors($e), null, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            return $this->back($r, 'danger', $e->messageKey);
        }
        return $this->back($r, 'success', 'draw.flash.saved');
    }

    public function import(Request $r): Response
    {
        try {
            $rep = DrawService::of($this->app)->import($this->ctx($r)->az(), (string) $r->params['id'], (string) ($r->body['text'] ?? ''), $r->file('file'), $this->str($r, 'mode', 10) !== 'append');
        } catch (ValidationException $e) {
            return $this->edit($r, $this->errors($e), null, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            return $this->back($r, 'danger', $e->messageKey);
        }
        return $this->edit($r, [], $rep);
    }

    public function clear(Request $r): Response
    {
        try {
            DrawService::of($this->app)->clearParticipants($this->ctx($r)->az(), (string) $r->params['id']);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            return $this->back($r, 'danger', $e->messageKey);
        }
        return $this->back($r, 'success', 'draw.flash.cleared');
    }

    public function prizes(Request $r): Response
    {
        $names = (array) ($r->body['prize_name'] ?? []);
        $qty = (array) ($r->body['prize_qty'] ?? []);
        $rows = [];
        foreach (array_slice($names, 0, DrawService::MAX_PRIZES + 5, true) as $i => $n) {
            $rows[] = ['name' => is_scalar($n) ? (string) $n : '', 'quantity' => is_scalar($qty[$i] ?? null) ? (string) $qty[$i] : ''];
        }
        try {
            DrawService::of($this->app)->savePrizes($this->ctx($r)->az(), (string) $r->params['id'], $rows);
        } catch (ValidationException $e) {
            return $this->edit($r, $this->errors($e), null, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            return $this->back($r, 'danger', $e->messageKey);
        }
        return $this->back($r, 'success', 'draw.flash.prizes');
    }

    public function lock(Request $r): Response
    {
        try {
            DrawService::of($this->app)->lock($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'law_ack', 1) === '1');
        } catch (ValidationException $e) {
            return $this->edit($r, $this->errors($e), null, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            return $this->back($r, 'danger', $e->messageKey);
        }
        // 0.11.1: locking is the "start" button — go straight to the draw screen
        $this->flash($r, 'success', 'draw.flash.locked');
        return $this->redirect($r, '/draw/' . $r->params['id'] . '/show');
    }

    public function unlock(Request $r): Response
    {
        try {
            DrawService::of($this->app)->unlock($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'reason', 300));
        } catch (ValidationException $e) {
            return $this->edit($r, $this->errors($e), null, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            return $this->back($r, 'danger', $e->messageKey);
        }
        return $this->back($r, 'success', 'draw.flash.unlocked');
    }

    public function show(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $svc = DrawService::of($this->app);
        $e = $svc->event($az, (string) $r->params['id']);
        if (!in_array($e['status'], ['ready', 'drawing', 'confirmed'], true)) {
            return $this->back($r, 'warning', 'draw.error.not_locked');
        }
        return $this->page('@draw/show', ['title' => (string) $e['name'], 'ev' => $e, 'prizes' => $svc->prizes((int) $e['id']), 'winners' => $svc->winners((int) $e['id']),
            'names' => $svc->showNames((int) $e['id'])]);
    }

    /** JSON: draw a round (the browser animates the answer). */
    public function round(Request $r): Response
    {
        try {
            $res = DrawService::of($this->app)->draw($this->ctx($r)->az(), (string) $r->params['id'], (int) $this->str($r, 'prize', 12), (int) $this->str($r, 'count', 6), $this->str($r, 'token', 32));
        } catch (ValidationException $e) {
            return Response::json(['error' => array_values($this->errors($e))[0] ?? ''], 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            return Response::json(['error' => $this->app->translator()->get($e->messageKey)], 409);
        }
        return Response::json($res);
    }

    public function redraw(Request $r): Response
    {
        try {
            DrawService::of($this->app)->draw(
                $this->ctx($r)->az(),
                (string) $r->params['id'],
                (int) $this->str($r, 'prize', 12),
                1,
                $this->str($r, 'token', 32),
                (int) $this->str($r, 'winner', 12),
                $this->str($r, 'reason', 300)
            );
        } catch (ValidationException $e) {
            return $this->edit($r, $this->errors($e), null, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            return $this->back($r, 'danger', $e->messageKey);
        }
        return $this->back($r, 'success', 'draw.flash.redrawn');
    }

    public function mode(Request $r): Response
    {
        DrawService::of($this->app)->setMode($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'mode', 10));
        return $this->redirect($r, '/draw/' . $r->params['id'] . '/show');
    }

    public function confirm(Request $r): Response
    {
        try {
            if ($this->str($r, 'name', 120) !== '') {
                DrawService::of($this->app)->rename($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'name', 120));
            }
            DrawService::of($this->app)->confirm($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'witness', 120));
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            return $this->back($r, 'danger', $e->messageKey);
        }
        $this->flash($r, 'success', 'draw.flash.confirmed');
        return $this->redirect($r, '/draw/' . $r->params['id'] . '/results');
    }

    public function publish(Request $r): Response
    {
        try {
            DrawService::of($this->app)->publish($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'on', 1) === '1');
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            return $this->back($r, 'danger', $e->messageKey);
        }
        return $this->back($r, 'success', 'draw.flash.published');
    }

    public function reset(Request $r): Response
    {
        try {
            DrawService::of($this->app)->reset($this->ctx($r)->az(), (string) $r->params['id'], $this->str($r, 'reason', 300));
        } catch (ValidationException $e) {
            return $this->edit($r, $this->errors($e), null, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            return $this->back($r, 'danger', $e->messageKey);
        }
        return $this->back($r, 'success', 'draw.flash.reset');
    }

    /** Excel template for the participant list. */
    public function template(Request $r): Response
    {
        if (!$this->ctx($r)->az()->can('draw.use')) {
            throw HttpException::forbidden();
        }
        return Response::attachment(
            DrawService::of($this->app)->template(),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $this->app->translator()->get('draw.template.file') . '.xlsx'
        );
    }

    public function archive(Request $r): Response
    {
        try {
            DrawService::of($this->app)->archive($this->ctx($r)->az(), (string) $r->params['id']);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            return $this->back($r, 'danger', $e->messageKey);
        }
        $this->flash($r, 'success', 'draw.flash.archived');
        return $this->redirect($r, '/draw');
    }

    public function results(Request $r): Response
    {
        $svc = DrawService::of($this->app);
        $e = $svc->results($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->page('@draw/results', ['title' => (string) $e['name'], 'ev' => $e, 'prizes' => $svc->prizes((int) $e['id']), 'winners' => $svc->winners((int) $e['id']),
            'seed' => $svc->revealedSeed($e), 'verify' => $svc->verify($e), 'count' => $svc->participantCount((int) $e['id'])]);
    }

    public function export(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $svc = DrawService::of($this->app);
        $e = $svc->event($az, (string) $r->params['id']);
        [$headers, $rows] = $svc->exportRows($e);
        $this->app->audit()->record('draw.exported', 'success', 'draw_event', (string) $e['public_id'], null);
        return TableExport::response(($r->query['format'] ?? '') === 'csv' ? 'csv' : 'xlsx', 'draw-results-' . substr((string) $e['public_id'], -6), $headers, $rows);
    }

    /** @return array<string, string> */
    private function input(Request $r): array
    {
        $in = [];
        foreach (self::FIELDS as $k => $max) {
            $in[$k] = $this->str($r, $k, $max);
        }
        return $in;
    }

    private function back(Request $r, string $tone, string $key): Response
    {
        $this->flash($r, $tone, $key);
        return $this->redirect($r, '/draw/' . $r->params['id']);
    }

    /** @return array<string, string> event date/time in the display time zone (form values) */
    private function local(mixed $utc): array
    {
        if (!is_string($utc) || $utc === '') {
            return [];
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $utc, new \DateTimeZone('UTC'));
        if ($d === false) {
            return [];
        }
        $l = $d->setTimezone(new \DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok')));
        return ['event_date' => $l->format('Y-m-d'), 'event_time' => $l->format('H:i')];
    }

    private function le(Request $r): ?int
    {
        $le = $this->store($r)->get('ctx_le');
        return is_int($le) ? $le : null;
    }
}
