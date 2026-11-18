<?php

declare(strict_types=1);

namespace Blue\Core\System;

use Blue\Core\App;
use Blue\Core\Config\AppConfig;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Install\Installer;
use Throwable;

/** Web installer (spec 03 §6.0). Reachable only before installation (InstallGuard, DEC-17). */
final class InstallController
{
    private Installer $installer;

    public function __construct(private readonly App $app)
    {
        $this->installer = new Installer($app->root, $app->install, $app->clock, $app->logger);
    }

    public function show(Request $r): Response
    {
        $this->app->install->setupCode(); // make sure the code file exists before asking for it
        return $this->page([]);
    }

    public function submit(Request $r): Response
    {
        $t = $this->app->translator();
        $input = [
            'DB_HOST' => trim((string) $r->input('db_host', 'localhost')),
            'DB_PORT' => (int) $r->input('db_port', 3306),
            'DB_NAME' => trim((string) $r->input('db_name', '')),
            'DB_USER' => trim((string) $r->input('db_user', '')),
            'DB_PASS' => (string) $r->input('db_pass', ''),
        ];
        $appEnv = (string) $r->input('app_env', 'prototype');
        $boot = [
            'org_name_th' => trim((string) $r->input('org_name_th', '')),
            'org_name_en' => trim((string) $r->input('org_name_en', '')),
            'admin_username' => mb_strtolower(trim((string) $r->input('admin_username', ''))),
            'admin_name' => trim((string) $r->input('admin_name', '')),
            'admin_email' => mb_strtolower(trim((string) $r->input('admin_email', ''))),
            'admin_password' => (string) $r->input('admin_password', ''),
            'admin_password_confirm' => (string) $r->input('admin_password_confirm', ''),
        ];
        $demo = $r->input('demo_data') === '1' && $appEnv !== 'production';
        $errors = array_map(
            fn (string $k): string => $t->get($k, ['min' => $this->app->authSettings()->passwordMin]),
            \Blue\Core\Install\Bootstrap::validate($boot, $this->app->authSettings()->passwordMin)
        );

        if (!hash_equals($this->app->install->setupCode(), strtoupper(trim((string) $r->input('setup_code', ''))))) {
            $errors['setup_code'] = $t->get('install.error.setup_code');
        }
        if (!in_array($appEnv, AppConfig::ENVIRONMENTS, true) || $appEnv === 'ci') {
            $errors['app_env'] = $t->get('install.error.env');
        }
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $k) {
            if ($input[$k] === '') {
                $errors[strtolower($k)] = $t->get('form.required');
            }
        }
        if ($input['DB_PORT'] < 1 || $input['DB_PORT'] > 65535) {
            $errors['db_port'] = $t->get('install.error.port');
        }
        if (!$this->installer->requirementsMet()) {
            $errors['_form'] = $t->get('install.error.requirements');
        }
        if ($errors === []) {
            $connError = $this->installer->testConnection($input);
            if ($connError !== null) {
                $errors['_form'] = $t->get('install.error.connection', ['detail' => $connError]);
            }
        }
        if ($errors !== []) {
            $old = ['app_env' => $appEnv, 'demo_data' => $demo ? '1' : '']
                + array_change_key_case(array_diff_key($input, ['DB_PASS' => 1]))
                + array_diff_key($boot, ['admin_password' => 1, 'admin_password_confirm' => 1]);
            return $this->page(['errors' => $errors, 'old' => $old], 422);
        }

        try {
            $result = $this->installer->install($input, $appEnv, $this->app->migrationPaths(), $this->app->seedDirs(), 'installer@' . $r->ip, $boot, $demo);
        } catch (Throwable $e) {
            $this->app->logger->error('install_failed', \Blue\Core\Log\Logger::exceptionContext($e));
            // The installer is only reachable before installation and behind the one-time setup code,
            // so the technical cause is shown here (never a stack trace, never the DB password).
            $cause = [];
            for ($x = $e; $x !== null; $x = $x->getPrevious()) {
                $cause[] = $x->getMessage();
            }
            $detail = str_replace($input['DB_PASS'] !== '' ? $input['DB_PASS'] : "\0", '***', implode(' ← ', array_unique($cause)));
            return $this->page(['errors' => ['_form' => $t->get('install.error.failed', [
                'ref' => $this->app->logger->correlationId(),
                'detail' => mb_substr($detail, 0, 600),
            ])]], 500);
        }

        return Response::html($this->app->view()->render('install/done', [
            'title' => $t->get('install.done.title'),
            'result' => $result,
            'admin' => $boot['admin_username'],
        ], 'layouts/minimal'));
    }

    /** @param array<string, mixed> $data */
    private function page(array $data, int $status = 200): Response
    {
        return Response::html($this->app->view()->render('install/form', $data + [
            'title' => $this->app->translator()->get('install.title'),
            'requirements' => $this->installer->requirements(),
            'codePath' => 'storage/locks/setup-code.txt',
            'errors' => [],
            'old' => ['demo_data' => '1'],
            'min' => $this->app->authSettings()->passwordMin,
            'demoAllowed' => true,
        ], 'layouts/minimal'), $status);
    }
}
