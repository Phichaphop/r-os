<?php

declare(strict_types=1);

namespace Blue\Core\Modules;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use RuntimeException;
use Throwable;

/**
 * Module Registry (P0-02 §5–6, 03A §4.1): every folder modules/<code>/ with a module.json manifest is a module.
 * The manifest declares permissions, role grants, menu items, configuration DEFINITIONS (never values), routes and
 * dependencies. A module that is switched off registers no routes and no menu items; its data and permissions stay.
 *
 * Manifest keys: code, version, pack, name{th,en}, icon, depends_on[], permissions{code: normal|high},
 * grants{ROLE|"*": [permission…]} ("*" = every role), menu[{group, label, icon, href, permission}],
 * config{key: definition as in ConfigCatalog}, routes ("routes.php"), public_paths["/x/"] (GET without sign-in), sort_order,
 * jobs{"<code>.x": {every, call}}, workflows{"<code>.x": {subject, class, steps, no_fallback?, distinct_steps?}} (class::create(App) returns the
 * WorkflowSubject), config_owners{"<code>.key": {propose: permission, workflow: "<code>.x"}} (a module-owned key is proposed and
 * approved with the module's own permissions instead of core.config.*), demo ("Class::method", DEC-34 demo data, called by
 * the platform sync on installs that have demo data), imports["Class"] (Class::create(App) returns an ImportDefinition),
 * home ("Class::method", called as method(App, Authorizer): ?array{key, title, items[{href, label, summary, icon, new_tab}], empty?, more_href?} — an app group on the signed-in home page).
 */
