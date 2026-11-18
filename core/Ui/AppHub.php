<?php

declare(strict_types=1);

namespace Blue\Core\Ui;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Authz\RbacSync;

/**
 * Home as an app hub (6.4, REQ-IAM-003): "Home shows every module — with rights = can be opened; without rights =
 * greyed out + 'no access' + a Request access button that goes to the central inbox".
 *
 * - Apps the user may open come from the App Shell (ShellData) so the hub and the menu can never disagree.
 * - Apps the user may NOT open are business apps of the platform (documents, templates, calendars, finance,
 *   payments) and the work/tool menu items of every ENABLED module. Administration and settings pages are not
 *   offered as locked tiles; a module that is switched off is not shown at all (it has no routes).
 * - A locked tile offers only the roles that would actually open it, least privilege first.
 * Views receive plain arrays only; nothing here changes data.
 */
final class AppHub
{
    /** Platform business apps that can be locked: href => [app key, label key, icon, permission, group] */
    public const CORE_APPS = [
        '/documents' => ['core.documents', 'nav.documents', 'file-text', 'core.document.view', 'work'],
        '/templates' => ['core.templates', 'nav.templates', 'clipboard-check', 'core.template.issue', 'work'],
        '/calendars' => ['core.calendars', 'nav.calendars', 'calendar-event', 'core.calendar.view', 'work'],
        '/finance' => ['core.finance', 'nav.finance', 'report-money', 'core.finance.view', 'money'],
        '/payments' => ['core.payments', 'nav.payments', 'cash', 'core.payment.view', 'money'],
    ];

    /** Translation keys of the groups a locked app belongs to (literal keys so the i18n check can see them). */
    private const GROUP_LABELS = ['work' => 'nav.group_work', 'tools' => 'nav.group_tools', 'money' => 'nav.group_money'];

    /** Short descriptions of platform pages (translation keys), by href. Modules use "<code>.app_summary". */
    private const CORE_SUMMARIES = [
        '/approvals' => 'hub.summary.approvals',
        '/requests' => 'hub.summary.requests',
        '/approvals/delegate' => 'hub.summary.delegate',
        '/documents' => 'hub.summary.documents',
        '/templates' => 'hub.summary.templates',
        '/calendars' => 'hub.summary.calendars',
        '/finance' => 'hub.summary.finance',
        '/payments' => 'hub.summary.payments',
        '/admin/org' => 'hub.summary.org',
    ];

    /**
     * @param array<string, mixed> $shell ShellData::build() result for this user
     * @return array{groups: list<array{label: string, items: list<array<string, mixed>>}>, locked: list<array<string, mixed>>, disabled: int, total: int}
     */
    public static function build(App $app, Authorizer $az, int $userId, array $shell): array
    {
        $t = $app->translator();
        $modules = [];
        try {
            $modules = $app->modules()->all();
        } catch (\Throwable) {
            // a broken manifest is reported on the module admin page; the hub still shows the platform apps
        }
        $summaryByHref = self::CORE_SUMMARIES;
        foreach ($modules as $code => $m) {
            foreach ((array) ($m['menu'] ?? []) as $i) {
                $summaryByHref[(string) $i['href']] ??= $code . '.app_summary';
            }
        }

        $open = [];
        $groups = [];
        foreach ((array) ($shell['groups'] ?? []) as $g) {
            $items = [];
            foreach ((array) $g['items'] as $i) {
                $href = (string) $i['href'];
                $open[$href] = true;
                $key = $summaryByHref[$href] ?? null;
                $items[] = [
                    'label' => (string) $i['label'], 'icon' => (string) $i['icon'], 'href' => $href,
                    'count' => (int) ($i['count'] ?? 0),
                    'summary' => $key !== null && $t->has($key) ? $t->get($key) : '',
                    'group' => (string) $g['label'],
                ];
            }
            if ($items !== []) {
                $groups[] = ['label' => (string) $g['label'], 'items' => $items];
            }
        }

        // candidates for "locked": platform business apps + enabled modules' work/tool items
        $candidates = [];
        foreach (self::CORE_APPS as $href => [$key, $label, $icon, $perm, $group]) {
            $candidates[] = ['key' => $key, 'href' => $href, 'label' => $t->get($label), 'icon' => $icon, 'permission' => $perm, 'group' => $group];
        }
        $enabled = [];
        try {
            $enabled = $app->modules()->enabled();
        } catch (\Throwable) {
        }
        foreach ($enabled as $code => $m) {
            foreach ((array) ($m['menu'] ?? []) as $i) {
                if (in_array($i['group'] ?? '', ['work', 'tools'], true)) {
                    $candidates[] = [
                        'key' => (string) $code, 'href' => (string) $i['href'], 'label' => $t->get((string) $i['label']),
                        'icon' => (string) ($i['icon'] ?? 'components'), 'permission' => (string) $i['permission'], 'group' => (string) $i['group'],
                    ];
                }
            }
        }

        $roles = RbacSync::roles($modules);
        $requests = self::openRequests($app, $userId);
        $locked = [];
        $seen = [];
        foreach ($candidates as $c) {
            if (isset($open[$c['href']]) || isset($seen[$c['key']])) {
                continue;
            }
            $offer = self::rolesFor($roles, $c['permission']);
            if ($offer === []) {
                continue; // no role opens it — nothing to ask for
            }
            $seen[$c['key']] = true;
            // a request made for this app, or for any role that would open it, is still waiting
            $statuses = [];
            foreach ($requests as $req) {
                if ($req['module_code'] === $c['key'] || in_array($req['role_code'], $offer, true)) {
                    $statuses[$req['status']] = true;
                }
            }
            $state = isset($statuses['returned']) ? 'returned' : (isset($statuses['pending']) ? 'pending' : 'locked');
            $sk = $summaryByHref[$c['href']] ?? null;
            $locked[] = [
                'key' => $c['key'], 'label' => $c['label'], 'icon' => $c['icon'], 'state' => $state, 'roles' => $offer,
                'summary' => $sk !== null && $t->has($sk) ? $t->get($sk) : '',
                'group' => $t->get(self::GROUP_LABELS[$c['group']]),
            ];
        }

        $disabled = 0;
        if ($az->can('core.module.manage')) {
            $disabled = count($modules) - count($enabled);
        }
        $total = array_sum(array_map(static fn (array $g): int => count($g['items']), $groups));
        return ['groups' => $groups, 'locked' => $locked, 'disabled' => $disabled, 'total' => $total];
    }

