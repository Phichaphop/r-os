<?php

declare(strict_types=1);

namespace Blue\Modules\Directory;

use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** M47 screens: search (type-ahead) / my team / pinned · my extension · HR: visible fields, group search, export. */
final class DirectoryController extends Controller
{
    private function svc(): DirectoryService
    {
        return DirectoryService::of($this->app);
    }

    /** @param array<string, string> $errors */
    public function index(Request $r, array $errors = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('directory.use')) {
            throw HttpException::forbidden();
        }
        $q = mb_substr(trim((string) ($r->query['q'] ?? '')), 0, 60);
        $view = in_array($r->query['view'] ?? '', ['team', 'favorites'], true) ? (string) $r->query['view'] : 'all';
        $cards = $this->svc()->search($az, $q, $view);
        if (($r->query['partial'] ?? '') === '1') {
            return $this->page('@directory/_results', ['cards' => $cards, 'q' => $q, 'view' => $view], 200, null);
        }
        return $this->page('@directory/index', [
            'title' => $this->app->translator()->get('directory.title'), 'cards' => $cards, 'q' => $q, 'view' => $view, 'me' => $this->svc()->me($az), 'errors' => $errors,
            'canAdmin' => !$az->scope('directory.manage')->isEmpty(),
        ], $status);
    }

    public function pin(Request $r): Response
    {
        $on = $r->input('on') === '1';
        $this->svc()->pin($this->ctx($r)->az(), (string) $r->params['id'], $on);
        $this->flash($r, 'success', $on ? 'directory.flash.pinned' : 'directory.flash.unpinned');
        $back = $this->str($r, 'back', 200);
        return $this->redirect($r, str_starts_with($back, '/directory') ? $back : '/directory?view=favorites');
    }

    public function extension(Request $r): Response
    {
        try {
            $this->svc()->saveExtension($this->ctx($r)->az(), $this->str($r, 'extension', 20));
        } catch (ValidationException $e) {
            return $this->index($r, $this->errors($e), 422);
        }
        $this->flash($r, 'success', 'directory.flash.extension');
        return $this->redirect($r, '/directory');
    }

    public function admin(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $companies = [];
        foreach ($this->app->db()->all('SELECT id, public_id, code FROM legal_entities ORDER BY code') as $l) {
            if ($az->scope('directory.manage')->coversLegalEntity((int) $l['id'])) {
                $companies[(string) $l['public_id']] = (string) $l['code'];
            }
        }
        if ($companies === []) {
            throw HttpException::forbidden();
        }
        $lePid = (string) ($r->query['le'] ?? '');
        if (!isset($companies[$lePid])) {
            $lePid = (string) array_key_first($companies);
        }
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        return $this->page('@directory/admin', [
            'title' => $this->app->translator()->get('directory.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'settings' => $this->svc()->settings($le),
            'canExport' => $az->scope('directory.export')->coversLegalEntity($le),
        ]);
    }

    public function saveSettings(Request $r): Response
    {
        $lePid = $this->str($r, 'legal_entity', 26);
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        $this->svc()->saveSettings($this->ctx($r)->az(), $le, ['fields' => array_map('strval', (array) ($r->body['fields'] ?? [])), 'cross_company' => $this->str($r, 'cross_company', 1)]);
        $this->flash($r, 'success', 'directory.flash.settings');
        return $this->redirect($r, '/directory/admin?le=' . rawurlencode($lePid));
    }

    public function export(Request $r): Response
    {
        $lePid = (string) ($r->query['le'] ?? '');
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        $x = $this->svc()->export($this->ctx($r)->az(), $le);
        return \Blue\Core\Files\TableExport::response(\Blue\Core\Files\TableExport::format($r->query['format'] ?? 'xlsx'), 'directory', $x['headers'], $x['rows'], []);
    }
}
