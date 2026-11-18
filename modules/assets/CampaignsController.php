<?php

declare(strict_types=1);

namespace Blue\Modules\Assets;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;

/** Asset verification campaigns (M42 · 9C-2): list and set up · one campaign (figures, items, cases) · counting desk. */
final class CampaignsController extends Controller
{
    private function svc(): CampaignService
    {
        return CampaignService::of($this->app);
    }

    private function assets(): AssetService
    {
        return AssetService::of($this->app);
    }

    private function t(string $k): string
    {
        return $this->app->translator()->get($k);
    }

    /** @return array<string, bool> */
    private function roles(Request $r): array
    {
        $az = $this->ctx($r)->az();
        return ['canView' => $this->assets()->companies($az, 'assets.view') !== [], 'canManage' => $this->assets()->companies($az, 'assets.manage') !== [],
            'canCount' => $this->assets()->companies($az, 'assets.count') !== []];
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} */
    private function company(Request $r, ?string $want = null): array
    {
        $az = $this->ctx($r)->az();
        $ids = array_values(array_unique(array_merge($this->assets()->companies($az, 'assets.count'), $this->assets()->companies($az, 'assets.manage'))));
        if ($ids === []) {
            throw HttpException::forbidden();
        }
        $companies = [];
        foreach ($this->app->db()->all('SELECT public_id, code FROM legal_entities WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY code') as $row) {
            $companies[(string) $row['public_id']] = (string) $row['code'];
        }
        $pid = $want ?? (string) ($r->query['le'] ?? $r->body['le'] ?? '');
        if (!isset($companies[$pid])) {
            $pid = (string) array_key_first($companies);
        }
        return [$companies, $pid, (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$pid])];
    }

    private function fresh(Response $res): Response
    {
        return $res->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    private function fail(Request $r, string $to, BusinessException $e): Response
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
        $this->flash($r, 'danger', $e->messageKey, $e->params);
        return $this->redirect($r, $to);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function index(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        return $this->fresh($this->page('@assets/campaigns', ['title' => $this->t('assets.campaigns_title'), 'companies' => $companies, 'lePid' => $lePid, 'rows' => $this->svc()->campaigns($le),
            'sites' => $this->assets()->sites($le), 'types' => $this->assets()->types($le), 'today' => $this->assets()->today(), 'errors' => $errors, 'old' => $old, 'here' => '/assets/campaigns'] + $this->roles($r), $status));
    }

    public function create(Request $r): Response
    {
        [, , $le] = $this->company($r);
        try {
            $pid = $this->svc()->create($this->ctx($r)->az(), $le, $r->body);
        } catch (ValidationException $e) {
            return $this->index($r, $this->errors($e), $r->body, 422);
        }
        $this->flash($r, 'success', 'assets.flash.campaign_created');
        return $this->redirect($r, '/assets/campaigns/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function show(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200, ?string $pid = null): Response
    {
        $c = $this->svc()->campaign($this->ctx($r)->az(), $pid ?? (string) $r->params['id']);
        return $this->fresh($this->page('@assets/campaign', ['title' => (string) $c['name'], 'camp' => $c, 'errors' => $errors, 'old' => $old, 'form' => $form, 'here' => '/assets/campaigns'] + $this->roles($r), $status));
    }

    public function act(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        try {
            if ($action === 'start') {
                $n = $this->svc()->start($az, $pid);
                $this->flash($r, 'success', 'assets.flash.campaign_started', ['n' => (string) $n]);
            } elseif ($action === 'finish') {
                $n = $this->svc()->finishCounting($az, $pid);
                $this->flash($r, 'success', 'assets.flash.campaign_counted', ['n' => (string) $n]);
            } else {
                $this->svc()->close($az, $pid, $this->str($r, 'exception_note', 1000));
                $this->flash($r, 'success', 'assets.flash.campaign_closed');
            }
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $r->body, $action, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/assets/campaigns/' . $pid, $e);
        }
        return $this->redirect($r, '/assets/campaigns/' . $pid);
    }

    /**
     * Counting desk: scan or type, then record what is seen.
     *
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function count(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $c = $this->svc()->campaign($az, $pid);
        if (!$c['can_count']) {
            throw HttpException::forbidden();
        }
        $code = mb_substr(trim((string) ($r->query['code'] ?? $old['code'] ?? '')), 0, 200);
        $found = $code !== '' ? $this->svc()->lookup($az, $pid, $code) : null;
        $le = (int) $c['legal_entity_id'];
        return $this->fresh($this->page('@assets/count', ['title' => $this->t('assets.count_title'), 'camp' => $c, 'code' => $code, 'found' => $found, 'sites' => $this->assets()->sites($le),
            'errors' => $errors, 'old' => $old, 'here' => '/assets/campaigns'] + $this->roles($r), $status));
    }

    public function record(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $res = $this->svc()->record($this->ctx($r)->az(), $pid, $r->body);
        } catch (ValidationException $e) {
            return $this->count($r, $this->errors($e), $r->body, 422);
        } catch (BusinessException $e) {
            return $this->fail($r, '/assets/campaigns/' . $pid . '/count', $e);
        }
        $this->flash(
            $r,
            $res['result'] === 'matched' ? 'success' : 'warning',
            $res['duplicate'] ? 'assets.flash.counted_again' : ($res['second'] ? 'assets.flash.second_counted' : 'assets.flash.counted'),
            ['result' => $this->t('assets.result.' . $res['result'])]
        );
        return $this->redirect($r, '/assets/campaigns/' . $pid . '/count');
    }

    public function notApplicable(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->notApplicable($this->ctx($r)->az(), $pid, (int) $r->params['item'], $this->str($r, 'na_reason', 500));
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, '/assets/campaigns/' . $pid);
        } catch (BusinessException $e) {
            return $this->fail($r, '/assets/campaigns/' . $pid, $e);
        }
        $this->flash($r, 'success', 'assets.flash.not_applicable');
        return $this->redirect($r, '/assets/campaigns/' . $pid);
    }

    public function resolve(Request $r): Response
    {
        $casePid = (string) $r->params['id'];
        $campaign = (string) ($this->app->db()->scalar('SELECT c.public_id FROM as_cases x JOIN as_campaigns c ON c.id = x.campaign_id WHERE x.public_id = ?', [$casePid]) ?? throw HttpException::notFound());
        try {
            $this->svc()->resolve($this->ctx($r)->az(), $casePid, $this->str($r, 'resolution', 30), $this->str($r, 'resolution_note', 1000));
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $r->body + ['case' => $casePid], 'case', 422, $campaign);
        } catch (BusinessException $e) {
            return $this->fail($r, '/assets/campaigns/' . $campaign, $e);
        }
        $this->flash($r, 'success', 'assets.flash.case_resolved');
        return $this->redirect($r, '/assets/campaigns/' . $campaign . '#cases');
    }

    public function export(Request $r): Response
    {
        $f = $this->svc()->export($this->ctx($r)->az(), (string) $r->params['id']);
        return DocumentsController::fileResponse($f['bytes'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $f['name'], false)->withHeader('Cache-Control', 'no-store, max-age=0');
    }
}
