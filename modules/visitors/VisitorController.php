<?php

declare(strict_types=1);

namespace Blue\Modules\Visitors;

use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** M43 screens (R6-2 · 0.43.0): my invitations, the gate desk, settings + blacklist + report, the visitor's page. */
final class VisitorController extends Controller
{
    private function svc(): VisitorService
    {
        return VisitorService::of($this->app);
    }

    private function t(string $key): string
    {
        return $this->app->translator()->get($key);
    }

    private function az(Request $r, string $perm): Authorizer
    {
        $az = $this->ctx($r)->az();
        if (!$az->can($perm)) {
            throw HttpException::forbidden();
        }
        return $az;
    }

    /** @return array{0: list<array{id: int, code: string, name: string}>, 1: int} */
    private function company(Request $r, string $perm): array
    {
        $list = $this->svc()->companies($this->az($r, $perm), $perm);
        if ($list === []) {
            throw HttpException::forbidden();
        }
        $want = (int) ($r->query['le'] ?? $r->input('le') ?? 0);
        foreach ($list as $c) {
            if ($c['id'] === $want) {
                return [$list, $want];
            }
        }
        return [$list, $list[0]['id']];
    }

    private function origin(Request $r): string
    {
        $configured = rtrim($this->app->config->string('APP_URL'), '/');
        return $configured !== '' ? $configured : ($r->secure ? 'https' : 'http') . '://' . $r->host . $r->basePath();
    }

