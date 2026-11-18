<?php

declare(strict_types=1);

namespace Blue\Modules\Attendance;

use DateTimeImmutable;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\Xlsx;
use Blue\Core\I18n\DateFormat;
use Blue\Core\Support\Ulid;

/**
 * 4C (DEC-66) — closing an attendance period for payroll (M34 B4/B5, P0-03 att_periods, 6.26 ledger, 6.27 locks).
 *
 *  open ──submit (HR, review done)──▶ pending ──approve (another HR)──▶ finalized ──export──▶ payroll input file(s)
 *    ▲            hard lock "attendance" on the date range         snapshot in the Calculation Ledger,      │
 *    └──── returned / rejected (lock released) ◀── pending           event attendance.period.finalized      │
 *    └──── reopen approved by another HR (lock released, snapshot kept in history) ◀── reopen_pending ◀─────┘
 *
 * While a period is pending or finalized, nothing may change its days: daily results are not recalculated, correction
 * requests and the punch file import refuse those dates, and leave refuses them through the core lock
 * (LockService::assertRangeWritable). Raw punches keep arriving (append-only) and count after a reopen.
 */
final class PeriodService
{
    public const MAX_DAYS = 62;
    public const ENGINE = 'att-period-summary-v1';
    public const LOCK_TYPE = 'attendance';

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function att(): AttendanceService
    {
        return AttendanceService::of($this->app);
    }

    /** @param array<string, mixed> $p */
    public static function ref(array $p): string
    {
        return $p['period_start'] . '..' . $p['period_end'];
    }

    /** Is this day frozen (in a pending or finalized period of the company)? */
    public function frozen(int $legalEntityId, string $ymd): bool
    {
        foreach ($this->app->locks()->lockedRanges(self::LOCK_TYPE, $legalEntityId) as [$a, $b]) {
            if ($ymd >= $a && $ymd <= $b) {
                return true;
            }
        }
        return false;
    }

    // ---- reading -----------------------------------------------------------------------------------------------

    /** May the user work with periods of this company? manage | approve | export | null */
    public function access(Authorizer $az, int $legalEntityId): ?string
    {
        foreach (['attendance.period' => 'manage', 'attendance.period_approve' => 'approve', 'attendance.export' => 'export'] as $perm => $kind) {
            if ($az->scope($perm)->coversLegalEntity($legalEntityId)) {
                return $kind;
            }
        }
        return null;
    }

