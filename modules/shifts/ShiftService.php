<?php

declare(strict_types=1);

namespace Blue\Modules\Shifts;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;
use Blue\Modules\Attendance\AttendanceService;
use Blue\Modules\Attendance\WorkSchedule;
use Blue\Modules\Employees\EmployeeService;

/**
 * M35 Shift Scheduling (phase 5B, DEC-70).
 *
 *  Patterns   a company's shift types — start, end (end ≤ start = overnight, ends the next morning), break and grace
 *             minutes. Company decisions entered by HR/managers; nothing is shipped (demo data is marked DEMO).
 *  Rosters    who works which shift on which date, for a range of at most 31 days. Draft → Published. A published roster
 *             is never edited in place: "revise" copies it to a new draft revision; publishing that supersedes the old
 *             one (kept as evidence) and logs every changed cell. An approved swap also changes cells and is logged.
 *  Checks     before publishing: an employee may not be in two published rosters on the same date; two shifts may not
 *             overlap; dates in a closed attendance period cannot change. The company's own rules (shifts.rules —
 *             minimum rest hours, maximum consecutive working days; no default, HR proposes, another HR approves) give
 *             warnings that must be acknowledged.
 *  Attendance the published shift replaces the work schedule for that day: late/early against the shift, overnight
 *             shifts measured across midnight, a day off is "off".
 */
final class ShiftService
{
    public const MAX_DAYS = 31;
    public const TONES = ['morning', 'afternoon', 'night', 'other'];
    private const OFF = 'OFF';

    /** @var array<string, mixed> */
    private array $cache = [];

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

    public function today(): string
    {
        return $this->emp()->today();
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    // ---- patterns ---------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function patterns(int $legalEntityId, bool $activeOnly = false): array
    {
        return $this->app->db()->all(
            'SELECT p.*, s.code AS site_code FROM sh_patterns p LEFT JOIN sites s ON s.id = p.site_id WHERE p.legal_entity_id = ?' . ($activeOnly ? " AND p.status = 'active'" : '') . ' ORDER BY p.status, p.start_time, p.code',
            [$legalEntityId]
        );
    }

    /** @return array<string, mixed>|null */
    public function pattern(int $id): ?array
    {
        $key = 'p:' . $id;
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $this->app->db()->one('SELECT * FROM sh_patterns WHERE id = ?', [$id]);
        }
        return $this->cache[$key];
    }

