<?php

declare(strict_types=1);

namespace Blue\Modules\Attendance;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\I18n\DateFormat;
use Blue\Modules\Employees\EmployeeService;
use Blue\Core\Support\Ulid;

/**
 * Correction requests (M34 B3/B4, DEC-64): "I forgot to scan", "the time is wrong". The employee asks for ONE date
 * with the time(s) it should be, a reason and optional evidence → the line manager (else HR with attendance.approve)
 * decides in the central inbox. Approval never touches raw punches — the day result uses the approved time and
 * shows it came from a correction; the request keeps the before/after values.
 */
final class CorrectionService
{
    public const KINDS = ['missing_in', 'missing_out', 'wrong_time', 'other'];

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

    /**
     * Checks a request without saving.
     *
     * @param array<string, mixed> $employee with job
     * @param array<string, string> $in work_date, kind, proposed_in, proposed_out, reason
     * @return array{date: string, kind: string, in: ?string, out: ?string, reason: string, job: array<string, mixed>, day: ?array<string, mixed>}
     */
    public function evaluate(array $employee, array $in, ?int $exceptId = null): array
    {
        $errors = [];
        $att = $this->att();
        $today = $att->today();
        $date = (string) ($in['work_date'] ?? '');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $job = null;
        if ($d === false || $d->format('Y-m-d') !== $date) {
            $errors['work_date'] = 'attendance.error.date';
        } elseif ($date > $today) {
            $errors['work_date'] = 'attendance.error.future_date';
        } else {
            $job = EmployeeService::of($this->app)->employment((int) $employee['id'], $date);
            if ($job === null) {
                $errors['work_date'] = 'attendance.error.not_employed_then';
            } else {
                if (PeriodService::of($this->app)->frozen((int) $job['legal_entity_id'], $date)) {
                    $errors['work_date'] = 'attendance.error.period_closed';
                }
                $sched = $att->schedule((int) $job['legal_entity_id'], $today);
                $max = $sched['value']['correction_max_days'] ?? null;
                if ($max !== null && $date < EmployeeService::ymd($today)->modify('-' . (int) $max . ' days')->format('Y-m-d')) {
                    $errors['work_date'] = 'attendance.error.too_old';
                }
            }
        }
        $kind = in_array($in['kind'] ?? '', self::KINDS, true) ? (string) $in['kind'] : '';
        if ($kind === '') {
            $errors['kind'] = 'attendance.error.kind';
        }
        $pin = trim((string) ($in['proposed_in'] ?? ''));
        $pout = trim((string) ($in['proposed_out'] ?? ''));
        $mi = $pin === '' ? null : WorkSchedule::minutes($pin);
        $mo = $pout === '' ? null : WorkSchedule::minutes($pout);
        if ($pin !== '' && $mi === null) {
            $errors['proposed_in'] = 'attendance.error.time';
        }
        if ($pout !== '' && $mo === null) {
            $errors['proposed_out'] = 'attendance.error.time';
        }
        if ($pin === '' && $pout === '') {
            $errors['proposed_in'] = 'attendance.error.need_time';
        }
        if ($kind === 'missing_in' && $pin === '') {
            $errors['proposed_in'] = 'attendance.error.need_in';
        }
        if ($kind === 'missing_out' && $pout === '') {
            $errors['proposed_out'] = 'attendance.error.need_out';
        }
        if ($mi !== null && $mo !== null && $mo <= $mi) {
            $errors['proposed_out'] = 'attendance.error.out_before_in';
        }
        $reason = trim((string) ($in['reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            $errors['reason'] = 'attendance.error.reason';
        }
        if (!isset($errors['work_date'])) {
            $open = $this->app->db()->scalar(
                "SELECT id FROM att_corrections WHERE employee_id = ? AND work_date = ? AND status IN ('pending','returned')" . ($exceptId !== null ? ' AND id <> ?' : ''),
                array_merge([$employee['id'], $date], $exceptId !== null ? [$exceptId] : [])
            );
            if ($open !== null) {
                $errors['work_date'] = 'attendance.error.open_request';
            }
        }
        if ($errors !== [] || $job === null) {
            throw new ValidationException($errors);
        }
        $att->recalc((int) $employee['id'], $date, $date);
        $day = $this->app->db()->one('SELECT * FROM att_days WHERE employee_id = ? AND work_date = ?', [$employee['id'], $date]);
        return ['date' => $date, 'kind' => $kind, 'in' => $mi !== null ? WorkSchedule::hm($mi) : null, 'out' => $mo !== null ? WorkSchedule::hm($mo) : null,
            'reason' => mb_substr($reason, 0, 500), 'job' => $job, 'day' => $day];
    }

    /** @param array<string, string> $in */
    public function submit(Authorizer $az, array $in, ?UploadedFile $file): string
    {
        $employee = $this->att()->self($az);
        $db = $this->app->db();
        return $db->transactional(function () use ($az, $in, $file, $employee, $db): string {
            $db->one('SELECT id FROM employees WHERE id = ? FOR UPDATE', [$employee['id']]);
            $ev = $this->evaluate($employee, $in);
            $doc = $this->storeEvidence($az, (int) $ev['job']['legal_entity_id'], $file, null);
            $pid = Ulid::generate($this->app->clock);
            $now = $this->now();
            $db->run(
                "INSERT INTO att_corrections (public_id, created_at, created_by, updated_at, updated_by, employee_id, legal_entity_id, site_id, department_id, work_date, kind,
                    proposed_in, proposed_out, before_in, before_out, reason, document_pid, status, requested_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)",
                [$pid, $now, $az->userId, $now, $az->userId, $employee['id'], $ev['job']['legal_entity_id'], $ev['job']['site_id'], $ev['job']['department_id'], $ev['date'], $ev['kind'],
                    $ev['in'], $ev['out'], $ev['day']['first_in'] ?? null, $ev['day']['last_out'] ?? null, $ev['reason'], $doc, $az->userId]
            );
            $inst = $this->app->workflow()->start(
                'attendance.correction',
                $pid,
                $this->title($employee, $ev['date']),
                (int) $ev['job']['legal_entity_id'],
                $az->userId,
                $ev['job']['site_id'] !== null ? (int) $ev['job']['site_id'] : null,
                $ev['job']['department_id'] !== null ? (int) $ev['job']['department_id'] : null
            );
            $db->run('UPDATE att_corrections SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE public_id = ?', [$inst, $pid]);
            $this->app->audit()->record('attendance.correction_requested', 'success', 'att_correction', $pid, (int) $ev['job']['legal_entity_id'], null, [
                'date' => $ev['date'], 'kind' => $ev['kind'], 'in' => $ev['in'], 'out' => $ev['out'], 'evidence' => $doc !== null,
            ]);
            return $pid;
        });
    }

    /** @param array<string, string> $in */
    public function resubmit(Authorizer $az, string $pid, array $in, ?UploadedFile $file): void
    {
        $employee = $this->att()->self($az);
        $db = $this->app->db();
        $db->transactional(function () use ($az, $pid, $in, $file, $employee, $db): void {
            $c = $db->one('SELECT * FROM att_corrections WHERE public_id = ? FOR UPDATE', [$pid]) ?? throw HttpException::notFound();
            if ((int) $c['employee_id'] !== (int) $employee['id']) {
                throw HttpException::notFound();
            }
            if ($c['status'] !== 'returned') {
                throw new BusinessException('attendance.error.not_returned', [], 409);
            }
            $in['work_date'] = (string) $c['work_date']; // the date of a request does not change
            $ev = $this->evaluate($employee, $in, (int) $c['id']);
            $doc = $this->storeEvidence($az, (int) $c['legal_entity_id'], $file, $c['document_pid'] !== null ? (string) $c['document_pid'] : null);
            $db->run(
                'UPDATE att_corrections SET kind = ?, proposed_in = ?, proposed_out = ?, reason = ?, document_pid = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                [$ev['kind'], $ev['in'], $ev['out'], $ev['reason'], $doc, $this->now(), $az->userId, $c['id']]
            );
            $inst = (string) $db->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$c['workflow_instance_id']]);
            $this->app->workflow()->resubmit($inst, $az->userId, $ev['reason']);
            $this->app->audit()->record('attendance.correction_resubmitted', 'success', 'att_correction', $pid, (int) $c['legal_entity_id'], null, ['in' => $ev['in'], 'out' => $ev['out']]);
        });
    }

    /** The employee withdraws a request that is still waiting (or was returned). */
    public function withdraw(Authorizer $az, string $pid, string $reason): void
    {
        $c = $this->correction($az, $pid);
        if ($c['access'] !== 'own' || !in_array($c['status'], ['pending', 'returned'], true)) {
            throw new BusinessException('attendance.error.cannot_withdraw', [], 409);
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $inst = (string) $this->app->db()->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$c['workflow_instance_id']]);
        $this->app->workflow()->cancel($inst, $az->userId, mb_substr(trim($reason), 0, 300)); // onClosed → cancelled
        $this->app->audit()->record('attendance.correction_withdrawn', 'success', 'att_correction', $pid, (int) $c['legal_entity_id'], trim($reason));
    }

    // ---- workflow subject callbacks --------------------------------------------------------------------------

    public function onApproved(string $pid, int $approverId): void
    {
        $db = $this->app->db();
        $c = $db->one('SELECT * FROM att_corrections WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        if ($c['status'] === 'approved') {
            return;
        }
        if ($c['status'] !== 'pending') {
            throw new BusinessException('attendance.error.not_pending', [], 409);
        }
        $this->app->locks()->assertRangeWritable(PeriodService::LOCK_TYPE, (string) $c['work_date'], (string) $c['work_date'], (int) $c['legal_entity_id'], null, 'ui', $approverId);
        $db->run("UPDATE att_corrections SET status = 'approved', decided_at = ?, decided_by = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $approverId, $this->now(), $c['id']]);
        $this->att()->forget();
        $this->att()->recalc((int) $c['employee_id'], (string) $c['work_date'], (string) $c['work_date']);
        $after = $db->one('SELECT first_in, last_out, status FROM att_days WHERE employee_id = ? AND work_date = ?', [$c['employee_id'], $c['work_date']]);
        $this->app->audit()->record('attendance.correction_approved', 'success', 'att_correction', $pid, (int) $c['legal_entity_id'], null, [
            'date' => $c['work_date'], 'before' => ['in' => $c['before_in'], 'out' => $c['before_out']], 'after' => $after,
        ], $approverId);
    }

    public function onClosed(string $pid, string $outcome): void
    {
        $status = ['rejected' => 'rejected', 'returned' => 'returned', 'cancelled' => 'cancelled'][$outcome] ?? null;
        if ($status !== null) {
            $this->app->db()->run(
                "UPDATE att_corrections SET status = ?, decided_at = ?, updated_at = ?, row_version = row_version + 1 WHERE public_id = ? AND status IN ('pending','returned')",
                [$status, $this->now(), $this->now(), $pid]
            );
        }
    }

    public function onResubmitted(string $pid): void
    {
        $this->app->db()->run("UPDATE att_corrections SET status = 'pending', updated_at = ?, row_version = row_version + 1 WHERE public_id = ? AND status = 'returned'", [$this->now(), $pid]);
    }

    /** @return list<int> */
    public function managerUserIds(string $pid): array
    {
        $emp = $this->app->db()->scalar('SELECT employee_id FROM att_corrections WHERE public_id = ?', [$pid]);
        return $emp === null ? [] : EmployeeService::of($this->app)->managerUserIds((int) $emp, $this->att()->today());
    }

    // ---- reading ------------------------------------------------------------------------------------------------

    /**
     * A correction the user may see: the employee, the line manager, an approver of its task, or HR in scope.
     *
     * @return array<string, mixed>
     */
    public function correction(Authorizer $az, string $pid): array
    {
        $db = $this->app->db();
        $c = $db->one(
            'SELECT c.*, e.public_id AS employee_pid, e.employee_code, e.name_th, e.name_en, e.preferred_name, w.public_id AS instance_pid
               FROM att_corrections c JOIN employees e ON e.id = c.employee_id LEFT JOIN workflow_instances w ON w.id = c.workflow_instance_id WHERE c.public_id = ?',
            [$pid]
        ) ?? throw HttpException::notFound();
        $access = $this->att()->accessTo($az, ['id' => $c['employee_id']]);
        if ($access === null && $c['workflow_instance_id'] !== null && $db->scalar('SELECT id FROM workflow_tasks WHERE instance_id = ? AND assignee_id = ?', [$c['workflow_instance_id'], $az->userId]) !== null) {
            $access = 'approver';
        }
        if ($access === null) {
            throw HttpException::notFound();
        }
        $c['access'] = $access;
        return $c;
    }

    /** @return list<array<string, mixed>> corrections of an employee (newest first) */
    public function forEmployee(int $employeeId, ?string $date = null, int $limit = 50): array
    {
        return $this->app->db()->all(
            'SELECT c.*, u.display_name AS decided_name FROM att_corrections c LEFT JOIN users u ON u.id = c.decided_by WHERE c.employee_id = ?' . ($date !== null ? ' AND c.work_date = ?' : '')
            . ' ORDER BY c.work_date DESC, c.id DESC LIMIT ' . (int) $limit,
            $date !== null ? [$employeeId, $date] : [$employeeId]
        );
    }

    /** @return array{name: string, bytes: string, mime: string} */
    public function evidence(Authorizer $az, string $pid): array
    {
        $c = $this->correction($az, $pid);
        $hr = EmployeeService::covers($az->scope('attendance.manage'), $c);
        if ($c['document_pid'] === null || !(in_array($c['access'], ['own', 'manager', 'approver'], true) || $hr)) {
            throw HttpException::notFound();
        }
        $doc = $this->app->documents()->moduleRead((string) $c['document_pid'], 'attendance', $az->userId, 'attendance correction ' . $pid, true);
        return ['name' => (string) $doc['filename'], 'bytes' => (string) $doc['bytes'], 'mime' => (string) $doc['mime']];
    }

    /**
     * What the approver needs (approval page and request page).
     *
     * @return array{rows: list<array{0: string, 1: string}>, link: ?string}
     */
    public function describe(string $pid): array
    {
        $t = $this->app->translator();
        $db = $this->app->db();
        $c = $db->one('SELECT c.*, e.employee_code, e.name_th, e.name_en, e.preferred_name FROM att_corrections c JOIN employees e ON e.id = c.employee_id WHERE c.public_id = ?', [$pid]);
        if ($c === null) {
            return ['rows' => [], 'link' => null];
        }
        $loc = $t->locale();
        $tz = $this->att()->siteTz($c['site_id'] !== null ? (int) $c['site_id'] : null);
        $time = static function (?string $utc) use ($tz): string {
            $d = $utc !== null ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($utc, 0, 19), new \DateTimeZone('UTC')) : false;
            return $d === false ? '—' : $d->setTimezone($tz)->format('H:i');
        };
        $raw = array_map(
            static fn (array $p): string => $p['at']->setTimezone($tz)->format('H:i'),
            $this->att()->rawPunches((int) $c['employee_id'], (string) $c['work_date'], (string) $c['work_date'])
        );
        $rows = [
            [$t->get('attendance.field.employee'), $c['employee_code'] . ' · ' . EmployeeService::of($this->app)->displayName($c)],
            [$t->get('attendance.field.date'), DateFormat::day((string) $c['work_date'], $loc)],
            [$t->get('attendance.field.kind'), $t->get(match ((string) $c['kind']) {
                'missing_in' => 'attendance.kind.missing_in', 'missing_out' => 'attendance.kind.missing_out', 'wrong_time' => 'attendance.kind.wrong_time', default => 'attendance.kind.other',
            })],
            [$t->get('attendance.field.raw_punches'), $raw === [] ? $t->get('attendance.no_punches') : implode(', ', $raw)],
            [$t->get('attendance.field.before'), $time($c['before_in'] !== null ? (string) $c['before_in'] : null) . ' → ' . $time($c['before_out'] !== null ? (string) $c['before_out'] : null)],
            [$t->get('attendance.field.proposed'), ($c['proposed_in'] !== null ? substr((string) $c['proposed_in'], 0, 5) : '—') . ' → ' . ($c['proposed_out'] !== null ? substr((string) $c['proposed_out'], 0, 5) : '—')],
            [$t->get('attendance.field.reason'), (string) $c['reason']],
            [$t->get('attendance.field.evidence'), $c['document_pid'] !== null ? $t->get('attendance.evidence_attached') : $t->get('attendance.evidence_none')],
        ];
        return ['rows' => $rows, 'link' => '/attendance/c/' . $pid];
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

    private function storeEvidence(Authorizer $az, int $legalEntityId, ?UploadedFile $file, ?string $existing): ?string
    {
        if ($file === null || (!$file->ok() && $file->errorKey() === 'file.error.missing')) {
            return $existing;
        }
        if (!$file->ok()) {
            throw new ValidationException(['evidence' => $file->errorKey()]);
        }
        try {
            $stored = $this->app->documents()->storeGenerated($az->userId, 'attendance.evidence', $file->bytes(), $file->safeName(), [
                'title' => 'Attendance correction evidence', 'legal_entity_id' => $legalEntityId,
            ], 'upload', true);
        } catch (ValidationException $e) {
            throw new ValidationException(['evidence' => array_values($e->errors)[0] ?? 'file.error.type']);
        }
        return (string) $stored['document'];
    }

    /** @param array<string, mixed> $employee */
    private function title(array $employee, string $date): string
    {
        return $this->app->translator()->get('attendance.wf_title', ['name' => EmployeeService::of($this->app)->displayName($employee), 'date' => DateFormat::day($date, $this->app->translator()->locale())]);
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }
}
