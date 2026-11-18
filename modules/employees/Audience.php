<?php

declare(strict_types=1);

namespace Blue\Modules\Employees;

use Blue\Core\App;
use Blue\Core\Error\ValidationException;
use Blue\Core\Org\Translations;

/**
 * Target group of people inside ONE company (phase 5D, DEC-72) — shared by forms (M02), announcements (M19) and
 * controlled documents (M40) so "who should see / acknowledge this" means the same everywhere.
 *
 *   { "sites": [ids], "departments": [ids], "groups": ["FIELD", …] }
 *
 * Empty dimension = no filter. Within a dimension any value matches (OR); across dimensions all must match (AND).
 * A department includes every unit below it in the one org chart (DEC-69). Matching uses the employment in force on
 * the date asked — moving someone changes what they see from the effective date, and leavers are never targeted.
 */
final class Audience
{
    /** @return array{sites: list<int>, departments: list<int>, groups: list<string>} */
    public static function empty(): array
    {
        return ['sites' => [], 'departments' => [], 'groups' => []];
    }

    /**
     * From a form: sites[] / departments[] (public ids of the company) and groups ("A, B").
     *
     * @param array<string, mixed> $in
     * @return array{sites: list<int>, departments: list<int>, groups: list<string>}
     */
    public static function fromInput(App $app, int $le, array $in): array
    {
        $db = $app->db();
        $out = self::empty();
        foreach (['sites' => 'sites', 'departments' => 'departments'] as $key => $table) {
            foreach (array_unique(array_map('strval', (array) ($in[$key] ?? []))) as $pid) {
                if ($pid === '') {
                    continue;
                }
                $id = $db->scalar("SELECT id FROM {$table} WHERE public_id = ? AND legal_entity_id = ?", [$pid, $le]);
                if ($id === null) {
                    throw new ValidationException(['audience' => 'form.invalid_choice']);
                }
                $out[$key][] = (int) $id;
            }
        }
        foreach (preg_split('/[\s,]+/', strtoupper(trim((string) ($in['groups'] ?? '')))) ?: [] as $g) {
            if ($g === '') {
                continue;
            }
            if (preg_match('/^[A-Z0-9_-]{1,20}$/', $g) !== 1) {
                throw new ValidationException(['audience' => 'employees.audience_error.groups']);
            }
            $out['groups'][$g] = $g;
        }
        $out['groups'] = array_values($out['groups']);
        sort($out['sites']);
        sort($out['departments']);
        return $out;
    }

