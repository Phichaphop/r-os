<?php

declare(strict_types=1);

namespace Blue\Modules\Links;

use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** M31 screens: the links page for everyone, and the management pages (links.manage). */
final class LinksController extends Controller
{
    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $svc = LinksService::of($this->app);
        $q = mb_substr(trim((string) ($r->query['q'] ?? '')), 0, 80);
        $cat = in_array($r->query['cat'] ?? '', LinksService::CATEGORIES, true) ? (string) $r->query['cat'] : '';
        $all = $svc->visible($az);
        $rows = ($q === '' && $cat === '') ? $all : $svc->visible($az, $q, $cat);
        $popular = array_values(array_filter($all, static fn (array $l): bool => (int) $l['clicks_30d'] > 0));
        usort($popular, static fn (array $a, array $b): int => (int) $b['clicks_30d'] <=> (int) $a['clicks_30d']);
        return $this->page('@links/index', [
            'title' => $this->app->translator()->get('links.title'), 'rows' => $rows, 'q' => $q, 'cat' => $cat,
            'categories' => array_values(array_unique(array_column($all, 'category'))),
            'popular' => $q === '' && $cat === '' ? array_slice($popular, 0, 6) : [],
            'canManage' => $az->can('links.manage'),
        ]);
    }

    /** Counted redirect — no referrer, nothing internal in the query string (M31 B2). */
    public function go(Request $r): Response
    {
        $url = LinksService::of($this->app)->go($this->ctx($r)->az(), (string) $r->params['id']);
        return (new Response('', 303, ['Location' => $url]))
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function manage(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        return $this->page('@links/manage', ['title' => $this->app->translator()->get('links.manage'), 'rows' => LinksService::of($this->app)->all($az)]);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>|null $old
     */
    public function form(Request $r, array $errors = [], ?array $old = null, int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $svc = LinksService::of($this->app);
        $pid = isset($r->params['id']) ? (string) $r->params['id'] : null;
        $link = $pid !== null ? $svc->get($az, $pid) : null;
        if ($old === null) {
            $old = $link === null ? ['category' => 'other', 'icon' => 'external-link', 'open_new_tab' => '1', 'sort_order' => '100'] : ($link['pending'] ?? $link);
        }
        $flashWarn = $this->store($r)->get('links_warnings');
        $this->store($r)->set('links_warnings', null);
        return $this->page('@links/form', [
            'title' => $this->app->translator()->get($link === null ? 'links.new' : 'links.edit'),
            'link' => $link, 'old' => $old, 'errors' => $errors, 'warnings' => is_array($flashWarn) ? $flashWarn : [],
            'roles' => array_keys(\Blue\Core\Authz\Catalog::ROLES),
            'companies' => $this->app->org()->scopeOptions($az->reachableScope(), $this->app->translator()->locale())['legal_entity'],
        ], $status);
    }

    public function save(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $pid = isset($r->params['id']) ? (string) $r->params['id'] : null;
        $in = [];
        foreach (['name_th', 'name_en', 'description', 'url', 'category', 'icon', 'open_new_tab', 'legal_entity_id', 'sort_order'] as $f) {
            $in[$f] = $this->str($r, $f, $f === 'url' ? 1000 : 300);
        }
        $roles = $r->input('audience_roles', []);
        $in['audience_roles'] = is_array($roles) ? array_values(array_map('strval', array_filter($roles, 'is_scalar'))) : [];
        try {
            $res = LinksService::of($this->app)->save($az, $pid, $in);
        } catch (ValidationException $e) {
            return $this->form($r, $this->errors($e), $in, 422);
        }
        $this->store($r)->set('links_warnings', $res['warnings']);
        $this->flash($r, 'success', $res['pending'] ? 'links.saved_pending' : 'links.saved');
        return $this->redirect($r, '/admin/links/' . $res['pid']);
    }

    public function publish(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            LinksService::of($this->app)->publish($this->ctx($r)->az(), $pid, $this->str($r, 'purpose', 300), $this->str($r, 'risk_note', 500), $r->input('confirm') === '1');
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, '/admin/links/' . $pid . '#review');
        }
        $this->flash($r, 'success', 'links.published');
        return $this->redirect($r, '/admin/links/' . $pid);
    }

    public function status(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $to = $this->str($r, 'to', 20);
        try {
            LinksService::of($this->app)->setStatus($this->ctx($r)->az(), $pid, $to, $this->str($r, 'reason', 300));
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, '/admin/links/' . $pid);
        }
        $this->flash($r, 'success', 'links.status_done');
        return $this->redirect($r, '/admin/links/' . $pid);
    }

    public function discard(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        LinksService::of($this->app)->discardPending($this->ctx($r)->az(), $pid);
        $this->flash($r, 'success', 'links.discarded');
        return $this->redirect($r, '/admin/links/' . $pid);
    }
}