final class ModuleRegistry
{
    public const MENU_GROUPS = ['work', 'tools', 'settings'];
    /** Sidebar sections a module's "work" items are filed under (0.27.1): "nav_section" in the manifest, else the pack. */
    public const NAV_SECTIONS = ['core_plus', 'hr_people', 'recruitment', 'hr_time', 'hr_pay', 'workplace', 'finance', 'utilities', 'other'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $manifests = null;

    /** @var array<string, bool>|null */
    private ?array $enabled = null;

    public function __construct(private readonly string $root, private readonly ?Connection $db)
    {
    }

    /**
     * Every manifest found on disk, validated, by code (sorted by sort_order, then code).
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->manifests ??= self::discover($this->root);
    }

    /** @return array<string, array<string, mixed>> */
    public static function discover(string $root): array
    {
        $out = [];
        foreach (glob($root . '/modules/*/module.json') ?: [] as $file) {
            $m = json_decode((string) file_get_contents($file), true);
            $dir = basename(dirname($file));
            if (!is_array($m)) {
                throw new RuntimeException("modules/{$dir}/module.json is not valid JSON");
            }
            $problems = self::validate($m, $dir);
            if ($problems !== []) {
                throw new RuntimeException("modules/{$dir}/module.json: " . implode('; ', $problems));
            }
            $m['dir'] = dirname($file);
            $out[(string) $m['code']] = $m;
        }
        uasort($out, static fn (array $a, array $b): int => [(int) ($a['sort_order'] ?? 100), $a['code']] <=> [(int) ($b['sort_order'] ?? 100), $b['code']]);
        return $out;
    }

    /**
     * Manifest validator (P0-02 §6 — checked by tests and on every sync).
     *
     * @param array<string, mixed> $m
     * @return list<string>
     */
    public static function validate(array $m, string $dir): array
    {
        $p = [];
        $code = (string) ($m['code'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]{1,30}$/', $code) || $code !== $dir || $code === 'core') {
            $p[] = 'code must match the folder name (a-z, 0-9, _), not "core"';
        }
        foreach (['version', 'pack'] as $k) {
            if (!is_string($m[$k] ?? null) || $m[$k] === '') {
                $p[] = "{$k} missing";
            }
        }
        if (!is_string($m['name']['th'] ?? null) || !is_string($m['name']['en'] ?? null)) {
            $p[] = 'name.th / name.en missing';
        }
        foreach ((array) ($m['permissions'] ?? []) as $perm => $risk) {
            if (!str_starts_with((string) $perm, $code . '.') || !in_array($risk, ['normal', 'high'], true)) {
                $p[] = "permission {$perm} must start with {$code}. and be normal|high";
            }
        }
        $declared = array_keys((array) ($m['permissions'] ?? []));
        foreach ((array) ($m['grants'] ?? []) as $role => $perms) {
            foreach ((array) $perms as $perm) {
                if (!in_array($perm, $declared, true)) {
                    $p[] = "grant {$role}: {$perm} is not declared by this module";
                }
            }
        }
        if (isset($m['nav_section']) && !in_array($m['nav_section'], self::NAV_SECTIONS, true)) {
            $p[] = 'nav_section must be one of ' . implode('|', self::NAV_SECTIONS);
        }
        foreach ((array) ($m['menu'] ?? []) as $i) {
            if (!is_array($i) || !in_array($i['group'] ?? '', self::MENU_GROUPS, true)) {
                $p[] = 'menu item group must be one of ' . implode('|', self::MENU_GROUPS);
            } elseif (!str_starts_with((string) ($i['href'] ?? ''), '/') || str_starts_with((string) $i['href'], '//') || !in_array($i['permission'] ?? '', $declared, true)) {
                $p[] = 'menu item needs a local href and a declared permission';
            }
        }
        // 0.8.1 (DEC-68): requests a visitor without an account may make, if the organization opens the tool —
        // "METHOD /path" regexes under the module's own first path segment, carrying only normal-risk own permissions
        if (isset($m['guest'])) {
            $g = $m['guest'];
            $risk = (array) ($m['permissions'] ?? []);
            $ok = is_array($g) && is_array($g['paths'] ?? null) && $g['paths'] !== [] && count($g['paths']) <= 20 && is_array($g['permissions'] ?? []);
            foreach ($ok ? (array) $g['paths'] : [] as $re) {
                if (!is_string($re) || preg_match('#^(GET|POST|\(GET\|POST\)) /[a-z]#', $re) !== 1 || str_contains($re, '#') || @preg_match('#^' . $re . '$#', '') === false) {
                    $ok = false;
                }
            }
            foreach ($ok ? (array) ($g['permissions'] ?? []) : [] as $perm) {
                if (!in_array($perm, $declared, true) || ($risk[$perm] ?? '') !== 'normal') {
                    $ok = false;
                }
            }
            if ($ok && isset($g['href']) && (!is_string($g['href']) || !str_starts_with($g['href'], '/') || str_starts_with($g['href'], '//'))) {
                $ok = false;
            }
            if (!$ok) {
                $p[] = 'guest needs paths ("METHOD /path" regexes, max 20), only own normal-risk permissions and a local href';
            }
        }
        foreach ((array) ($m['config'] ?? []) as $key => $def) {
            if (!str_starts_with((string) $key, $code . '.') || !is_array($def) || count($def) !== 8) {
                $p[] = "config {$key} must start with {$code}. and use the 8-field ConfigCatalog definition";
            }
        }
        foreach ((array) ($m['document_classes'] ?? []) as $dc => $def) {
            if (!str_starts_with((string) $dc, $code . '.') || !is_array($def) || count($def) !== 8) {
                $p[] = "document class {$dc} must start with {$code}. and use the 8-field DocumentCatalog definition";
            }
        }
        $ns = 'Blue\\Modules\\' . str_replace(' ', '', ucwords(str_replace('_', ' ', $code))) . '\\';
        foreach ((array) ($m['jobs'] ?? []) as $type => $job) {
            $every = is_array($job) ? ($job['every'] ?? null) : null;
            $call = is_array($job) ? (string) ($job['call'] ?? '') : '';
            if (
                !str_starts_with((string) $type, $code . '.') || !is_int($every) || $every < 1 || $every > 10080
                || preg_match('/^[A-Za-z0-9_\\\\]+::[a-zA-Z_][a-zA-Z0-9_]*$/', $call) !== 1 || stripos($call, $ns) !== 0
            ) {
                $p[] = "job {$type} must start with {$code}., run every 1–10080 minutes and call a static method of this module";
            }
        }
        $wfCodes = [];
        foreach ((array) ($m['workflows'] ?? []) as $wcode => $wf) {
            $wfCodes[] = (string) $wcode;
            $ok = str_starts_with((string) $wcode, $code . '.') && is_array($wf) && preg_match('/^[a-z][a-z_]{1,39}$/', (string) ($wf['subject'] ?? '')) === 1
                && (!isset($wf['class']) || (preg_match('/^[A-Za-z0-9_\\\\]+$/', (string) $wf['class']) === 1 && stripos((string) $wf['class'], $ns) === 0))
                && is_array($wf['steps'] ?? null) && $wf['steps'] !== [] && count($wf['steps']) <= 5;
            foreach ((array) ($wf['steps'] ?? []) as $st) {
                $type = (string) ($st['approver']['type'] ?? '');
                $val = (string) ($st['approver']['value'] ?? '');
                if (
                    !in_array($type, ['permission', 'role', 'manager'], true) || $val === '' || !in_array($st['scope'] ?? '', ['subject', 'any'], true)
                    || ($type !== 'role' && !in_array($val, $declared, true) && !str_starts_with($val, 'core.'))
                ) {
                    $ok = false;
                }
            }
            if (!$ok) {
                $p[] = "workflow {$wcode} must start with {$code}., name a subject, a class of this module and 1–5 steps (permission|role|manager)";
            }
        }
        foreach ((array) ($m['config_owners'] ?? []) as $ckey => $own) {
            if (
                !isset(((array) ($m['config'] ?? []))[$ckey]) || !in_array($own['propose'] ?? '', $declared, true) || !in_array($own['workflow'] ?? '', $wfCodes, true)
                || (isset($own['describe']) && (preg_match('/^[A-Za-z0-9_\\\\]+::[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $own['describe']) !== 1 || stripos((string) $own['describe'], $ns) !== 0))
            ) {
                $p[] = "config_owners {$ckey}: key, propose permission and workflow must be declared by this module";
            }
        }
        foreach ((array) ($m['imports'] ?? []) as $class) {
            if (preg_match('/^[A-Za-z0-9_\\\\]+$/', (string) $class) !== 1 || stripos((string) $class, $ns) !== 0) {
                $p[] = 'imports must list classes of this module';
            }
        }
        if (isset($m['demo']) && (preg_match('/^[A-Za-z0-9_\\\\]+::[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $m['demo']) !== 1 || stripos((string) $m['demo'], $ns) !== 0)) {
            $p[] = 'demo must be a static method of this module';
        }
        if (isset($m['personal_data']) && (preg_match('/^[A-Za-z0-9_\\\\]+$/', (string) $m['personal_data']) !== 1 || stripos((string) $m['personal_data'], $ns) !== 0)) {
            $p[] = 'personal_data must be a class of this module';
        }
        if (isset($m['measures']) && (preg_match('/^[A-Za-z0-9_\\\\]+$/', (string) $m['measures']) !== 1 || stripos((string) $m['measures'], $ns) !== 0)) {
            $p[] = 'measures must be a class of this module';
        }
        if (isset($m['home']) && (preg_match('/^[A-Za-z0-9_\\\\]+::[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $m['home']) !== 1 || stripos((string) $m['home'], $ns) !== 0)) {
            $p[] = 'home must be a static method of this module';
        }
        foreach ((array) ($m['public_paths'] ?? []) as $pp) {
            if (!is_string($pp) || !preg_match('#^/[a-z0-9_-]+/$#', $pp) || in_array($pp, ['/admin/', '/system/', '/api/', '/m2m/'], true)) {
                $p[] = 'public_paths must be "/segment/" prefixes outside /admin/, /system/, /api/';
            }
        }
        if (isset($m['routes']) && !preg_match('/^[a-z_]+\.php$/', (string) $m['routes'])) {
            $p[] = 'routes must be a php file name in the module folder';
        }
        return $p;
    }

    /** @return array<string, mixed>|null */
    public function get(string $code): ?array
    {
        return $this->all()[$code] ?? null;
    }

    public function isEnabled(string $code): bool
    {
        if (!isset($this->all()[$code])) {
            return false;
        }
        if ($this->enabled === null) {
            $this->enabled = [];
            if ($this->db !== null) {
                try {
                    foreach ($this->db->all("SELECT code, enabled FROM modules WHERE code <> 'core'") as $row) {
                        $this->enabled[(string) $row['code']] = (int) $row['enabled'] === 1;
                    }
                } catch (Throwable) {
                    // before installation / during an update — treat discovered modules as on
                }
            }
        }
        if (!($this->enabled[$code] ?? true)) {
            return false;
        }
        foreach ((array) ($this->all()[$code]['depends_on'] ?? []) as $dep) {
            if ($dep !== 'core' && !$this->isEnabled((string) $dep)) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, array<string, mixed>> enabled manifests */
    public function enabled(): array
    {
        return array_filter($this->all(), fn (array $m): bool => $this->isEnabled((string) $m['code']));
    }

    public function forget(): void
    {
        $this->enabled = null;
    }

    /** Switch a module on/off (System Admin; audited by the caller). Refuses to switch off a module others need. */
    public function setEnabled(string $code, bool $on, Clock $clock): void
    {
        if ($this->db === null || !isset($this->all()[$code])) {
            throw new RuntimeException('module.error.unknown');
        }
        if (!$on) {
            foreach ($this->enabled() as $other) {
                if (in_array($code, (array) ($other['depends_on'] ?? []), true)) {
                    throw new RuntimeException('module.error.needed_by');
                }
            }
        }
        $this->db->run('UPDATE modules SET enabled = ?, updated_at = ? WHERE code = ?', [$on ? 1 : 0, $clock->now()->format('Y-m-d H:i:s.u'), $code]);
        $this->forget();
    }

    /**
     * Writes module rows (new modules start ON, existing switches are kept). Permissions, grants and
     * configuration definitions are merged by RbacSync / ConfigRegistry::sync.
     *
     * @param array<string, array<string, mixed>> $manifests
     */
    public static function syncModules(Connection $db, Clock $clock, array $manifests): void
    {
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        foreach ($manifests as $m) {
            $db->run(
                'INSERT INTO modules (code, version, pack, enabled, sort_order, updated_at) VALUES (?, ?, ?, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE version = VALUES(version), pack = VALUES(pack), sort_order = VALUES(sort_order), updated_at = VALUES(updated_at)',
                [$m['code'], $m['version'], $m['pack'], (int) ($m['sort_order'] ?? 100), $now]
            );
        }
    }
}
