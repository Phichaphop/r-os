<?php

declare(strict_types=1);

namespace Blue\Core\System;

use Blue\Core\Database\MigrationRunner;
use Blue\Core\Error\HttpException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\RequestContext;
use Blue\Core\Http\Response;
use Blue\Core\I18n\MessageSync;
use Blue\Core\Install\PlatformSync;
use Throwable;

/**
 * "Update database" (03 §6.0 item 8, DEC-16): runs the pending VERSIONED migrations through the same runner as the
 * CLI — never ad-hoc SQL. System administrator only, re-authentication, backup confirmation, audit, migration log.
 * Destructive migrations are refused here (they need the CLI with explicit approval — 6.30.11).
 */
final class UpdateController extends Controller
{
    public function show(Request $r): Response
    {
        $this->guard($r);
        return $this->page('system/update', [
            'title' => $this->app->translator()->get('update.title'),
            'migrations' => $this->runner()->status(),
            'needsBackup' => !in_array($this->app->config->env(), ['dev', 'ci'], true),
            'result' => $this->store($r)->get('update_result'),
        ]);
    }

    public function run(Request $r): Response
    {
        $ctx = $this->guard($r);
        $ctx->requireFreshReauth();
        $needsBackup = !in_array($this->app->config->env(), ['dev', 'ci'], true);
        if ($needsBackup && $r->input('backup_confirmed') !== '1') {
            $this->flash($r, 'danger', 'update.error.backup');
            return $this->redirect($r, '/system/update');
        }
        try {
            $res = $this->runner()->migrate('user:' . $ctx->userId(), false);
            (new MessageSync($this->app->db(), $this->app->clock))->sync($this->app->seedDirs(), ['en', 'th']);
            $sync = PlatformSync::run($this->app->db(), $this->app->clock);
            $this->app->resetSchemaPending();
            $this->app->audit()->record('system.update', 'success', 'system', 'migrations', null, null, ['applied' => $res['applied'], 'run_id' => $res['run_id'], 'sync' => $sync]);
            $this->store($r)->set('update_result', ['ok' => true, 'applied' => $res['applied'], 'run_id' => $res['run_id']]);
            $this->flash($r, 'success', 'update.done', ['n' => count($res['applied'])]);
        } catch (Throwable $e) {
            $this->app->logger->error('update_failed', ['error' => $e->getMessage()]);
            $this->app->audit()->record('system.update', 'failure', 'system', 'migrations', null, mb_substr($e->getMessage(), 0, 250));
            $this->store($r)->set('update_result', ['ok' => false, 'error' => $e->getMessage(), 'ref' => $this->app->logger->correlationId()]);
            $this->flash($r, 'danger', 'update.failed');
        }
        return $this->redirect($r, '/system/update');
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner($this->app->db(), $this->app->migrationPaths(), $this->app->clock, $this->app->logger);
    }

    /** SYS role is accepted as well: before the first update the new permission is not in the database yet. */
    private function guard(Request $r): RequestContext
    {
        $ctx = $this->ctx($r);
        $az = $ctx->az();
        if (!in_array('SYS', $az->roleCodes(), true) && !$az->scope('core.system.update')->all) {
            throw HttpException::forbidden();
        }
        return $ctx;
    }
}
