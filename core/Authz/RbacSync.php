<?php

declare(strict_types=1);

namespace Blue\Core\Authz;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;

/** Writes Catalog plus module manifests (permissions, role grants) into modules / permissions / roles / role_permissions. Idempotent. */
final class RbacSync
{
    /** @param array<string, array<string, mixed>> $modules manifests from the module registry */
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly array $modules = [])
    {
    }

    /**
     * Core permissions + every module's permissions.
     *
     * @param array<string, array<string, mixed>> $modules
     * @return array<string, array{risk: string, module: string}>
     */
    public static function permissions(array $modules): array
    {
        $out = [];
        foreach (Catalog::PERMISSIONS as $code => $risk) {
            $out[$code] = ['risk' => $risk, 'module' => explode('.', $code)[0]];
        }
        foreach ($modules as $m) {
            foreach ((array) ($m['permissions'] ?? []) as $code => $risk) {
                $out[(string) $code] = ['risk' => (string) $risk, 'module' => (string) $m['code']];
            }
        }
        return $out;
    }

    /**
     * Catalog roles with module grants added ("*" = every role).
     *
     * @param array<string, array<string, mixed>> $modules
     * @return array<string, array{0:string, 1:bool, 2:bool, 3:list<string>}>
     */
    public static function roles(array $modules): array
    {
        $roles = Catalog::ROLES;
        foreach ($modules as $m) {
            foreach ((array) ($m['grants'] ?? []) as $role => $perms) {
                foreach ($role === '*' ? array_keys($roles) : [(string) $role] as $r) {
                    if (isset($roles[$r])) {
                        $roles[$r][3] = array_values(array_unique(array_merge($roles[$r][3], array_map('strval', (array) $perms))));
                    }
                }
            }
        }
        return $roles;
    }

    public function sync(): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $this->db->transactional(function (Connection $db) use ($now): void {
            $db->run(
                "INSERT INTO modules (code, version, pack, enabled, sort_order, updated_at) VALUES ('core', '2026.11.1', 'core', 1, 0, ?)
                 ON DUPLICATE KEY UPDATE version = VALUES(version), updated_at = VALUES(updated_at)",
                [$now]
            );
            \Blue\Core\Modules\ModuleRegistry::syncModules($db, $this->clock, $this->modules);
            foreach (self::permissions($this->modules) as $code => $p) {
                $db->run(
                    'INSERT INTO permissions (code, module_code, risk) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE risk = VALUES(risk), module_code = VALUES(module_code)',
                    [$code, $p['module'], $p['risk']]
                );
            }
            foreach (self::roles($this->modules) as $code => [$scope, $high, $mfa, $perms]) {
                $db->run(
                    "INSERT INTO roles (code, module_code, default_scope, is_system, high_risk, requires_mfa) VALUES (?, 'core', ?, 1, ?, ?)
                     ON DUPLICATE KEY UPDATE default_scope = VALUES(default_scope), high_risk = VALUES(high_risk), requires_mfa = VALUES(requires_mfa)",
                    [$code, $scope, (int) $high, (int) $mfa]
                );
                $roleId = (int) $db->scalar('SELECT id FROM roles WHERE code = ?', [$code]);
                $db->run('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
                foreach ($perms as $p) {
                    $db->run('INSERT INTO role_permissions (role_id, permission_code) VALUES (?, ?)', [$roleId, $p]);
                }
            }
        });
    }
}