    /** @return array{sites: list<int>, departments: list<int>, groups: list<string>} */
    public static function decode(?string $json): array
    {
        $v = $json !== null && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($v)) {
            return self::empty();
        }
        return ['sites' => array_values(array_map('intval', (array) ($v['sites'] ?? []))), 'departments' => array_values(array_map('intval', (array) ($v['departments'] ?? []))),
            'groups' => array_values(array_map('strval', (array) ($v['groups'] ?? [])))];
    }

    /** @param array{sites: list<int>, departments: list<int>, groups: list<string>} $a */
    public static function encode(array $a): string
    {
        return (string) json_encode($a);
    }

    /** @param array{sites: list<int>, departments: list<int>, groups: list<string>} $a */
    public static function isEveryone(array $a): bool
    {
        return $a['sites'] === [] && $a['departments'] === [] && $a['groups'] === [];
    }

    /**
     * @param list<int> $departmentIds
     * @return list<int> the departments plus every unit below them
     */
    public static function expand(App $app, array $departmentIds): array
    {
        if ($departmentIds === []) {
            return [];
        }
        $children = [];
        foreach ($app->db()->all('SELECT id, parent_id FROM departments WHERE parent_id IS NOT NULL') as $d) {
            $children[(int) $d['parent_id']][] = (int) $d['id'];
        }
        $out = [];
        $stack = array_map('intval', $departmentIds);
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($out[$id])) {
                continue;
            }
            $out[$id] = true;
            foreach ($children[$id] ?? [] as $c) {
                $stack[] = $c;
            }
        }
        return array_keys($out);
    }

    /**
     * Does this employment (company, site, unit, group) belong to the audience of company $le?
     *
     * @param array<string, mixed>|null $job employment_history row
     * @param array{sites: list<int>, departments: list<int>, groups: list<string>} $a
     * @param list<int>|null $expanded departments already expanded (pass when checking many people)
     */
    public static function matches(App $app, int $le, array $a, ?array $job, ?array $expanded = null): bool
    {
        if ($job === null || (int) $job['legal_entity_id'] !== $le) {
            return false;
        }
        if ($a['sites'] !== [] && !in_array((int) ($job['site_id'] ?? 0), $a['sites'], true)) {
            return false;
        }
        if ($a['departments'] !== [] && !in_array((int) ($job['department_id'] ?? 0), $expanded ?? self::expand($app, $a['departments']), true)) {
            return false;
        }
        return $a['groups'] === [] || in_array(strtoupper((string) ($job['employee_group'] ?? '')), $a['groups'], true);
    }

    /**
     * Everyone currently in the audience who can sign in: [employee_id => user_id].
     *
     * @param array{sites: list<int>, departments: list<int>, groups: list<string>} $a
     * @return array<int, int|null>
     */
    public static function members(App $app, int $le, array $a, string $date): array
    {
        $expanded = self::expand($app, $a['departments']);
        $out = [];
        foreach (
            $app->db()->all(
                "SELECT h.*, (SELECT u.id FROM users u WHERE u.employee_id = e.id AND u.status = 'active' LIMIT 1) AS user_id
               FROM employment_history h JOIN employees e ON e.id = h.employee_id
              WHERE h.legal_entity_id = ? AND h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?) AND e.status <> 'former'",
                [$le, $date, $date]
            ) as $job
        ) {
            if (self::matches($app, $le, $a, $job, $expanded)) {
                $out[(int) $job['employee_id']] = $job['user_id'] !== null ? (int) $job['user_id'] : null;
            }
        }
        return $out;
    }

    /** @param array{sites: list<int>, departments: list<int>, groups: list<string>} $a */
    public static function describe(App $app, array $a): string
    {
        $t = $app->translator();
        if (self::isEveryone($a)) {
            return $t->get('employees.audience_everyone');
        }
        $tr = new Translations($app->db());
        $loc = $t->locale();
        $parts = [];
        foreach (['sites' => 'site', 'departments' => 'department'] as $key => $type) {
            if ($a[$key] !== []) {
                $names = $tr->many($type, $a[$key]);
                $codes = [];
                foreach ($a[$key] as $id) {
                    $codes[] = Translations::pick($names[$id] ?? [], $loc) ?: (string) $app->db()->scalar("SELECT code FROM {$type}s WHERE id = ?", [$id]);
                }
                $parts[] = $t->get('employees.audience_' . $key) . ': ' . implode(', ', $codes);
            }
        }
        if ($a['groups'] !== []) {
            $parts[] = $t->get('employees.audience_groups') . ': ' . implode(', ', $a['groups']);
        }
        return implode(' · ', $parts);
    }

    /**
     * Options for the audience fieldset of company $le.
     *
     * @return array{sites: array<string, string>, departments: array<string, string>}
     */
    public static function options(App $app, int $le): array
    {
        $db = $app->db();
        $tr = new Translations($db);
        $loc = $app->translator()->locale();
        $out = ['sites' => [], 'departments' => []];
        foreach (['sites' => 'site', 'departments' => 'department'] as $key => $type) {
            $rows = $db->all("SELECT id, public_id, code FROM {$key} WHERE legal_entity_id = ? AND status = 'active' ORDER BY code", [$le]);
            $names = $tr->many($type, array_map(static fn (array $r): int => (int) $r['id'], $rows));
            foreach ($rows as $r) {
                $n = Translations::pick($names[(int) $r['id']] ?? [], $loc);
                $out[$key][(string) $r['public_id']] = $r['code'] . ($n !== '' ? ' · ' . $n : '');
            }
        }
        return $out;
    }

    /**
     * Public ids of the stored audience (to re-fill the form).
     *
     * @param array{sites: list<int>, departments: list<int>, groups: list<string>} $a
     * @return array{sites: list<string>, departments: list<string>, groups: string}
     */
    public static function toInput(App $app, array $a): array
    {
        $db = $app->db();
        $pids = static fn (string $table, array $ids): array => $ids === [] ? [] : array_map('strval', array_column($db->all(
            "SELECT public_id FROM {$table} WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        ), 'public_id'));
        return ['sites' => $pids('sites', $a['sites']), 'departments' => $pids('departments', $a['departments']), 'groups' => implode(', ', $a['groups'])];
    }
}
