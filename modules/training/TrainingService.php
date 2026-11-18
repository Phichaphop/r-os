<?php

declare(strict_types=1);

namespace Blue\Modules\Training;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;
use Blue\Modules\Employees\PersonnelFileService;

/**
 * M10 Training (phase 5E-3, DEC-78).
 *
 *  course      code, title, category, hours, pass mark (none = attendance only), certificate validity in months
 *              (none = no expiry), manager approval for self-enrolment, prerequisite course, positions that must have it
 *              (training matrix).
 *  session     dates and times, place, trainer, seats, cost, last day to enrol: planned → open → closed → completed,
 *              or cancelled with a reason (everyone enrolled is told; their places are cancelled).
 *  enrolment   an employee enrols in an open session (or HR enrols people): prerequisite passed, no clash with another
 *              session they are in, seats → enrolled, else waitlisted; approval courses go to the line manager first
 *              (pending). Cancelling frees the place for the first on the waitlist. Never twice in a session.
 *  check-in    HR scans the e-ID card QR (the reader types the card link) or types the employee code — or ticks the list;
 *              scanning twice changes nothing. Only enrolled people can be checked in.
 *  result      attended → passed (attendance-only course, or score ≥ pass mark) / failed; enrolled but not attended →
 *              no-show. A certificate (running number, expiry from the validity) is issued for passed people — as a
 *              frozen A4 document; re-issuing revokes the former one with a reason.
 *  reminders   enrolled people are reminded the day before; certificates are flagged 30 days before they expire.
 */