    private function back(Request $r, string $to, BusinessException $e): Response
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
        $this->flash($r, 'danger', $e->messageKey, $e->params);
        return $this->redirect($r, $to);
    }

    // ---- host ---------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function mine(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->az($r, 'visitors.invite');
        $le = $this->svc()->employerOf($az->userId);
        return $this->page('@visitors/mine', ['title' => $this->t('visitors.mine.title'), 'rows' => $this->svc()->mine($az->userId), 'le' => $le,
            'sites' => $le === null ? [] : $this->svc()->sites($le), 'settings' => $le === null ? null : $this->svc()->settings($le),
            'notice' => $le === null ? null : $this->svc()->noticeUrl($le), 'errors' => $errors, 'old' => $old], $status);
    }

    public function invite(Request $r): Response
    {
        $az = $this->az($r, 'visitors.invite');
        try {
            $pid = $this->svc()->create($az, $r->body);
        } catch (ValidationException $e) {
            return $this->mine($r, $this->errors($e), $r->body, 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/visitors', $e);
        }
        $this->flash($r, 'success', 'visitors.flash.invited');
        return $this->redirect($r, '/visitors/v/' . $pid);
    }

    /** @param array<string, string> $errors */
    public function show(Request $r, array $errors = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $v = $this->svc()->visit($az, (string) $r->params['id']);
        $le = (int) $v['legal_entity_id'];
        $isHost = (int) $v['host_user_id'] === $az->userId;
        $link = $isHost && $v['qr_token'] !== null && $v['status'] === 'invited' ? rtrim($this->origin($r), '/') . '/visit/' . $v['qr_token'] : null;
        return $this->page('@visitors/visit', [
            'title' => (string) $v['ref'], 'visit' => $v, 'errors' => $errors, 'isHost' => $isHost, 'link' => $link,
            'qr' => $link === null ? null : $this->svc()->qrSvg((string) $v['qr_token'], $this->origin($r), (string) $v['ref']),
            'gate' => $az->scope('visitors.gate')->coversLegalEntity($le), 'manage' => $az->scope('visitors.manage')->coversLegalEntity($le),
            'notice' => $this->svc()->noticeUrl($le),
        ], $status);
    }

    public function action(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $pid = (string) $r->params['id'];
        $action = (string) $r->params['action'];
        try {
            match ($action) {
                'checkin' => $this->svc()->checkIn($az, $pid, $r->body),
                'checkout' => $this->svc()->checkOut($az, $pid, $r->input('badge_returned') === '1'),
                'deny', 'cancel' => $this->svc()->close($az, $pid, $action, $this->str($r, 'reason', 500)),
                default => throw HttpException::notFound(),
            };
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/visitors/v/' . $pid, $e);
        }
        $this->flash($r, 'success', 'visitors.flash.' . $action);
        return $this->redirect($r, '/visitors/v/' . $pid);
    }

    // ---- gate ---------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function gate(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$list, $le] = $this->company($r, 'visitors.gate');
        $code = trim((string) ($r->query['code'] ?? ''));
        if ($code !== '' && $errors === []) {
            try {
                return $this->redirect($r, '/visitors/v/' . $this->svc()->find($this->ctx($r)->az(), $le, $code));
            } catch (BusinessException $e) {
                if ($e instanceof HttpException) {
                    throw $e;
                }
                $this->flash($r, 'danger', $e->messageKey, $e->params);
                return $this->redirect($r, '/visitors/gate?le=' . $le);
            }
        }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r->query['date'] ?? '')) === 1 ? (string) $r->query['date'] : $this->svc()->today();
        return $this->page('@visitors/gate', ['title' => $this->t('visitors.gate.title'), 'companies' => $list, 'le' => $le, 'day' => $date,
            'rows' => $this->svc()->day($le, $date), 'hosts' => $this->svc()->hosts($le), 'sites' => $this->svc()->sites($le), 'settings' => $this->svc()->settings($le),
            'notice' => $this->svc()->noticeUrl($le), 'errors' => $errors, 'old' => $old], $status);
    }

    public function walkIn(Request $r): Response
    {
        [, $le] = $this->company($r, 'visitors.gate');
        try {
            $pid = $this->svc()->create($this->ctx($r)->az(), $r->body, true, $le);
        } catch (ValidationException $e) {
            return $this->gate($r, $this->errors($e), $r->body, 422);
        }
        $this->flash($r, 'success', 'visitors.flash.walk_in');
        return $this->redirect($r, '/visitors/v/' . $pid);
    }

    // ---- admin --------------------------------------------------------------------------------------------------------------

    /** @param array<string, string> $errors */
    public function admin(Request $r, array $errors = [], int $status = 200): Response
    {
        [$list, $le] = $this->company($r, 'visitors.manage');
        $ym = preg_match('/^\d{4}-\d{2}$/', (string) ($r->query['month'] ?? '')) === 1 ? (string) $r->query['month'] : substr($this->svc()->today(), 0, 7);
        return $this->page('@visitors/admin', ['title' => $this->t('visitors.admin.title'), 'companies' => $list, 'le' => $le, 'ym' => $ym,
            'settings' => $this->svc()->settings($le), 'blacklist' => $this->svc()->blacklist($le), 'report' => $this->svc()->monthly($le, $ym),
            'notice' => $this->svc()->noticeUrl($le), 'errors' => $errors], $status);
    }

    public function adminPost(Request $r): Response
    {
        [, $le] = $this->company($r, 'visitors.manage');
        $az = $this->ctx($r)->az();
        $what = (string) $r->params['what'];
        try {
            match ($what) {
                'settings' => $this->svc()->saveSettings($az, $le, $r->body),
                'blacklist' => $this->svc()->addBlacklist($az, $le, $r->body),
                'unlist' => $this->svc()->removeBlacklist($az, $this->str($r, 'id', 26), $this->str($r, 'reason', 500)),
                default => throw HttpException::notFound(),
            };
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), 422);
        }
        $this->flash($r, 'success', 'visitors.flash.' . $what);
        return $this->redirect($r, '/visitors/admin?le=' . $le);
    }

    // ---- visitor (public) ---------------------------------------------------------------------------------------------------

    public function visitorPage(Request $r): Response
    {
        $v = $this->svc()->byToken((string) $r->params['token']);
        return $this->page('@visitors/public', ['title' => $v === null ? $this->t('visitors.public.none') : $this->t('visitors.public.title'), 'visit' => $v,
            'qr' => $v === null ? null : $this->svc()->qrSvg((string) $v['qr_token'], $this->origin($r), (string) $v['ref'])], $v === null ? 404 : 200, 'layouts/minimal');
    }
}
