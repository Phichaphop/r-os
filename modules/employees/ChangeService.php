<?php

declare(strict_types=1);

namespace Blue\Modules\Employees;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;

/**
 * Approved, effective-dated changes of an employee (M09 B2/B4/B5, 04 §6.19.3 / §6.19.5 — DEC-67):
 *
 *  employment  company / site / department / position / manager / group / type from a date
 *  status      active · on leave · suspended · former (separation: type, last working day)
 *
 * HR (employees.manage) asks; ANOTHER HR person (employees.approve, route employees.change, no fallback to the system
 * admin) decides. Approved changes apply on their date — today or past dates at once, future dates by the daily job —
 * close the row in force and add a new one; history is never rewritten. A date in the past is "retroactive": it needs
 * the reason like any change and is refused over a closed attendance period (reopen the period first). A separation
 * starts the exit checklist, ends the account's sessions on its date and starts the retention clock of the file.
 * Every applied change is an outbox event so other modules follow (failures are visible and can be retried).
 */
final class ChangeService
{
    public const STATUS_TARGETS = ['active', 'on_leave', 'suspended', 'former'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function emp(): EmployeeService
    {
        return EmployeeService::of($this->app);
    }

    /** Status on a date from the effective-dated status history. */
    public function statusOn(int $employeeId, string $date): ?string
    {
        $s = $this->app->db()->scalar(
            'SELECT status FROM employee_status_history WHERE employee_id = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC LIMIT 1',
            [$employeeId, $date, $date]
        );
        return $s === null ? null : (string) $s;
    }

    /** @return list<array<string, mixed>> */
    public function statusHistory(int $employeeId): array
    {
        return $this->app->db()->all('SELECT * FROM employee_status_history WHERE employee_id = ? ORDER BY effective_from DESC', [$employeeId]);
    }

    /** @return list<array<string, mixed>> */
    public function requests(int $employeeId): array
    {
        return $this->app->db()->all(
            'SELECT r.*, u.display_name AS requested_name, a.display_name AS approved_name, i.public_id AS instance_pid FROM emp_change_requests r
               LEFT JOIN users u ON u.id = r.requested_by LEFT JOIN users a ON a.id = r.approved_by LEFT JOIN workflow_instances i ON i.id = r.workflow_instance_id
              WHERE r.employee_id = ? ORDER BY r.id DESC LIMIT 50',
            [$employeeId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function offboarding(int $employeeId): array
    {
        return $this->app->db()->all(
            'SELECT o.*, u.display_name AS done_name FROM emp_offboarding_items o LEFT JOIN users u ON u.id = o.done_by WHERE o.employee_id = ? ORDER BY o.request_id DESC, o.id',
            [$employeeId]
        );
    }

    // ---- asking -------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $in employment fields + effective_from + change_reason */
    public function requestEmployment(Authorizer $az, string $pid, array $in): string
    {
        $e = $this->emp()->get($az, $pid, true);
        [$job, $errors] = $this->emp()->validateJob($in, (int) $e['id']);
        $from = (string) ($in['effective_from'] ?? '');
        $newest = $e['history'][0] ?? null;
        if (!$this->isDate($from)) {
            $errors['effective_from'] = 'employees.error.date';
        } elseif ($newest !== null && $from <= (string) $newest['effective_from']) {
            $errors['effective_from'] = 'employees.error.after_newest';
        }
        $reason = trim((string) ($in['change_reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            $errors['change_reason'] = 'iam.error.reason_required';
        }
        if ($e['status'] === 'former') {
            $errors['effective_from'] = 'employees.error.former';
        }
        if ($errors === [] && $newest !== null && $this->sameJob($newest, $job)) {
            $errors['position_title'] = 'employees.error.no_change';
        }
        if ($errors === []) {
            $errors += $this->lockErrors([(int) $e['job']['legal_entity_id'], (int) $job['legal_entity_id']], $from);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if (!EmployeeService::covers($az->scope('employees.manage'), $job)) {
            throw HttpException::forbidden(); // may not move someone into a company outside one's own scope
        }
        $before = $newest === null ? null : array_intersect_key($newest, $job);
        return $this->open($az, $e, 'employment', $job, $before, $from, $reason);
    }

    /** @param array<string, mixed> $in to_status, effective_from | last_working_day, separation_type, reason */
    public function requestStatus(Authorizer $az, string $pid, array $in): string
    {
        $e = $this->emp()->get($az, $pid, true);
        $errors = [];
        $to = (string) ($in['to_status'] ?? '');
        if (!in_array($to, self::STATUS_TARGETS, true)) {
            $errors['to_status'] = 'form.invalid_choice';
        }
        $payload = ['status' => $to];
        if ($to === 'former') {
            $lwd = (string) ($in['last_working_day'] ?? '');
            $type = (string) ($in['separation_type'] ?? '');
            if (!$this->isDate($lwd) || $lwd < (string) $e['hire_date']) {
                $errors['last_working_day'] = 'employees.error.last_day';
            }
            if (!in_array($type, FileRules::SEPARATION_TYPES, true)) {
                $errors['separation_type'] = 'form.invalid_choice';
            }
            $from = $this->isDate($lwd) ? EmployeeService::ymd($lwd)->modify('+1 day')->format('Y-m-d') : '';
            $payload += ['separation_type' => $type, 'last_working_day' => $lwd];
        } else {
            $from = (string) ($in['effective_from'] ?? '');
            if (!$this->isDate($from) || $from < (string) $e['hire_date']) {
                $errors['effective_from'] = 'employees.error.date';
            }
        }
        $reason = trim((string) ($in['reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ($errors === []) {
            $newest = $this->app->db()->one('SELECT * FROM employee_status_history WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 1', [$e['id']]);
            if ($newest !== null && $newest['status'] === 'former') {
                $errors['to_status'] = 'employees.error.former'; // a person who left comes back as a new hire (a new code — 6.19.5)
            } elseif ($newest !== null && $from <= (string) $newest['effective_from']) {
                $errors[$to === 'former' ? 'last_working_day' : 'effective_from'] = 'employees.error.after_newest';
            } elseif ($newest !== null && $newest['status'] === $to) {
                $errors['to_status'] = 'employees.error.same_status';
            }
        }
        if ($errors === []) {
            $errors += $this->lockErrors([(int) $e['job']['legal_entity_id']], $from);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $this->open($az, $e, 'status', $payload, ['status' => $e['status']], $from, $reason);
    }

    /**
     * @param array<string, mixed> $e
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $before
     */
    private function open(Authorizer $az, array $e, string $kind, array $payload, ?array $before, string $from, string $reason): string
    {
        $db = $this->app->db();
        if ($db->scalar("SELECT id FROM emp_change_requests WHERE employee_id = ? AND kind = ? AND status IN ('pending','returned','approved','failed')", [$e['id'], $kind]) !== null) {
            throw new BusinessException('employees.error.request_open', [], 409);
        }
        $pid = Ulid::generate($this->app->clock);
        $le = (int) $e['job']['legal_entity_id'];
        $today = $this->emp()->today();
        $db->transactional(function () use ($db, $pid, $e, $kind, $payload, $before, $from, $reason, $az, $le, $today): void {
            $now = $this->now();
            $db->run(
                'INSERT INTO emp_change_requests (public_id, created_at, created_by, updated_at, updated_by, employee_id, legal_entity_id, kind, payload_json, before_json, effective_from, reason, retroactive, requested_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $e['id'], $le, $kind, json_encode($payload, JSON_UNESCAPED_UNICODE), $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
                    $from, mb_substr($reason, 0, 500), $from < $today ? 1 : 0, $az->userId]
            );
            $title = $e['employee_code'] . ' · ' . $this->app->translator()->get('employees.change_kind.' . $kind);
            $job = $e['job'];
            $inst = $this->app->workflow()->start(
                'employees.change',
                $pid,
                $title,
                $le,
                $az->userId,
                $job['site_id'] !== null ? (int) $job['site_id'] : null,
                $job['department_id'] !== null ? (int) $job['department_id'] : null
            );
            $db->run('UPDATE emp_change_requests SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE public_id = ?', [$inst, $pid]);
            $this->app->audit()->record('employee.change_requested', 'success', 'employee', (string) $e['public_id'], $le, $reason, ['request' => $pid, 'kind' => $kind, 'from' => $from]);
        });
        return $pid;
    }

    /** The requester takes back a pending (or returned) request. */
    public function withdraw(Authorizer $az, string $requestPid): void
    {
        $r = $this->row($requestPid);
        if ((int) $r['requested_by'] !== $az->userId) {
            throw HttpException::forbidden();
        }
        $inst = (string) $this->app->db()->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$r['workflow_instance_id']]);
        $this->app->workflow()->cancel($inst, $az->userId, 'withdrawn');
    }

    // ---- workflow callbacks ----------------------------------------------------------------------------

    public function onApproved(string $requestPid, int $approverId): void
    {
        $r = $this->row($requestPid);
        $this->app->db()->run("UPDATE emp_change_requests SET status = 'approved', approved_by = ?, updated_at = ? WHERE id = ? AND status = 'pending'", [$approverId, $this->now(), $r['id']]);
        if ((string) $r['effective_from'] <= $this->emp()->today()) {
            $this->apply($requestPid, $approverId);
        }
    }

    public function onClosed(string $requestPid, string $outcome): void
    {
        $status = ['returned' => 'returned', 'rejected' => 'rejected', 'cancelled' => 'withdrawn'][$outcome] ?? 'rejected';
        $this->app->db()->run("UPDATE emp_change_requests SET status = ?, updated_at = ? WHERE public_id = ? AND status IN ('pending','returned')", [$status, $this->now(), $requestPid]);
    }

    public function onResubmitted(string $requestPid): void
    {
        $this->app->db()->run("UPDATE emp_change_requests SET status = 'pending', updated_at = ? WHERE public_id = ? AND status = 'returned'", [$this->now(), $requestPid]);
    }

    // ---- applying -------------------------------------------------------------------------------------

    /**
     * Applies an approved request (on its date). Idempotent: an applied request is not applied twice. A conflict (the
     * history moved on since the request was asked) marks it failed with the reason; HR fixes and retries.
     */
    public function apply(string $requestPid, ?int $actorId): bool
    {
        $db = $this->app->db();
        $r = $this->row($requestPid);
        if (!in_array($r['status'], ['approved', 'failed'], true)) {
            return false;
        }
        $payload = (array) json_decode((string) $r['payload_json'], true);
        $from = (string) $r['effective_from'];
        $empId = (int) $r['employee_id'];
        $actor = $actorId ?? (int) $r['approved_by'];
        try {
            $locks = $this->lockErrors(array_values(array_unique([(int) $r['legal_entity_id'], (int) ($payload['legal_entity_id'] ?? $r['legal_entity_id'])])), $from);
            if ($locks !== []) {
                throw new BusinessException('employees.error.period_closed', [], 409);
            }
            $db->transactional(function () use ($db, $r, $payload, $from, $empId, $actor): void {
                if ($r['kind'] === 'employment') {
                    $newest = $db->one('SELECT * FROM employment_history WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 1 FOR UPDATE', [$empId]);
                    if ($newest !== null && $from <= (string) $newest['effective_from']) {
                        throw new BusinessException('employees.error.after_newest', [], 409);
                    }
                    if ($newest !== null) {
                        $db->run('UPDATE employment_history SET effective_to = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$this->dayBefore($from), $this->now(), $actor, $newest['id']]);
                    }
                    $this->emp()->insertJob($empId, $payload + ['effective_from' => $from, 'change_reason' => mb_substr((string) $r['reason'], 0, 255)], $actor);
                } else {
                    $newest = $db->one('SELECT * FROM employee_status_history WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 1 FOR UPDATE', [$empId]);
                    if ($newest !== null && $from <= (string) $newest['effective_from']) {
                        throw new BusinessException('employees.error.after_newest', [], 409);
                    }
                    if ($newest !== null) {
                        $db->run('UPDATE employee_status_history SET effective_to = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$this->dayBefore($from), $this->now(), $actor, $newest['id']]);
                    }
                    $now = $this->now();
                    $db->run(
                        'INSERT INTO employee_status_history (public_id, created_at, created_by, updated_at, updated_by, employee_id, status, effective_from, separation_type, last_working_day, reason, request_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [Ulid::generate($this->app->clock), $now, $actor, $now, $actor, $empId, $payload['status'], $from, $payload['separation_type'] ?? null, $payload['last_working_day'] ?? null,
                            mb_substr((string) $r['reason'], 0, 500), $r['id']]
                    );
                    if ($payload['status'] === 'former') {
                        $db->run('UPDATE employees SET termination_date = ?, separation_type = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$payload['last_working_day'], $payload['separation_type'], $now, $actor, $empId]);
                        $this->startOffboarding($r, (string) $payload['last_working_day'], $actor);
                    }
                }
                $db->run("UPDATE emp_change_requests SET status = 'applied', applied_at = ?, error = NULL, updated_at = ? WHERE id = ?", [$this->now(), $this->now(), $r['id']]);
            });
        } catch (BusinessException $ex) {
            $db->run("UPDATE emp_change_requests SET status = 'failed', error = ?, updated_at = ? WHERE id = ?", [$ex->messageKey, $this->now(), $r['id']]);
            $this->app->audit()->record('employee.change_failed', 'failure', 'employee', (string) $this->pidOf($empId), (int) $r['legal_entity_id'], $ex->messageKey, ['request' => $requestPid], $actor);
            return false;
        }
        $this->refreshStatus($empId);
        $empPid = $this->pidOf($empId);
        $event = $r['kind'] === 'employment' ? 'employees.employment.changed' : ($payload['status'] === 'former' ? 'employees.separated' : 'employees.status.changed');
        $this->app->outbox()->record($event, [
            'employee_id' => $empPid, 'request_id' => $requestPid, 'effective_from' => $from, 'legal_entity_id' => (int) ($payload['legal_entity_id'] ?? $r['legal_entity_id']),
            'before' => json_decode((string) ($r['before_json'] ?? 'null'), true), 'after' => $payload,
        ], $event . ':' . $requestPid, (int) $r['legal_entity_id']);
        $this->app->audit()->record(
            $r['kind'] === 'employment' ? 'employee.employment_changed' : 'employee.status_changed',
            'success',
            'employee',
            $empPid,
            (int) $r['legal_entity_id'],
            (string) $r['reason'],
            ['request' => $requestPid, 'from' => $from, 'before' => json_decode((string) ($r['before_json'] ?? 'null'), true), 'after' => $payload,
            'retroactive' => (int) $r['retroactive'] === 1],
            $actor
        );
        return true;
    }

    /** HR retries a failed request (after fixing what blocked it, e.g. reopening the attendance period). */
    public function retry(Authorizer $az, string $requestPid): bool
    {
        $r = $this->row($requestPid);
        $e = $this->emp()->get($az, (string) $this->pidOf((int) $r['employee_id']), true);
        unset($e);
        if ($r['status'] !== 'failed') {
            throw new BusinessException('employees.error.not_failed', [], 409);
        }
        if ((string) $r['effective_from'] > $this->emp()->today()) {
            $this->app->db()->run("UPDATE emp_change_requests SET status = 'approved', error = NULL WHERE id = ?", [$r['id']]);
            return true;
        }
        return $this->apply($requestPid, $az->userId);
    }

    /**
     * employees.status = the status in force today. On the day a separation starts the account is moved to offboarding
     * (all sessions end) and the retention clock of the personnel file starts at the last working day.
     */
    public function refreshStatus(int $employeeId): void
    {
        $db = $this->app->db();
        $today = $this->emp()->today();
        $status = $this->statusOn($employeeId, $today);
        if ($status === null) {
            return;
        }
        $e = $db->one('SELECT id, public_id, status, termination_date FROM employees WHERE id = ?', [$employeeId]);
        if ($e === null) {
            return;
        }
        if ($e['status'] !== $status) {
            $db->run('UPDATE employees SET status = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?', [$status, $this->now(), $employeeId]);
        }
        if ($status === 'former') {
            $uid = $db->scalar('SELECT id FROM users WHERE employee_id = ?', [$employeeId]);
            if ($uid !== null) {
                $this->app->iam()->offboardForSeparation((int) $uid, 'employee ' . $e['public_id'] . ' separated', null);
            }
            if ($e['termination_date'] !== null && $e['status'] !== 'former') {
                $end = EmployeeService::ymd((string) $e['termination_date']);
                foreach ($db->all("SELECT document_pid FROM personnel_file_items WHERE employee_id = ? AND status <> 'archived'", [$employeeId]) as $f) {
                    try {
                        $this->app->documents()->setRetentionTrigger((string) $f['document_pid'], $end, 0);
                    } catch (\Throwable) {
                        // a missing document must not stop the separation — it shows in the file as unreadable
                    }
                }
            }
        }
    }

    /** Daily (hourly is fine): applies approved changes whose date has come and refreshes statuses. Returns changes applied. */
    public static function dueJob(App $app): int
    {
        $db = $app->db();
        if (!$db->tableExists('emp_change_requests')) {
            return 0;
        }
        $svc = self::of($app);
        $today = EmployeeService::of($app)->today();
        $n = 0;
        foreach ($db->all("SELECT public_id FROM emp_change_requests WHERE status = 'approved' AND effective_from <= ? ORDER BY effective_from, id LIMIT 200", [$today]) as $r) {
            $n += $svc->apply((string) $r['public_id'], null) ? 1 : 0;
        }
        foreach ($db->all('SELECT DISTINCT employee_id FROM employee_status_history WHERE effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)', [$today, $today]) as $s) {
            $svc->refreshStatus((int) $s['employee_id']);
        }
        return $n;
    }

    /** HR ticks an exit-checklist item done / not applicable, with a note. */
    public function setOffboardingItem(Authorizer $az, string $empPid, string $itemPid, string $status, string $note): void
    {
        $e = $this->emp()->get($az, $empPid, true);
        if (!in_array($status, ['done', 'not_applicable', 'open'], true)) {
            throw new ValidationException(['status' => 'form.invalid_choice']);
        }
        if ($status === 'not_applicable' && mb_strlen(trim($note)) < 3) {
            throw new ValidationException(['note' => 'employees.error.na_note']);
        }
        $item = $this->app->db()->one('SELECT * FROM emp_offboarding_items WHERE public_id = ? AND employee_id = ?', [$itemPid, $e['id']]) ?? throw HttpException::notFound();
        $this->app->db()->run(
            'UPDATE emp_offboarding_items SET status = ?, note = ?, done_by = ?, done_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$status, trim($note) === '' ? null : mb_substr(trim($note), 0, 255), $status === 'open' ? null : $az->userId, $status === 'open' ? null : $this->now(), $this->now(), $az->userId, $item['id']]
        );
        $this->app->audit()->record('employee.offboarding_item', 'success', 'employee', $empPid, (int) ($e['job']['legal_entity_id'] ?? 0), null, ['item' => $item['item_code'], 'status' => $status]);
    }

    /**
     * Approval page: what changes, from when, before → after.
     *
     * @return array{rows: list<array{0: string, 1: string}>, link: ?string}
     */
    public function describe(string $requestPid): array
    {
        $r = $this->app->db()->one('SELECT r.*, e.public_id AS emp_pid, e.employee_code, e.name_th, e.name_en, e.preferred_name FROM emp_change_requests r JOIN employees e ON e.id = r.employee_id WHERE r.public_id = ?', [$requestPid]);
        if ($r === null) {
            return ['rows' => [], 'link' => null];
        }
        $t = $this->app->translator();
        $payload = (array) json_decode((string) $r['payload_json'], true);
        $before = (array) json_decode((string) ($r['before_json'] ?? '[]'), true);
        $rows = [
            [$t->get('employees.field.employee'), $r['employee_code'] . ' · ' . $this->emp()->displayName($r)],
            [$t->get('employees.change_kind_label'), $t->get('employees.change_kind.' . $r['kind'])],
            [$t->get('employees.field.effective_from'), \Blue\Core\I18n\DateFormat::day((string) $r['effective_from'], $t->locale()) . ((int) $r['retroactive'] === 1 ? ' · ' . $t->get('employees.retroactive') : '')],
        ];
        foreach ($this->labelled($payload) as $k => $after) {
            $old = $this->labelled($before)[$k] ?? '—';
            if ($old !== $after || $r['kind'] === 'status') {
                $rows[] = [$t->get('employees.field.' . $k), $old . ' → ' . $after];
            }
        }
        $rows[] = [$t->get('employees.field.change_reason'), (string) $r['reason']];
        return ['rows' => $rows, 'link' => '/employees/' . $r['emp_pid'] . '?tab=changes'];
    }

    /**
     * Payload values as people read them (codes for company/site/department, names for the manager, translated enums).
     *
     * @param array<string, mixed> $p
     * @return array<string, string>
     */
    public function labelled(array $p): array
    {
        $db = $this->app->db();
        $t = $this->app->translator();
        $out = [];
        foreach ($p as $k => $v) {
            $out[(string) $k] = match ((string) $k) {
                'legal_entity_id' => (string) ($db->scalar('SELECT code FROM legal_entities WHERE id = ?', [$v]) ?? '—'),
                'site_id' => $v === null ? '—' : (string) ($db->scalar('SELECT code FROM sites WHERE id = ?', [$v]) ?? '—'),
                'department_id' => $v === null ? '—' : (string) ($db->scalar('SELECT code FROM departments WHERE id = ?', [$v]) ?? '—'),
                'manager_employee_id' => $v === null ? '—' : (string) ($db->scalar("SELECT CONCAT(employee_code, ' · ', name_th) FROM employees WHERE id = ?", [$v]) ?? '—'),
                'employment_type' => $t->get('employees.type.' . $v),
                'status' => $t->get('employees.status.' . $v),
                'separation_type' => $t->get('employees.separation.' . $v),
                'last_working_day' => \Blue\Core\I18n\DateFormat::day((string) $v, $t->locale()),
                default => $v === null || $v === '' ? '—' : (string) $v,
            };
        }
        return $out;
    }

    // ---- helpers ------------------------------------------------------------------------------------

    /** @param array<string, mixed> $r change request row */
    private function startOffboarding(array $r, string $lastDay, int $actor): void
    {
        $rules = PersonnelFileService::of($this->app)->rules((int) $r['legal_entity_id']);
        $suggested = $rules === null || ($rules['offboarding'] ?? []) === [];
        $items = $suggested ? FileRules::SUGGESTED_OFFBOARDING : (array) $rules['offboarding'];
        $t = $this->app->translator();
        $now = $this->now();
        foreach ($items as $it) {
            $label = $suggested ? $t->get('employees.offb_suggest.' . $it['code']) : (string) $it['label'];
            $this->app->db()->run(
                'INSERT IGNORE INTO emp_offboarding_items (public_id, created_at, created_by, updated_at, updated_by, employee_id, request_id, item_code, label, owner, due_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [Ulid::generate($this->app->clock), $now, $actor, $now, $actor, $r['employee_id'], $r['id'], $it['code'], mb_substr($label, 0, 160), $it['owner'],
                    EmployeeService::ymd($lastDay)->modify(((int) $it['days'] >= 0 ? '+' : '') . (int) $it['days'] . ' days')->format('Y-m-d')]
            );
        }
    }

    /**
     * A change dated on or before today may not reach into a closed attendance period of the companies involved.
     *
     * @param list<int> $legalEntityIds
     * @return array<string, string>
     */
    private function lockErrors(array $legalEntityIds, string $from): array
    {
        $today = $this->emp()->today();
        if ($from > $today) {
            return [];
        }
        foreach (array_unique($legalEntityIds) as $le) {
            foreach ($this->app->locks()->lockedRanges('attendance', $le) as [$a, $b]) {
                if ($b >= $from) {
                    return ['effective_from' => 'employees.error.period_closed'];
                }
            }
        }
        return [];
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private function sameJob(array $old, array $new): bool
    {
        foreach ($new as $k => $v) {
            if ((string) ($old[$k] ?? '') !== (string) ($v ?? '')) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed> */
    private function row(string $pid): array
    {
        return $this->app->db()->one('SELECT * FROM emp_change_requests WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    private function pidOf(int $employeeId): string
    {
        return (string) $this->app->db()->scalar('SELECT public_id FROM employees WHERE id = ?', [$employeeId]);
    }

    private function isDate(string $d): bool
    {
        $x = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $x !== false && $x->format('Y-m-d') === $d;
    }

    private function dayBefore(string $d): string
    {
        return EmployeeService::ymd($d)->modify('-1 day')->format('Y-m-d');
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }
}
