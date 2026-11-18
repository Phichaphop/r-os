<?php

declare(strict_types=1);

namespace Blue\Modules\Celebrate;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** M45 screens: board of the month · my celebrations · HR settings and upcoming events. */
final class CelebrateController extends Controller
{
    private function svc(): CelebrateService
    {
        return CelebrateService::of($this->app);
    }

    private function t(string $key): string
    {
        return $this->app->translator()->get($key);
    }

    /** @param array<string, string> $errors */
    public function index(Request $r, array $errors = [], int $status = 200): Response
    {
        if (!$this->ctx($r)->can('celebrate.view')) {
            throw HttpException::forbidden();
        }
        $az = $this->ctx($r)->az();
        $month = is_string($r->query['m'] ?? null) ? (string) $r->query['m'] : null;
        return $this->page('@celebrate/index', ['title' => $this->t('celebrate.title'), 'board' => $this->svc()->board($az, $month), 'errors' => $errors,
            'canManage' => $this->svc()->companies($az) !== [], 'today' => $this->svc()->today()], $status);
    }

    public function wish(Request $r): Response
    {
        try {
            $this->svc()->wish($this->ctx($r)->az(), (string) $r->params['id'], (string) ($r->body['message'] ?? ''));
        } catch (ValidationException $e) {
            return $this->index($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/celebrate');
        }
        $this->flash($r, 'success', 'celebrate.flash.wished');
        return $this->redirect($r, '/celebrate' . (is_string($r->body['m'] ?? null) && preg_match('/^\d{4}-\d{2}$/', (string) $r->body['m']) === 1 ? '?m=' . $r->body['m'] : ''));
    }

    public function me(Request $r): Response
    {
        if (!$this->ctx($r)->can('celebrate.view')) {
            throw HttpException::forbidden();
        }
        return $this->page('@celebrate/me', ['title' => $this->t('celebrate.me_title'), 'mine' => $this->svc()->mine($this->ctx($r)->az())]);
    }

    public function optouts(Request $r): Response
    {
        if (!$this->ctx($r)->can('celebrate.view')) {
            throw HttpException::forbidden();
        }
        $hidden = array_values(array_intersect(CelebrateService::KINDS, array_map('strval', (array) ($r->body['hide'] ?? []))));
        $this->svc()->setOptouts($this->ctx($r)->az(), $hidden);
        $this->flash($r, 'success', 'celebrate.flash.saved');
        return $this->redirect($r, '/celebrate/me');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function admin(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $les = $this->svc()->companies($this->ctx($r)->az());
        if ($les === []) {
            throw HttpException::forbidden();
        }
        $companies = [];
        foreach ($les as $id) {
            $row = $this->app->db()->one('SELECT public_id, code FROM legal_entities WHERE id = ?', [$id]);
            $companies[(string) $row['public_id']] = (string) $row['code'];
        }
        $lePid = (string) ($old['le'] ?? $r->query['le'] ?? '');
        if (!isset($companies[$lePid])) {
            $lePid = (string) array_key_first($companies);
        }
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        return $this->page('@celebrate/admin', ['title' => $this->t('celebrate.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 's' => $this->svc()->settings($le),
            'raw' => $this->app->db()->one('SELECT birthdays_enabled, anniversaries_enabled FROM cel_settings WHERE legal_entity_id = ?', [$le]),
            'rows' => $this->svc()->upcoming($le), 'errors' => $errors, 'old' => $old], $status);
    }

    public function saveSettings(Request $r): Response
    {
        $in = $r->body;
        $lePid = $this->str($r, 'le', 26);
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        try {
            $this->svc()->saveSettings($this->ctx($r)->az(), $le, $in);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'celebrate.flash.settings');
        return $this->redirect($r, '/celebrate/admin?le=' . rawurlencode($lePid));
    }

    public function cancel(Request $r): Response
    {
        try {
            $this->svc()->cancel($this->ctx($r)->az(), (string) $r->params['id'], (string) ($r->body['reason'] ?? ''));
        } catch (ValidationException | BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e instanceof ValidationException ? 'celebrate.error.reason' : $e->messageKey);
            return $this->redirect($r, '/celebrate/admin');
        }
        $this->flash($r, 'success', 'celebrate.flash.cancelled');
        return $this->redirect($r, '/celebrate/admin');
    }
}
