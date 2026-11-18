<?php

declare(strict_types=1);

namespace Blue\Core\Modules;

use Blue\Core\Error\HttpException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use RuntimeException;

/** Settings → Modules: what is installed, which pack, on/off (P0-02 §5 "กติกาเปิด/ปิดโมดูล"). */
final class ModulesController extends Controller
{
    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('core.module.manage')) {
            throw HttpException::forbidden();
        }
        $reg = $this->app->modules();
        $rows = [];
        foreach ($reg->all() as $code => $m) {
            $rows[] = [
                'code' => $code, 'name' => (string) ($m['name'][$this->app->translator()->locale()] ?? $m['name']['en']),
                'version' => (string) $m['version'], 'pack' => (string) $m['pack'], 'icon' => (string) ($m['icon'] ?? 'components'),
                'enabled' => $reg->isEnabled($code), 'switched_on' => $this->switchedOn($code),
                'depends_on' => array_values(array_diff((array) ($m['depends_on'] ?? []), ['core'])),
                'permissions' => count((array) ($m['permissions'] ?? [])), 'config' => count((array) ($m['config'] ?? [])),
                'spec' => (string) ($m['spec'] ?? ''),
            ];
        }
        return $this->page('admin/modules/index', ['title' => $this->app->translator()->get('module.title'), 'rows' => $rows]);
    }

    public function toggle(Request $r): Response
    {
        $ctx = $this->ctx($r);
        if (!$ctx->az()->can('core.module.manage')) {
            throw HttpException::forbidden();
        }
        $ctx->requireFreshReauth();
        $code = (string) ($r->params['code'] ?? '');
        $on = $r->input('enabled') === '1';
        $reason = $this->str($r, 'reason', 200);
        if (mb_strlen($reason) < 5) {
            $this->flash($r, 'danger', 'module.error.reason');
            return $this->redirect($r, '/admin/modules');
        }
        try {
            $this->app->modules()->setEnabled($code, $on, $this->app->clock);
        } catch (RuntimeException $e) {
            $this->flash($r, 'danger', str_starts_with($e->getMessage(), 'module.') ? $e->getMessage() : 'module.error.unknown');
            return $this->redirect($r, '/admin/modules');
        }
        $this->app->audit()->record('module.' . ($on ? 'enabled' : 'disabled'), 'success', 'module', $code, null, $reason);
        $this->flash($r, 'success', $on ? 'module.enabled' : 'module.disabled', ['name' => $code]);
        return $this->redirect($r, '/admin/modules');
    }

    private function switchedOn(string $code): bool
    {
        return (int) ($this->app->db()->scalar('SELECT enabled FROM modules WHERE code = ?', [$code]) ?? 1) === 1;
    }
}