    /** @return list<int> companies the user may see periods of */
    public function companies(Authorizer $az): array
    {
        $ids = [];
        foreach (['attendance.period', 'attendance.period_approve', 'attendance.export'] as $perm) {
            $s = $az->scope($perm);
            if ($s->isEmpty()) {
                continue;
            }
            if ($s->all) {
                return array_map('intval', array_column($this->app->db()->all("SELECT id FROM legal_entities WHERE status = 'active' ORDER BY code"), 'id'));
            }
            $ids = array_merge($ids, $s->legalEntityIds);
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** @return list<array<string, mixed>> */
    public function list(Authorizer $az, int $legalEntityId): array
    {
        if ($this->access($az, $legalEntityId) === null) {
            throw HttpException::forbidden();
        }
        return $this->app->db()->all(
            'SELECT p.*, (SELECT COUNT(*) FROM att_period_exports x WHERE x.period_id = p.id) AS exports FROM att_periods p WHERE p.legal_entity_id = ? ORDER BY p.period_start DESC LIMIT 60',
            [$legalEntityId]
        );
    }

    /** @return array<string, mixed> a period the user may see (404 otherwise) */
    public function get(Authorizer $az, string $pid): array
    {
        $p = $this->row($pid);
        $access = $this->access($az, (int) $p['legal_entity_id']);
        if ($access === null) {
            throw HttpException::notFound();
        }
        $p['access'] = $access;
        return $p;
    }

    /** @return list<array<string, mixed>> */
    public function exports(int $periodId): array
    {
        return $this->app->db()->all('SELECT x.*, u.display_name AS by_name FROM att_period_exports x LEFT JOIN users u ON u.id = x.exported_by WHERE x.period_id = ? ORDER BY x.id DESC', [$periodId]);
    }

    // ---- creating ----------------------------------------------------------------------------------------------

    public function create(Authorizer $az, int $legalEntityId, string $start, string $end, string $label): string
    {
        if (!$az->scope('attendance.period')->coversLegalEntity($legalEntityId)) {
            throw HttpException::forbidden();
        }
        $errors = [];
        $ds = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $de = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        if ($ds === false || $ds->format('Y-m-d') !== $start) {
            $errors['period_start'] = 'attendance.period_error.date';
        }
        if ($de === false || $de->format('Y-m-d') !== $end) {
            $errors['period_end'] = 'attendance.period_error.date';
        } elseif ($ds !== false && $end < $start) {
            $errors['period_end'] = 'attendance.period_error.order';
        } elseif ($ds !== false && (int) $ds->diff($de)->days + 1 > self::MAX_DAYS) {
            $errors['period_end'] = 'attendance.period_error.too_long';
        }
        if ($errors === []) {
            $clash = $this->app->db()->scalar('SELECT public_id FROM att_periods WHERE legal_entity_id = ? AND period_start <= ? AND period_end >= ?', [$legalEntityId, $end, $start]);
            if ($clash !== null) {
                $errors['period_start'] = 'attendance.period_error.overlap';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $this->app->db()->run(
            "INSERT INTO att_periods (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, period_start, period_end, label, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open')",
            [$pid, $now, $az->userId, $now, $az->userId, $legalEntityId, $start, $end, trim($label) !== '' ? mb_substr(trim($label), 0, 60) : null]
        );
        $this->app->audit()->record('attendance.period_created', 'success', 'att_period', $pid, $legalEntityId, null, ['start' => $start, 'end' => $end]);
        return $pid;
    }

    // ---- review -------------------------------------------------------------------------------------------------

    /**
     * @return list<int> employees who worked for the company at some point of the period
     *
     * @param array<string, mixed> $p
     */
    public function employees(array $p): array
    {
        return array_map('intval', array_column($this->app->db()->all(
            "SELECT DISTINCT h.employee_id FROM employment_history h JOIN employees e ON e.id = h.employee_id
              WHERE h.legal_entity_id = ? AND h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?) AND e.status <> 'pre_hire' ORDER BY h.employee_id",
            [$p['legal_entity_id'], $p['period_end'], $p['period_start']]
        ), 'employee_id'));
    }

    /**
     * What HR must look at before closing: pending corrections block; the rest are warnings HR confirms.
     *
     * @return array{blockers: array<string, int>, warnings: array<string, int>, items: list<array<string, mixed>>, employees: int, refreshed: bool}
     * @param array<string, mixed> $p
     */
    public function review(array $p, bool $refresh = true): array
    {
        $db = $this->app->db();
        $ids = $this->employees($p);
        $end = min((string) $p['period_end'], $this->att()->today());
        $refreshed = false;
        if ($refresh && $p['status'] === 'open' && $ids !== [] && (string) $p['period_start'] <= $end) {
            $this->att()->forget();
            foreach ($ids as $id) {
                $this->att()->recalc($id, (string) $p['period_start'], (string) $p['period_end']);
            }
            $refreshed = true;
        }
        $blockers = ['pending_corrections' => 0, 'not_ended' => (string) $p['period_end'] >= $this->att()->today() ? 1 : 0];
        $warnings = ['absent' => 0, 'missing' => 0, 'no_data' => 0, 'no_calendar' => 0, 'unmapped' => 0];
        $items = [];
        if ($ids !== []) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            foreach (
                $db->all(
                    "SELECT c.public_id, c.work_date, c.kind, e.employee_code, e.name_th, e.name_en, e.preferred_name FROM att_corrections c JOIN employees e ON e.id = c.employee_id
                      WHERE c.employee_id IN ({$place}) AND c.legal_entity_id = ? AND c.status IN ('pending','returned') AND c.work_date BETWEEN ? AND ? ORDER BY c.work_date",
                    array_merge($ids, [$p['legal_entity_id'], $p['period_start'], $p['period_end']])
                ) as $c
            ) {
                $blockers['pending_corrections']++;
                $items[] = ['kind' => 'pending_correction', 'blocker' => true, 'date' => (string) $c['work_date'], 'employee' => $c, 'link' => '/attendance/c/' . $c['public_id']];
            }
            $map = ['absent' => 'absent', 'missing_in' => 'missing', 'missing_out' => 'missing', 'no_data' => 'no_data', 'no_calendar' => 'no_calendar'];
            foreach (
                $db->all(
                    "SELECT d.work_date, d.status, e.public_id AS emp_pid, e.employee_code, e.name_th, e.name_en, e.preferred_name FROM att_days d JOIN employees e ON e.id = d.employee_id
                      WHERE d.employee_id IN ({$place}) AND d.legal_entity_id = ? AND d.work_date BETWEEN ? AND ? AND d.status IN ('absent','missing_in','missing_out','no_data','no_calendar')
                      ORDER BY d.work_date, e.employee_code",
                    array_merge($ids, [$p['legal_entity_id'], $p['period_start'], $p['period_end']])
                ) as $d
            ) {
                $k = $map[(string) $d['status']];
                $warnings[$k]++;
                if (count($items) < 300) {
                    $items[] = ['kind' => (string) $d['status'], 'blocker' => false, 'date' => (string) $d['work_date'], 'employee' => $d,
                        'link' => '/attendance/e/' . $d['emp_pid'] . '/day/' . $d['work_date']];
                }
            }
        }
        $warnings['unmapped'] = (int) $db->scalar(
            "SELECT COUNT(DISTINCT p.device_user_code) FROM att_raw_punches p JOIN att_devices d ON d.id = p.device_id JOIN sites s ON s.id = d.site_id
              WHERE s.legal_entity_id = ? AND p.source = 'api' AND p.employee_id IS NULL AND p.occurred_at_utc >= ? AND p.occurred_at_utc < ? + INTERVAL 2 DAY
                AND NOT EXISTS (SELECT 1 FROM att_device_users m WHERE m.device_user_code = p.device_user_code AND (m.device_id IS NULL OR m.device_id = p.device_id)
                    AND m.valid_from <= DATE(p.occurred_at_utc + INTERVAL 1 DAY) AND (m.valid_to IS NULL OR m.valid_to >= DATE(p.occurred_at_utc - INTERVAL 1 DAY)))",
            [$p['legal_entity_id'], $p['period_start'] . ' 00:00:00', $p['period_end'] . ' 00:00:00']
        );
        return ['blockers' => $blockers, 'warnings' => $warnings, 'items' => $items, 'employees' => count($ids), 'refreshed' => $refreshed];
    }

    /**
     * The payroll input of a period, computed from the stored daily results (never from live punches).
     *
     * @return array{lines: list<array<string, mixed>>, leave_types: list<string>, days: list<array<string, mixed>>, hash: string}
     * @param array<string, mixed> $p
     */
    public function summary(array $p): array
    {
        $db = $this->app->db();
        $ids = $this->employees($p);
        if ($ids === []) {
            return ['lines' => [], 'leave_types' => [], 'days' => [], 'hash' => hash('sha256', '[]')];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $days = $db->all(
            "SELECT d.employee_id, d.work_date, d.site_id, d.department_id, d.day_type, d.leave_part, d.leave_type, d.first_in, d.last_out, d.in_source, d.out_source,
                    d.worked_minutes, d.late_minutes, d.early_minutes, d.status, d.schedule_version_id, d.calc_version
               FROM att_days d WHERE d.employee_id IN ({$place}) AND d.legal_entity_id = ? AND d.work_date BETWEEN ? AND ? ORDER BY d.employee_id, d.work_date",
            array_merge($ids, [$p['legal_entity_id'], $p['period_start'], $p['period_end']])
        );
        $emps = [];
        foreach (
            $db->all(
                "SELECT e.id, e.employee_code, e.name_th, e.name_en FROM employees e WHERE e.id IN ({$place}) ORDER BY e.employee_code",
                $ids
            ) as $e
        ) {
            $emps[(int) $e['id']] = $e;
        }
        $codes = static fn (string $table, array $idsOf) => $idsOf === [] ? [] : array_column(
            $db->all('SELECT id, code FROM ' . $table . ' WHERE id IN (' . implode(',', array_fill(0, count($idsOf), '?')) . ')', $idsOf),
            'code',
            'id'
        );
        $siteCodes = $codes('sites', array_values(array_unique(array_filter(array_map(static fn ($d) => $d['site_id'] !== null ? (int) $d['site_id'] : null, $days)))));
        $deptCodes = $codes('departments', array_values(array_unique(array_filter(array_map(static fn ($d) => $d['department_id'] !== null ? (int) $d['department_id'] : null, $days)))));
        $acc = [];
        $leaveTypes = [];
        foreach ($days as $d) {
            $id = (int) $d['employee_id'];
            $a = $acc[$id] ?? ['scheduled_days' => 0, 'worked_days' => 0, 'absent_days' => 0, 'missing_punch_days' => 0, 'late_count' => 0, 'late_minutes' => 0,
                'early_count' => 0, 'early_minutes' => 0, 'worked_minutes' => 0, 'leave_days' => 0.0, 'leave' => [], 'holiday_days' => 0, 'off_days' => 0,
                'no_data_days' => 0, 'corrected_days' => 0, 'site' => null, 'department' => null];
            $a['scheduled_days'] += $d['day_type'] === 'working' ? 1 : 0;
            $a['worked_days'] += ($d['first_in'] !== null || $d['last_out'] !== null) ? 1 : 0;
            $a['absent_days'] += $d['status'] === 'absent' ? 1 : 0;
            $a['missing_punch_days'] += in_array($d['status'], ['missing_in', 'missing_out'], true) ? 1 : 0;
            if ((int) $d['late_minutes'] > 0) {
                $a['late_count']++;
                $a['late_minutes'] += (int) $d['late_minutes'];
            }
            if ((int) $d['early_minutes'] > 0) {
                $a['early_count']++;
                $a['early_minutes'] += (int) $d['early_minutes'];
            }
            $a['worked_minutes'] += (int) $d['worked_minutes'];
            if ($d['leave_part'] !== null) {
                $units = $d['leave_part'] === 'full' ? 1.0 : 0.5;
                $type = (string) ($d['leave_type'] ?? '');
                $a['leave_days'] += $units;
                $a['leave'][$type] = ($a['leave'][$type] ?? 0.0) + $units;
                $leaveTypes[$type] = true;
            }
            $a['holiday_days'] += $d['day_type'] === 'holiday' ? 1 : 0;
            $a['off_days'] += $d['day_type'] === 'weekend' ? 1 : 0;
            $a['no_data_days'] += $d['status'] === 'no_data' ? 1 : 0;
            $a['corrected_days'] += ($d['in_source'] === 'correction' || $d['out_source'] === 'correction') ? 1 : 0;
            $a['site'] = $d['site_id'] !== null ? ($siteCodes[(int) $d['site_id']] ?? null) : $a['site'];
            $a['department'] = $d['department_id'] !== null ? ($deptCodes[(int) $d['department_id']] ?? null) : $a['department'];
            $acc[$id] = $a;
        }
        $leaveTypes = array_keys($leaveTypes);
        sort($leaveTypes);
        $lines = [];
        foreach ($emps as $id => $e) {
            $a = $acc[$id] ?? null;
            if ($a === null) {
                continue; // employed by the company but no day in range (e.g. hired after the end) — nothing to pay from time data
            }
            ksort($a['leave']);
            $lines[] = ['employee_code' => (string) $e['employee_code'], 'name' => (string) $e['name_th'], 'site' => $a['site'], 'department' => $a['department']]
                + array_diff_key($a, ['site' => 1, 'department' => 1]);
        }
        $out = ['lines' => $lines, 'leave_types' => $leaveTypes];
        return $out + ['days' => $days, 'hash' => hash('sha256', (string) json_encode($out, JSON_UNESCAPED_UNICODE))];
    }

    // ---- close -------------------------------------------------------------------------------------------------

    public function submit(Authorizer $az, string $pid, string $note, bool $confirmed): void
    {
        $p = $this->row($pid);
        $le = (int) $p['legal_entity_id'];
        if (!$az->scope('attendance.period')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        if ($p['status'] !== 'open') {
            throw new BusinessException('attendance.period_error.state', [], 409);
        }
        $rv = $this->review($p);
        if ($rv['blockers']['not_ended'] > 0) {
            throw new ValidationException(['period' => 'attendance.period_error.not_ended']);
        }
        if ($rv['blockers']['pending_corrections'] > 0) {
            throw new ValidationException(['period' => 'attendance.period_error.pending']);
        }
        if (array_sum($rv['warnings']) > 0 && !$confirmed) {
            throw new ValidationException(['confirm' => 'attendance.period_error.confirm']);
        }
        if ($rv['employees'] === 0) {
            throw new ValidationException(['period' => 'attendance.period_error.empty']);
        }
        $note = mb_substr(trim($note), 0, 500);
        $db = $this->app->db();
        $inst = $p['workflow_instance_id'] !== null ? $db->one('SELECT public_id, status FROM workflow_instances WHERE id = ?', [$p['workflow_instance_id']]) : null;
        $db->transactional(function () use ($db, $p, $az, $le, $rv, $note, $inst, $pid): void {
            $this->freeze($p, $az->userId, $note, $rv);
            if ($inst !== null && $inst['status'] === 'returned') {
                $this->app->workflow()->resubmit((string) $inst['public_id'], $az->userId, $note);
            } else {
                $title = $this->app->translator()->get('attendance.period_title', ['range' => $this->rangeLabel($p)]);
                $iid = $this->app->workflow()->start('attendance.period_close', $pid, $title, $le, $az->userId);
                $db->run('UPDATE att_periods SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE id = ?', [$iid, $p['id']]);
            }
        });
        $this->app->audit()->record('attendance.period_submitted', 'success', 'att_period', $pid, $le, $note !== '' ? $note : null, ['revision' => (int) $p['revision'] + 1,
            'warnings' => $rv['warnings'], 'employees' => $rv['employees']]);
    }

    /**
     * Locks the date range and remembers what was reviewed (status pending, revision + 1).
     *
     * @param array<string, mixed> $rv review()
     * @param array<string, mixed> $p
     */
    private function freeze(array $p, int $actorId, string $note, array $rv): void
    {
        $sum = $this->summary($p);
        $lockPid = $this->app->locks()->lock(self::LOCK_TYPE, 'legal_entity', (int) $p['legal_entity_id'], self::ref($p), 'hard', $actorId, 'attendance period sent for close');
        $this->app->db()->run(
            "UPDATE att_periods SET status = 'pending', revision = revision + 1, lock_pid = ?, review_json = ?, submitted_hash = ?, submitted_by = ?, submitted_at = ?, submit_note = ?,
                updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'open'",
            [$lockPid, json_encode(['blockers' => $rv['blockers'], 'warnings' => $rv['warnings'], 'employees' => $rv['employees']]), $sum['hash'], $actorId, $this->now(),
                $note !== '' ? $note : null, $this->now(), $actorId, $p['id']]
        );
    }

    /** Close approved (by someone other than the sender — the workflow engine enforces it): snapshot, finalize, event. */
    public function onCloseApproved(string $pid, int $approverId): void
    {
        $p = $this->row($pid);
        if ($p['status'] === 'finalized') {
            return;
        }
        if ($p['status'] !== 'pending') {
            throw new BusinessException('attendance.period_error.state', [], 409);
        }
        $sum = $this->summary($p);
        if (!hash_equals((string) $p['submitted_hash'], $sum['hash'])) {
            // cannot happen while the lock holds; refusing is safer than finalizing something nobody reviewed
            throw new BusinessException('attendance.period_error.changed', [], 409);
        }
        $rev = (int) $p['revision'];
        $previous = $p['snapshot_run_pid'] !== null ? (string) $p['snapshot_run_pid'] : null;
        $run = $this->app->ledger()->record(
            'attendance.period',
            DayCalculator::VERSION,
            self::ENGINE,
            $this->app->clock->now(),
            [],
            ['period' => ['public_id' => $pid, 'legal_entity_id' => (int) $p['legal_entity_id'], 'start' => (string) $p['period_start'], 'end' => (string) $p['period_end'], 'revision' => $rev],
                'schedule_versions' => array_values(array_unique(array_filter(array_map(static fn ($d) => $d['schedule_version_id'] !== null ? (int) $d['schedule_version_id'] : null, $sum['days'])))),
                'days' => $sum['days']],
            ['submitted_by' => (int) $p['submitted_by'], 'review' => json_decode((string) $p['review_json'], true)],
            ['lines' => $sum['lines'], 'leave_types' => $sum['leave_types']],
            ['legal_entity_id' => (int) $p['legal_entity_id'], 'period_ref' => self::ref($p), 'source_type' => 'att_period', 'source_id' => $pid, 'source_revision' => $rev,
                'initiated_by' => $approverId, 'status' => 'approved', 'supersedes' => $previous, 'idempotency_key' => 'att_period:' . $pid . ':' . $rev]
        );
        $now = $this->now();
        $this->app->db()->run(
            "UPDATE att_periods SET status = 'finalized', snapshot_run_pid = ?, output_hash = ?, employees = ?, finalized_by = ?, finalized_at = ?, updated_at = ?, updated_by = ?,
                row_version = row_version + 1 WHERE id = ? AND status = 'pending'",
            [$run, $sum['hash'], count($sum['lines']), $approverId, $now, $now, $approverId, $p['id']]
        );
        if ($p['lock_pid'] !== null) {
            $this->app->locks()->attachSnapshot((string) $p['lock_pid'], 'calc:' . $run, $approverId);
        }
        $this->app->outbox()->record('attendance.period.finalized', [
            'period_id' => $pid, 'legal_entity_id' => (int) $p['legal_entity_id'], 'period_start' => (string) $p['period_start'], 'period_end' => (string) $p['period_end'],
            'revision' => $rev, 'snapshot_run_id' => $run, 'output_hash' => $sum['hash'], 'employees' => count($sum['lines']),
        ], 'attendance.period.finalized:' . $pid . ':' . $rev, (int) $p['legal_entity_id']);
        $this->app->audit()->record('attendance.period_finalized', 'success', 'att_period', $pid, (int) $p['legal_entity_id'], null, ['revision' => $rev, 'snapshot' => $run,
            'hash' => $sum['hash']], $approverId);
    }

    /** Close sent back, rejected or withdrawn: the lock is released and the period is open again. */
    public function onCloseClosed(string $pid, string $outcome): void
    {
        $p = $this->row($pid);
        if ($p['status'] !== 'pending') {
            return;
        }
        if ($p['lock_pid'] !== null) {
            $this->app->locks()->release((string) $p['lock_pid'], (int) $p['submitted_by'], 'close ' . $outcome);
        }
        $this->app->db()->run("UPDATE att_periods SET status = 'open', updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $p['id']]);
        $this->app->audit()->record('attendance.period_close_' . $outcome, 'success', 'att_period', $pid, (int) $p['legal_entity_id']);
    }

    /** A returned close sent again from the approvals pages (not from the period page): freeze again. */
    public function onCloseResubmitted(string $pid): void
    {
        $p = $this->row($pid);
        if ($p['status'] === 'open') {
            $rv = $this->review($p);
            if ($rv['blockers']['pending_corrections'] > 0 || $rv['blockers']['not_ended'] > 0) {
                throw new BusinessException('attendance.period_error.pending', [], 409);
            }
            $this->freeze($p, (int) $p['submitted_by'], (string) ($p['submit_note'] ?? ''), $rv);
        }
    }

    // ---- reopen --------------------------------------------------------------------------------------------------

    public function requestReopen(Authorizer $az, string $pid, string $reason): void
    {
        $p = $this->row($pid);
        $le = (int) $p['legal_entity_id'];
        if (!$az->scope('attendance.period')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        if ($p['status'] !== 'finalized') {
            throw new BusinessException('attendance.period_error.state', [], 409);
        }
        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['reason' => 'attendance.period_error.reopen_reason']);
        }
        $db = $this->app->db();
        $db->transactional(function () use ($db, $p, $az, $reason, $le, $pid): void {
            $db->run(
                "UPDATE att_periods SET status = 'reopen_pending', reopen_reason = ?, reopen_requested_by = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'finalized'",
                [$reason, $az->userId, $this->now(), $az->userId, $p['id']]
            );
            $title = $this->app->translator()->get('attendance.period_reopen_title', ['range' => $this->rangeLabel($p)]);
            $iid = $this->app->workflow()->start('attendance.period_reopen', $pid, $title, $le, $az->userId);
            $db->run('UPDATE att_periods SET reopen_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE id = ?', [$iid, $p['id']]);
        });
        $this->app->audit()->record('attendance.period_reopen_requested', 'success', 'att_period', $pid, $le, $reason, ['exported' => $p['first_exported_at'] !== null]);
    }

    public function onReopenApproved(string $pid, int $approverId): void
    {
        $p = $this->row($pid);
        if ($p['status'] === 'open') {
            return;
        }
        if ($p['status'] !== 'reopen_pending') {
            throw new BusinessException('attendance.period_error.state', [], 409);
        }
        if ($p['lock_pid'] !== null) {
            $this->app->locks()->release((string) $p['lock_pid'], $approverId, 'reopen approved: ' . (string) $p['reopen_reason']);
        }
        $this->app->db()->run("UPDATE att_periods SET status = 'open', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $approverId, $p['id']]);
        $this->app->outbox()->record('attendance.period.reopened', [
            'period_id' => $pid, 'legal_entity_id' => (int) $p['legal_entity_id'], 'revision' => (int) $p['revision'], 'snapshot_run_id' => $p['snapshot_run_pid'],
            'was_exported' => $p['first_exported_at'] !== null, 'reason' => (string) $p['reopen_reason'],
        ], 'attendance.period.reopened:' . $pid . ':' . (int) $p['revision'], (int) $p['legal_entity_id']);
        $this->app->audit()->record('attendance.period_reopened', 'success', 'att_period', $pid, (int) $p['legal_entity_id'], (string) $p['reopen_reason'], [], $approverId);
    }

    public function onReopenClosed(string $pid): void
    {
        $this->app->db()->run(
            "UPDATE att_periods SET status = 'finalized', updated_at = ?, row_version = row_version + 1 WHERE public_id = ? AND status = 'reopen_pending'",
            [$this->now(), $pid]
        );
    }

    // ---- payroll input -------------------------------------------------------------------------------------------

    /**
     * The snapshot of a finalized period, read back from the Calculation Ledger and checked against its hash.
     *
     * @return array{lines: list<array<string, mixed>>, leave_types: list<string>, intact: bool}
     * @param array<string, mixed> $p
     */
    public function snapshot(array $p): array
    {
        if ($p['snapshot_run_pid'] === null) {
            throw new BusinessException('attendance.period_error.no_snapshot', [], 409);
        }
        $run = $this->app->ledger()->open((string) $p['snapshot_run_pid']);
        $out = $run['outputs'];
        return ['lines' => array_values((array) ($out['lines'] ?? [])), 'leave_types' => array_values(array_map('strval', (array) ($out['leave_types'] ?? []))), 'intact' => $run['intact']];
    }

    /**
     * BLUE_PAYROLL_TIME_INPUT_XLSX_v1 (or CSV): one row per employee from the snapshot, plus a "meta" sheet that says
     * which snapshot it came from. Every hand-out is logged.
     *
     * @return array{bytes: string, name: string, mime: string}
     */
    public function export(Authorizer $az, string $pid, string $format): array
    {
        $p = $this->row($pid);
        $le = (int) $p['legal_entity_id'];
        if (!$az->scope('attendance.export')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        if ($p['status'] !== 'finalized') {
            throw new BusinessException('attendance.period_error.not_final', [], 409);
        }
        $snap = $this->snapshot($p);
        if (!$snap['intact']) {
            throw new BusinessException('attendance.period_error.snapshot_damaged', [], 409);
        }
        [$headers, $rows] = $this->table($snap);
        $t = $this->app->translator();
        $leCode = (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]);
        $base = 'BLUE_PAYROLL_TIME_INPUT_v1_' . preg_replace('/[^A-Za-z0-9_-]/', '', $leCode) . '_' . $p['period_start'] . '_' . $p['period_end'] . '_r' . (int) $p['revision'];
        if ($format === 'csv') {
            $fh = fopen('php://temp', 'w+');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, $headers, ',', '"', '');
            foreach ($rows as $r) {
                // text columns (code, name, site, department) never start a spreadsheet formula when the CSV is opened
                fputcsv($fh, array_map(static fn ($v, int $i): string => $i < 4 && preg_match('/^[=+\-@\t\r]/', (string) $v) === 1 ? "'" . $v : (string) $v, $r, array_keys($r)), ',', '"', '');
            }
            rewind($fh);
            $bytes = (string) stream_get_contents($fh);
            fclose($fh);
            $mime = 'text/csv; charset=utf-8';
            $name = $base . '.csv';
        } else {
            $numeric = range(4, count($headers) - 1);
            $meta = [
                [$t->get('attendance.export_meta.format'), 'BLUE_PAYROLL_TIME_INPUT_XLSX_v1'],
                [$t->get('attendance.export_meta.company'), $leCode],
                [$t->get('attendance.export_meta.period'), $p['period_start'] . ' – ' . $p['period_end']],
                [$t->get('attendance.export_meta.revision'), (string) (int) $p['revision']],
                [$t->get('attendance.export_meta.snapshot'), (string) $p['snapshot_run_pid']],
                [$t->get('attendance.export_meta.hash'), (string) $p['output_hash']],
                [$t->get('attendance.export_meta.rows'), (string) count($rows)],
                [$t->get('attendance.export_meta.finalized_at'), (string) $p['finalized_at'] . ' UTC'],
                [$t->get('attendance.export_meta.exported_at'), $this->now() . ' UTC'],
                [$t->get('attendance.export_meta.note'), $t->get('attendance.export_meta.note_text')],
            ];
            $bytes = Xlsx::write([
                'time_input' => ['headers' => $headers, 'rows' => $rows, 'numeric' => $numeric, 'widths' => array_merge([14, 28, 10, 12], array_fill(0, count($headers) - 4, 12))],
                'meta' => ['headers' => [$t->get('attendance.export_meta.item'), $t->get('attendance.export_meta.value')], 'rows' => $meta, 'freeze' => false, 'widths' => [24, 70]],
            ], 'Blue OS payroll time input');
            $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            $name = $base . '.xlsx';
        }
        $now = $this->now();
        $db = $this->app->db();
        $db->run(
            'INSERT INTO att_period_exports (period_id, revision, snapshot_run_pid, format, file_sha256, row_count, exported_by, exported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$p['id'], $p['revision'], $p['snapshot_run_pid'], $format === 'csv' ? 'csv' : 'xlsx', hash('sha256', $bytes), count($rows), $az->userId, $now]
        );
        $db->run('UPDATE att_periods SET first_exported_at = COALESCE(first_exported_at, ?) WHERE id = ?', [$now, $p['id']]);
        $this->app->audit()->record('attendance.period_exported', 'success', 'att_period', $pid, $le, null, ['revision' => (int) $p['revision'], 'format' => $format, 'rows' => count($rows),
            'sha256' => hash('sha256', $bytes)]);
        return ['bytes' => $bytes, 'name' => $name, 'mime' => $mime];
    }

    /**
     * Column keys are fixed (a payroll program reads them); leave columns follow, one per leave type found.
     *
     * @param array{lines: list<array<string, mixed>>, leave_types: list<string>} $snap
     * @return array{0: list<string>, 1: list<list<scalar|null>>}
     */
    public function table(array $snap): array
    {
        $headers = ['employee_code', 'employee_name', 'site', 'department', 'scheduled_days', 'worked_days', 'absent_days', 'missing_punch_days', 'late_count', 'late_minutes',
            'early_count', 'early_minutes', 'worked_hours', 'leave_days_total'];
        foreach ($snap['leave_types'] as $lt) {
            $headers[] = 'leave:' . $lt;
        }
        $headers = array_merge($headers, ['holiday_days', 'off_days', 'no_data_days', 'corrected_days']);
        $rows = [];
        foreach ($snap['lines'] as $l) {
            $r = [(string) $l['employee_code'], (string) $l['name'], (string) ($l['site'] ?? ''), (string) ($l['department'] ?? ''), (int) $l['scheduled_days'], (int) $l['worked_days'],
                (int) $l['absent_days'], (int) $l['missing_punch_days'], (int) $l['late_count'], (int) $l['late_minutes'], (int) $l['early_count'], (int) $l['early_minutes'],
                round((int) $l['worked_minutes'] / 60, 2), (float) $l['leave_days']];
            foreach ($snap['leave_types'] as $lt) {
                $r[] = (float) (((array) $l['leave'])[$lt] ?? 0);
            }
            $rows[] = array_merge($r, [(int) $l['holiday_days'], (int) $l['off_days'], (int) $l['no_data_days'], (int) $l['corrected_days']]);
        }
        return [$headers, $rows];
    }

    // ---- approval page ---------------------------------------------------------------------------------------------

    /** @return array{rows: list<array{0: string, 1: string}>, link: ?string} */
    public function describe(string $pid, bool $reopen): array
    {
        $p = $this->app->db()->one('SELECT * FROM att_periods WHERE public_id = ?', [$pid]);
        if ($p === null) {
            return ['rows' => [], 'link' => null];
        }
        $t = $this->app->translator();
        $leCode = (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$p['legal_entity_id']]);
        $rv = json_decode((string) ($p['review_json'] ?? ''), true);
        $rows = [
            [$t->get('attendance.period_field.company'), $leCode],
            [$t->get('attendance.period_field.range'), $this->rangeLabel($p)],
            [$t->get('attendance.period_field.revision'), (string) (int) $p['revision']],
        ];
        if ($reopen) {
            $rows[] = [$t->get('attendance.period_field.reopen_reason'), (string) $p['reopen_reason']];
            $rows[] = [$t->get('attendance.period_field.exported'), $t->get($p['first_exported_at'] !== null ? 'attendance.period_exported_yes' : 'attendance.period_exported_no')];
        } else {
            $w = is_array($rv) ? (array) ($rv['warnings'] ?? []) : [];
            $rows[] = [$t->get('attendance.period_field.employees'), (string) (is_array($rv) ? (int) ($rv['employees'] ?? 0) : 0)];
            $rows[] = [$t->get('attendance.period_field.warnings'), $t->get('attendance.period_warn_line', [
                'absent' => (string) (int) ($w['absent'] ?? 0), 'missing' => (string) (int) ($w['missing'] ?? 0), 'no_data' => (string) (int) ($w['no_data'] ?? 0),
                'unmapped' => (string) (int) ($w['unmapped'] ?? 0),
            ])];
            $rows[] = [$t->get('attendance.period_field.note'), (string) ($p['submit_note'] ?? '—')];
        }
        return ['rows' => $rows, 'link' => '/attendance/periods/' . $pid];
    }

    /** @param array<string, mixed> $p */
    public function rangeLabel(array $p): string
    {
        $loc = $this->app->translator()->locale();
        return DateFormat::day((string) $p['period_start'], $loc) . ' – ' . DateFormat::day((string) $p['period_end'], $loc) . (($p['label'] ?? null) ? ' (' . $p['label'] . ')' : '');
    }

    /** @return array<string, mixed> */
    private function row(string $pid): array
    {
        return $this->app->db()->one('SELECT * FROM att_periods WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }
}