    /**
     * A locked app the user may ask for (key from build()), or null when the key is unknown / already open.
     *
     * @param array<string, mixed> $shell
     * @return array<string, mixed>|null
     */
    public static function lockedApp(App $app, Authorizer $az, int $userId, array $shell, string $key): ?array
    {
        foreach (self::build($app, $az, $userId, $shell)['locked'] as $l) {
            if ($l['key'] === $key) {
                return $l;
            }
        }
        return null;
    }

    /** Display name of an app key (for the approval page); the key itself when the app no longer exists. */
    public static function appLabel(App $app, string $key): string
    {
        foreach (self::CORE_APPS as [$k, $label]) {
            if ($k === $key) {
                return $app->translator()->get($label);
            }
        }
        $m = $app->modules()->get($key);
        if ($m === null) {
            return $key;
        }
        $loc = $app->translator()->locale() === 'th' ? 'th' : 'en';
        return (string) ($m['name'][$loc] ?? $key);
    }

    /**
     * Roles that grant $permission, least privilege first: ordinary before high-risk (high-risk only when nothing
     * else opens it), then the narrowest default data scope (self → team → site → company → group), then the fewest
     * permissions.
     *
     * @param array<string, array{0:string, 1:bool, 2:bool, 3:list<string>}> $roles
     * @return list<string>
     */
    public static function rolesFor(array $roles, string $permission): array
    {
        $width = ['self' => 0, 'team' => 1, 'department' => 2, 'site' => 3, 'legal_entity' => 4, 'organization' => 5];
        $normal = [];
        $high = [];
        foreach ($roles as $code => [$scope, $isHigh, , $perms]) {
            if (in_array($permission, $perms, true)) {
                $rank = [$width[$scope] ?? 9, count($perms), (string) $code];
                if ($isHigh) {
                    $high[$code] = $rank;
                } else {
                    $normal[$code] = $rank;
                }
            }
        }
        $pick = $normal !== [] ? $normal : $high;
        asort($pick);
        return array_map('strval', array_keys($pick));
    }

    /** @return list<array{role_code: string, module_code: ?string, status: string}> the user's requests still waiting */
    private static function openRequests(App $app, int $userId): array
    {
        try {
            $rows = $app->db()->all(
                "SELECT role_code, module_code, status FROM access_requests WHERE user_id = ? AND status IN ('pending','returned')",
                [$userId]
            );
        } catch (\Throwable) {
            return []; // before the 0.4.3 database update
        }
        return array_map(static fn (array $r): array => [
            'role_code' => (string) $r['role_code'], 'module_code' => $r['module_code'] !== null ? (string) $r['module_code'] : null, 'status' => (string) $r['status'],
        ], $rows);
    }
}
