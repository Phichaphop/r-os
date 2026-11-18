<?php

declare(strict_types=1);

namespace Blue\Core\Ui;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Throwable;

/**
 * What a visitor who has NOT signed in may see and use (0.8.1, DEC-68):
 *
 *  - Home "/" is a public app launcher. Which apps appear there is the organization's choice
 *    (guest.home_apps; nothing chosen yet = every switched-on module that has a work or tool page).
 *    Opening an app that needs an account goes through sign-in and then straight on to that app.
 *  - A few tools may be used without an account at all (guest.tools). Only modules that declare a
 *    "guest" block in their manifest can be chosen; the block lists exactly which requests a visitor may make
 *    (e.g. calculate, but not share or save) and which permissions such a request carries. Nothing a visitor does
 *    is stored against an account; the visitor is never "a user".
 * Both settings are normal-risk Config Registry keys changed by a system admin (applied directly, audited).
 */
final class GuestAccess
{
    public const HOME_KEY = 'guest.home_apps';
    public const TOOLS_KEY = 'guest.tools';

    /** @return list<string> module codes the visitor may use without signing in (chosen ∩ able ∩ switched on) */
    public static function tools(App $app): array
    {
        $able = array_keys(self::guestable($app));
        $chosen = self::listValue($app, self::TOOLS_KEY) ?? [];
        return array_values(array_intersect($able, $chosen));
    }

    /**
     * Switched-on modules that declare a guest block.
     *
     * @return array<string, array{paths: list<string>, permissions: list<string>, href: ?string}>
     */
    public static function guestable(App $app): array
    {
        $out = [];
        foreach (self::enabled($app) as $code => $m) {
            $g = $m['guest'] ?? null;
            if (is_array($g) && is_array($g['paths'] ?? null) && $g['paths'] !== []) {
                $out[(string) $code] = ['paths' => array_values(array_map('strval', $g['paths'])), 'permissions' => array_values(array_map('strval', (array) ($g['permissions'] ?? []))),
                    'href' => isset($g['href']) ? (string) $g['href'] : null];
            }
        }
        return $out;
    }

    /**
     * The guest rule that lets a visitor make this request, or null.
     *
     * @return array{module: string, permissions: list<string>}|null
     */
    public static function match(App $app, string $method, string $path): ?array
    {
        $allowed = self::tools($app);
        if ($allowed === []) {
            return null;
        }
        $line = strtoupper($method === 'HEAD' ? 'GET' : $method) . ' ' . $path;
        foreach (self::guestable($app) as $code => $g) {
            if (!in_array($code, $allowed, true)) {
                continue;
            }
            foreach ($g['paths'] as $re) {
                if (@preg_match('#^' . $re . '$#', $line) === 1) {
                    return ['module' => $code, 'permissions' => $g['permissions']];
                }
            }
        }
        return null;
    }

    /**
     * Apps on the public home: label, summary, icon, address, and whether a visitor can use it straight away.
     *
     * @return list<array{key: string, label: string, summary: string, icon: string, href: string, open: bool}>
     */
    public static function homeApps(App $app): array
    {
        $t = $app->translator();
        $chosen = self::listValue($app, self::HOME_KEY);
        $tools = self::tools($app);
        $able = self::guestable($app);
        $out = [];
        foreach (self::candidates($app) as $code => $c) {
            if ($chosen !== null && !in_array($code, $chosen, true)) {
                continue;
            }
            $out[] = ['key' => $code, 'label' => $t->get($c['label']), 'summary' => $t->has($code . '.app_summary') ? $t->get($code . '.app_summary') : '',
                'icon' => $c['icon'], 'href' => in_array($code, $tools, true) ? ($able[$code]['href'] ?? $c['href']) : $c['href'], 'open' => in_array($code, $tools, true)];
        }
        usort($out, static fn (array $a, array $b): int => [$a['open'] ? 0 : 1, $a['label']] <=> [$b['open'] ? 0 : 1, $b['label']]);
        return $out;
    }

    /**
     * Every switched-on module with a work/tool page — what the admin can choose from (first such menu item).
     *
     * @return array<string, array{label: string, icon: string, href: string, guest: bool}>
     */
    public static function candidates(App $app): array
    {
        $out = [];
        $able = self::guestable($app);
        foreach (self::enabled($app) as $code => $m) {
            foreach ((array) ($m['menu'] ?? []) as $i) {
                if (in_array((string) ($i['group'] ?? ''), ['work', 'tools'], true) && !isset($out[$code])) {
                    $out[(string) $code] = ['label' => (string) $i['label'], 'icon' => (string) ($i['icon'] ?? 'components'), 'href' => (string) $i['href'], 'guest' => isset($able[$code])];
                }
            }
        }
        return $out;
    }

    /**
     * The visitor's stand-in for permission checks: only the permissions of the matched guest rule, on no company's data.
     *
     * @param list<string> $permissions
     */
    public static function authorizer(App $app, array $permissions, string $guestKey): Authorizer
    {
        return Authorizer::guest($app->db(), $app->clock, $permissions, $guestKey);
    }

    /** @return list<string>|null null = never set */
    private static function listValue(App $app, string $key): ?array
    {
        try {
            $r = $app->registry()->resolveOrNull($key);
        } catch (Throwable) {
            return null;
        }
        if ($r === null || !is_array($r->value)) {
            return null;
        }
        return array_values(array_filter(array_map('strval', $r->value), static fn (string $c): bool => preg_match('/^[a-z][a-z0-9_]{1,30}$/', $c) === 1));
    }

    /** @return array<string, array<string, mixed>> */
    private static function enabled(App $app): array
    {
        try {
            return $app->modules()->enabled();
        } catch (Throwable) {
            return [];
        }
    }
}
