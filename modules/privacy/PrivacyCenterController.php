<?php

declare(strict_types=1);

namespace Blue\Modules\Privacy;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** Privacy centre screens (R6-1 · 0.42.0): my notices, the officers' centre (notices, activities, breaches), public notice. */
final class PrivacyCenterController extends Controller
{
    private function svc(): PrivacyCenterService
    {
        return PrivacyCenterService::of($this->app);
    }

    private function t(string $key): string
    {
        return $this->app->translator()->get($key);
    }

    /**
     * The company chosen in the centre (one of those I manage).
     * @return array{0: list<array{id: int, code: string, name: string}>, 1: int}
     */
    private function company(Request $r): array
    {
        $az = $this->ctx($r)->az();
        $list = $this->svc()->companies($az);
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

    private function back(Request $r, string $to, BusinessException $e): Response
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
        $this->flash($r, 'danger', $e->messageKey, $e->params);
        return $this->redirect($r, $to);
    }

    // ---- my notices -------------------------------------------------------------------------------------------------------

    private function reader(Request $r): \Blue\Core\Authz\Authorizer
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('privacy.read')) {
            throw HttpException::forbidden();
        }
        return $az;
    }

    public function mine(Request $r): Response
    {
        $az = $this->reader($r);
        return $this->page('@privacy/mine', ['title' => $this->t('privacy.mine.title'), 'rows' => $this->svc()->mine($az->userId)]);
    }

    /** @param array<string, string> $errors */
    public function mineOne(Request $r, array $errors = [], int $status = 200): Response
    {
        $az = $this->reader($r);
        $n = $this->svc()->mineOne($az->userId, (string) $r->params['id']);
        return $this->page('@privacy/read', ['title' => (string) $n['title'], 'n' => $n, 'errors' => $errors], $status);
    }

    public function ack(Request $r): Response
    {
        $az = $this->reader($r);
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->acknowledge($az->userId, $pid, $r->input('confirm') === '1', $r->ip, $r->header('User-Agent'));
        } catch (ValidationException $e) {
            return $this->mineOne($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/privacy/notices/' . $pid, $e);
        }
        $this->flash($r, 'success', 'privacy.flash.acked');
        return $this->redirect($r, '/privacy/notices');
    }

    // ---- public -----------------------------------------------------------------------------------------------------------

    public function publicNotice(Request $r): Response
    {
        $n = $this->svc()->published((string) $r->params['code'], (string) $r->params['audience']);
        if ($n === null) {
            return $this->page('@privacy/public', ['title' => $this->t('privacy.public.none_title'), 'n' => null], 404, 'layouts/minimal');
        }
        return $this->page('@privacy/public', ['title' => (string) $n['title'], 'n' => $n], 200, 'layouts/minimal');
    }

    // ---- centre -----------------------------------------------------------------------------------------------------------

    public function center(Request $r): Response
    {
        [$list, $le] = $this->company($r);
        $tab = in_array($r->query['tab'] ?? '', ['notices', 'activities', 'breaches'], true) ? (string) $r->query['tab'] : 'notices';
        $az = $this->ctx($r)->az();
        return $this->page('@privacy/center', [
            'title' => $this->t('privacy.center.title'), 'companies' => $list, 'le' => $le, 'tab' => $tab,
            'notices' => $this->svc()->notices($le), 'activities' => $this->svc()->activities($le), 'breaches' => $this->svc()->breaches($le),
            'canApprove' => $az->scope('privacy.approve')->coversLegalEntity($le), 'hours' => $this->svc()->notifyHours(), 'errors' => [], 'old' => [],
            'leCode' => (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]),
        ]);
    }

    public function startNotice(Request $r): Response
    {
        [, $le] = $this->company($r);
        try {
            $pid = $this->svc()->startNotice($this->ctx($r)->az(), $le, (string) $r->input('audience'), $r->input('template') === '1');
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, '/privacy/center?le=' . $le);
        } catch (BusinessException $e) {
            return $this->back($r, '/privacy/center?le=' . $le, $e);
        }
        return $this->redirect($r, '/privacy/center/n/' . $pid);
    }

    /** @param array<string, string> $errors */
    public function notice(Request $r, array $errors = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $n = $this->svc()->notice($az, (string) $r->params['id']);
        $le = (int) $n['legal_entity_id'];
        return $this->page('@privacy/notice', [
            'title' => (string) $n['title'], 'n' => $n, 'errors' => $errors, 'gaps' => PrivacyCenterService::gaps($n['body'] . "\n" . ($n['body_en'] ?? '')),
            'canApprove' => $az->scope('privacy.approve')->coversLegalEntity($le) && (int) $n['submitted_by'] !== $az->userId && (int) $n['updated_by'] !== $az->userId,
            'canWithdraw' => $az->scope('privacy.approve')->coversLegalEntity($le),
            'acks' => $n['status'] === 'published' && (int) $n['requires_ack'] === 1 ? $this->svc()->ackReport($az, (string) $n['public_id']) : null,
            'leCode' => (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]), 'today' => $this->svc()->today(),
        ], $status);
    }

    public function noticeAction(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $pid = (string) $r->params['id'];
        $action = (string) $r->params['action'];
        try {
            match ($action) {
                'save' => $this->svc()->saveNotice($az, $pid, $r->body),
                'submit' => $this->svc()->submitNotice($az, $pid),
                'publish' => $this->svc()->decideNotice($az, $pid, true, $this->str($r, 'note', 300), $this->str($r, 'effective_from', 10), $r->input('legal_checked') === '1'),
                'return' => $this->svc()->decideNotice($az, $pid, false, $this->str($r, 'note', 500), '', false),
                'withdraw' => $this->svc()->withdrawNotice($az, $pid, $this->str($r, 'reason', 500)),
                'delete' => $this->svc()->deleteDraft($az, $pid),
                default => throw HttpException::notFound(),
            };
        } catch (ValidationException $e) {
            return $this->notice($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/privacy/center/n/' . $pid, $e);
        }
        $this->flash($r, 'success', 'privacy.flash.notice_' . $action);
        return $this->redirect($r, $action === 'delete' ? '/privacy/center' : '/privacy/center/n/' . $pid);
    }

    public function seed(Request $r): Response
    {
        [, $le] = $this->company($r);
        $n = $this->svc()->seedActivities($this->ctx($r)->az(), $le);
        $this->flash($r, 'success', 'privacy.flash.seeded', ['n' => $n]);
        return $this->redirect($r, '/privacy/center?tab=activities&le=' . $le);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function activityForm(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $pid = isset($r->params['id']) ? (string) $r->params['id'] : null;
        if ($pid !== null) {
            $a = $this->svc()->activity($az, $pid);
            $le = (int) $a['legal_entity_id'];
        } else {
            [, $le] = $this->company($r);
            $a = null;
        }
        return $this->page('@privacy/activity', ['title' => $a === null ? $this->t('privacy.activity.new') : (string) $a['name'], 'a' => $a, 'le' => $le,
            'errors' => $errors, 'old' => $old, 'canApprove' => $a !== null && $az->scope('privacy.approve')->coversLegalEntity($le) && (int) $a['updated_by'] !== $az->userId], $status);
    }

    public function activitySave(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $pid = isset($r->params['id']) ? (string) $r->params['id'] : null;
        $le = $pid === null ? $this->company($r)[1] : (int) $this->svc()->activity($az, $pid)['legal_entity_id'];
        try {
            $pid = $this->svc()->saveActivity($az, $le, $pid, $r->body);
        } catch (ValidationException $e) {
            return $this->activityForm($r, $this->errors($e), $r->body, 422);
        }
        $this->flash($r, 'success', 'privacy.flash.activity_saved');
        return $this->redirect($r, '/privacy/center/a/' . $pid);
    }

    public function activityAction(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->activateActivity($this->ctx($r)->az(), $pid, $r->params['action'] === 'retire');
        } catch (BusinessException $e) {
            return $this->back($r, '/privacy/center/a/' . $pid, $e);
        }
        $this->flash($r, 'success', 'privacy.flash.activity_' . $r->params['action']);
        return $this->redirect($r, '/privacy/center/a/' . $pid);
    }

    public function breachNew(Request $r): Response
    {
        [, $le] = $this->company($r);
        try {
            $pid = $this->svc()->recordBreach($this->ctx($r)->az(), $le, $r->body);
        } catch (ValidationException $e) {
            [$list, $le] = $this->company($r);
            $az = $this->ctx($r)->az();
            return $this->page('@privacy/center', [
                'title' => $this->t('privacy.center.title'), 'companies' => $list, 'le' => $le, 'tab' => 'breaches',
                'notices' => $this->svc()->notices($le), 'activities' => $this->svc()->activities($le), 'breaches' => $this->svc()->breaches($le),
                'canApprove' => $az->scope('privacy.approve')->coversLegalEntity($le), 'hours' => $this->svc()->notifyHours(), 'errors' => $this->errors($e), 'old' => $r->body,
                'leCode' => (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]),
            ], 422);
        }
        $this->flash($r, 'success', 'privacy.flash.breach_recorded');
        return $this->redirect($r, '/privacy/center/b/' . $pid);
    }

    /** @param array<string, string> $errors */
    public function breach(Request $r, array $errors = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $b = $this->svc()->breach($az, (string) $r->params['id']);
        return $this->page('@privacy/breach', ['title' => (string) $b['ref'], 'b' => $b, 'errors' => $errors, 'hours' => $this->svc()->notifyHours(),
            'canApprove' => $az->scope('privacy.approve')->coversLegalEntity((int) $b['legal_entity_id'])], $status);
    }

    public function breachAction(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->updateBreach($this->ctx($r)->az(), $pid, (string) $r->params['step'], $r->body);
        } catch (ValidationException $e) {
            return $this->breach($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/privacy/center/b/' . $pid, $e);
        }
        $this->flash($r, 'success', 'privacy.flash.breach_' . $r->params['step']);
        return $this->redirect($r, '/privacy/center/b/' . $pid);
    }
}