final class TrainingService
{
    public const DONE = ['passed', 'failed', 'no_show'];
    public const TAKING = ['pending', 'enrolled', 'waitlisted', 'attended'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    public function today(): string
    {
        return EmployeeService::of($this->app)->today();
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    public static function titleKey(string $title): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', $title));
    }

    /** @return list<int> */
    public function companies(Authorizer $az): array
    {
        $scope = $az->scope('training.manage');
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $scope->coversLegalEntity($id)));
    }

    // ---- courses ------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function courses(int $le, bool $activeOnly = false): array
    {
        return $this->app->db()->all(
            'SELECT c.*, p.code AS prereq_code, (SELECT COUNT(*) FROM trn_sessions s WHERE s.course_id = c.id) AS sessions FROM trn_courses c LEFT JOIN trn_courses p ON p.id = c.prerequisite_id
              WHERE c.legal_entity_id = ?' . ($activeOnly ? " AND c.status = 'active'" : '') . ' ORDER BY c.status, c.code',
            [$le]
        );
    }

    /** @param array<string, string> $in */
    public function saveCourse(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        if (!$az->scope('training.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $cur = $pid !== null ? $db->one('SELECT * FROM trn_courses WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]) : null;
        if ($pid !== null && $cur === null) {
            throw HttpException::notFound();
        }
        $errors = [];
        $code = strtoupper(trim($in['code'] ?? ''));
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,29}$/', $code) !== 1) {
            $errors['code'] = 'training.error.code';
        } elseif ($db->scalar('SELECT id FROM trn_courses WHERE legal_entity_id = ? AND code = ? AND id <> ?', [$le, $code, (int) ($cur['id'] ?? 0)]) !== null) {
            $errors['code'] = 'training.error.code_taken';
        }
        $title = mb_substr(trim($in['title'] ?? ''), 0, 200);
        if (mb_strlen($title) < 3) {
            $errors['title'] = 'training.error.title';
        }
        $hours = trim($in['hours'] ?? '');
        if (preg_match('/^\d{1,3}(\.\d)?$/', $hours) !== 1 || (float) $hours <= 0) {
            $errors['hours'] = 'training.error.hours';
        }
        $pass = trim($in['pass_score'] ?? '');
        if ($pass !== '' && (!ctype_digit($pass) || (int) $pass > 100)) {
            $errors['pass_score'] = 'training.error.pass_score';
        }
        $valid = trim($in['valid_months'] ?? '');
        if ($valid !== '' && (!ctype_digit($valid) || (int) $valid < 1 || (int) $valid > 120)) {
            $errors['valid_months'] = 'training.error.valid_months';
        }
        $prereq = null;
        if (($in['prerequisite'] ?? '') !== '') {
            $prereq = $db->scalar('SELECT id FROM trn_courses WHERE public_id = ? AND legal_entity_id = ?', [$in['prerequisite'], $le]);
            if ($prereq === null || (int) $prereq === (int) ($cur['id'] ?? 0)) {
                $errors['prerequisite'] = 'form.invalid_choice';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $titles = implode(', ', array_values(array_unique(array_filter(array_map(static fn (string $x): string => mb_substr(trim($x), 0, 120), explode(',', (string) ($in['required_titles'] ?? ''))), static fn (string $x): bool => $x !== ''))));
        $vals = [$code, $title, mb_substr(trim($in['category'] ?? ''), 0, 60) ?: null, mb_substr(trim($in['description'] ?? ''), 0, 4000) ?: null, mb_substr(trim($in['objectives'] ?? ''), 0, 4000) ?: null,
            (float) $hours, $pass !== '' ? (int) $pass : null, $valid !== '' ? (int) $valid : null, ($in['needs_approval'] ?? '') === '1' ? 1 : 0, $prereq !== null ? (int) $prereq : null, mb_substr($titles, 0, 500)];
        $now = $this->now();
        if ($cur !== null) {
            $db->run('UPDATE trn_courses SET code = ?, title = ?, category = ?, description = ?, objectives = ?, hours = ?, pass_score = ?, valid_months = ?, needs_approval = ?, prerequisite_id = ?, required_titles = ?,
                        status = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [...$vals, ($in['status'] ?? '') === 'archived' ? 'archived' : 'active', $now, $az->userId, $cur['id']]);
            $this->app->audit()->record('training.course_saved', 'success', 'trn_course', (string) $pid, $le, null, ['code' => $code]);
            return (string) $pid;
        }
        $pid = Ulid::generate($this->app->clock);
        $db->run('INSERT INTO trn_courses (code, title, category, description, objectives, hours, pass_score, valid_months, needs_approval, prerequisite_id, required_titles, public_id, created_at, created_by, updated_at, updated_by, legal_entity_id)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$vals, $pid, $now, $az->userId, $now, $az->userId, $le]);
        $this->app->audit()->record('training.course_created', 'success', 'trn_course', $pid, $le, null, ['code' => $code]);
        return $pid;
    }

    // ---- sessions -----------------------------------------------------------------------------------------------------------

    /** @param array<string, string> $in course, start_date, end_date, start_time, end_time, location, trainer, seats, cost, enroll_until */
    public function saveSession(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        if (!$az->scope('training.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $cur = $pid !== null ? $db->one('SELECT * FROM trn_sessions WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]) : null;
        if ($pid !== null && ($cur === null || in_array($cur['status'], ['completed', 'cancelled'], true))) {
            throw HttpException::notFound();
        }
        $errors = [];
        $course = $db->one("SELECT * FROM trn_courses WHERE public_id = ? AND legal_entity_id = ? AND status = 'active'", [$in['course'] ?? '', $le]);
        if ($course === null) {
            $errors['course'] = 'training.error.course';
        }
        $d1 = trim($in['start_date'] ?? '');
        $d2 = trim($in['end_date'] ?? '') ?: $d1;
        if (!$this->isDate($d1)) {
            $errors['start_date'] = 'form.invalid_date';
        }
        if (!$this->isDate($d2) || $d2 < $d1) {
            $errors['end_date'] = 'training.error.end_date';
        }
        $t1 = trim($in['start_time'] ?? '');
        $t2 = trim($in['end_time'] ?? '');
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t1) !== 1 || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t2) !== 1 || $t2 <= $t1) {
            $errors['end_time'] = 'training.error.time';
        }
        $place = mb_substr(trim($in['location'] ?? ''), 0, 200);
        if (mb_strlen($place) < 2) {
            $errors['location'] = 'training.error.location';
        }
        $seats = trim($in['seats'] ?? '');
        $taken = $cur !== null ? (int) $db->scalar("SELECT COUNT(*) FROM trn_enrollments WHERE session_id = ? AND status IN ('enrolled','attended')", [$cur['id']]) : 0;
        if (!ctype_digit($seats) || (int) $seats < 1 || (int) $seats > 2000 || (int) $seats < $taken) {
            $errors['seats'] = 'training.error.seats';
        }
        $cost = str_replace(',', '', trim($in['cost'] ?? ''));
        if ($cost !== '' && preg_match('/^\d{1,10}(\.\d{1,2})?$/', $cost) !== 1) {
            $errors['cost'] = 'training.error.cost';
        }
        $until = trim($in['enroll_until'] ?? '');
        if ($until !== '' && (!$this->isDate($until) || ($this->isDate($d1) && $until > $d1))) {
            $errors['enroll_until'] = 'training.error.enroll_until';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $vals = [(int) $course['id'], $d1, $d2, $t1, $t2, $place, mb_substr(trim($in['trainer'] ?? ''), 0, 150) ?: null, (int) $seats, $cost !== '' ? (float) $cost : null, $until !== '' ? $until : null];
        $now = $this->now();
        if ($cur !== null) {
            $db->run(
                'UPDATE trn_sessions SET course_id = ?, start_date = ?, end_date = ?, start_time = ?, end_time = ?, location = ?, trainer = ?, seats = ?, cost = ?, enroll_until = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                [...$vals, $now, $az->userId, $cur['id']]
            );
            $this->promote((int) $cur['id']);
            return (string) $pid;
        }
        $pid = Ulid::generate($this->app->clock);
        $db->run('INSERT INTO trn_sessions (course_id, start_date, end_date, start_time, end_time, location, trainer, seats, cost, enroll_until, public_id, created_at, created_by, updated_at, updated_by, legal_entity_id)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$vals, $pid, $now, $az->userId, $now, $az->userId, $le]);
        $this->app->audit()->record('training.session_created', 'success', 'trn_session', $pid, $le, null, ['course' => $course['code']]);
        return $pid;
    }

    /** @return array<string, mixed> a session (+ course, counts) for HR of its company */
    public function managedSession(Authorizer $az, string $pid): array
    {
        $s = $this->session($pid);
        if ($s === null || !$az->scope('training.manage')->coversLegalEntity((int) $s['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        return $s;
    }

    /** @return array<string, mixed>|null */
    public function session(string $pid): ?array
    {
        return $this->app->db()->one(
            "SELECT s.*, c.code, c.title, c.hours, c.pass_score, c.valid_months, c.needs_approval, c.prerequisite_id, c.category, c.description, c.objectives, c.public_id AS course_pid,
                    (SELECT COUNT(*) FROM trn_enrollments e WHERE e.session_id = s.id AND e.status IN ('enrolled','attended','passed','failed','no_show')) AS taken,
                    (SELECT COUNT(*) FROM trn_enrollments e WHERE e.session_id = s.id AND e.status = 'waitlisted') AS waiting,
                    (SELECT COUNT(*) FROM trn_enrollments e WHERE e.session_id = s.id AND e.status = 'pending') AS pending
               FROM trn_sessions s JOIN trn_courses c ON c.id = s.course_id WHERE s.public_id = ?",
            [$pid]
        );
    }

    /** @return list<array<string, mixed>> sessions of a company from a date */
    public function sessions(int $le, string $from, bool $openOnly = false): array
    {
        return $this->app->db()->all(
            "SELECT s.*, c.code, c.title, c.hours, c.needs_approval, c.category,
                    (SELECT COUNT(*) FROM trn_enrollments e WHERE e.session_id = s.id AND e.status IN ('enrolled','attended','passed','failed','no_show')) AS taken,
                    (SELECT COUNT(*) FROM trn_enrollments e WHERE e.session_id = s.id AND e.status = 'waitlisted') AS waiting
               FROM trn_sessions s JOIN trn_courses c ON c.id = s.course_id WHERE s.legal_entity_id = ? AND s.end_date >= ?" . ($openOnly ? " AND s.status = 'open'" : '') . ' ORDER BY s.start_date, s.start_time LIMIT 200',
            [$le, $from]
        );
    }

    public function setSessionStatus(Authorizer $az, string $pid, string $to, string $reason = ''): void
    {
        $s = $this->managedSession($az, $pid);
        $from = (string) $s['status'];
        $allowed = ['open' => ['planned', 'closed'], 'closed' => ['open'], 'completed' => ['open', 'closed'], 'cancelled' => ['planned', 'open', 'closed']];
        if (!in_array($from, $allowed[$to] ?? [], true)) {
            throw new BusinessException('training.error.state', [], 409);
        }
        $db = $this->app->db();
        if ($to === 'completed' && (int) $db->scalar("SELECT COUNT(*) FROM trn_enrollments WHERE session_id = ? AND status IN ('enrolled','attended')", [$s['id']]) > 0) {
            throw new BusinessException('training.error.not_evaluated', [], 409);
        }
        if ($to === 'cancelled') {
            $reason = mb_substr(trim($reason), 0, 300);
            if (mb_strlen($reason) < 5) {
                throw new ValidationException(['reason' => 'training.error.reason']);
            }
            foreach ($db->all("SELECT * FROM trn_enrollments WHERE session_id = ? AND status IN ('pending','enrolled','waitlisted')", [$s['id']]) as $e) {
                $db->run("UPDATE trn_enrollments SET status = 'cancelled', cancel_reason = ?, updated_at = ? WHERE id = ?", [$reason, $this->now(), $e['id']]);
                if ($e['user_id'] !== null) {
                    $this->app->notifier()->notify((int) $e['user_id'], 'training.session', 'training.notify.cancelled', ['title' => (string) $s['title'], 'date' => (string) $s['start_date']], '/training', 'trn.cancel:' . $s['id'] . ':' . $e['id']);
                }
            }
        }
        $db->run('UPDATE trn_sessions SET status = ?, cancel_reason = ?, updated_at = ?, updated_by = ? WHERE id = ?', [$to, $to === 'cancelled' ? $reason : $s['cancel_reason'], $this->now(), $az->userId, $s['id']]);
        $this->app->audit()->record('training.session_' . $to, 'success', 'trn_session', $pid, (int) $s['legal_entity_id'], $to === 'cancelled' ? $reason : null);
    }

    // ---- enrolment ----------------------------------------------------------------------------------------------------------

    /** Enrols the signed-in employee in an open session. Returns the resulting status. */
    public function enrollSelf(Authorizer $az, string $sessionPid): string
    {
        if (!$az->can('training.enroll')) {
            throw HttpException::forbidden();
        }
        $me = EmployeeService::of($this->app)->forUser($az->userId);
        $s = $this->session($sessionPid);
        if ($me === null || $me['job'] === null || $s === null || (int) $me['job']['legal_entity_id'] !== (int) $s['legal_entity_id']) {
            throw HttpException::notFound();
        }
        if ($s['status'] !== 'open' || ($s['enroll_until'] !== null && $this->today() > $s['enroll_until']) || $this->today() > $s['start_date']) {
            throw new BusinessException('training.error.closed', [], 409);
        }
        return $this->enroll($s, (int) $me['id'], $az->userId, 'self', (int) $s['needs_approval'] === 1);
    }

    /**
     * HR enrols people by employee code (one per line or comma). Approval is not needed (HR decides); seats, clashes
     * and prerequisites still apply.
     *
     * @return array{done: list<string>, failed: array<string, string>}
     */
    public function enrollByHr(Authorizer $az, string $sessionPid, string $codes): array
    {
        $s = $this->managedSession($az, $sessionPid);
        if (!in_array($s['status'], ['planned', 'open', 'closed'], true)) {
            throw new BusinessException('training.error.state', [], 409);
        }
        $out = ['done' => [], 'failed' => []];
        foreach (array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', $codes) ?: []))) as $code) {
            $emp = $this->app->db()->one(
                "SELECT e.id FROM employees e JOIN employment_history h ON h.employee_id = e.id AND h.effective_to IS NULL WHERE e.employee_code = ? AND h.legal_entity_id = ? AND e.status IN ('active','on_leave','pre_hire')",
                [mb_substr($code, 0, 30), $s['legal_entity_id']]
            );
            if ($emp === null) {
                $out['failed'][$code] = 'training.error.unknown_employee';
                continue;
            }
            try {
                $uid = $this->app->db()->scalar("SELECT id FROM users WHERE employee_id = ? AND status = 'active' LIMIT 1", [$emp['id']]);
                $this->enroll($s, (int) $emp['id'], $uid !== null ? (int) $uid : null, 'hr', false, $az->userId);
                $out['done'][] = $code;
            } catch (BusinessException $e) {
                $out['failed'][$code] = $e->messageKey;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $s */
    private function enroll(array $s, int $employeeId, ?int $userId, string $source, bool $approval, ?int $actor = null): string
    {
        $db = $this->app->db();
        $ex = $db->one('SELECT * FROM trn_enrollments WHERE session_id = ? AND employee_id = ?', [$s['id'], $employeeId]);
        if ($ex !== null && !in_array($ex['status'], ['cancelled', 'rejected'], true)) {
            throw new BusinessException('training.error.already', [], 409);
        }
        if (
            $s['prerequisite_id'] !== null && $db->scalar(
                "SELECT 1 FROM trn_enrollments e JOIN trn_sessions x ON x.id = e.session_id WHERE e.employee_id = ? AND x.course_id = ? AND e.status = 'passed' LIMIT 1",
                [$employeeId, $s['prerequisite_id']]
            ) === null
        ) {
            throw new BusinessException('training.error.prerequisite', [], 409);
        }
        if ($this->clash($s, $employeeId)) {
            throw new BusinessException('training.error.clash', [], 409);
        }
        $status = $approval ? 'pending' : ($this->freeSeats((int) $s['id'], (int) $s['seats']) > 0 ? 'enrolled' : 'waitlisted');
        $now = $this->now();
        if ($ex !== null) {
            $db->run('UPDATE trn_enrollments SET status = ?, source = ?, user_id = ?, cancel_reason = NULL, created_at = ?, created_by = ?, updated_at = ? WHERE id = ?', [$status, $source, $userId, $now, $actor ?? $userId, $now, $ex['id']]);
            $pid = (string) $ex['public_id'];
        } else {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO trn_enrollments (public_id, session_id, employee_id, user_id, status, source, created_at, created_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $s['id'], $employeeId, $userId, $status, $source, $now, $actor ?? $userId, $now]
            );
        }
        if ($approval) {
            $inst = $this->app->workflow()->start('training.enroll', $pid, $this->app->translator()->get('training.title_line', ['title' => (string) $s['title'], 'date' => (string) $s['start_date']]), (int) $s['legal_entity_id'], (int) $userId);
            $db->run('UPDATE trn_enrollments SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE public_id = ?', [$inst, $pid]);
        }
        if ($userId !== null && $source === 'hr') {
            $this->app->notifier()->notify($userId, 'training.enrolment', 'training.notify.' . $status, ['title' => (string) $s['title'], 'date' => (string) $s['start_date']], '/training', 'trn.enr:' . $pid . ':' . $status);
        }
        $this->app->audit()->record('training.enrolled', 'success', 'trn_enrollment', $pid, (int) $s['legal_entity_id'], null, ['status' => $status, 'source' => $source]);
        return $status;
    }

    /** @param array<string, mixed> $s */
    private function clash(array $s, int $employeeId): bool
    {
        foreach (
            $this->app->db()->all(
                "SELECT x.start_date, x.end_date, x.start_time, x.end_time FROM trn_enrollments e JOIN trn_sessions x ON x.id = e.session_id
                  WHERE e.employee_id = ? AND e.status IN ('pending','enrolled','waitlisted','attended') AND x.id <> ? AND x.status IN ('planned','open','closed')",
                [$employeeId, $s['id']]
            ) as $o
        ) {
            if ($o['start_date'] <= $s['end_date'] && $o['end_date'] >= $s['start_date'] && $o['start_time'] < $s['end_time'] && $o['end_time'] > $s['start_time']) {
                return true;
            }
        }
        return false;
    }

    private function freeSeats(int $sessionId, int $seats): int
    {
        return $seats - (int) $this->app->db()->scalar("SELECT COUNT(*) FROM trn_enrollments WHERE session_id = ? AND status IN ('enrolled','attended','passed','failed','no_show')", [$sessionId]);
    }

    /** Fills free seats from the waitlist (first come first served). */
    private function promote(int $sessionId): void
    {
        $db = $this->app->db();
        $s = $db->one('SELECT s.*, c.title FROM trn_sessions s JOIN trn_courses c ON c.id = s.course_id WHERE s.id = ?', [$sessionId]);
        if ($s === null || !in_array($s['status'], ['planned', 'open', 'closed'], true)) {
            return;
        }
        $free = $this->freeSeats($sessionId, (int) $s['seats']);
        foreach ($free > 0 ? $db->all("SELECT * FROM trn_enrollments WHERE session_id = ? AND status = 'waitlisted' ORDER BY updated_at, id LIMIT " . $free, [$sessionId]) : [] as $e) {
            $db->run("UPDATE trn_enrollments SET status = 'enrolled', updated_at = ? WHERE id = ? AND status = 'waitlisted'", [$this->now(), $e['id']]);
            if ($e['user_id'] !== null) {
                $this->app->notifier()->notify((int) $e['user_id'], 'training.enrolment', 'training.notify.promoted', ['title' => (string) $s['title'], 'date' => (string) $s['start_date']], '/training', 'trn.promote:' . $e['id']);
            }
        }
    }

    public function cancelMine(Authorizer $az, string $enrollmentPid): void
    {
        $db = $this->app->db();
        $e = $db->one('SELECT e.*, s.start_date, s.status AS session_status FROM trn_enrollments e JOIN trn_sessions s ON s.id = e.session_id WHERE e.public_id = ? AND e.user_id = ?', [$enrollmentPid, $az->userId]);
        if ($e === null) {
            throw HttpException::notFound();
        }
        if (!in_array($e['status'], ['pending', 'enrolled', 'waitlisted'], true) || $this->today() >= $e['start_date']) {
            throw new BusinessException('training.error.state', [], 409);
        }
        if ($e['status'] === 'pending' && $e['workflow_instance_id'] !== null) {
            $inst = (string) $db->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$e['workflow_instance_id']]);
            try {
                $this->app->workflow()->cancel($inst, $az->userId, 'cancelled by the employee');
            } catch (\Throwable) {
                // already decided — the enrolment is cancelled anyway
            }
        }
        $db->run("UPDATE trn_enrollments SET status = 'cancelled', cancel_reason = 'self', updated_at = ? WHERE id = ?", [$this->now(), $e['id']]);
        $this->promote((int) $e['session_id']);
    }

    public function onApproved(string $enrollmentPid): void
    {
        $db = $this->app->db();
        $e = $db->one("SELECT e.*, s.seats FROM trn_enrollments e JOIN trn_sessions s ON s.id = e.session_id WHERE e.public_id = ? AND e.status = 'pending'", [$enrollmentPid]);
        if ($e === null) {
            return;
        }
        $to = $this->freeSeats((int) $e['session_id'], (int) $e['seats']) > 0 ? 'enrolled' : 'waitlisted';
        $db->run('UPDATE trn_enrollments SET status = ?, updated_at = ? WHERE id = ?', [$to, $this->now(), $e['id']]);
    }

    public function onClosed(string $enrollmentPid, string $outcome): void
    {
        $this->app->db()->run("UPDATE trn_enrollments SET status = ?, updated_at = ? WHERE public_id = ? AND status = 'pending'", [$outcome === 'cancelled' ? 'cancelled' : 'rejected', $this->now(), $enrollmentPid]);
    }

    /** @return list<int> the line manager of the employee enrolling */
    public function managerUserIds(string $enrollmentPid): array
    {
        $emp = (int) $this->app->db()->scalar('SELECT employee_id FROM trn_enrollments WHERE public_id = ?', [$enrollmentPid]);
        return EmployeeService::of($this->app)->managerUserIds($emp, $this->today());
    }

    /** @return list<array<string, mixed>> */
    public function roster(int $sessionId): array
    {
        return $this->app->db()->all(
            "SELECT e.*, p.employee_code, p.name_th, d.code AS dept_code, h.position_title FROM trn_enrollments e JOIN employees p ON p.id = e.employee_id
               LEFT JOIN employment_history h ON h.employee_id = p.id AND h.effective_to IS NULL LEFT JOIN departments d ON d.id = h.department_id
              WHERE e.session_id = ? ORDER BY FIELD(e.status, 'enrolled', 'attended', 'passed', 'failed', 'no_show', 'pending', 'waitlisted', 'cancelled', 'rejected'), p.employee_code",
            [$sessionId]
        );
    }

    // ---- check-in and results ---------------------------------------------------------------------------------------------

    /**
     * Check-in by a scanned e-ID card link/token or an employee code.
     *
     * @return array{result: string, name?: string}
     */
    public function checkIn(Authorizer $az, string $sessionPid, string $input): array
    {
        $s = $this->managedSession($az, $sessionPid);
        if (!in_array($s['status'], ['open', 'closed'], true) || $this->today() < $s['start_date'] || $this->today() > $s['end_date']) {
            return ['result' => 'not_today'];
        }
        $input = trim($input);
        $empId = null;
        $method = 'code';
        if (preg_match('#(?:/eid/v/)?([A-Za-z0-9_-]{22}\.(?:p|\d{1,12})\.[A-Za-z0-9_-]{16})$#', $input, $m) === 1) {
            $method = 'qr';
            if (!$this->app->modules()->isEnabled('eid')) {
                return ['result' => 'no_eid'];
            }
            try {
                $v = \Blue\Modules\Eid\CardService::of($this->app)->verify($az, $m[1]);
            } catch (HttpException) {
                return ['result' => 'no_eid'];
            }
            if ($v['result'] !== 'valid') {
                return ['result' => 'card_' . $v['result']];
            }
            $empId = (int) $v['card']['employee_id'];
        } elseif ($input !== '') {
            $empId = $this->app->db()->scalar('SELECT id FROM employees WHERE employee_code = ?', [mb_substr($input, 0, 30)]);
            $empId = $empId !== null ? (int) $empId : null;
        }
        if ($empId === null) {
            return ['result' => 'unknown'];
        }
        $e = $this->app->db()->one('SELECT e.*, p.name_th FROM trn_enrollments e JOIN employees p ON p.id = e.employee_id WHERE e.session_id = ? AND e.employee_id = ?', [$s['id'], $empId]);
        if ($e === null || !in_array($e['status'], ['enrolled', 'attended'], true)) {
            return ['result' => 'not_enrolled'] + ($e !== null ? ['name' => (string) $e['name_th']] : []);
        }
        if ($e['status'] === 'attended') {
            return ['result' => 'already', 'name' => (string) $e['name_th']];
        }
        $this->app->db()->run(
            "UPDATE trn_enrollments SET status = 'attended', attended_at = ?, check_method = ?, checked_by = ?, updated_at = ? WHERE id = ? AND status = 'enrolled'",
            [$this->now(), $method, $az->userId, $this->now(), $e['id']]
        );
        return ['result' => 'ok', 'name' => (string) $e['name_th']];
    }

    /** @param list<int> $enrollmentIds ticked on the list */
    public function markAttended(Authorizer $az, string $sessionPid, array $enrollmentIds): int
    {
        $s = $this->managedSession($az, $sessionPid);
        if (!in_array($s['status'], ['open', 'closed'], true) || $this->today() < $s['start_date']) {
            throw new BusinessException('training.error.not_started', [], 409);
        }
        $n = 0;
        foreach ($enrollmentIds as $id) {
            $n += $this->app->db()->run(
                "UPDATE trn_enrollments SET status = 'attended', attended_at = ?, check_method = 'list', checked_by = ?, updated_at = ? WHERE id = ? AND session_id = ? AND status = 'enrolled'",
                [$this->now(), $az->userId, $this->now(), $id, $s['id']]
            )->rowCount();
        }
        return $n;
    }

    /**
     * Results: score per attended person (when the course has a pass mark); everyone still enrolled → no-show.
     *
     * @param array<int, string> $scores enrollment id => score
     */
    public function evaluate(Authorizer $az, string $sessionPid, array $scores, bool $noShows): int
    {
        $s = $this->managedSession($az, $sessionPid);
        if (!in_array($s['status'], ['open', 'closed'], true) || $this->today() < $s['start_date']) {
            throw new BusinessException('training.error.not_started', [], 409);
        }
        $db = $this->app->db();
        $errors = [];
        $n = 0;
        foreach ($db->all("SELECT * FROM trn_enrollments WHERE session_id = ? AND status IN ('attended','passed','failed')", [$s['id']]) as $e) {
            if ($s['pass_score'] === null) {
                if ($e['status'] === 'attended') {
                    $db->run("UPDATE trn_enrollments SET status = 'passed', evaluated_by = ?, evaluated_at = ?, updated_at = ? WHERE id = ?", [$az->userId, $this->now(), $this->now(), $e['id']]);
                    $n++;
                }
                continue;
            }
            $raw = trim((string) ($scores[(int) $e['id']] ?? ''));
            if ($raw === '') {
                continue;
            }
            if (preg_match('/^\d{1,3}(\.\d)?$/', $raw) !== 1 || (float) $raw > 100) {
                $errors['score_' . $e['id']] = 'training.error.score';
                continue;
            }
            if ($this->app->db()->scalar("SELECT 1 FROM trn_certificates WHERE enrollment_id = ? AND status = 'valid'", [$e['id']]) !== null) {
                continue; // a certificate stands — revoke it first to change the result
            }
            $to = (float) $raw >= (int) $s['pass_score'] ? 'passed' : 'failed';
            $db->run('UPDATE trn_enrollments SET status = ?, score = ?, evaluated_by = ?, evaluated_at = ?, updated_at = ? WHERE id = ?', [$to, (float) $raw, $az->userId, $this->now(), $this->now(), $e['id']]);
            $n++;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($noShows) {
            $n += $db->run("UPDATE trn_enrollments SET status = 'no_show', evaluated_by = ?, evaluated_at = ?, updated_at = ? WHERE session_id = ? AND status = 'enrolled'", [$az->userId, $this->now(), $this->now(), $s['id']])->rowCount();
        }
        $this->app->audit()->record('training.evaluated', 'success', 'trn_session', $sessionPid, (int) $s['legal_entity_id'], null, ['changed' => $n]);
        return $n;
    }

    // ---- certificates ---------------------------------------------------------------------------------------------------------

    /** Issues certificates for everyone passed in the session who has none valid. Returns how many. */
    public function issueCertificates(Authorizer $az, string $sessionPid): int
    {
        $s = $this->managedSession($az, $sessionPid);
        $n = 0;
        foreach ($this->app->db()->all("SELECT e.* FROM trn_enrollments e WHERE e.session_id = ? AND e.status = 'passed' AND NOT EXISTS (SELECT 1 FROM trn_certificates c WHERE c.enrollment_id = e.id AND c.status = 'valid')", [$s['id']]) as $e) {
            $this->issueOne($az, $s, $e);
            $n++;
        }
        return $n;
    }

    /**
     * @param array<string, mixed> $s
     * @param array<string, mixed> $e
     */
    private function issueOne(Authorizer $az, array $s, array $e): string
    {
        $db = $this->app->db();
        $le = (int) $s['legal_entity_id'];
        $completed = (string) $s['end_date'];
        $expires = $s['valid_months'] !== null ? EmployeeService::ymd($completed)->modify('+' . (int) $s['valid_months'] . ' months')->format('Y-m-d') : null;
        $prev = (int) $db->scalar('SELECT COALESCE(MAX(version_no), 0) FROM trn_certificates WHERE enrollment_id = ?', [$e['id']]);
        $no = $prev > 0 ? (string) $db->scalar('SELECT cert_no FROM trn_certificates WHERE enrollment_id = ? ORDER BY id LIMIT 1', [$e['id']])
            : $this->app->numbering()->next($le, null, 'training.cert', substr($completed, 0, 4), 'TRN-{YYYY}-{SEQ:5}', 'trn_enrollment', (string) $e['public_id'], $az->userId);
        $emp = $db->one('SELECT name_th, name_en, employee_code FROM employees WHERE id = ?', [$e['employee_id']]);
        $company = \Blue\Core\Org\Translations::pick((new \Blue\Core\Org\Translations($db))->many('legal_entity', [$le])[$le] ?? [], 'th');
        $html = $this->certificateHtml($company, (string) ($emp['name_th'] ?? ''), (string) ($emp['employee_code'] ?? ''), $s, $no, $prev + 1, $completed, $expires, $e['score'] !== null ? (float) $e['score'] : null);
        $doc = $this->app->documents()->storeGenerated($az->userId, 'training.certificate', $html, $no . '-v' . ($prev + 1) . '.html', [
            'title' => mb_substr($this->app->translator()->get('training.cert_title') . ' · ' . $s['title'] . ' · ' . ($emp['name_th'] ?? ''), 0, 200), 'legal_entity_id' => $le,
            'related_type' => 'trn_enrollment', 'related_id' => (string) $e['public_id'], 'subject_user_id' => $e['user_id'], 'document_number' => $no,
        ], 'system', true);
        $pid = Ulid::generate($this->app->clock);
        $db->run(
            'INSERT INTO trn_certificates (public_id, enrollment_id, cert_no, version_no, issued_at, issued_by, completed_on, expires_on, document_pid) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $e['id'], $no, $prev + 1, $this->now(), $az->userId, $completed, $expires, $doc['document']]
        );
        if ($e['user_id'] !== null) {
            $this->app->notifier()->notify((int) $e['user_id'], 'training.certificate', 'training.notify.certificate', ['title' => (string) $s['title']], '/training/c/' . $pid, 'trn.cert:' . $pid);
        }
        $this->app->audit()->record('training.certificate_issued', 'success', 'trn_certificate', $pid, $le, null, ['no' => $no, 'version' => $prev + 1]);
        return $pid;
    }

    /** @param array<string, mixed> $s */
    private function certificateHtml(string $company, string $name, string $code, array $s, string $no, int $version, string $completed, ?string $expires, ?float $score): string
    {
        $t = $this->app->translator();
        $h = static fn (string $x): string => htmlspecialchars($x, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $day = static fn (string $d): string => \Blue\Core\I18n\DateFormat::day($d, 'th', true);
        return '<!doctype html><html lang="th"><head><meta charset="utf-8"><title>' . $h($no) . '</title><style>'
            . '@page{size:A4 landscape;margin:14mm}body{font-family:"TH Sarabun New","TH SarabunPSK",Sarabun,sans-serif;color:black;margin:0}'
            . '.c{border:6px double black;padding:28mm 20mm;text-align:center;min-height:120mm}h1{font-size:34pt;margin:0 0 6mm}p{font-size:18pt;margin:2mm 0}.n{font-size:30pt;font-weight:bold;margin:8mm 0}'
            . '.s{font-size:13pt;color:dimgray;margin-top:10mm}</style></head><body><div class="c">'
            . '<p>' . $h($company) . '</p><h1>' . $h($t->get('training.cert_title')) . '</h1>'
            . '<p>' . $h($t->get('training.cert_given')) . '</p><p class="n">' . $h($name) . '</p>'
            . '<p>' . $h($t->get('training.cert_completed', ['title' => (string) $s['title'], 'hours' => rtrim(rtrim((string) $s['hours'], '0'), '.')])) . '</p>'
            . '<p>' . $h($t->get('training.cert_on', ['d' => $day($completed)])) . ($score !== null ? ' · ' . $h($t->get('training.cert_score', ['s' => (string) $score])) : '') . '</p>'
            . ($expires !== null ? '<p>' . $h($t->get('training.cert_valid', ['d' => $day($expires)])) . '</p>' : '')
            . '<p class="s">' . $h($t->get('training.cert_no', ['no' => $no, 'v' => (string) $version, 'code' => $code])) . '</p>'
            . '</div></body></html>';
    }

    public function revokeCertificate(Authorizer $az, string $certPid, string $reason, bool $reissue): ?string
    {
        $db = $this->app->db();
        $c = $db->one('SELECT c.*, e.session_id, e.public_id AS enr_pid FROM trn_certificates c JOIN trn_enrollments e ON e.id = c.enrollment_id WHERE c.public_id = ?', [$certPid]);
        if ($c === null) {
            throw HttpException::notFound();
        }
        $s = $this->managedSession($az, (string) $db->scalar('SELECT public_id FROM trn_sessions WHERE id = ?', [$c['session_id']]));
        if ($c['status'] !== 'valid') {
            throw new BusinessException('training.error.state', [], 409);
        }
        $reason = mb_substr(trim($reason), 0, 300);
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['reason' => 'training.error.reason']);
        }
        $db->run("UPDATE trn_certificates SET status = 'revoked', revoke_reason = ?, revoked_by = ?, revoked_at = ? WHERE id = ?", [$reason, $az->userId, $this->now(), $c['id']]);
        $this->app->audit()->record('training.certificate_revoked', 'success', 'trn_certificate', $certPid, (int) $s['legal_entity_id'], $reason, ['reissue' => $reissue]);
        if (!$reissue) {
            return null;
        }
        $e = $db->one('SELECT * FROM trn_enrollments WHERE id = ?', [$c['enrollment_id']]);
        if ($e === null || $e['status'] !== 'passed') {
            return null;
        }
        return $this->issueOne($az, $s, $e);
    }

    /** @return array{bytes: string, mime: string, name: string} a certificate for its owner or HR of the company */
    public function certificate(Authorizer $az, string $certPid): array
    {
        $db = $this->app->db();
        $c = $db->one('SELECT c.*, e.user_id, s.legal_entity_id FROM trn_certificates c JOIN trn_enrollments e ON e.id = c.enrollment_id JOIN trn_sessions s ON s.id = e.session_id WHERE c.public_id = ?', [$certPid]);
        if ($c === null || ((int) ($c['user_id'] ?? 0) !== $az->userId && !$az->scope('training.manage')->coversLegalEntity((int) $c['legal_entity_id']))) {
            throw HttpException::notFound();
        }
        $f = $this->app->documents()->moduleRead((string) $c['document_pid'], 'training', $az->userId, 'training certificate', true);
        return ['bytes' => $f['bytes'], 'mime' => $f['mime'], 'name' => $c['cert_no'] . '.html'];
    }

    // ---- people ---------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> an employee's training (all statuses) with certificates */
    public function history(int $employeeId): array
    {
        return $this->app->db()->all(
            "SELECT e.*, s.public_id AS session_pid, s.start_date, s.end_date, s.start_time, s.end_time, s.location, s.status AS session_status, c.code, c.title, c.hours,
                    (SELECT x.public_id FROM trn_certificates x WHERE x.enrollment_id = e.id AND x.status = 'valid' ORDER BY x.id DESC LIMIT 1) AS cert_pid,
                    (SELECT x.cert_no FROM trn_certificates x WHERE x.enrollment_id = e.id AND x.status = 'valid' ORDER BY x.id DESC LIMIT 1) AS cert_no,
                    (SELECT x.expires_on FROM trn_certificates x WHERE x.enrollment_id = e.id AND x.status = 'valid' ORDER BY x.id DESC LIMIT 1) AS cert_expires
               FROM trn_enrollments e JOIN trn_sessions s ON s.id = e.session_id JOIN trn_courses c ON c.id = s.course_id WHERE e.employee_id = ? ORDER BY s.start_date DESC, e.id DESC",
            [$employeeId]
        );
    }

    /**
     * Training matrix: people whose position needs a course × their status (valid / passed / expired / missing / booked).
     *
     * @return array{courses: list<array<string, mixed>>, rows: list<array{employee: array<string, mixed>, cells: array<int, string>}>}
     */
    public function matrix(int $le): array
    {
        $db = $this->app->db();
        $courses = array_values(array_filter($this->courses($le, true), static fn (array $c): bool => trim((string) $c['required_titles']) !== ''));
        if ($courses === []) {
            return ['courses' => [], 'rows' => []];
        }
        $need = [];
        foreach ($courses as $c) {
            foreach (explode(',', (string) $c['required_titles']) as $tt) {
                $need[self::titleKey($tt)][] = (int) $c['id'];
            }
        }
        $today = $this->today();
        $rows = [];
        foreach (
            $db->all("SELECT e.id, e.employee_code, e.name_th, e.public_id, h.position_title, d.code AS dept_code FROM employees e JOIN employment_history h ON h.employee_id = e.id AND h.effective_to IS NULL
                       LEFT JOIN departments d ON d.id = h.department_id WHERE h.legal_entity_id = ? AND e.status IN ('active','on_leave') ORDER BY d.code, e.employee_code", [$le]) as $emp
        ) {
            $required = $need[self::titleKey((string) $emp['position_title'])] ?? [];
            if ($required === []) {
                continue;
            }
            $cells = [];
            foreach ($courses as $c) {
                if (!in_array((int) $c['id'], $required, true)) {
                    $cells[(int) $c['id']] = '';
                    continue;
                }
                $best = $db->one(
                    "SELECT e.status, (SELECT MAX(x.expires_on) FROM trn_certificates x WHERE x.enrollment_id = e.id AND x.status = 'valid') AS expires,
                            (SELECT COUNT(*) FROM trn_certificates x WHERE x.enrollment_id = e.id AND x.status = 'valid') AS certs
                       FROM trn_enrollments e JOIN trn_sessions s ON s.id = e.session_id WHERE e.employee_id = ? AND s.course_id = ? AND e.status IN ('passed','enrolled','pending','waitlisted','attended')
                      ORDER BY FIELD(e.status, 'passed', 'attended', 'enrolled', 'pending', 'waitlisted'), s.end_date DESC LIMIT 1",
                    [$emp['id'], $c['id']]
                );
                $cells[(int) $c['id']] = match (true) {
                    $best === null => 'missing',
                    $best['status'] !== 'passed' => 'booked',
                    $best['expires'] !== null && $best['expires'] < $today => 'expired',
                    (int) $best['certs'] > 0 && $best['expires'] !== null && $best['expires'] <= EmployeeService::ymd($today)->modify('+30 days')->format('Y-m-d') => 'expiring',
                    default => 'valid',
                };
            }
            $rows[] = ['employee' => $emp, 'cells' => $cells];
        }
        return ['courses' => $courses, 'rows' => $rows];
    }

    /** Daily-ish: remind enrolled people the day before; flag certificates expiring in 30 days (once each). */
    public static function remindersJob(App $app): int
    {
        $svc = self::of($app);
        $db = $app->db();
        $tomorrow = EmployeeService::ymd($svc->today())->modify('+1 day')->format('Y-m-d');
        $n = 0;
        foreach (
            $db->all("SELECT e.id, e.user_id, s.start_date, s.start_time, s.location, c.title FROM trn_enrollments e JOIN trn_sessions s ON s.id = e.session_id JOIN trn_courses c ON c.id = s.course_id
                       WHERE s.start_date = ? AND s.status IN ('open','closed') AND e.status = 'enrolled' AND e.user_id IS NOT NULL", [$tomorrow]) as $r
        ) {
            $app->notifier()->notify((int) $r['user_id'], 'training.reminder', 'training.notify.tomorrow', ['title' => (string) $r['title'], 'time' => (string) $r['start_time'], 'place' => (string) $r['location']], '/training', 'trn.remind:' . $r['id']);
            $n++;
        }
        $soon = EmployeeService::ymd($svc->today())->modify('+30 days')->format('Y-m-d');
        foreach (
            $db->all("SELECT x.id, x.public_id, x.expires_on, e.user_id, s.legal_entity_id, c.title FROM trn_certificates x JOIN trn_enrollments e ON e.id = x.enrollment_id JOIN trn_sessions s ON s.id = e.session_id
                        JOIN trn_courses c ON c.id = s.course_id WHERE x.status = 'valid' AND x.expires_on IS NOT NULL AND x.expires_on BETWEEN ? AND ?", [$svc->today(), $soon]) as $r
        ) {
            $who = PersonnelFileService::of($app)->holders('training.manage', (int) $r['legal_entity_id']);
            if ($r['user_id'] !== null) {
                $who[] = (int) $r['user_id'];
            }
            foreach (array_unique($who) as $uid) {
                $app->notifier()->notify($uid, 'training.expiry', 'training.notify.expiring', ['title' => (string) $r['title'], 'date' => (string) $r['expires_on']], '/training/c/' . $r['public_id'], 'trn.exp:' . $r['id'] . ':' . $uid);
            }
            $n++;
        }
        return $n;
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
