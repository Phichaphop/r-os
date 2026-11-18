<?php

declare(strict_types=1);

namespace Blue\Modules\Shifts;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;
use Throwable;

/**
 * Shift swaps (M35, DEC-70): an employee asks a colleague to swap a published shift (same date or two dates) →
 * the colleague agrees → the approver decides (line manager, else shifts.approve of the employee's branch/unit).
 * On approval both cells must still be what they were when asked, the result must not overlap other shifts and the
 * dates must not be in a closed attendance period — otherwise the swap ends as "failed" with the reason.
 */
final class SwapService
{
    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function shifts(): ShiftService
    {
        return ShiftService::of($this->app);
    }

    /** @param array<string, mixed> $in partner (employee pid), my_date, partner_date, reason */
    public function request(Authorizer $az, array $in): string
    {
        $sh = $this->shifts();
        $me = $sh->emp()->forUser($az->userId) ?? throw new BusinessException('shifts.error.no_employee', [], 409);
        $db = $this->app->db();
        $errors = [];
        $partner = $db->one("SELECT id, public_id, employee_code FROM employees WHERE public_id = ? AND status <> 'former'", [(string) ($in['partner'] ?? '')]);
        if ($partner === null || (int) $partner['id'] === (int) $me['id']) {
            $errors['partner'] = 'shifts.error.partner';
        }
        $myDate = (string) ($in['my_date'] ?? '');
        $theirDate = (string) (($in['partner_date'] ?? '') !== '' ? $in['partner_date'] : $myDate);
        $today = $sh->today();
        foreach (['my_date' => $myDate, 'partner_date' => $theirDate] as $f => $d) {
            if (!ShiftService::isDate($d) || $d < $today) {
                $errors[$f] = 'shifts.error.swap_date';
            }
        }
        $reason = trim((string) ($in['reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ($errors !== [] || $partner === null) {
            throw new ValidationException($errors);
        }
        $mine = $sh->shiftFor((int) $me['id'], $myDate);
        $theirs = $sh->shiftFor((int) $partner['id'], $theirDate);
        $job = $me['job'];
        $pjob = $sh->emp()->employment((int) $partner['id'], $theirDate);
        if ($mine === null) {
            $errors['my_date'] = 'shifts.error.no_shift_mine';
        }
        if ($theirs === null) {
            $errors['partner_date'] = 'shifts.error.no_shift_partner';
        }
        if ($mine !== null && $theirs !== null && $myDate === $theirDate && $mine['code'] === $theirs['code']) {
            $errors['partner'] = 'shifts.error.same_shift';
        }
        if ($mine !== null && $theirs !== null && $myDate !== $theirDate) {
            // a day swap: I give my working day and take theirs — each must be working on their own day and free on the other's
            $meThere = $sh->shiftFor((int) $me['id'], $theirDate);
            $themHere = $sh->shiftFor((int) $partner['id'], $myDate);
            if ($mine['off'] || $theirs['off'] || ($meThere !== null && !$meThere['off']) || ($themHere !== null && !$themHere['off'])) {
                $errors['partner_date'] = 'shifts.error.day_swap';
            }
        }
        if ($job === null || $pjob === null || (int) $job['legal_entity_id'] !== (int) $pjob['legal_entity_id']) {
            $errors['partner'] = 'shifts.error.partner_company';
        }
        if ($errors === [] && $job !== null && ($sh->frozen((int) $job['legal_entity_id'], $myDate) || $sh->frozen((int) $job['legal_entity_id'], $theirDate))) {
            $errors['my_date'] = 'shifts.error.locked';
        }
        $open = "status IN ('pending_partner','pending')";
        if (
            $errors === [] && $db->scalar(
                "SELECT id FROM sh_swaps WHERE {$open} AND ((requester_employee_id = ? AND requester_date = ?) OR (partner_employee_id = ? AND partner_date = ?)
                  OR (requester_employee_id = ? AND requester_date = ?) OR (partner_employee_id = ? AND partner_date = ?))",
                [$me['id'], $myDate, $me['id'], $myDate, $partner['id'], $theirDate, $partner['id'], $theirDate]
            ) !== null
        ) {
            $errors['my_date'] = 'shifts.error.swap_open';
        }
        if ($errors !== [] || $job === null || $mine === null || $theirs === null) {
            throw new ValidationException($errors);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $db->run(
            "INSERT INTO sh_swaps (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, site_id, department_id, requester_employee_id, partner_employee_id,
                requester_date, partner_date, requester_pattern_id, partner_pattern_id, reason, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_partner')",
            [$pid, $now, $az->userId, $now, $az->userId, $job['legal_entity_id'], $job['site_id'], $job['department_id'], $me['id'], $partner['id'], $myDate, $theirDate,
                $mine['pattern_id'], $theirs['pattern_id'], mb_substr($reason, 0, 500)]
        );
        $this->app->audit()->record('shifts.swap_requested', 'success', 'sh_swap', $pid, (int) $job['legal_entity_id'], null, ['partner' => $partner['employee_code'], 'dates' => [$myDate, $theirDate]]);
        $this->tell((int) $partner['id'], 'shifts.notify.swap_asked', ['code' => (string) $me['employee_code']], $pid);
        return $pid;
    }

    /** The colleague agrees (→ approval) or declines. */
    public function answer(Authorizer $az, string $pid, bool $accept, string $note = ''): void
    {
        $db = $this->app->db();
        $s = $db->one('SELECT * FROM sh_swaps WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $me = $this->shifts()->emp()->forUser($az->userId);
        if ($me === null || (int) $me['id'] !== (int) $s['partner_employee_id']) {
            throw HttpException::notFound();
        }
        if ($s['status'] !== 'pending_partner') {
            throw new BusinessException('shifts.error.swap_state', [], 409);
        }
        $now = $this->now();
        if (!$accept) {
            $db->run(
                "UPDATE sh_swaps SET status = 'declined', partner_answered_at = ?, updated_at = ?, updated_by = ?, error = ?, row_version = row_version + 1 WHERE id = ?",
                [$now, $now, $az->userId, $note !== '' ? mb_substr($note, 0, 255) : null, $s['id']]
            );
            $this->app->audit()->record('shifts.swap_declined', 'success', 'sh_swap', $pid, (int) $s['legal_entity_id']);
            $this->tell((int) $s['requester_employee_id'], 'shifts.notify.swap_declined', [], $pid);
            return;
        }
        $db->transactional(function () use ($db, $s, $az, $now, $pid): void {
            $db->run(
                "UPDATE sh_swaps SET status = 'pending', partner_answered_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'pending_partner'",
                [$now, $now, $az->userId, $s['id']]
            );
            $codes = $db->one('SELECT a.employee_code AS a, b.employee_code AS b FROM employees a, employees b WHERE a.id = ? AND b.id = ?', [$s['requester_employee_id'], $s['partner_employee_id']]) ?? [];
            $title = $this->app->translator()->get('shifts.swap_title', ['a' => (string) ($codes['a'] ?? ''), 'b' => (string) ($codes['b'] ?? ''), 'date' => (string) $s['requester_date']]);
            $requesterUser = (int) ($db->scalar("SELECT id FROM users WHERE employee_id = ? AND status = 'active' ORDER BY id LIMIT 1", [$s['requester_employee_id']]) ?? $az->userId);
            $inst = $this->app->workflow()->start(
                'shifts.swap',
                $pid,
                $title,
                (int) $s['legal_entity_id'],
                $requesterUser,
                $s['site_id'] !== null ? (int) $s['site_id'] : null,
                $s['department_id'] !== null ? (int) $s['department_id'] : null
            );
            $db->run('UPDATE sh_swaps SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE id = ?', [$inst, $s['id']]);
        });
        $this->app->audit()->record('shifts.swap_agreed', 'success', 'sh_swap', $pid, (int) $s['legal_entity_id']);
        $this->tell((int) $s['requester_employee_id'], 'shifts.notify.swap_agreed', [], $pid);
    }

    /** The requester withdraws a swap that is not decided yet. */
    public function cancel(Authorizer $az, string $pid): void
    {
        $db = $this->app->db();
        $s = $db->one('SELECT s.*, w.public_id AS inst FROM sh_swaps s LEFT JOIN workflow_instances w ON w.id = s.workflow_instance_id WHERE s.public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $me = $this->shifts()->emp()->forUser($az->userId);
        if ($me === null || (int) $me['id'] !== (int) $s['requester_employee_id']) {
            throw HttpException::notFound();
        }
        if (!in_array($s['status'], ['pending_partner', 'pending'], true)) {
            throw new BusinessException('shifts.error.swap_state', [], 409);
        }
        if ($s['status'] === 'pending' && $s['inst'] !== null) {
            $this->app->workflow()->cancel((string) $s['inst'], $az->userId, 'withdrawn by requester'); // onClosed → cancelled
        } else {
            $db->run("UPDATE sh_swaps SET status = 'cancelled', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $az->userId, $s['id']]);
        }
        $this->app->audit()->record('shifts.swap_cancelled', 'success', 'sh_swap', $pid, (int) $s['legal_entity_id']);
    }

    // ---- workflow callbacks --------------------------------------------------------------------------------------

    public function onApproved(string $pid, int $approverId): void
    {
        $db = $this->app->db();
        $s = $db->one('SELECT * FROM sh_swaps WHERE public_id = ?', [$pid]);
        if ($s === null || $s['status'] !== 'pending') {
            return;
        }
        try {
            $this->apply($s, $approverId);
            $db->run("UPDATE sh_swaps SET status = 'approved', error = NULL, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $approverId, $s['id']]);
            $this->app->audit()->record('shifts.swap_applied', 'success', 'sh_swap', $pid, (int) $s['legal_entity_id']);
            foreach ([(int) $s['requester_employee_id'], (int) $s['partner_employee_id']] as $e) {
                $this->tell($e, 'shifts.notify.swap_approved', [], $pid);
            }
        } catch (Throwable $e) {
            $key = $e instanceof BusinessException ? $e->messageKey : 'shifts.error.swap_failed';
            $db->run("UPDATE sh_swaps SET status = 'failed', error = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$key, $this->now(), $approverId, $s['id']]);
            $this->app->audit()->record('shifts.swap_applied', 'failure', 'sh_swap', $pid, (int) $s['legal_entity_id'], $key);
            $this->tell((int) $s['requester_employee_id'], 'shifts.notify.swap_failed', [], $pid);
        }
    }

    public function onClosed(string $pid, string $outcome): void
    {
        $status = $outcome === 'rejected' ? 'rejected' : 'cancelled';
        $this->app->db()->run("UPDATE sh_swaps SET status = ?, updated_at = ?, row_version = row_version + 1 WHERE public_id = ? AND status IN ('pending','pending_partner')", [$status, $this->now(), $pid]);
        $req = $this->app->db()->scalar('SELECT requester_employee_id FROM sh_swaps WHERE public_id = ?', [$pid]);
        if ($status === 'rejected' && $req !== null) {
            $this->tell((int) $req, 'shifts.notify.swap_rejected', [], $pid);
        }
    }

    /**
     * Swap the two cells in the published roster(s) that hold them, after re-checking that nothing moved.
     *
     * @param array<string, mixed> $s
     */
    private function apply(array $s, int $actorId): void
    {
        $sh = $this->shifts();
        $sh->forget();
        $db = $this->app->db();
        $a = (int) $s['requester_employee_id'];
        $b = (int) $s['partner_employee_id'];
        [$da, $db_] = [(string) $s['requester_date'], (string) $s['partner_date']];
        $ca = $sh->shiftFor($a, $da);
        $cb = $sh->shiftFor($b, $db_);
        $expA = $s['requester_pattern_id'] !== null ? (int) $s['requester_pattern_id'] : null;
        $expB = $s['partner_pattern_id'] !== null ? (int) $s['partner_pattern_id'] : null;
        if ($ca === null || $cb === null || $ca['pattern_id'] !== $expA || $cb['pattern_id'] !== $expB) {
            throw new BusinessException('shifts.error.swap_changed', [], 409);
        }
        $le = (int) $s['legal_entity_id'];
        if ($sh->frozen($le, $da) || $sh->frozen($le, $db_)) {
            throw new BusinessException('shifts.error.locked', [], 423);
        }
        // the new cells: A gets B's shift on B's date and is off on A's date (and vice versa); same date = exchange
        if ($da === $db_) {
            $moves = [[$a, $da, $expB, $ca['roster_id'], $expA], [$b, $db_, $expA, $cb['roster_id'], $expB]];
        } else {
            // swapping days: each takes the other's working day and is off on their own (both must be free on the other day)
            $aOther = $sh->shiftFor($a, $db_);
            $bOther = $sh->shiftFor($b, $da);
            if (($aOther !== null && !$aOther['off']) || ($bOther !== null && !$bOther['off'])) {
                throw new BusinessException('shifts.error.swap_changed', [], 409);
            }
            $moves = [[$a, $da, null, $ca['roster_id'], $expA], [$a, $db_, $expB, $aOther['roster_id'] ?? $cb['roster_id'], $aOther !== null ? null : false],
                [$b, $db_, null, $cb['roster_id'], $expB], [$b, $da, $expA, $bOther['roster_id'] ?? $ca['roster_id'], $bOther !== null ? null : false]];
        }
        foreach ($moves as [$emp, $date, $pat]) {
            if ($pat !== null && !$this->fits($emp, $date, (int) $pat, $s)) {
                throw new BusinessException('shifts.error.swap_overlap', [], 409);
            }
        }
        $now = $this->now();
        $db->transactional(function () use ($db, $moves, $s, $actorId, $now): void {
            foreach ($moves as [$emp, $date, $pat, $roster, $from]) {
                $db->run('INSERT INTO sh_assignments (roster_id, employee_id, work_date, pattern_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE pattern_id = VALUES(pattern_id)', [$roster, $emp, $date, $pat]);
                $db->run(
                    "INSERT INTO sh_changes (roster_id, employee_id, work_date, from_pattern_id, to_pattern_id, from_set, to_set, source, ref_pid, actor_id, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, 1, 'swap', ?, ?, ?)",
                    [$roster, $emp, $date, $from === false ? null : $from, $pat, $from === false ? 0 : 1, $s['public_id'], $actorId, $now]
                );
            }
        });
        $sh->forget();
        $sh->recalc([$a, $b], min($da, $db_), max($da, $db_));
    }

    /**
     * Does a shift on $date leave the employee's neighbouring shifts untouched (no overlap)?
     *
     * @param array<string, mixed> $s
     */
    private function fits(int $emp, string $date, int $patternId, array $s): bool
    {
        $sh = $this->shifts();
        $p = $sh->pattern($patternId);
        if ($p === null) {
            return false;
        }
        $prev = EmployeeService::ymd($date)->modify('-1 day')->format('Y-m-d');
        $next = EmployeeService::ymd($date)->modify('+1 day')->format('Y-m-d');
        $swapDates = [(string) $s['requester_date'], (string) $s['partner_date']];
        $start = (int) \Blue\Modules\Attendance\WorkSchedule::minutes((string) $p['start_time']);
        $end = $start + ShiftService::length($start, (int) \Blue\Modules\Attendance\WorkSchedule::minutes((string) $p['end_time']));
        $before = in_array($prev, $swapDates, true) ? null : $sh->shiftFor($emp, $prev);
        if ($before !== null && !$before['off'] && $before['overnight']) {
            $bEnd = (int) \Blue\Modules\Attendance\WorkSchedule::minutes($before['end']);
            if ($bEnd > $start) {
                return false;
            }
        }
        $after = in_array($next, $swapDates, true) ? null : $sh->shiftFor($emp, $next);
        if ($after !== null && !$after['off'] && $end > 1440 + (int) \Blue\Modules\Attendance\WorkSchedule::minutes($after['start'])) {
            return false;
        }
        return true;
    }

    // ---- reading ---------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> swaps involving the employee (newest first) */
    public function forEmployee(int $employeeId, int $limit = 30): array
    {
        return $this->app->db()->all(
            'SELECT s.*, a.employee_code AS a_code, a.name_th AS a_name, b.employee_code AS b_code, b.name_th AS b_name, pa.code AS a_shift, pb.code AS b_shift, w.public_id AS instance_pid
               FROM sh_swaps s JOIN employees a ON a.id = s.requester_employee_id JOIN employees b ON b.id = s.partner_employee_id
               LEFT JOIN sh_patterns pa ON pa.id = s.requester_pattern_id LEFT JOIN sh_patterns pb ON pb.id = s.partner_pattern_id
               LEFT JOIN workflow_instances w ON w.id = s.workflow_instance_id
              WHERE s.requester_employee_id = ? OR s.partner_employee_id = ? ORDER BY s.id DESC LIMIT ' . max(1, min(200, $limit)),
            [$employeeId, $employeeId]
        );
    }

    /** @return list<int> */
    public function managerUserIds(string $pid): array
    {
        $emp = $this->app->db()->scalar('SELECT requester_employee_id FROM sh_swaps WHERE public_id = ?', [$pid]);
        return $emp === null ? [] : $this->shifts()->emp()->managerUserIds((int) $emp, $this->shifts()->today());
    }

    /** @return array{rows: list<array{0: string, 1: string}>, link: ?string} */
    public function describe(string $pid): array
    {
        $t = $this->app->translator();
        $s = $this->app->db()->one(
            'SELECT s.*, a.employee_code AS a_code, a.name_th AS a_name, b.employee_code AS b_code, b.name_th AS b_name,
                    pa.code AS a_shift, pa.start_time AS a_s, pa.end_time AS a_e, pb.code AS b_shift, pb.start_time AS b_s, pb.end_time AS b_e
               FROM sh_swaps s JOIN employees a ON a.id = s.requester_employee_id JOIN employees b ON b.id = s.partner_employee_id
               LEFT JOIN sh_patterns pa ON pa.id = s.requester_pattern_id LEFT JOIN sh_patterns pb ON pb.id = s.partner_pattern_id WHERE s.public_id = ?',
            [$pid]
        );
        if ($s === null) {
            return ['rows' => [], 'link' => null];
        }
        $shift = static fn (?string $c, ?string $a, ?string $b): string => $c === null ? $t->get('shifts.off') : $c . ' ' . $a . '–' . $b;
        return ['rows' => [
            [$t->get('shifts.swap.requester'), $s['a_code'] . ' · ' . $s['a_name'] . ' — ' . $s['requester_date'] . ' ' . $shift($s['a_shift'], $s['a_s'], $s['a_e'])],
            [$t->get('shifts.swap.partner'), $s['b_code'] . ' · ' . $s['b_name'] . ' — ' . $s['partner_date'] . ' ' . $shift($s['b_shift'], $s['b_s'], $s['b_e'])],
            [$t->get('shifts.swap.after'), $t->get('shifts.swap.after_text')],
            [$t->get('shifts.swap.reason'), (string) $s['reason']],
            [$t->get('shifts.swap.partner_agreed'), (string) ($s['partner_answered_at'] ?? '—')],
        ], 'link' => '/shifts'];
    }

    /** @param array<string, string> $params */
    private function tell(int $employeeId, string $key, array $params, string $pid): void
    {
        foreach ($this->app->db()->all("SELECT id FROM users WHERE employee_id = ? AND status = 'active'", [$employeeId]) as $u) {
            $this->app->notifier()->notify((int) $u['id'], 'shifts.swap', $key, $params, '/shifts', 'swap:' . $pid . ':' . $key . ':' . $employeeId);
        }
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }
}
