<?php

declare(strict_types=1);

namespace Blue\Core\Authz;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;

/**
 * Effective rights of one user (P0-04 §4.1):
 *   permission (role) ∩ data scope (access_scopes) [∩ record rule ∩ field rule — added by modules].
 * Only assignments that are `active` and inside [valid_from, valid_to] count. No assignment = no rights.
 * System Admin does NOT get business data automatically — only what Catalog gives the SYS role (6.4).
 */
final class Authorizer
{
    /** @var array<string, ScopeSet>|null permission => scope */
    private ?array $byPermission = null;

    /** @var list<array{code:string, requires_mfa:bool, high_risk:bool}> */
    private array $roles = [];

    private ScopeSet $union;

    /** A visitor who has not signed in (0.8.1): set only through guest(); null for every real user. */
    public ?string $guestKey = null;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        public readonly int $userId,
    ) {
        $this->union = ScopeSet::none();
    }

    /**
     * Stand-in for a visitor without an account using a tool the organization opened to the public (GuestAccess).
     * userId is 0 and the given permissions cover NO company, site or department (legal entity id 0 does not exist), so
     * every query that filters by scope or by owner returns nothing that belongs to anyone. $guestKey ties temporary
     * work (e.g. a PDF job) to this browser session only.
     *
     * @param list<string> $permissions
     */
    public static function guest(Connection $db, Clock $clock, array $permissions, string $guestKey): self
    {
        $az = new self($db, $clock, 0);
        $az->byPermission = [];
        foreach ($permissions as $p) {
            $az->byPermission[$p] = new ScopeSet(false, [0]);
        }
        $az->guestKey = $guestKey;
        return $az;
    }

    public function isGuest(): bool
    {
        return $this->guestKey !== null;
    }

    public function can(string $permission): bool
    {
        return !$this->scope($permission)->isEmpty();
    }

    public function scope(string $permission): ScopeSet
    {
        $this->load();
        return $this->byPermission[$permission] ?? ScopeSet::none();
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $this->load();
        return array_keys((array) $this->byPermission);
    }

    /** @return list<string> role codes */
    public function roleCodes(): array
    {
        $this->load();
        return array_values(array_unique(array_column($this->roles, 'code')));
    }

    /** DEC-51: MFA for system admins, money approvers and anyone who can see more than one company. */
    public function requiresMfa(): bool
    {
        $this->load();
        foreach ($this->roles as $r) {
            if ($r['requires_mfa']) {
                return true;
            }
        }
        return $this->union->crossCompany();
    }

    /** All legal entities the user can reach through any permission (for the context switcher). */
    public function reachableScope(): ScopeSet
    {
        $this->load();
        return $this->union;
    }

    private function load(): void
    {
        if ($this->byPermission !== null) {
            return;
        }
        $today = $this->clock->now()->format('Y-m-d');
        $rows = $this->db->all(
            "SELECT a.id AS assignment_id, r.code, r.requires_mfa, r.high_risk, rp.permission_code, s.scope_level, s.scope_id
               FROM user_role_assignments a
               JOIN roles r ON r.id = a.role_id
               LEFT JOIN role_permissions rp ON rp.role_id = r.id
               LEFT JOIN access_scopes s ON s.assignment_id = a.id
              WHERE a.user_id = ? AND a.status = 'active' AND a.valid_from <= ? AND (a.valid_to IS NULL OR a.valid_to >= ?)",
            [$this->userId, $today, $today]
        );

        /** @var array<string, array{all:bool, le:array<int,true>, site:array<int,true>, dept:array<int,true>}> $acc */
        $acc = [];
        $union = ['all' => false, 'le' => [], 'site' => [], 'dept' => []];
        foreach ($rows as $row) {
            $this->roles[] = ['code' => (string) $row['code'], 'requires_mfa' => (bool) $row['requires_mfa'], 'high_risk' => (bool) $row['high_risk']];
            if ($row['scope_level'] === null) {
                continue; // assignment without scope rows grants nothing (deny-by-default)
            }
            $id = (int) $row['scope_id'];
            $level = (string) $row['scope_level'];
            self::add($union, $level, $id);
            if ($row['permission_code'] !== null) {
                $acc[$row['permission_code']] ??= ['all' => false, 'le' => [], 'site' => [], 'dept' => []];
                self::add($acc[$row['permission_code']], $level, $id);
            }
        }
        // 0.8.2 (DEC-69): a unit scope covers the units below it (Division → its Departments, Sections, Sub Sections)
        if ($union['dept'] !== []) {
            $children = [];
            foreach ($this->db->all('SELECT id, parent_id FROM departments WHERE parent_id IS NOT NULL') as $d) {
                $children[(int) $d['parent_id']][] = (int) $d['id'];
            }
            self::cascade($union, $children);
            foreach ($acc as $perm => $a) {
                self::cascade($acc[$perm], $children);
            }
        }
        $this->byPermission = [];
        foreach ($acc as $perm => $a) {
            $this->byPermission[$perm] = self::toSet($a);
        }
        $this->union = self::toSet($union);
    }

    /**
     * @param array{all:bool, le:array<int,true>, site:array<int,true>, dept:array<int,true>} $t
     * @param array<int, list<int>> $children
     */
    private static function cascade(array &$t, array $children): void
    {
        $queue = array_keys($t['dept']);
        for ($i = 0; $i < count($queue) && $i < 5000; $i++) {
            foreach ($children[$queue[$i]] ?? [] as $c) {
                if (!isset($t['dept'][$c])) {
                    $t['dept'][$c] = true;
                    $queue[] = $c;
                }
            }
        }
    }

    /** @param array{all:bool, le:array<int,true>, site:array<int,true>, dept:array<int,true>} $t */
    private static function add(array &$t, string $level, int $id): void
    {
        match ($level) {
            'organization' => $t['all'] = true,
            'legal_entity' => $t['le'][$id] = true,
            'site' => $t['site'][$id] = true,
            'department' => $t['dept'][$id] = true,
            default => null,
        };
    }

    /** @param array{all:bool, le:array<int,true>, site:array<int,true>, dept:array<int,true>} $a */
    private static function toSet(array $a): ScopeSet
    {
        return new ScopeSet($a['all'], array_keys($a['le']), array_keys($a['site']), array_keys($a['dept']));
    }
}
