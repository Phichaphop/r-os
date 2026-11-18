<?php

declare(strict_types=1);

namespace Blue\Core\Org;

use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** Organization structure pages (03A): companies, sites, departments — all access through the caller's scope. */
final class OrgController extends Controller
{
    public function index(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $ctx->require('core.org.view');
        $locale = $this->app->translator()->locale();
        $tree = $this->app->org()->tree($ctx->az(), $locale);
        $org = $this->app->org()->organization();
        // 0.8.2 (DEC-69): the chart shows ONE company at a time — its single structure, each unit tagged with its branch
        $pick = (string) ($r->query['le'] ?? '');
        $current = $tree[0] ?? null;
        foreach ($tree as $le) {
            if ((string) $le['public_id'] === $pick) {
                $current = $le;
            }
        }
        $siteFilter = (string) ($r->query['site'] ?? '');
        return $this->page('admin/org/index', [
            'title' => $this->app->translator()->get('org.title'),
            'tree' => $tree,
            'current' => $current,
            'siteFilter' => $siteFilter,
            'view' => ($r->query['view'] ?? '') === 'list' ? 'list' : 'tree',
            'orgName' => $org ? Translations::pick((new Translations($this->app->db()))->many('organization', [(int) $org['id']])[(int) $org['id']] ?? [], $locale) : '',
            'canCreateCompany' => $ctx->az()->scope('core.org.manage')->all,
            'manage' => $ctx->az()->scope('core.org.manage'),
            'crossCompany' => $ctx->az()->scope('core.org.view')->crossCompany(),
        ]);
    }

    /** GET forms: kind = legal-entity | site | department; mode = new (under parent) or edit. */
    public function form(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $kind = (string) $r->params['kind'];
        $id = (string) ($r->params['id'] ?? '');
        $isNew = str_ends_with($r->path, '/new');
        $row = [];
        $parent = null;
        $org = $this->app->org();
        if ($isNew && $kind === 'legal-entity') {
            $ctx->require('core.org.manage');
        } elseif ($isNew) {
            $parent = $org->legalEntity($ctx->az(), $id, 'core.org.manage');
        } else {
            $row = match ($kind) {
                'legal-entity' => $org->legalEntity($ctx->az(), $id, 'core.org.manage'),
                'site' => $org->site($ctx->az(), $id, 'core.org.manage'),
                default => $org->department($ctx->az(), $id, 'core.org.manage'),
            };
        }
        return $this->renderForm($r, $kind, $isNew, $row, $parent, [], []);
    }

    public function save(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $kind = (string) $r->params['kind'];
        $id = (string) ($r->params['id'] ?? '');
        $isNew = str_ends_with($r->path, '/new');
        $in = [];
        foreach (['code', 'name_th', 'name_en', 'registration_no', 'fiscal_year_start_month', 'site_type', 'branch_no', 'site', 'parent', 'status', 'country_code', 'level'] as $k) {
            $in[$k] = $this->str($r, $k, 200);
        }
        $svc = $this->app->org();
        try {
            match (true) {
                $kind === 'legal-entity' && $isNew => $svc->createLegalEntity($ctx->az(), $in),
                $kind === 'legal-entity' => $svc->updateLegalEntity($ctx->az(), $id, (int) $r->input('row_version', 0), $in),
                $kind === 'site' && $isNew => $svc->createSite($ctx->az(), $id, $in),
                $kind === 'site' => $svc->updateSite($ctx->az(), $id, (int) $r->input('row_version', 0), $in),
                $isNew => $svc->createDepartment($ctx->az(), $id, $in),
                default => $svc->updateDepartment($ctx->az(), $id, (int) $r->input('row_version', 0), $in),
            };
        } catch (ValidationException $e) {
            $row = [];
            $parent = null;
            if ($isNew && $kind !== 'legal-entity') {
                $parent = $svc->legalEntity($ctx->az(), $id, 'core.org.manage');
            } elseif (!$isNew) {
                $row = match ($kind) {
                    'legal-entity' => $svc->legalEntity($ctx->az(), $id, 'core.org.manage'),
                    'site' => $svc->site($ctx->az(), $id, 'core.org.manage'),
                    default => $svc->department($ctx->az(), $id, 'core.org.manage'),
                };
            }
            return $this->renderForm($r, $kind, $isNew, $row, $parent, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'org.saved');
        return $this->redirect($r, '/admin/org');
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $parent
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderForm(Request $r, string $kind, bool $isNew, array $row, ?array $parent, array $errors, array $old, int $status = 200): Response
    {
        $t = $this->app->translator();
        $sites = [];
        $depts = [];
        $leId = $kind === 'department' ? ($parent !== null ? (int) $parent['id'] : (int) ($row['legal_entity_id'] ?? 0)) : 0;
        if ($leId !== 0) {
            foreach ($this->app->org()->tree($this->ctx($r)->az(), $t->locale()) as $le) {
                if ((int) $le['id'] === $leId) {
                    foreach ($le['sites'] as $s) {
                        $sites[(string) $s['public_id']] = $s['code'] . ' · ' . $s['name'];
                    }
                    foreach ($le['departments'] as $d) {
                        if ((int) $d['id'] !== (int) ($row['id'] ?? 0)) {
                            $depts[(string) $d['public_id']] = $t->get('org.level.' . $d['unit_level']) . ' · ' . $d['code'] . ' · ' . $d['name'];
                        }
                    }
                }
            }
        }
        return $this->page('admin/org/form', [
            'title' => $t->get('org.form.' . $kind . ($isNew ? '.new' : '.edit')),
            'kind' => $kind, 'isNew' => $isNew, 'row' => $row, 'parent' => $parent,
            'errors' => $errors, 'old' => $old, 'sites' => $sites, 'depts' => $depts,
            'action' => $r->path,
        ], $status);
    }
}
