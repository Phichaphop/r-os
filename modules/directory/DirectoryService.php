<?php

declare(strict_types=1);

namespace Blue\Modules\Directory;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Org\Translations;
use Blue\Modules\Employees\EmployeeService;

/**
 * M47 Company directory (phase 5D, DEC-72).
 *
 *  source    name, position, unit, branch, work e-mail and work phone are read from the employee master on every
 *            search (no copy that can go stale); only the desk extension is kept here, set by the employee.
 *  fields    each company chooses which WORK contact fields are shown — never personal phone, address, birthday or
 *            ID numbers. Leavers and people not yet started never appear.
 *  who       anyone signed in sees the people of their own company; another company's people only when that company
 *            allows group-wide search; HR (directory.manage) sees the companies in their scope.
 *  export    CSV/XLSX of the visible fields only, for directory.export, audited.
 */
final class DirectoryService
{
    public const FIELDS = ['email', 'phone', 'extension', 'position', 'department', 'site'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    public function emp(): EmployeeService
    {
        return EmployeeService::of($this->app);
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    /** @return array{fields: array<string, bool>, cross_company: bool} */
    public function settings(int $le): array
    {
        $raw = $this->app->db()->scalar('SELECT value_json FROM dir_settings WHERE legal_entity_id = ?', [$le]);
        $v = is_string($raw) ? (array) json_decode($raw, true) : [];
        $f = [];
        foreach (self::FIELDS as $k) {
            $f[$k] = (bool) ($v['fields'][$k] ?? true);
        }
        return ['fields' => $f, 'cross_company' => (bool) ($v['cross_company'] ?? false)];
    }

    /** @param array<string, mixed> $in */
    public function saveSettings(Authorizer $az, int $le, array $in): void
    {
        if (!$az->scope('directory.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $f = [];
        foreach (self::FIELDS as $k) {
            $f[$k] = in_array($k, (array) ($in['fields'] ?? []), true);
        }
        $v = ['fields' => $f, 'cross_company' => ($in['cross_company'] ?? '') === '1'];
        $old = $this->settings($le);
        $this->app->db()->run(
            'INSERT INTO dir_settings (legal_entity_id, value_json, updated_at, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$le, json_encode($v), $this->now(), $az->userId]
        );
        $this->app->audit()->record('directory.settings_changed', 'success', 'legal_entity', $le, $le, null, ['from' => $old, 'to' => $v]);
    }

    /** @return list<int> companies whose people this user may find */
    public function reachable(Authorizer $az): array
    {
        $me = $this->emp()->forUser($az->userId);
        $out = [];
        foreach ($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code') as $l) {
            $id = (int) $l['id'];
            if (($me !== null && $me['job'] !== null && (int) $me['job']['legal_entity_id'] === $id) || $az->scope('directory.manage')->coversLegalEntity($id) || ($me !== null && $this->settings($id)['cross_company'])) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * Search people: name, code, work e-mail / phone, extension, position; or my team / my pinned people.
     *
     * @return list<array<string, mixed>> contact cards (only the fields the person's company shows)
     */
    public function search(Authorizer $az, string $q, string $view = 'all', int $limit = 60): array
    {
        $les = $this->reachable($az);
        if ($les === []) {
            return [];
        }
        $db = $this->app->db();
        $today = $this->emp()->today();
        $me = $this->emp()->forUser($az->userId);
        $sql = "SELECT e.id, e.public_id, e.employee_code, e.name_th, e.name_en, e.preferred_name, e.work_email, e.work_phone, h.legal_entity_id, h.site_id, h.department_id, h.position_title,
                       h.manager_employee_id, p.extension, (SELECT 1 FROM dir_favorites f WHERE f.user_id = ? AND f.employee_id = e.id) AS pinned
                  FROM employees e JOIN employment_history h ON h.employee_id = e.id AND h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?)
                  LEFT JOIN dir_profiles p ON p.employee_id = e.id
                 WHERE e.status IN ('active','on_leave') AND h.legal_entity_id IN (" . implode(',', array_fill(0, count($les), '?')) . ')';
        $params = array_merge([$az->userId, $today, $today], $les);
        if ($view === 'favorites') {
            $sql .= ' AND EXISTS (SELECT 1 FROM dir_favorites f WHERE f.user_id = ? AND f.employee_id = e.id)';
            $params[] = $az->userId;
        } elseif ($view === 'team') {
            if ($me === null || $me['job'] === null) {
                return [];
            }
            $sql .= ' AND (e.id = ? OR h.manager_employee_id = ? OR (h.department_id IS NOT NULL AND h.department_id = ?))';
            array_push($params, (int) ($me['job']['manager_employee_id'] ?? 0), $me['id'], (int) ($me['job']['department_id'] ?? 0));
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql .= ' AND (e.name_th LIKE ? OR e.name_en LIKE ? OR e.preferred_name LIKE ? OR e.employee_code LIKE ? OR e.work_email LIKE ? OR e.work_phone LIKE ? OR p.extension LIKE ? OR h.position_title LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
        } elseif ($view === 'all') {
            return [];
        }
        $rows = $db->all($sql . ' ORDER BY e.name_th LIMIT ' . max(1, min(500, $limit)), $params);
        return $this->cards($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function cards(array $rows): array
    {
        $tr = new Translations($this->app->db());
        $loc = $this->app->translator()->locale();
        $depts = $tr->many('department', array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) $r['department_id'], $rows)))));
        $sites = $tr->many('site', array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) $r['site_id'], $rows)))));
        $out = [];
        foreach ($rows as $r) {
            $f = $this->settings((int) $r['legal_entity_id'])['fields'];
            $out[] = [
                'public_id' => (string) $r['public_id'], 'code' => (string) $r['employee_code'], 'name' => $this->emp()->displayName($r), 'pinned' => (int) $r['pinned'] === 1,
                'position' => $f['position'] ? $r['position_title'] : null,
                'department' => $f['department'] && $r['department_id'] !== null ? (Translations::pick($depts[(int) $r['department_id']] ?? [], $loc) ?: null) : null,
                'site' => $f['site'] && $r['site_id'] !== null ? (Translations::pick($sites[(int) $r['site_id']] ?? [], $loc) ?: null) : null,
                'email' => $f['email'] ? $r['work_email'] : null, 'phone' => $f['phone'] ? $r['work_phone'] : null, 'extension' => $f['extension'] ? $r['extension'] : null,
                'company' => (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$r['legal_entity_id']]),
            ];
        }
        return $out;
    }

    public function pin(Authorizer $az, string $employeePid, bool $on): void
    {
        $id = $this->app->db()->scalar("SELECT id FROM employees WHERE public_id = ? AND status IN ('active','on_leave')", [$employeePid]);
        $job = $id !== null ? $this->emp()->employment((int) $id, $this->emp()->today()) : null;
        if ($job === null || !in_array((int) $job['legal_entity_id'], $this->reachable($az), true)) {
            throw HttpException::notFound();
        }
        if ($on) {
            $this->app->db()->run('INSERT IGNORE INTO dir_favorites (user_id, employee_id, created_at) VALUES (?, ?, ?)', [$az->userId, $id, $this->now()]);
        } else {
            $this->app->db()->run('DELETE FROM dir_favorites WHERE user_id = ? AND employee_id = ?', [$az->userId, $id]);
        }
    }

    /** @return array<string, mixed>|null my contact card + extension */
    public function me(Authorizer $az): ?array
    {
        $me = $this->emp()->forUser($az->userId);
        if ($me === null) {
            return null;
        }
        $me['extension'] = $this->app->db()->scalar('SELECT extension FROM dir_profiles WHERE employee_id = ?', [$me['id']]);
        return $me;
    }

    public function saveExtension(Authorizer $az, string $ext): void
    {
        $me = $this->emp()->forUser($az->userId);
        if ($me === null) {
            throw HttpException::notFound();
        }
        $ext = trim($ext);
        if ($ext !== '' && preg_match('/^[0-9#*+ ()-]{1,20}$/', $ext) !== 1) {
            throw new ValidationException(['extension' => 'directory.error.extension']);
        }
        $this->app->db()->run(
            'INSERT INTO dir_profiles (employee_id, extension, updated_at, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE extension = VALUES(extension), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$me['id'], $ext === '' ? null : $ext, $this->now(), $az->userId]
        );
        $this->app->audit()->record('directory.extension_changed', 'success', 'employee', (string) $me['public_id'], $me['job'] !== null ? (int) $me['job']['legal_entity_id'] : null);
    }

    /** @return array{headers: list<string>, rows: list<list<string>>} visible fields of a company's directory */
    public function export(Authorizer $az, int $le): array
    {
        if (!$az->scope('directory.export')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $today = $this->emp()->today();
        $rows = $this->app->db()->all(
            "SELECT e.id, e.public_id, e.employee_code, e.name_th, e.name_en, e.preferred_name, e.work_email, e.work_phone, h.legal_entity_id, h.site_id, h.department_id, h.position_title, h.manager_employee_id,
                    p.extension, 0 AS pinned FROM employees e JOIN employment_history h ON h.employee_id = e.id AND h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?)
               LEFT JOIN dir_profiles p ON p.employee_id = e.id WHERE e.status IN ('active','on_leave') AND h.legal_entity_id = ? ORDER BY e.employee_code",
            [$today, $today, $le]
        );
        $f = $this->settings($le)['fields'];
        $cols = array_values(array_filter(['position', 'department', 'site', 'email', 'phone', 'extension'], static fn (string $k): bool => $f[$k]));
        $out = [];
        foreach ($this->cards($rows) as $c) {
            $out[] = array_merge([$c['code'], $c['name']], array_map(static fn (string $k): string => (string) ($c[$k] ?? ''), $cols));
        }
        $this->app->audit()->record('directory.exported', 'success', 'legal_entity', $le, $le, null, ['rows' => count($out), 'fields' => $cols]);
        return ['headers' => array_merge(['code', 'name'], $cols), 'rows' => $out];
    }
}