    /**
     * Create (pid null) or change a pattern. Changing times affects only rosters published afterwards — published
     * days keep being measured against the pattern as it is now, so a change asks for a reason and is audited.
     *
     * @param array<string, mixed> $in code, name, start, end, break_minutes, late_grace_minutes, early_grace_minutes, tone, site, status, legal_entity
     */
    public function savePattern(Authorizer $az, ?string $pid, array $in): string
    {
        $db = $this->app->db();
        $before = $pid !== null ? ($db->one('SELECT * FROM sh_patterns WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound()) : null;
        $le = $before !== null ? (int) $before['legal_entity_id'] : (int) ($db->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [(string) ($in['legal_entity'] ?? '')]) ?? 0);
        if ($le === 0 || !$az->scope('shifts.manage')->coversLegalEntity($le)) {
            throw $le === 0 ? new ValidationException(['legal_entity' => 'form.invalid_choice']) : HttpException::forbidden();
        }
        $errors = [];
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{0,19}$/', $code) !== 1 || $code === self::OFF) {
            $errors['code'] = 'shifts.error.code';
        } elseif ($db->scalar('SELECT id FROM sh_patterns WHERE legal_entity_id = ? AND code = ? AND id <> ?', [$le, $code, (int) ($before['id'] ?? 0)]) !== null) {
            $errors['code'] = 'shifts.error.code_taken';
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'form.required';
        }
        $s = WorkSchedule::minutes(trim((string) ($in['start'] ?? '')));
        $e = WorkSchedule::minutes(trim((string) ($in['end'] ?? '')));
        if ($s === null) {
            $errors['start'] = 'shifts.error.time';
        }
        if ($e === null) {
            $errors['end'] = 'shifts.error.time';
        }
        $len = $s !== null && $e !== null ? self::length($s, $e) : 0;
        if ($s !== null && $e !== null && ($s === $e || $len > 16 * 60)) {
            $errors['end'] = 'shifts.error.length';
        }
        $nums = [];
        foreach (['break_minutes' => 240, 'late_grace_minutes' => 120, 'early_grace_minutes' => 120] as $f => $lim) {
            $v = trim((string) ($in[$f] ?? ''));
            $v = $v === '' ? '0' : $v;
            if (!ctype_digit($v) || (int) $v > $lim) {
                $errors[$f] = 'shifts.error.minutes';
            }
            $nums[$f] = (int) $v;
        }
        if ($len > 0 && $nums['break_minutes'] >= $len) {
            $errors['break_minutes'] = 'shifts.error.break';
        }
        $tone = (string) ($in['tone'] ?? 'other');
        if (!in_array($tone, self::TONES, true)) {
            $errors['tone'] = 'form.invalid_choice';
        }
        $site = null;
        if (($in['site'] ?? '') !== '') {
            $site = $db->one('SELECT id, legal_entity_id FROM sites WHERE public_id = ?', [(string) $in['site']]);
            if ($site === null || (int) $site['legal_entity_id'] !== $le) {
                $errors['site'] = 'form.invalid_choice';
            }
        }
        $status = ($in['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $reason = trim((string) ($in['reason'] ?? ''));
        $timesChanged = $before !== null && ($before['start_time'] !== WorkSchedule::hm((int) $s) || $before['end_time'] !== WorkSchedule::hm((int) $e)
            || (int) $before['break_minutes'] !== $nums['break_minutes'] || (int) $before['late_grace_minutes'] !== $nums['late_grace_minutes']
            || (int) $before['early_grace_minutes'] !== $nums['early_grace_minutes']);
        if ($timesChanged && mb_strlen($reason) < 3) {
            $errors['reason'] = 'shifts.error.reason_times';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $row = ['code' => $code, 'name' => mb_substr($name, 0, 120), 'start_time' => WorkSchedule::hm((int) $s), 'end_time' => WorkSchedule::hm((int) $e),
            'site_id' => $site !== null ? (int) $site['id'] : null, 'tone' => $tone, 'status' => $status] + $nums;
        $now = $this->now();
        if ($before === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO sh_patterns (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, site_id, code, name, start_time, end_time, break_minutes,
                    late_grace_minutes, early_grace_minutes, tone, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, $row['site_id'], $code, $row['name'], $row['start_time'], $row['end_time'], $nums['break_minutes'],
                    $nums['late_grace_minutes'], $nums['early_grace_minutes'], $tone, $status]
            );
            $this->app->audit()->record('shifts.pattern_created', 'success', 'sh_pattern', $pid, $le, null, ['after' => $row]);
            return $pid;
        }
        $db->run(
            'UPDATE sh_patterns SET code = ?, name = ?, start_time = ?, end_time = ?, break_minutes = ?, late_grace_minutes = ?, early_grace_minutes = ?, site_id = ?, tone = ?, status = ?,
                updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$code, $row['name'], $row['start_time'], $row['end_time'], $nums['break_minutes'], $nums['late_grace_minutes'], $nums['early_grace_minutes'], $row['site_id'], $tone, $status,
                $now, $az->userId, $before['id']]
        );
        $this->forget();
        $this->app->audit()->record('shifts.pattern_changed', 'success', 'sh_pattern', (string) $pid, $le, $reason !== '' ? mb_substr($reason, 0, 255) : null, [
            'before' => array_intersect_key($before, $row + ['break_minutes' => 1]), 'after' => $row,
        ]);
        return (string) $pid;
    }

    /** Minutes from start to end (overnight when end ≤ start). */
    public static function length(int $start, int $end): int
    {
        return $end > $start ? $end - $start : $end + 1440 - $start;
    }

    // ---- the shift of an employee on a date ----------------------------------------------------------------------

    /**
     * The published shift of an employee on a date (latest published roster that has the cell), or null when no
     * published roster covers it.
     *
     * @return array{off: bool, code: string, name: string, start: string, end: string, overnight: bool, break_minutes: int, late_grace_minutes: int,
     *               early_grace_minutes: int, pattern_id: ?int, roster_id: int, tone: string}|null
     */
    public function shiftFor(int $employeeId, string $date): ?array
    {
        $m = $this->shiftsFor($employeeId, $date, $date);
        return $m[$date] ?? null;
    }

    /**
     * @return array<string, array{off: bool, code: string, name: string, start: string, end: string, overnight: bool, break_minutes: int, late_grace_minutes: int,
     *                              early_grace_minutes: int, pattern_id: ?int, roster_id: int, tone: string}>
     */
    public function shiftsFor(int $employeeId, string $from, string $to): array
    {
        $key = 's:' . $employeeId . ':' . $from . ':' . $to;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        $out = [];
        $rows = $this->app->db()->all(
            "SELECT a.work_date, a.pattern_id, a.roster_id, p.code, p.name, p.start_time, p.end_time, p.break_minutes, p.late_grace_minutes, p.early_grace_minutes, p.tone
               FROM sh_assignments a JOIN sh_rosters r ON r.id = a.roster_id AND r.status = 'published' LEFT JOIN sh_patterns p ON p.id = a.pattern_id
              WHERE a.employee_id = ? AND a.work_date BETWEEN ? AND ? ORDER BY a.work_date, r.published_at",
            [$employeeId, $from, $to]
        );
        foreach ($rows as $r) {
            $out[(string) $r['work_date']] = self::shape($r); // later-published rosters win (ORDER BY published_at)
        }
        return $this->cache[$key] = $out;
    }

    /**
     * @param array<string, mixed> $r
     * @return array{off: bool, code: string, name: string, start: string, end: string, overnight: bool, break_minutes: int, late_grace_minutes: int,
     *               early_grace_minutes: int, pattern_id: ?int, roster_id: int, tone: string}
     */
    private static function shape(array $r): array
    {
        $off = $r['pattern_id'] === null;
        $s = $off ? 0 : (int) WorkSchedule::minutes((string) $r['start_time']);
        $e = $off ? 0 : (int) WorkSchedule::minutes((string) $r['end_time']);
        return ['off' => $off, 'code' => $off ? self::OFF : (string) $r['code'], 'name' => $off ? '' : (string) $r['name'], 'start' => $off ? '' : (string) $r['start_time'],
            'end' => $off ? '' : (string) $r['end_time'], 'overnight' => !$off && $e <= $s, 'break_minutes' => (int) ($r['break_minutes'] ?? 0),
            'late_grace_minutes' => (int) ($r['late_grace_minutes'] ?? 0), 'early_grace_minutes' => (int) ($r['early_grace_minutes'] ?? 0),
            'pattern_id' => $off ? null : (int) $r['pattern_id'], 'roster_id' => (int) $r['roster_id'], 'tone' => $off ? 'off' : (string) ($r['tone'] ?? 'other')];
    }

    // ---- rosters --------------------------------------------------------------------------------------------------

    /** May $az plan shifts for this company / branch / unit? (shifts.manage covering it — unit rights include sub-units) */
    public function canManage(Authorizer $az, int $le, ?int $site, ?int $dept): bool
    {
        return EmployeeService::covers($az->scope('shifts.manage'), ['legal_entity_id' => $le, 'site_id' => $site, 'department_id' => $dept]);
    }

    /** @return list<array<string, mixed>> rosters the user may manage, newest first */
    public function rosters(Authorizer $az, int $limit = 100): array
    {
        $scope = $az->scope('shifts.manage');
        if ($scope->isEmpty()) {
            return [];
        }
        [$sql, $p] = $scope->sql('r.legal_entity_id', 'r.site_id', 'r.department_id');
        return $this->app->db()->all(
            "SELECT r.*, l.code AS le_code, s.code AS site_code, d.code AS dept_code, (SELECT COUNT(DISTINCT a.employee_id) FROM sh_assignments a WHERE a.roster_id = r.id) AS people
               FROM sh_rosters r JOIN legal_entities l ON l.id = r.legal_entity_id LEFT JOIN sites s ON s.id = r.site_id LEFT JOIN departments d ON d.id = r.department_id
              WHERE {$sql} AND r.status <> 'cancelled' ORDER BY r.start_date DESC, r.id DESC LIMIT " . max(1, min(500, $limit)),
            $p
        );
    }

    /** @return array<string, mixed> */
    public function roster(Authorizer $az, string $pid): array
    {
        $r = $this->app->db()->one(
            'SELECT r.*, l.code AS le_code, l.public_id AS le_pid, s.code AS site_code, d.code AS dept_code, u.display_name AS published_name, o.public_id AS supersedes_pid
               FROM sh_rosters r JOIN legal_entities l ON l.id = r.legal_entity_id LEFT JOIN sites s ON s.id = r.site_id LEFT JOIN departments d ON d.id = r.department_id
               LEFT JOIN users u ON u.id = r.published_by LEFT JOIN sh_rosters o ON o.id = r.supersedes_id WHERE r.public_id = ?',
            [$pid]
        ) ?? throw HttpException::notFound();
        if (!$this->canManage($az, (int) $r['legal_entity_id'], $r['site_id'] !== null ? (int) $r['site_id'] : null, $r['department_id'] !== null ? (int) $r['department_id'] : null)) {
            throw HttpException::notFound();
        }
        $r['phase'] = $this->phase($r);
        $r['next_draft_pid'] = $this->app->db()->scalar("SELECT public_id FROM sh_rosters WHERE supersedes_id = ? AND status = 'draft'", [$r['id']]);
        return $r;
    }

    /**
     * Draft → Published → Active (today inside the range) → Completed (range over); superseded / cancelled as stored.
     *
     * @param array<string, mixed> $r
     */
    public function phase(array $r): string
    {
        if ($r['status'] !== 'published') {
            return (string) $r['status'];
        }
        $today = $this->today();
        return $today < (string) $r['start_date'] ? 'published' : ($today > (string) $r['end_date'] ? 'completed' : 'active');
    }

    /** @param array<string, mixed> $in legal_entity, site, department, name, start_date, end_date, note */
    public function createRoster(Authorizer $az, array $in): string
    {
        $db = $this->app->db();
        $errors = [];
        $le = (int) ($db->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [(string) ($in['legal_entity'] ?? '')]) ?? 0);
        if ($le === 0) {
            $errors['legal_entity'] = 'form.invalid_choice';
        }
        $site = null;
        if (($in['site'] ?? '') !== '') {
            $row = $db->one('SELECT id, legal_entity_id FROM sites WHERE public_id = ?', [(string) $in['site']]);
            $site = $row !== null && (int) $row['legal_entity_id'] === $le ? (int) $row['id'] : null;
            if ($site === null) {
                $errors['site'] = 'form.invalid_choice';
            }
        }
        $dept = null;
        if (($in['department'] ?? '') !== '') {
            $row = $db->one('SELECT id, legal_entity_id FROM departments WHERE public_id = ?', [(string) $in['department']]);
            $dept = $row !== null && (int) $row['legal_entity_id'] === $le ? (int) $row['id'] : null;
            if ($dept === null) {
                $errors['department'] = 'form.invalid_choice';
            }
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'form.required';
        }
        [$from, $to] = [(string) ($in['start_date'] ?? ''), (string) ($in['end_date'] ?? '')];
        if (!self::isDate($from)) {
            $errors['start_date'] = 'shifts.error.date';
        }
        if (!self::isDate($to)) {
            $errors['end_date'] = 'shifts.error.date';
        } elseif (self::isDate($from) && ($to < $from || self::days($from, $to) > self::MAX_DAYS)) {
            $errors['end_date'] = 'shifts.error.range';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if (!$this->canManage($az, $le, $site, $dept)) {
            throw HttpException::forbidden();
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $db->run(
            "INSERT INTO sh_rosters (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, site_id, department_id, name, start_date, end_date, status, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)",
            [$pid, $now, $az->userId, $now, $az->userId, $le, $site, $dept, mb_substr($name, 0, 120), $from, $to, mb_substr(trim((string) ($in['note'] ?? '')), 0, 500) ?: null]
        );
        $this->app->audit()->record('shifts.roster_created', 'success', 'sh_roster', $pid, $le, null, ['from' => $from, 'to' => $to, 'site' => $site, 'department' => $dept]);
        return $pid;
    }

    /**
     * Employees a roster plans for: employed in the company on any day of the range, in its branch / unit (and the
     * units below it) when the roster has one, plus anyone already in it.
     *
     * @param array<string, mixed> $r
     * @return list<array<string, mixed>>
     */
    public function people(array $r): array
    {
        $db = $this->app->db();
        $units = $r['department_id'] !== null ? $this->unitAndBelow((int) $r['department_id']) : [];
        $where = 'h.legal_entity_id = ? AND h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?)';
        $p = [(int) $r['legal_entity_id'], (string) $r['end_date'], (string) $r['start_date']];
        if ($r['site_id'] !== null) {
            $where .= ' AND h.site_id = ?';
            $p[] = (int) $r['site_id'];
        }
        if ($units !== []) {
            $where .= ' AND h.department_id IN (' . implode(',', array_fill(0, count($units), '?')) . ')';
            $p = array_merge($p, $units);
        }
        $rows = $db->all(
            "SELECT DISTINCT e.id, e.public_id, e.employee_code, e.name_th, e.name_en, e.preferred_name FROM employment_history h JOIN employees e ON e.id = h.employee_id
              WHERE {$where} AND e.status <> 'former'
             UNION SELECT DISTINCT e.id, e.public_id, e.employee_code, e.name_th, e.name_en, e.preferred_name FROM sh_assignments a JOIN employees e ON e.id = a.employee_id WHERE a.roster_id = ?
             ORDER BY employee_code",
            array_merge($p, [(int) $r['id']])
        );
        return $rows;
    }

    /** @return list<int> */
    private function unitAndBelow(int $dept): array
    {
        $ids = [$dept];
        for ($i = 0; $i < count($ids) && $i < 500; $i++) {
            foreach ($this->app->db()->all('SELECT id FROM departments WHERE parent_id = ?', [$ids[$i]]) as $c) {
                if (!in_array((int) $c['id'], $ids, true)) {
                    $ids[] = (int) $c['id'];
                }
            }
        }
        return $ids;
    }

    /** @return array<int, array<string, ?int>> employee id => date => pattern id (null = day off); missing date = not planned */
    public function cells(int $rosterId): array
    {
        $out = [];
        foreach ($this->app->db()->all('SELECT employee_id, work_date, pattern_id FROM sh_assignments WHERE roster_id = ?', [$rosterId]) as $a) {
            $out[(int) $a['employee_id']][(string) $a['work_date']] = $a['pattern_id'] !== null ? (int) $a['pattern_id'] : null;
        }
        return $out;
    }

    /**
     * Save the grid of a draft: cells[employee pid][Y-m-d] = pattern pid | "OFF" | "" (not planned).
     *
     * @param array<string, mixed> $cells
     */
    public function saveCells(Authorizer $az, string $pid, array $cells): int
    {
        $r = $this->draft($az, $pid);
        $people = [];
        foreach ($this->people($r) as $e) {
            $people[(string) $e['public_id']] = (int) $e['id'];
        }
        $patterns = $this->patternIds((int) $r['legal_entity_id']);
        $errors = [];
        $set = [];
        foreach ($cells as $empPid => $days) {
            if (!isset($people[(string) $empPid]) || !is_array($days)) {
                continue;
            }
            foreach ($days as $date => $val) {
                $date = (string) $date;
                if (!self::isDate($date) || $date < (string) $r['start_date'] || $date > (string) $r['end_date']) {
                    continue;
                }
                $val = is_string($val) ? $val : '';
                if ($val !== '' && $val !== self::OFF && !isset($patterns[$val])) {
                    $errors['cells'] = 'shifts.error.pattern';
                    continue;
                }
                $set[] = [$people[(string) $empPid], $date, $val === '' ? false : ($val === self::OFF ? null : $patterns[$val])];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $this->write($az, $r, $set, 'grid');
    }

    /**
     * Fill many cells at once: employees × dates in [from, to] on the chosen weekdays (0 = Sunday … 6).
     *
     * @param list<string> $employeePids
     * @param list<int|string> $weekdays
     */
    public function fill(Authorizer $az, string $pid, array $employeePids, string $from, string $to, array $weekdays, string $pattern): int
    {
        $r = $this->draft($az, $pid);
        $errors = [];
        $people = [];
        foreach ($this->people($r) as $e) {
            $people[(string) $e['public_id']] = (int) $e['id'];
        }
        $ids = array_values(array_filter(array_map(static fn ($p): ?int => $people[(string) $p] ?? null, $employeePids)));
        if ($ids === []) {
            $errors['employees'] = 'shifts.error.pick_people';
        }
        if (!self::isDate($from) || $from < (string) $r['start_date'] || $from > (string) $r['end_date']) {
            $errors['from'] = 'shifts.error.date_in_roster';
        }
        if (!self::isDate($to) || $to < $from || $to > (string) $r['end_date']) {
            $errors['to'] = 'shifts.error.date_in_roster';
        }
        $days = array_values(array_unique(array_filter(array_map('intval', $weekdays), static fn (int $d): bool => $d >= 0 && $d <= 6)));
        if ($days === []) {
            $errors['weekdays'] = 'shifts.error.weekdays';
        }
        $patterns = $this->patternIds((int) $r['legal_entity_id']);
        if ($pattern !== '' && $pattern !== self::OFF && !isset($patterns[$pattern])) {
            $errors['pattern'] = 'shifts.error.pattern';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $set = [];
        for ($d = EmployeeService::ymd($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            if (in_array((int) $d->format('w'), $days, true)) {
                foreach ($ids as $id) {
                    $set[] = [$id, $d->format('Y-m-d'), $pattern === '' ? false : ($pattern === self::OFF ? null : $patterns[$pattern])];
                }
            }
        }
        return $this->write($az, $r, $set, 'fill');
    }

    /** Copy the 7 days starting at $source onto the 7 days starting at $target (inside the roster) for everyone. */
    public function copyWeek(Authorizer $az, string $pid, string $source, string $target): int
    {
        $r = $this->draft($az, $pid);
        $errors = [];
        foreach (['source' => $source, 'target' => $target] as $f => $d) {
            if (!self::isDate($d) || $d < (string) $r['start_date'] || $d > (string) $r['end_date']) {
                $errors[$f] = 'shifts.error.date_in_roster';
            }
        }
        if ($errors === [] && $source === $target) {
            $errors['target'] = 'shifts.error.same_week';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $cells = $this->cells((int) $r['id']);
        $set = [];
        for ($i = 0; $i < 7; $i++) {
            $src = EmployeeService::ymd($source)->modify("+{$i} day")->format('Y-m-d');
            $dst = EmployeeService::ymd($target)->modify("+{$i} day")->format('Y-m-d');
            if ($dst > (string) $r['end_date'] || $src > (string) $r['end_date']) {
                continue;
            }
            foreach ($cells as $emp => $days) {
                $set[] = [$emp, $dst, array_key_exists($src, $days) ? $days[$src] : false];
            }
        }
        return $this->write($az, $r, $set, 'copy_week');
    }

    /**
     * @param array<string, mixed> $r draft roster
     * @param list<array{0: int, 1: string, 2: int|null|false}> $set employee, date, pattern (null = off, false = clear)
     */
    private function write(Authorizer $az, array $r, array $set, string $how): int
    {
        $db = $this->app->db();
        $n = 0;
        $db->transactional(function () use ($db, $r, $set, &$n): void {
            foreach ($set as [$emp, $date, $pat]) {
                if ($pat === false) {
                    $n += $db->run('DELETE FROM sh_assignments WHERE roster_id = ? AND employee_id = ? AND work_date = ?', [$r['id'], $emp, $date])->rowCount();
                } else {
                    $db->run(
                        'INSERT INTO sh_assignments (roster_id, employee_id, work_date, pattern_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE pattern_id = VALUES(pattern_id)',
                        [$r['id'], $emp, $date, $pat]
                    );
                    $n++;
                }
            }
            $db->run('UPDATE sh_rosters SET updated_at = ?, row_version = row_version + 1 WHERE id = ?', [$this->now(), $r['id']]);
        });
        $this->app->audit()->record('shifts.roster_edited', 'success', 'sh_roster', (string) $r['public_id'], (int) $r['legal_entity_id'], null, ['how' => $how, 'cells' => count($set)]);
        $this->forget();
        return $n;
    }

    /** @return array<string, mixed> */
    private function draft(Authorizer $az, string $pid): array
    {
        $r = $this->roster($az, $pid);
        if ($r['status'] !== 'draft') {
            throw new BusinessException('shifts.error.not_draft', [], 409);
        }
        return $r;
    }

    /** @return array<string, int> pattern pid => id (active patterns of the company) */
    private function patternIds(int $le): array
    {
        $out = [];
        foreach ($this->patterns($le, true) as $p) {
            $out[(string) $p['public_id']] = (int) $p['id'];
        }
        return $out;
    }

    // ---- checks + publish -------------------------------------------------------------------------------------------

    /**
     * What stops publishing (errors) and what needs an acknowledgement (warnings).
     *
     * @param array<string, mixed> $r
     * @return array{errors: list<array{key: string, params: array<string, string>}>, warnings: list<array{key: string, params: array<string, string>}>, rules: ?array<string, mixed>}
     */
    public function check(array $r): array
    {
        $db = $this->app->db();
        $errors = [];
        $warnings = [];
        $cells = $this->cells((int) $r['id']);
        $codes = [];
        foreach ($db->all('SELECT id, employee_code FROM employees WHERE id IN (SELECT employee_id FROM sh_assignments WHERE roster_id = ?)', [$r['id']]) as $e) {
            $codes[(int) $e['id']] = (string) $e['employee_code'];
        }
        if ($cells === []) {
            $errors[] = ['key' => 'shifts.check.empty', 'params' => []];
        }
        // closed attendance periods: the shift is part of the payroll snapshot (DEC-66)
        if ($this->frozenRange((int) $r['legal_entity_id'], (string) $r['start_date'], (string) $r['end_date'])) {
            if ($r['supersedes_id'] === null) {
                $errors[] = ['key' => 'shifts.check.locked', 'params' => []];
            } else {
                foreach ($this->diff($r) as $c) {
                    if ($this->frozen((int) $r['legal_entity_id'], $c['date'])) {
                        $errors[] = ['key' => 'shifts.check.locked_cell', 'params' => ['code' => $codes[$c['employee']] ?? '?', 'date' => $c['date']]];
                        break;
                    }
                }
            }
        }
        $rules = $this->rules((int) $r['legal_entity_id'], (string) $r['start_date']);
        foreach ($cells as $emp => $days) {
            $code = $codes[$emp] ?? '?';
            // the employee's week around the roster: other published rosters (minus the one this revises) + this one
            $from = EmployeeService::ymd((string) $r['start_date'])->modify('-7 day')->format('Y-m-d');
            $to = EmployeeService::ymd((string) $r['end_date'])->modify('+7 day')->format('Y-m-d');
            $other = [];
            foreach (
                $db->all(
                    "SELECT a.work_date, a.pattern_id, a.roster_id FROM sh_assignments a JOIN sh_rosters x ON x.id = a.roster_id AND x.status = 'published'
                      WHERE a.employee_id = ? AND a.work_date BETWEEN ? AND ? AND x.id <> ? AND x.id <> ? ORDER BY x.published_at",
                    [$emp, $from, $to, $r['id'], (int) ($r['supersedes_id'] ?? 0)]
                ) as $o
            ) {
                $d = (string) $o['work_date'];
                if (array_key_exists($d, $days)) {
                    $errors[] = ['key' => 'shifts.check.twice', 'params' => ['code' => $code, 'date' => $d]];
                    continue;
                }
                $other[$d] = $o['pattern_id'] !== null ? (int) $o['pattern_id'] : null;
            }
            $all = $days + $other;
            ksort($all);
            $prevEnd = null;
            $run = 0;
            $prevDate = null;
            foreach ($all as $d => $pat) {
                $consecutive = $prevDate !== null && EmployeeService::ymd($prevDate)->modify('+1 day')->format('Y-m-d') === $d;
                if ($pat === null) {
                    $run = 0;
                    $prevDate = $d;
                    $prevEnd = null;
                    continue;
                }
                $p = $this->pattern($pat);
                if ($p === null) {
                    continue;
                }
                $s = self::at($d, (string) $p['start_time'], 0);
                $len = self::length((int) WorkSchedule::minutes((string) $p['start_time']), (int) WorkSchedule::minutes((string) $p['end_time']));
                $mine = array_key_exists($d, $days);
                if ($prevEnd !== null) {
                    $gap = intdiv($s - $prevEnd, 60);
                    if ($gap < 0 && $mine) {
                        $errors[] = ['key' => 'shifts.check.overlap', 'params' => ['code' => $code, 'date' => $d]];
                    } elseif ($mine && ($rules['min_rest_hours'] ?? null) !== null && $gap < (int) $rules['min_rest_hours'] * 60) {
                        $warnings[] = ['key' => 'shifts.check.rest', 'params' => ['code' => $code, 'date' => $d, 'hours' => (string) round($gap / 60, 1), 'min' => (string) $rules['min_rest_hours']]];
                    }
                }
                $run = $consecutive || $prevDate === null ? $run + 1 : 1;
                if ($mine && ($rules['max_consecutive_days'] ?? null) !== null && $run === (int) $rules['max_consecutive_days'] + 1) {
                    $warnings[] = ['key' => 'shifts.check.consecutive', 'params' => ['code' => $code, 'date' => $d, 'max' => (string) $rules['max_consecutive_days']]];
                }
                $prevEnd = $s + $len * 60;
                $prevDate = $d;
            }
        }
        return ['errors' => array_values(array_unique($errors, SORT_REGULAR)), 'warnings' => array_values(array_unique($warnings, SORT_REGULAR)), 'rules' => $rules];
    }

    /** Unix seconds of a local date + HH:MM (+ day offset) — only differences are used, so the zone does not matter. */
    private static function at(string $date, string $hm, int $plusDays): int
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        return ($d !== false ? $d->getTimestamp() : 0) + ((int) WorkSchedule::minutes($hm)) * 60 + $plusDays * 86400;
    }

    /**
     * Publish a draft (acknowledging warnings when there are any). A revision supersedes the roster it revises; the
     * changed cells are logged and the people concerned are told. Attendance is recalculated for days already past.
     *
     * @return array{changes: int, notified: int}
     */
    public function publish(Authorizer $az, string $pid, bool $acknowledged): array
    {
        $r = $this->draft($az, $pid);
        $chk = $this->check($r);
        if ($chk['errors'] !== []) {
            throw new BusinessException('shifts.error.check_failed', [], 422);
        }
        if ($chk['warnings'] !== [] && !$acknowledged) {
            throw new ValidationException(['acknowledge' => 'shifts.error.acknowledge']);
        }
        $db = $this->app->db();
        $now = $this->now();
        $changes = $r['supersedes_id'] !== null ? $this->diff($r) : [];
        $db->transactional(function () use ($db, $r, $az, $now, $chk, $changes): void {
            $db->run(
                "UPDATE sh_rosters SET status = 'published', published_at = ?, published_by = ?, warnings_json = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'draft'",
                [$now, $az->userId, $chk['warnings'] !== [] ? json_encode($chk['warnings'], JSON_UNESCAPED_UNICODE) : null, $now, $r['id']]
            );
            if ($r['supersedes_id'] !== null) {
                $db->run("UPDATE sh_rosters SET status = 'superseded', updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'published'", [$now, $r['supersedes_id']]);
                foreach ($changes as $c) {
                    $db->run(
                        "INSERT INTO sh_changes (roster_id, employee_id, work_date, from_pattern_id, to_pattern_id, from_set, to_set, source, ref_pid, actor_id, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'revision', ?, ?, ?)",
                        [$r['id'], $c['employee'], $c['date'], $c['from'] ?: null, $c['to'] ?: null, $c['from'] === false ? 0 : 1, $c['to'] === false ? 0 : 1, $r['public_id'], $az->userId, $now]
                    );
                }
            }
        });
        $this->forget();
        $this->app->audit()->record('shifts.roster_published', 'success', 'sh_roster', $pid, (int) $r['legal_entity_id'], null, [
            'revision' => (int) $r['revision'], 'supersedes' => $r['supersedes_pid'], 'changes' => count($changes), 'warnings' => count($chk['warnings']),
        ]);
        // who to tell: everyone in a new roster; only the people whose cells changed in a revision
        $people = $r['supersedes_id'] === null ? array_keys($this->cells((int) $r['id'])) : array_values(array_unique(array_column($changes, 'employee')));
        foreach ($people as $emp) {
            foreach ($db->all("SELECT id FROM users WHERE employee_id = ? AND status = 'active'", [$emp]) as $u) {
                $this->app->notifier()->notify(
                    (int) $u['id'],
                    'shifts.published',
                    $r['supersedes_id'] === null ? 'shifts.notify.published' : 'shifts.notify.changed',
                    ['name' => (string) $r['name']],
                    '/shifts',
                    'shifts:' . $pid . ':' . $emp
                );
            }
        }
        $this->recalc($people, (string) $r['start_date'], (string) $r['end_date']);
        return ['changes' => count($changes), 'notified' => count($people)];
    }

    /**
     * Cells that differ between a revision and the roster it revises (false = not planned).
     *
     * @param array<string, mixed> $r
     * @return list<array{employee: int, date: string, from: int|null|false, to: int|null|false}>
     */
    public function diff(array $r): array
    {
        if ($r['supersedes_id'] === null) {
            return [];
        }
        $old = $this->cells((int) $r['supersedes_id']);
        $new = $this->cells((int) $r['id']);
        $out = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $emp) {
            $dates = array_unique(array_merge(array_keys($old[$emp] ?? []), array_keys($new[$emp] ?? [])));
            sort($dates);
            foreach ($dates as $d) {
                $f = array_key_exists($d, $old[$emp] ?? []) ? $old[$emp][$d] : false;
                $t = array_key_exists($d, $new[$emp] ?? []) ? $new[$emp][$d] : false;
                if ($f !== $t) {
                    $out[] = ['employee' => (int) $emp, 'date' => (string) $d, 'from' => $f, 'to' => $t];
                }
            }
        }
        return $out;
    }

    /** A published roster → a new draft revision with the same cells (one open revision at a time). */
    public function revise(Authorizer $az, string $pid): string
    {
        $r = $this->roster($az, $pid);
        if ($r['status'] !== 'published') {
            throw new BusinessException('shifts.error.not_published', [], 409);
        }
        if ($r['next_draft_pid'] !== null) {
            return (string) $r['next_draft_pid'];
        }
        $db = $this->app->db();
        $new = Ulid::generate($this->app->clock);
        $now = $this->now();
        $db->transactional(function () use ($db, $r, $az, $new, $now): void {
            $db->run(
                "INSERT INTO sh_rosters (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, site_id, department_id, name, start_date, end_date, status, revision, supersedes_id, note, is_demo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?)",
                [$new, $now, $az->userId, $now, $az->userId, $r['legal_entity_id'], $r['site_id'], $r['department_id'], $r['name'], $r['start_date'], $r['end_date'], (int) $r['revision'] + 1,
                    $r['id'], $r['note'], $r['is_demo']]
            );
            $id = (int) $db->scalar('SELECT id FROM sh_rosters WHERE public_id = ?', [$new]);
            $db->run('INSERT INTO sh_assignments (roster_id, employee_id, work_date, pattern_id) SELECT ?, employee_id, work_date, pattern_id FROM sh_assignments WHERE roster_id = ?', [$id, $r['id']]);
        });
        $this->app->audit()->record('shifts.roster_revised', 'success', 'sh_roster', $new, (int) $r['legal_entity_id'], null, ['from' => $pid, 'revision' => (int) $r['revision'] + 1]);
        return $new;
    }

    /** Throw away a draft (a new roster or an unpublished revision). */
    public function discard(Authorizer $az, string $pid): void
    {
        $r = $this->draft($az, $pid);
        $this->app->db()->run("UPDATE sh_rosters SET status = 'cancelled', updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $r['id']]);
        $this->app->audit()->record('shifts.roster_discarded', 'success', 'sh_roster', $pid, (int) $r['legal_entity_id']);
    }

    /**
     * Logged changes of a roster family (this roster and the ones it superseded).
     *
     * @param array<string, mixed> $r
     * @return list<array<string, mixed>>
     */
    public function changes(array $r, int $limit = 200): array
    {
        $ids = [(int) $r['id']];
        $cur = $r['supersedes_id'];
        for ($i = 0; $cur !== null && $i < 50; $i++) {
            $ids[] = (int) $cur;
            $cur = $this->app->db()->scalar('SELECT supersedes_id FROM sh_rosters WHERE id = ?', [$cur]);
        }
        return $this->app->db()->all(
            'SELECT c.*, e.employee_code, f.code AS from_code, t.code AS to_code, u.display_name AS actor_name, r.revision
               FROM sh_changes c JOIN employees e ON e.id = c.employee_id JOIN sh_rosters r ON r.id = c.roster_id LEFT JOIN sh_patterns f ON f.id = c.from_pattern_id
               LEFT JOIN sh_patterns t ON t.id = c.to_pattern_id LEFT JOIN users u ON u.id = c.actor_id
              WHERE c.roster_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY c.id DESC LIMIT ' . max(1, min(1000, $limit)),
            $ids
        );
    }

    // ---- rules (Config Registry) ------------------------------------------------------------------------------------

    /** @return array{min_rest_hours: ?int, max_consecutive_days: ?int, version_pid: ?string}|null */
    public function rules(int $le, string $date): ?array
    {
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' 12:00:00', $this->tz()) ?: $this->app->clock->now();
        $r = $this->app->registry()->resolveOrNull('shifts.rules', $le, null, $at);
        if ($r === null || !is_array($r->value)) {
            return null;
        }
        return ['min_rest_hours' => isset($r->value['min_rest_hours']) ? (int) $r->value['min_rest_hours'] : null,
            'max_consecutive_days' => isset($r->value['max_consecutive_days']) ? (int) $r->value['max_consecutive_days'] : null, 'version_pid' => $r->versionPublicId];
    }

    /**
     * HR proposes the company's shift rules with the work-rule reference; another HR approves (no default).
     *
     * @param array<string, mixed> $in
     */
    public function proposeRules(Authorizer $az, int $le, array $in, string $from, string $source, string $reason): string
    {
        if (!$az->scope('shifts.policy')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $errors = [];
        $value = [];
        foreach (['min_rest_hours' => 24, 'max_consecutive_days' => 31] as $f => $max) {
            $v = trim((string) ($in[$f] ?? ''));
            if ($v === '') {
                $value[$f] = null;
            } elseif (!ctype_digit($v) || (int) $v < 1 || (int) $v > $max) {
                $errors[$f] = 'shifts.error.rule_number';
            } else {
                $value[$f] = (int) $v;
            }
        }
        if ($errors === [] && $value['min_rest_hours'] === null && $value['max_consecutive_days'] === null) {
            $errors['min_rest_hours'] = 'shifts.error.rules_empty';
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $this->tz());
        if ($d === false || $d->format('Y-m-d') !== $from) {
            $errors['effective_from'] = 'shifts.error.date';
        }
        if (mb_strlen(trim($source)) < 5) {
            $errors['source_reference'] = 'shifts.error.source';
        }
        if (mb_strlen(trim($reason)) < 3) {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ($errors !== [] || $d === false) {
            throw new ValidationException($errors);
        }
        $reg = $this->app->registry();
        $pid = $reg->propose($az, 'shifts.rules', 'legal_entity', $le, (string) json_encode($value), $d, null, trim($reason), trim($source));
        $reg->submit($az, $pid);
        return $pid;
    }

    /** @return list<array{0: string, 1: string}> approval page rows */
    public static function describeRules(App $app, mixed $value): array
    {
        $t = $app->translator();
        $v = is_array($value) ? $value : [];
        return [
            [$t->get('shifts.rules.min_rest_hours'), ($v['min_rest_hours'] ?? null) === null ? '—' : $t->get('shifts.hours', ['n' => (string) $v['min_rest_hours']])],
            [$t->get('shifts.rules.max_consecutive_days'), ($v['max_consecutive_days'] ?? null) === null ? '—' : $t->get('shifts.days_n', ['n' => (string) $v['max_consecutive_days']])],
        ];
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------

    /** Is the date inside a period sent for close / closed (DEC-66)? */
    public function frozen(int $le, string $date): bool
    {
        return $this->frozenRange($le, $date, $date);
    }

    /** Does [from, to] touch a locked attendance range of the company? (read-only: no "blocked write" is logged) */
    public function frozenRange(int $le, string $from, string $to): bool
    {
        foreach ($this->app->locks()->lockedRanges('attendance', $le) as [$a, $b]) {
            if ($from <= $b && $to >= $a) {
                return true;
            }
        }
        return false;
    }

    /** @param list<int> $employees */
    public function recalc(array $employees, string $from, string $to): void
    {
        if (!$this->app->modules()->isEnabled('attendance')) {
            return;
        }
        $att = AttendanceService::of($this->app);
        $att->forget();
        $to = min($to, $this->today());
        foreach (array_unique($employees) as $e) {
            if ($from <= $to) {
                $att->recalc((int) $e, $from, $to);
            }
        }
    }

    public static function isDate(string $d): bool
    {
        $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $x !== false && $x->format('Y-m-d') === $d;
    }

    public static function days(string $from, string $to): int
    {
        return (int) EmployeeService::ymd($from)->diff(EmployeeService::ymd($to))->days + 1;
    }

    public function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }
}
