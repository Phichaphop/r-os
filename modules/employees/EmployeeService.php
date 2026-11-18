<?php

declare(strict_types=1);

namespace Blue\Modules\Employees;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Authz\ScopeSet;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;

/**
 * Minimal employee master (DEC-63, P0-03 §4) — the public service other modules read (Leave, later Attendance/Payroll):
 * who an account is, where they work on a date (company/site/department/group) and who their line manager is.
 * Employment is effective-dated: a change closes the previous row and starts a new one; history is never rewritten.
 * Rights: employees.view / employees.manage, checked against the company/site/department of the CURRENT employment.
 */
final class EmployeeService
{
    public const TYPES = ['permanent', 'probation', 'contract', 'part_time', 'daily'];
    public const STATUSES = ['pre_hire', 'active', 'on_leave', 'suspended', 'former'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    // ---- read API for other modules ---------------------------------------------------------------

    /** Today in the display time zone (Y-m-d) — "current" employment means on this date. */
    public function today(): string
    {
        return $this->app->clock->now()->setTimezone(new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok')))->format('Y-m-d');
    }

    /**
     * The employee linked to a sign-in account, with the employment on $date (null when not linked).
     *
     * @return array<string, mixed>|null
     */
    public function forUser(int $userId, ?string $date = null): ?array
    {
        $empId = $this->app->db()->scalar('SELECT employee_id FROM users WHERE id = ?', [$userId]);
        if ($empId === null) {
            return null;
        }
        return $this->byId((int) $empId, $date);
    }

    /** @return array<string, mixed>|null employee + 'job' (employment on $date, or null) */
    public function byId(int $employeeId, ?string $date = null): ?array
    {
        $e = $this->app->db()->one('SELECT * FROM employees WHERE id = ?', [$employeeId]);
        if ($e === null) {
            return null;
        }
        $e['job'] = $this->employment($employeeId, $date ?? $this->today());
        return $e;
    }

    /** @return array<string, mixed>|null employment row in force on $date */
    public function employment(int $employeeId, string $date): ?array
    {
        return $this->app->db()->one(
            'SELECT * FROM employment_history WHERE employee_id = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC LIMIT 1',
            [$employeeId, $date, $date]
        );
    }

    /**
     * Active sign-in accounts of the employee's line manager on $date (workflow approver type "manager").
     *
     * @return list<int>
     */
    public function managerUserIds(int $employeeId, string $date): array
    {
        $job = $this->employment($employeeId, $date);
        if ($job === null || $job['manager_employee_id'] === null) {
            return [];
        }
        return array_map('intval', array_column($this->app->db()->all(
            "SELECT u.id FROM users u JOIN employees m ON m.id = u.employee_id WHERE m.id = ? AND m.status = 'active' AND u.status = 'active'",
            [(int) $job['manager_employee_id']]
        ), 'id'));
    }

    /** @return list<int> employee ids reporting directly to $managerEmployeeId on $date */
    public function directReports(int $managerEmployeeId, string $date): array
    {
        return array_map('intval', array_column($this->app->db()->all(
            "SELECT h.employee_id FROM employment_history h JOIN employees e ON e.id = h.employee_id
              WHERE h.manager_employee_id = ? AND h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?) AND e.status <> 'former'",
            [$managerEmployeeId, $date, $date]
        ), 'employee_id'));
    }

    /**
     * Display name: preferred name if set, else the Thai legal name (English when the UI is English and it exists).
     *
     * @param array<string, mixed> $e
     */
    public function displayName(array $e): string
    {
        $locale = $this->app->translator()->locale();
        $name = $locale === 'en' && ($e['name_en'] ?? '') !== '' ? (string) $e['name_en'] : (string) $e['name_th'];
        return ($e['preferred_name'] ?? '') !== '' ? $name . ' (' . $e['preferred_name'] . ')' : $name;
    }

    /**
     * Does $scope cover this employment row (company, site or department)?
     *
     * @param array<string, mixed>|null $job
     */
    public static function covers(ScopeSet $scope, ?array $job): bool
    {
        if ($scope->all) {
            return true;
        }
        if ($job === null) {
            return false;
        }
        return in_array((int) $job['legal_entity_id'], $scope->legalEntityIds, true)
            || ($job['site_id'] !== null && in_array((int) $job['site_id'], $scope->siteIds, true))
            || ($job['department_id'] !== null && in_array((int) $job['department_id'], $scope->departmentIds, true));
    }

    // ---- screens --------------------------------------------------------------------------------------

    /**
     * Employees whose current employment is in the user's scope.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(Authorizer $az, string $q, ?int $leId, string $status, int $page, int $per): array
    {
        $scope = $az->scope('employees.view');
        if ($scope->isEmpty()) {
            throw HttpException::forbidden();
        }
        [$sql, $params] = $scope->sql('h.legal_entity_id', 'h.site_id', 'h.department_id');
        $today = $this->today();
        $where = "h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?) AND {$sql}";
        $args = array_merge([$today, $today], $params);
        if ($q !== '') {
            $where .= ' AND (e.employee_code LIKE ? OR e.name_th LIKE ? OR e.name_en LIKE ? OR e.preferred_name LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($args, $like, $like, $like, $like);
        }
        if ($leId !== null) {
            $where .= ' AND h.legal_entity_id = ?';
            $args[] = $leId;
        }
        if (in_array($status, self::STATUSES, true)) {
            $where .= ' AND e.status = ?';
            $args[] = $status;
        }
        $from = "FROM employees e JOIN employment_history h ON h.employee_id = e.id
                 LEFT JOIN employees m ON m.id = h.manager_employee_id LEFT JOIN users u ON u.employee_id = e.id WHERE {$where}";
        $db = $this->app->db();
        $total = (int) $db->scalar("SELECT COUNT(*) {$from}", $args);
        $rows = $db->all(
            "SELECT e.*, h.legal_entity_id, h.site_id, h.department_id, h.position_title, h.employee_group, h.employment_type,
                    m.name_th AS manager_name, u.username {$from} ORDER BY e.employee_code LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per),
            $args
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, mixed> employee + current job + history + linked username — 404 outside the user's scope */
    public function get(Authorizer $az, string $pid, bool $manage = false): array
    {
        $e = $this->app->db()->one('SELECT * FROM employees WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $id = (int) $e['id'];
        $job = $this->employment($id, $this->today()) ?? $this->latest($id);
        if (!self::covers($az->scope('employees.view'), $job) || ($manage && !self::covers($az->scope('employees.manage'), $job))) {
            throw $manage && self::covers($az->scope('employees.view'), $job) ? HttpException::forbidden() : HttpException::notFound();
        }
        $e['job'] = $job;
        $e['history'] = $this->app->db()->all(
            'SELECT h.*, m.name_th AS manager_name, m.employee_code AS manager_code FROM employment_history h LEFT JOIN employees m ON m.id = h.manager_employee_id
              WHERE h.employee_id = ? ORDER BY h.effective_from DESC',
            [$id]
        );
        $e['username'] = $this->app->db()->scalar('SELECT username FROM users WHERE employee_id = ?', [$id]);
        return $e;
    }

    /** @return array<string, mixed>|null the newest employment row (also for former employees) */
    private function latest(int $employeeId): ?array
    {
        return $this->app->db()->one('SELECT * FROM employment_history WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 1', [$employeeId]);
    }

    /**
     * Active employees of the given companies (null = all) — the manager picker. A manager may work in another company.
     *
     * @param list<int>|null $legalEntityIds
     * @return array<int, string> id => "COMPANY / code · name"
     */
    public function managerOptions(?array $legalEntityIds, ?int $exceptId = null): array
    {
        $today = $this->today();
        $where = '';
        $args = [$today, $today];
        if ($legalEntityIds !== null) {
            if ($legalEntityIds === []) {
                return [];
            }
            $where = ' AND h.legal_entity_id IN (' . implode(',', array_fill(0, count($legalEntityIds), '?')) . ')';
            array_push($args, ...$legalEntityIds);
        }
        $out = [];
        foreach (
            $this->app->db()->all(
                "SELECT e.id, e.employee_code, e.name_th, e.name_en, e.preferred_name, l.code AS le_code FROM employees e
               JOIN employment_history h ON h.employee_id = e.id JOIN legal_entities l ON l.id = h.legal_entity_id
              WHERE h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?) AND e.status = 'active'{$where}
              ORDER BY l.code, e.employee_code LIMIT 1000",
                $args
            ) as $r
        ) {
            if ((int) $r['id'] !== $exceptId) {
                $out[(int) $r['id']] = $r['le_code'] . ' / ' . $r['employee_code'] . ' · ' . $this->displayName($r);
            }
        }
        return $out;
    }

    // ---- changes ------------------------------------------------------------------------------------

    /**
     * New employee + first employment row (effective from the hire date).
     *
     * @param array<string, mixed> $in
     */
    public function create(Authorizer $az, array $in): string
    {
        [$person, $errors] = $this->validatePerson($in, null);
        [$job, $jobErrors] = $this->validateJob($in, null);
        $errors += $jobErrors;
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if (!self::covers($az->scope('employees.manage'), $job)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        if ($db->scalar('SELECT id FROM employees WHERE employee_code = ?', [$person['employee_code']]) !== null) {
            throw new ValidationException(['employee_code' => 'employees.error.code_taken']);
        }
        $pid = Ulid::generate($this->app->clock);
        $db->transactional(function () use ($db, $pid, $person, $job, $az): void {
            $now = $this->now();
            $db->run(
                'INSERT INTO employees (public_id, created_at, created_by, updated_at, updated_by, employee_code, name_th, name_en, preferred_name, status, hire_date, termination_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $person['employee_code'], $person['name_th'], $person['name_en'], $person['preferred_name'],
                    $person['hire_date'] > $this->today() ? 'pre_hire' : 'active', $person['hire_date'], null]
            );
            $id = (int) $db->pdo()->lastInsertId();
            $this->insertJob($id, $job + ['effective_from' => $person['hire_date'], 'change_reason' => 'hire'], $az->userId);
            // 0.8.0: status is effective-dated — before the hire date the person is "pre-hire", from it "active"
            $hireStart = $person['hire_date'] > $this->today() ? ['pre_hire', $this->today()] : ['active', $person['hire_date']];
            if ($hireStart[0] === 'pre_hire') {
                $db->run(
                    "INSERT INTO employee_status_history (public_id, created_at, created_by, updated_at, updated_by, employee_id, status, effective_from, effective_to, reason) VALUES (?, ?, ?, ?, ?, ?, 'pre_hire', ?, ?, 'hire')",
                    [Ulid::generate($this->app->clock), $now, $az->userId, $now, $az->userId, $id, $this->today(), self::ymd($person['hire_date'])->modify('-1 day')->format('Y-m-d')]
                );
            }
            $db->run(
                "INSERT INTO employee_status_history (public_id, created_at, created_by, updated_at, updated_by, employee_id, status, effective_from, reason) VALUES (?, ?, ?, ?, ?, ?, 'active', ?, 'hire')",
                [Ulid::generate($this->app->clock), $now, $az->userId, $now, $az->userId, $id, $person['hire_date']]
            );
            $this->app->audit()->record('employee.created', 'success', 'employee', $pid, $job['legal_entity_id'], null, ['code' => $person['employee_code']]);
        });
        return $pid;
    }

    /**
     * Names and the hire date. Status, separation and employment are approved changes (ChangeService — 0.8.0).
     *
     * @param array<string, mixed> $in
     */
    public function update(Authorizer $az, string $pid, array $in): void
    {
        $e = $this->get($az, $pid, true);
        [$person, $errors] = $this->validatePerson(['status' => $e['status'], 'termination_date' => $e['termination_date']] + $in + ['employee_code' => $e['employee_code']], $e);
        $person['status'] = $e['status'];
        $person['termination_date'] = $e['termination_date'];
        if ($person['hire_date'] !== (string) $e['hire_date'] && $e['status'] !== 'pre_hire') {
            $errors['hire_date'] = 'employees.error.hire_locked';
        }
        if ($person['hire_date'] !== (string) $e['hire_date'] && count($e['history']) > 1) {
            $errors['hire_date'] = 'employees.error.hire_after_job';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->app->db()->run(
            'UPDATE employees SET name_th = ?, name_en = ?, preferred_name = ?, status = ?, hire_date = ?, termination_date = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$person['name_th'], $person['name_en'], $person['preferred_name'], $person['status'], $person['hire_date'], $person['termination_date'], $this->now(), $az->userId, $e['id']]
        );
        if ($person['hire_date'] !== (string) $e['hire_date'] && count($e['history']) === 1) {
            // pre-hire only: the first employment row and the start of "active" move with the hire date
            $db = $this->app->db();
            $db->run('UPDATE employment_history SET effective_from = ?, updated_at = ?, updated_by = ? WHERE id = ?', [$person['hire_date'], $this->now(), $az->userId, $e['history'][0]['id']]);
            $db->run("UPDATE employee_status_history SET effective_from = ? WHERE employee_id = ? AND status = 'active' AND effective_from = ?", [$person['hire_date'], $e['id'], $e['hire_date']]);
            $db->run("UPDATE employee_status_history SET effective_to = ? WHERE employee_id = ? AND status = 'pre_hire'", [self::ymd($person['hire_date'])->modify('-1 day')->format('Y-m-d'), $e['id']]);
            $db->run("DELETE FROM employee_status_history WHERE employee_id = ? AND status = 'pre_hire' AND effective_to < effective_from", [$e['id']]);
            ChangeService::of($this->app)->refreshStatus((int) $e['id']);
        }
        $diff = [];
        foreach (['name_th', 'name_en', 'preferred_name', 'status', 'hire_date', 'termination_date'] as $k) {
            if ((string) ($e[$k] ?? '') !== (string) ($person[$k] ?? '')) {
                $diff[$k] = ['before' => $e[$k], 'after' => $person[$k]];
            }
        }
        $this->app->audit()->record('employee.updated', 'success', 'employee', $pid, (int) $e['job']['legal_entity_id'], null, $diff);
    }

    /** Links (or unlinks with '') the sign-in account of the employee. One account per employee and vice versa. */
    public function linkUser(Authorizer $az, string $pid, string $username): void
    {
        $e = $this->get($az, $pid, true);
        $db = $this->app->db();
        $before = $e['username'];
        if (trim($username) === '') {
            $db->run('UPDATE users SET employee_id = NULL, updated_at = ? WHERE employee_id = ?', [$this->now(), $e['id']]);
        } else {
            $u = $db->one('SELECT id, employee_id, status FROM users WHERE username = ?', [trim($username)]);
            if ($u === null) {
                throw new ValidationException(['username' => 'employees.error.no_user']);
            }
            if ($u['employee_id'] !== null && (int) $u['employee_id'] !== (int) $e['id']) {
                throw new ValidationException(['username' => 'employees.error.user_linked']);
            }
            $db->transactional(function () use ($db, $e, $u): void {
                $db->run('UPDATE users SET employee_id = NULL, updated_at = ? WHERE employee_id = ? AND id <> ?', [$this->now(), $e['id'], $u['id']]);
                $db->run('UPDATE users SET employee_id = ?, updated_at = ? WHERE id = ?', [$e['id'], $this->now(), $u['id']]);
            });
        }
        $this->app->audit()->record('employee.user_linked', 'success', 'employee', $pid, (int) $e['job']['legal_entity_id'], null, ['before' => $before, 'after' => trim($username) === '' ? null : trim($username)]);
    }

    // ---- helpers ----------------------------------------------------------------------------------------

    /** @param array<string, mixed> $job (ChangeService applies approved changes with this) */
    public function insertJob(int $employeeId, array $job, int $actorId): void
    {
        $now = $this->now();
        $this->app->db()->run(
            'INSERT INTO employment_history (public_id, created_at, created_by, updated_at, updated_by, employee_id, legal_entity_id, site_id, department_id,
                position_title, manager_employee_id, employee_group, employment_type, effective_from, effective_to, change_reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)',
            [Ulid::generate($this->app->clock), $now, $actorId, $now, $actorId, $employeeId, $job['legal_entity_id'], $job['site_id'], $job['department_id'],
                $job['position_title'], $job['manager_employee_id'], $job['employee_group'], $job['employment_type'], $job['effective_from'], $job['change_reason'] ?? null]
        );
    }

    /**
     * @param array<string, mixed> $in
     * @param array<string, mixed>|null $existing
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validatePerson(array $in, ?array $existing): array
    {
        $errors = [];
        $code = strtoupper(trim((string) ($in['employee_code'] ?? '')));
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{0,29}$/', $code) !== 1) {
            $errors['employee_code'] = 'employees.error.code';
        }
        $th = trim((string) ($in['name_th'] ?? ''));
        if ($th === '' || mb_strlen($th) > 120) {
            $errors['name_th'] = 'employees.error.name';
        }
        $en = trim((string) ($in['name_en'] ?? ''));
        $pref = trim((string) ($in['preferred_name'] ?? ''));
        $status = (string) ($in['status'] ?? ($existing['status'] ?? 'active'));
        if (!in_array($status, self::STATUSES, true)) {
            $errors['status'] = 'form.invalid_choice';
        }
        $hire = (string) ($in['hire_date'] ?? '');
        if (DateTimeImmutable::createFromFormat('!Y-m-d', $hire) === false) {
            $errors['hire_date'] = 'employees.error.date';
        }
        $term = trim((string) ($in['termination_date'] ?? ''));
        if ($term !== '' && (DateTimeImmutable::createFromFormat('!Y-m-d', $term) === false || $term < $hire)) {
            $errors['termination_date'] = 'employees.error.termination';
        }
        if ($status === 'former' && $term === '') {
            $errors['termination_date'] = 'employees.error.termination_required';
        }
        return [[
            'employee_code' => $code, 'name_th' => mb_substr($th, 0, 120), 'name_en' => $en === '' ? null : mb_substr($en, 0, 120),
            'preferred_name' => $pref === '' ? null : mb_substr($pref, 0, 60), 'status' => $status, 'hire_date' => $hire, 'termination_date' => $term === '' ? null : $term,
        ], $errors];
    }

    /**
     * @param array<string, mixed> $in
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function validateJob(array $in, ?int $employeeId): array
    {
        $db = $this->app->db();
        $errors = [];
        $le = (int) ($in['legal_entity_id'] ?? 0);
        if ($db->scalar('SELECT id FROM legal_entities WHERE id = ?', [$le]) === null) {
            $errors['legal_entity_id'] = 'form.invalid_choice';
        }
        $site = (int) ($in['site_id'] ?? 0);
        if ($site !== 0 && (int) ($db->scalar('SELECT legal_entity_id FROM sites WHERE id = ?', [$site]) ?? 0) !== $le) {
            $errors['site_id'] = 'employees.error.site';
        }
        $dept = (int) ($in['department_id'] ?? 0);
        $unit = $dept !== 0 ? $db->one('SELECT legal_entity_id, site_id FROM departments WHERE id = ?', [$dept]) : null;
        if ($dept !== 0 && (int) ($unit['legal_entity_id'] ?? 0) !== $le) {
            $errors['department_id'] = 'employees.error.department';
        } elseif ($unit !== null && $unit['site_id'] !== null) {
            // 0.8.2 (DEC-69): a unit tagged with a branch belongs to that branch — the employee works there too
            if ($site === 0) {
                $site = (int) $unit['site_id'];
            } elseif ($site !== (int) $unit['site_id']) {
                $errors['site_id'] = 'employees.error.site_unit';
            }
        }
        $mgr = (int) ($in['manager_employee_id'] ?? 0);
        if ($mgr !== 0 && ($mgr === $employeeId || $db->scalar("SELECT id FROM employees WHERE id = ? AND status = 'active'", [$mgr]) === null)) {
            $errors['manager_employee_id'] = 'employees.error.manager';
        }
        if ($mgr !== 0 && $employeeId !== null && $this->reportsTo($mgr, $employeeId)) {
            $errors['manager_employee_id'] = 'employees.error.manager_loop';
        }
        $group = strtoupper(trim((string) ($in['employee_group'] ?? '')));
        if (preg_match('/^[A-Z0-9_]{1,20}$/', $group) !== 1) {
            $errors['employee_group'] = 'employees.error.group';
        }
        $type = (string) ($in['employment_type'] ?? 'permanent');
        if (!in_array($type, self::TYPES, true)) {
            $errors['employment_type'] = 'form.invalid_choice';
        }
        $pos = trim((string) ($in['position_title'] ?? ''));
        return [[
            'legal_entity_id' => $le, 'site_id' => $site === 0 ? null : $site, 'department_id' => $dept === 0 ? null : $dept,
            'position_title' => $pos === '' ? null : mb_substr($pos, 0, 120), 'manager_employee_id' => $mgr === 0 ? null : $mgr,
            'employee_group' => $group, 'employment_type' => $type,
        ], $errors];
    }

    /** Would making $managerId the manager of $employeeId create a loop (the manager already reports up to the employee)? */
    private function reportsTo(int $managerId, int $employeeId): bool
    {
        $today = $this->today();
        $seen = [];
        $cur = $managerId;
        for ($i = 0; $i < 50 && $cur !== 0 && !isset($seen[$cur]); $i++) {
            if ($cur === $employeeId) {
                return true;
            }
            $seen[$cur] = true;
            $cur = (int) ($this->employment($cur, $today)['manager_employee_id'] ?? 0);
        }
        return false;
    }

    /** A business date (Y-m-d) as a DateTimeImmutable — never the system clock (architecture rule 4). */
    public static function ymd(string $ymd): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));
        if ($d === false) {
            throw new \InvalidArgumentException('date');
        }
        return $d;
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }
}
