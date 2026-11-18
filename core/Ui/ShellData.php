<?php

declare(strict_types=1);

namespace Blue\Core\Ui;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Http\SessionStore;
use Blue\Core\Modules\ModuleRegistry;

/**
 * Everything the App Shell needs about the signed-in user (P0-05 §2): menu items the user may open,
 * the company/site context switcher (DEC-21) and the user badge. Views receive plain arrays only.
 */
final class ShellData
{
    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function build(App $app, array $user, Authorizer $az, SessionStore $store): array
    {
        $t = $app->translator();
        $groups = [];
        // everyone: approvals waiting for me, my requests (6.9 item 2, 6.9b)
        $inboxCount = 0;
        $unread = 0;
        try {
            $inboxCount = $app->workflow()->openTaskCount((int) $user['id']);
            $unread = $app->notifier()->unreadCount((int) $user['id']);
        } catch (\Throwable) {
            // database not updated yet (schema guard shows the update page)
        }
        $work = [
            ['label' => $t->get('nav.approvals'), 'icon' => 'checks', 'href' => '/approvals', 'count' => $inboxCount],
            ['label' => $t->get('nav.my_requests'), 'icon' => 'send', 'href' => '/requests'],
            ['label' => $t->get('nav.delegate'), 'icon' => 'users', 'href' => '/approvals/delegate', 'sub' => true], // sub-item of the two above
        ];
        try {
            [$docSql] = $app->documents()->visibleSql($az);
            if ($docSql !== '1=0' || $app->documents()->uploadableClasses($az) !== []) {
                $work[] = ['label' => $t->get('nav.documents'), 'icon' => 'file-text', 'href' => '/documents'];
            }
        } catch (\Throwable) {
            // before the 3B database update
        }
        if ($az->can('core.template.issue') || $az->scope('core.template.manage')->all) {
            $work[] = ['label' => $t->get('nav.templates'), 'icon' => 'clipboard-check', 'href' => '/templates'];
        }
        // everyone signed in sees the calendar that applies to them (0.6.1); managing calendars is under settings
        $work[] = ['label' => $t->get('nav.calendars'), 'icon' => 'calendar-event', 'href' => '/calendars'];
        // module menus (module registry): only enabled modules, only items the user has the permission for.
        // 0.27.1 (DEC-92): "work" items are filed into sections (manifest "nav_section", else the pack) so an
        // administrator's menu is a handful of foldable groups; a module's 2nd+ item in a group becomes a sub-item;
        // self-service pages (/me/...) stay in "my work".
        $sections = [];
        $tools = [];
        $moduleSettings = [];
        try {
            foreach ($app->modules()->enabled() as $m) {
                $section = (string) ($m['nav_section'] ?? $m['pack'] ?? '');
                if (!in_array($section, ModuleRegistry::NAV_SECTIONS, true)) {
                    $section = 'other';
                }
                $seen = ['work' => false, 'tools' => false, 'settings' => false];
                foreach ((array) ($m['menu'] ?? []) as $i) {
                    $group = (string) ($i['group'] ?? '');
                    if (!isset($seen[$group]) || !$az->can((string) $i['permission'])) {
                        continue;
                    }
                    $item = ['label' => $t->get((string) $i['label']), 'icon' => (string) ($i['icon'] ?? 'components'), 'href' => (string) $i['href']];
                    if ($group === 'work' && str_starts_with($item['href'], '/me/')) {
                        $work[] = $item;
                        continue;
                    }
                    if ($seen[$group]) {
                        $item['sub'] = true;
                    }
                    $seen[$group] = true;
                    if ($group === 'work') {
                        $sections[$section][] = $item;
                    } elseif ($group === 'tools') {
                        $tools[] = $item;
                    } else {
                        $moduleSettings[] = $item;
                    }
                }
            }
        } catch (\Throwable) {
            // a broken manifest must not take the shell down; the module admin page reports it
        }
        $groups[] = ['key' => 'work', 'label' => $t->get('nav.group_work'), 'items' => $work];
        foreach (ModuleRegistry::NAV_SECTIONS as $section) {
            if (isset($sections[$section])) {
                $groups[] = ['key' => 'sec_' . $section, 'label' => $t->get('nav.section.' . $section), 'items' => $sections[$section]];
            }
        }
        if ($tools !== []) {
            $groups[] = ['key' => 'tools', 'label' => $t->get('nav.group_tools'), 'items' => $tools];
        }
        $money = [];
        if ($az->can('core.finance.view')) {
            $money[] = ['label' => $t->get('nav.finance'), 'icon' => 'report-money', 'href' => '/finance'];
        }
        if ($az->can('core.payment.view')) {
            $money[] = ['label' => $t->get('nav.payments'), 'icon' => 'cash', 'href' => '/payments'];
        }
        if ($money !== []) {
            $groups[] = ['key' => 'money', 'label' => $t->get('nav.group_money'), 'items' => $money];
        }
        $admin = [];
        if ($az->can('core.org.view')) {
            $admin[] = ['label' => $t->get('nav.org'), 'icon' => 'building', 'href' => '/admin/org'];
        }
        if ($az->scope('core.user.view')->all) {
            $admin[] = ['label' => $t->get('nav.users'), 'icon' => 'users', 'href' => '/admin/users', 'count' => $az->can('core.user.grant') ? count($app->iam()->pendingApprovals()) : 0];
        }
        if ($az->can('core.role.view')) {
            $admin[] = ['label' => $t->get('nav.roles'), 'icon' => 'shield-check', 'href' => '/admin/roles'];
        }
        if ($az->scope('core.user.manage')->all) {
            $admin[] = ['label' => $t->get('nav.security'), 'icon' => 'lock', 'href' => '/admin/security'];
        }
        if ($az->can('core.audit.view')) {
            $admin[] = ['label' => $t->get('nav.audit'), 'icon' => 'history', 'href' => '/admin/audit'];
        }
        if (!$az->scope('core.privacy.manage')->isEmpty()) {
            $admin[] = ['label' => $t->get('nav.privacy'), 'icon' => 'shield-check', 'href' => '/privacy/requests'];
        }
        if ($admin !== []) {
            $groups[] = ['key' => 'admin', 'label' => $t->get('nav.group_admin'), 'items' => $admin];
        }
        $settings = [];
        if ($az->scope('core.config.approve')->all) {
            $settings[] = ['label' => $t->get('nav.appearance'), 'icon' => 'palette', 'href' => '/admin/settings/appearance'];
            $settings[] = ['label' => $t->get('nav.guest_access'), 'icon' => 'lock-open', 'href' => '/admin/settings/guest'];
        }
        if (!$az->scope('core.config.view')->isEmpty()) {
            $settings[] = ['label' => $t->get('nav.config'), 'icon' => 'settings', 'href' => '/admin/config'];
        }
        if ($az->can('core.records.manage') || $az->can('core.records.approve')) {
            $settings[] = ['label' => $t->get('nav.records'), 'icon' => 'history', 'href' => '/admin/records'];
        }
        if ($az->scope('core.backup.view')->all) {
            $settings[] = ['label' => $t->get('nav.backups'), 'icon' => 'database', 'href' => '/admin/backups'];
        }
        if ($az->scope('core.integration.view')->all) {
            $settings[] = ['label' => $t->get('nav.integrations'), 'icon' => 'components', 'href' => '/admin/integrations'];
        }
        if ($az->scope('core.workflow.manage')->all) {
            $settings[] = ['label' => $t->get('nav.workflows'), 'icon' => 'list-check', 'href' => '/admin/workflows'];
        }
        if ($az->can('core.module.manage')) {
            $settings[] = ['label' => $t->get('nav.modules'), 'icon' => 'layout-dashboard', 'href' => '/admin/modules'];
        }
        if (!$az->scope('core.calendar.manage')->isEmpty()) {
            $settings[] = ['label' => $t->get('nav.calendars_admin'), 'icon' => 'calendar-event', 'href' => '/admin/calendars'];
        }
        if ($settings !== []) {
            $groups[] = ['key' => 'settings', 'label' => $t->get('nav.group_settings'), 'items' => $settings];
        }
        if ($moduleSettings !== []) {
            $groups[] = ['key' => 'module_settings', 'label' => $t->get('nav.group_module_settings'), 'items' => $moduleSettings];
        }
        $system = [];
        if (in_array('SYS', $az->roleCodes(), true) || $az->scope('core.system.update')->all) {
            $system[] = ['label' => $t->get('nav.update'), 'icon' => 'refresh', 'href' => '/system/update', 'count' => $app->schemaPending()];
        }
        if (!$app->config->isProduction()) {
            if ($az->can('core.system.status')) {
                $system[] = ['label' => $t->get('nav.system_status'), 'icon' => 'database', 'href' => '/system/status'];
            }
            if ($az->can('core.system.showcase') && $app->config->bool('UI_SHOWCASE')) {
                $system[] = ['label' => $t->get('nav.ui_showcase'), 'icon' => 'components', 'href' => '/system/ui-showcase'];
            }
            if ($az->can('core.system.mailbox') && $app->mailer()->available()) {
                $system[] = ['label' => $t->get('nav.mailbox'), 'icon' => 'inbox', 'href' => '/system/mailbox'];
            }
        }
        if ($system !== []) {
            $groups[] = ['key' => 'system', 'label' => $t->get('nav.group_system'), 'items' => $system];
        }

        $companies = $app->org()->scopeOptions($az->reachableScope(), $t->locale())['legal_entity'];
        $current = $store->get('ctx_le');
        if (!is_int($current) || !isset($companies[$current])) {
            $current = $companies === [] ? null : (int) array_key_first($companies);
        }
        $name = (string) $user['display_name'];
        // first letter of the first two words that start with a letter ("ผู้ดูแลระบบ (DEMO)" → "ผ", not "ผ(")
        preg_match_all('/(?<!\\S)\\p{L}/u', $name, $letters);
        $initials = mb_strtoupper(implode('', array_slice($letters[0], 0, 2)) ?: mb_substr($name, 0, 1));

        return [
            'groups' => $groups,
            'companies' => $companies,
            'company' => $current,
            'user' => ['name' => $name, 'username' => (string) $user['username'], 'initials' => $initials],
            'unread' => $unread,
        ];
    }
}
