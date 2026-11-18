<?php

declare(strict_types=1);

namespace Blue\Modules\Manpower;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;

/**
 * M11 Manpower budget (phase 5E-3, DEC-78).
 *
 *  plan       a yearly plan of a company: title, assumptions, lines (unit — or the whole company — × position title —
 *             or any title — × heads × monthly cost per head × months). Draft → review → another person with
 *             manpower.approve approves → ACTIVE baseline (the former active plan of that year is superseded) →
 *             closed at the end of the year. An active plan is never edited: a revision copies it into a new draft
 *             version; a draft is also the "what-if" — it is shown next to the active plan.
 *  actual     heads = employees of the company employed today (active, on leave, suspended) whose unit and position
 *             title match the line (no unit = whole company, no title = every title); joining = pre-hire people;
 *             open = approved requisitions for new people not yet filled; pending = requisitions waiting for
 *             approval. Counts only — no names (PDPA). Each line is counted on its own; lines may overlap.
 *             FTE = heads (part-time is not weighted — no rule yet). Actual cost is not known (no payroll yet).
 *  snapshot   when a plan is approved the actual numbers of that day are kept with it; every month the headcount
 *             per unit is kept for the history chart.
 *  check      a requisition for NEW people is compared with the best-matching line of the active plan of the year
 *             (unit+title, unit, title, company): planned − (heads + joining + open) < asked → over budget. The
 *             company chooses "warn" (approvers see it) or "block". No active plan → nothing is blocked. The plan is
 *             never changed by a requisition.
 */
final class ManpowerService
{
    public const EMPLOYED = ['active', 'on_leave', 'suspended'];

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
    public function companies(Authorizer $az, string $permission = 'manpower.view'): array
    {
        $scope = $az->scope($permission);
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $scope->coversLegalEntity($id)));
    }

    /** @return array{over_budget: string} */
    public function settings(int $le): array
    {
        return ['over_budget' => (string) ($this->app->db()->scalar('SELECT over_budget FROM mp_settings WHERE legal_entity_id = ?', [$le]) ?? 'warn')];
    }

    public function saveSettings(Authorizer $az, int $le, string $mode): void
    {
        if (!$az->scope('manpower.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $mode = $mode === 'block' ? 'block' : 'warn';
        $this->app->db()->run(
            'INSERT INTO mp_settings (legal_entity_id, over_budget, updated_at, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE over_budget = VALUES(over_budget), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$le, $mode, $this->now(), $az->userId]
        );
        $this->app->audit()->record('manpower.settings_saved', 'success', 'mp_settings', (string) $le, $le, null, ['over_budget' => $mode]);
    }

    // ---- plans --------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function plans(int $le): array
    {
        return $this->app->db()->all(
            'SELECT p.*, (SELECT COALESCE(SUM(l.headcount), 0) FROM mp_lines l WHERE l.plan_id = p.id) AS heads, (SELECT COUNT(*) FROM mp_lines l WHERE l.plan_id = p.id) AS line_count
               FROM mp_plans p WHERE p.legal_entity_id = ? ORDER BY p.fiscal_year DESC, p.version_no DESC',
            [$le]
        );
    }

    /** @return array<string, mixed>|null the active plan of a company and year */
    public function active(int $le, int $year): ?array
    {
        return $this->app->db()->one("SELECT * FROM mp_plans WHERE legal_entity_id = ? AND fiscal_year = ? AND status = 'active'", [$le, $year]);
    }

    /** @return array<string, mixed> a plan the person may see (+ can_manage) */
    public function plan(Authorizer $az, string $pid): array
    {
        $p = $this->app->db()->one('SELECT p.*, u.display_name AS approved_name, c.display_name AS created_name FROM mp_plans p LEFT JOIN users u ON u.id = p.approved_by LEFT JOIN users c ON c.id = p.created_by WHERE p.public_id = ?', [$pid]);
        if ($p === null || !$az->scope('manpower.view')->coversLegalEntity((int) $p['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        $p['can_manage'] = $az->scope('manpower.manage')->coversLegalEntity((int) $p['legal_entity_id']);
        return $p;
    }

    /** @return array<string, mixed> */
    private function managed(Authorizer $az, string $pid): array
    {
        $p = $this->plan($az, $pid);
        if (!$p['can_manage']) {
            throw HttpException::forbidden();
        }
        return $p;
    }

    /** @param array<string, string> $in fiscal_year, title, assumptions */
    public function create(Authorizer $az, int $le, array $in): string
    {
        if (!$az->scope('manpower.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $errors = [];
        $year = (int) ($in['fiscal_year'] ?? 0);
        $now = (int) substr($this->today(), 0, 4);
        if ($year < $now - 1 || $year > $now + 2) {
            $errors['fiscal_year'] = 'manpower.error.year';
        }
        $title = mb_substr(trim($in['title'] ?? ''), 0, 150);
        if (mb_strlen($title) < 3) {
            $errors['title'] = 'manpower.error.title';
        }
        if ($errors === [] && $this->openVersion($le, $year) !== null) {
            $errors['fiscal_year'] = 'manpower.error.open_exists';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $this->insertPlan($az, $le, $year, $title, mb_substr(trim($in['assumptions'] ?? ''), 0, 4000), null);
    }

    /** @return array<string, mixed>|null a draft or one in review of the year */
    private function openVersion(int $le, int $year): ?array
    {
        return $this->app->db()->one("SELECT * FROM mp_plans WHERE legal_entity_id = ? AND fiscal_year = ? AND status IN ('draft','review')", [$le, $year]);
    }

    private function insertPlan(Authorizer $az, int $le, int $year, string $title, string $assumptions, ?int $revisionOf): string
    {
        $db = $this->app->db();
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $no = (int) $db->scalar('SELECT COALESCE(MAX(version_no), 0) + 1 FROM mp_plans WHERE legal_entity_id = ? AND fiscal_year = ?', [$le, $year]);
        $db->run(
            'INSERT INTO mp_plans (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, fiscal_year, version_no, title, assumptions, revision_of) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $le, $year, $no, $title, $assumptions !== '' ? $assumptions : null, $revisionOf]
        );
        $this->app->audit()->record('manpower.plan_created', 'success', 'mp_plan', $pid, $le, null, ['year' => $year, 'version' => $no, 'revision_of' => $revisionOf]);
        return $pid;
    }

    /** A new draft version copied from the active plan (the active one stays the baseline until the revision is approved). */
    public function revise(Authorizer $az, string $pid): string
    {
        $p = $this->managed($az, $pid);
        if ($p['status'] !== 'active') {
            throw new BusinessException('manpower.error.state', [], 409);
        }
        if ($this->openVersion((int) $p['legal_entity_id'], (int) $p['fiscal_year']) !== null) {
            throw new BusinessException('manpower.error.open_exists', [], 409);
        }
        $db = $this->app->db();
        $new = $this->insertPlan($az, (int) $p['legal_entity_id'], (int) $p['fiscal_year'], (string) $p['title'], (string) ($p['assumptions'] ?? ''), (int) $p['id']);
        $nid = (int) $db->scalar('SELECT id FROM mp_plans WHERE public_id = ?', [$new]);
        $db->run('INSERT INTO mp_lines (plan_id, department_id, position_title, title_key, headcount, monthly_cost, months, note) SELECT ?, department_id, position_title, title_key, headcount, monthly_cost, months, note FROM mp_lines WHERE plan_id = ?', [$nid, $p['id']]);
        return $new;
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $planId): array
    {
        return $this->app->db()->all('SELECT l.*, d.code AS dept_code, d.public_id AS dept_pid FROM mp_lines l LEFT JOIN departments d ON d.id = l.department_id WHERE l.plan_id = ? ORDER BY d.code, l.position_title, l.id', [$planId]);
    }

    /**
     * Saves a draft: title, assumptions and all its lines (empty rows are ignored).
     *
     * @param array<string, mixed> $in title, assumptions, lines: list<array{department, position_title, headcount, monthly_cost, months, note}>
     */
    public function saveDraft(Authorizer $az, string $pid, array $in): void
    {
        $p = $this->managed($az, $pid);
        if ($p['status'] !== 'draft') {
            throw new BusinessException('manpower.error.state', [], 409);
        }
        $db = $this->app->db();
        $le = (int) $p['legal_entity_id'];
        $errors = [];
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 150);
        if (mb_strlen($title) < 3) {
            $errors['title'] = 'manpower.error.title';
        }
        $depts = [];
        foreach ($db->all('SELECT id, public_id FROM departments WHERE legal_entity_id = ?', [$le]) as $d) {
            $depts[(string) $d['public_id']] = (int) $d['id'];
        }
        $rows = [];
        $seen = [];
        foreach ((array) ($in['lines'] ?? []) as $i => $l) {
            $l = (array) $l;
            $dept = trim((string) ($l['department'] ?? ''));
            $titleL = mb_substr(trim((string) ($l['position_title'] ?? '')), 0, 120);
            $hc = str_replace(',', '', trim((string) ($l['headcount'] ?? '')));
            $cost = str_replace(',', '', trim((string) ($l['monthly_cost'] ?? '')));
            $months = trim((string) ($l['months'] ?? ''));
            if ($dept === '' && $titleL === '' && $hc === '' && $cost === '') {
                continue;
            }
            if ($dept !== '' && !isset($depts[$dept])) {
                $errors['line_' . $i] = 'manpower.error.line_dept';
                continue;
            }
            if (preg_match('/^\d{1,5}(\.\d)?$/', $hc) !== 1) {
                $errors['line_' . $i] = 'manpower.error.line_heads';
                continue;
            }
            if ($cost !== '' && preg_match('/^\d{1,11}(\.\d{1,2})?$/', $cost) !== 1) {
                $errors['line_' . $i] = 'manpower.error.line_cost';
                continue;
            }
            $m = $months === '' ? 12 : (int) $months;
            if ($m < 1 || $m > 12 || ($months !== '' && !ctype_digit($months))) {
                $errors['line_' . $i] = 'manpower.error.line_months';
                continue;
            }
            $key = ($dept !== '' ? $depts[$dept] : 0) . '|' . self::titleKey($titleL);
            if (isset($seen[$key])) {
                $errors['line_' . $i] = 'manpower.error.line_duplicate';
                continue;
            }
            $seen[$key] = true;
            $rows[] = [$dept !== '' ? $depts[$dept] : null, $titleL, self::titleKey($titleL), (float) $hc, $cost !== '' ? (float) $cost : null, $m, mb_substr(trim((string) ($l['note'] ?? '')), 0, 300) ?: null];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $db->transactional(function () use ($db, $p, $rows, $title, $in, $az): void {
            $db->run(
                'UPDATE mp_plans SET title = ?, assumptions = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                [$title, mb_substr(trim((string) ($in['assumptions'] ?? '')), 0, 4000) ?: null, $this->now(), $az->userId, $p['id']]
            );
            $db->run('DELETE FROM mp_lines WHERE plan_id = ?', [$p['id']]);
            foreach ($rows as $r) {
                $db->run('INSERT INTO mp_lines (plan_id, department_id, position_title, title_key, headcount, monthly_cost, months, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$p['id'], ...$r]);
            }
        });
    }

    public function submit(Authorizer $az, string $pid): void
    {
        $p = $this->managed($az, $pid);
        if ($p['status'] !== 'draft') {
            throw new BusinessException('manpower.error.state', [], 409);
        }
        if ($this->lines((int) $p['id']) === []) {
            throw new BusinessException('manpower.error.no_lines', [], 422);
        }
        $db = $this->app->db();
        $db->transactional(function () use ($db, $az, $p): void {
            $title = $this->app->translator()->get('manpower.title_line', ['year' => (string) $p['fiscal_year'], 'n' => (string) $p['version_no'], 'title' => (string) $p['title']]);
            $inst = $this->app->workflow()->start('manpower.approve', (string) $p['public_id'], $title, (int) $p['legal_entity_id'], $az->userId);
            $db->run("UPDATE mp_plans SET status = 'review', workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?), updated_at = ? WHERE id = ?", [$inst, $this->now(), $p['id']]);
        });
        $this->app->audit()->record('manpower.plan_submitted', 'success', 'mp_plan', $pid, (int) $p['legal_entity_id']);
    }

    public function withdraw(Authorizer $az, string $pid): void
    {
        $p = $this->managed($az, $pid);
        if ($p['status'] !== 'draft') {
            throw new BusinessException('manpower.error.state', [], 409);
        }
        $this->app->db()->run("UPDATE mp_plans SET status = 'withdrawn', updated_at = ? WHERE id = ?", [$this->now(), $p['id']]);
    }

    /** Approved → the active baseline of the year; the former one is superseded; today's actual numbers are kept with it. */
    public function onApproved(string $pid, int $approverId): void
    {
        $db = $this->app->db();
        $p = $db->one("SELECT * FROM mp_plans WHERE public_id = ? AND status = 'review'", [$pid]);
        if ($p === null) {
            return;
        }
        $snap = $this->report((int) $p['id']);
        $now = $this->now();
        $db->transactional(function () use ($db, $p, $approverId, $snap, $now): void {
            $db->run("UPDATE mp_plans SET status = 'superseded', updated_at = ? WHERE legal_entity_id = ? AND fiscal_year = ? AND status = 'active'", [$now, $p['legal_entity_id'], $p['fiscal_year']]);
            $db->run(
                "UPDATE mp_plans SET status = 'active', approved_by = ?, approved_at = ?, snapshot_json = ?, snapshot_at = ?, updated_at = ? WHERE id = ?",
                [$approverId, $now, json_encode($snap, JSON_UNESCAPED_UNICODE), $now, $now, $p['id']]
            );
        });
        $this->app->audit()->record('manpower.plan_active', 'success', 'mp_plan', $pid, (int) $p['legal_entity_id'], null, ['year' => (int) $p['fiscal_year'], 'version' => (int) $p['version_no']]);
    }

    public function onClosed(string $pid, string $outcome): void
    {
        $this->app->db()->run("UPDATE mp_plans SET status = ?, updated_at = ? WHERE public_id = ? AND status = 'review'", [$outcome === 'returned' ? 'draft' : 'rejected', $this->now(), $pid]);
    }

    public function close(Authorizer $az, string $pid): void
    {
        $p = $this->managed($az, $pid);
        if ($p['status'] !== 'active') {
            throw new BusinessException('manpower.error.state', [], 409);
        }
        $this->app->db()->run("UPDATE mp_plans SET status = 'closed', closed_at = ?, updated_at = ? WHERE id = ?", [$this->now(), $this->now(), $p['id']]);
        $this->app->audit()->record('manpower.plan_closed', 'success', 'mp_plan', $pid, (int) $p['legal_entity_id']);
    }

    // ---- actual ---------------------------------------------------------------------------------------------------------------

    /**
     * Today's people of a company by unit and position title.
     *
     * @return array{employed: list<array{dept: int, key: string}>, joining: list<array{dept: int, key: string}>}
     */
    private function people(int $le): array
    {
        $today = $this->today();
        $out = ['employed' => [], 'joining' => []];
        foreach (
            $this->app->db()->all(
                'SELECT e.status, h.department_id, h.position_title FROM employment_history h JOIN employees e ON e.id = h.employee_id
                  WHERE h.legal_entity_id = ? AND h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?)
                  UNION ALL
                 SELECT e.status, h.department_id, h.position_title FROM employment_history h JOIN employees e ON e.id = h.employee_id
                  WHERE h.legal_entity_id = ? AND e.status = \'pre_hire\' AND h.effective_from > ? AND h.effective_to IS NULL',
                [$le, $today, $today, $le, $today]
            ) as $r
        ) {
            $row = ['dept' => (int) ($r['department_id'] ?? 0), 'key' => self::titleKey((string) $r['position_title'])];
            if (in_array($r['status'], self::EMPLOYED, true)) {
                $out['employed'][] = $row;
            } elseif ($r['status'] === 'pre_hire') {
                $out['joining'][] = $row;
            }
        }
        return $out;
    }

    /**
     * Requisitions for new people: open = approved heads not yet hired, pending = waiting for approval.
     *
     * @return list<array{dept: int, key: string, open: int, pending: int}>
     */
    private function requisitions(int $le, ?int $exceptReqId = null): array
    {
        if (!$this->app->db()->tableExists('rec_requisitions')) {
            return [];
        }
        $out = [];
        foreach (
            $this->app->db()->all(
                "SELECT r.id, r.department_id, r.position_title, r.headcount, r.status,
                        (SELECT COUNT(*) FROM rec_candidates c JOIN rec_postings p ON p.id = c.posting_id WHERE p.requisition_id = r.id AND c.stage = 'hired') AS hired
                   FROM rec_requisitions r WHERE r.legal_entity_id = ? AND r.reason = 'new' AND r.status IN ('pending','approved') AND r.id <> ?",
                [$le, $exceptReqId ?? 0]
            ) as $r
        ) {
            $left = max(0, (int) $r['headcount'] - (int) $r['hired']);
            $out[] = ['dept' => (int) ($r['department_id'] ?? 0), 'key' => self::titleKey((string) $r['position_title']), 'open' => $r['status'] === 'approved' ? $left : 0, 'pending' => $r['status'] === 'pending' ? $left : 0];
        }
        return $out;
    }

    /** @param array<string, mixed> $line */
    private static function matches(array $line, int $dept, string $key): bool
    {
        return ($line['department_id'] === null || (int) $line['department_id'] === $dept) && ((string) $line['title_key'] === '' || (string) $line['title_key'] === $key);
    }

    /**
     * Plan vs actual of a plan, per line (+ totals, + people not covered by any line).
     *
     * @return array{date: string, lines: list<array<string, mixed>>, totals: array<string, float|int>, unplanned: list<array{dept: string, title: string, heads: int}>}
     */
    public function report(int $planId): array
    {
        $db = $this->app->db();
        $p = $db->one('SELECT * FROM mp_plans WHERE id = ?', [$planId]);
        if ($p === null) {
            throw HttpException::notFound();
        }
        $le = (int) $p['legal_entity_id'];
        $people = $this->people($le);
        $reqs = $this->requisitions($le);
        $lines = $this->lines($planId);
        $totals = ['planned' => 0.0, 'actual' => 0, 'joining' => 0, 'open' => 0, 'pending' => 0, 'cost' => 0.0];
        $out = [];
        foreach ($lines as $l) {
            $a = count(array_filter($people['employed'], static fn (array $x): bool => self::matches($l, $x['dept'], $x['key'])));
            $j = count(array_filter($people['joining'], static fn (array $x): bool => self::matches($l, $x['dept'], $x['key'])));
            $o = 0;
            $pe = 0;
            foreach ($reqs as $r) {
                if (self::matches($l, $r['dept'], $r['key'])) {
                    $o += $r['open'];
                    $pe += $r['pending'];
                }
            }
            $cost = $l['monthly_cost'] !== null ? (float) $l['monthly_cost'] * (float) $l['headcount'] * (int) $l['months'] : null;
            $out[] = ['id' => (int) $l['id'], 'dept' => (string) ($l['dept_code'] ?? ''), 'title' => (string) $l['position_title'], 'planned' => (float) $l['headcount'], 'actual' => $a, 'joining' => $j,
                'open' => $o, 'pending' => $pe, 'variance' => round((float) $l['headcount'] - $a - $j - $o, 1), 'monthly_cost' => $l['monthly_cost'] !== null ? (float) $l['monthly_cost'] : null,
                'months' => (int) $l['months'], 'cost' => $cost, 'note' => (string) ($l['note'] ?? '')];
            $totals['planned'] += (float) $l['headcount'];
            $totals['cost'] += $cost ?? 0.0;
        }
        $totals['actual'] = count($people['employed']);
        $totals['joining'] = count($people['joining']);
        foreach ($reqs as $r) {
            $totals['open'] += $r['open'];
            $totals['pending'] += $r['pending'];
        }
        $un = [];
        foreach ($people['employed'] as $x) {
            $covered = false;
            foreach ($lines as $l) {
                if (self::matches($l, $x['dept'], $x['key'])) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $k = $x['dept'] . '|' . $x['key'];
                $un[$k] = ($un[$k] ?? 0) + 1;
            }
        }
        $unplanned = [];
        $titles = [];
        foreach ($db->all('SELECT DISTINCT h.department_id, h.position_title FROM employment_history h WHERE h.legal_entity_id = ? AND h.effective_to IS NULL', [$le]) as $t) {
            $titles[(int) ($t['department_id'] ?? 0) . '|' . self::titleKey((string) $t['position_title'])] = (string) $t['position_title'];
        }
        foreach ($un as $k => $n) {
            [$d] = explode('|', $k, 2);
            $unplanned[] = ['dept' => (string) ($db->scalar('SELECT code FROM departments WHERE id = ?', [(int) $d]) ?? '—'), 'title' => $titles[$k] ?? '—', 'heads' => $n];
        }
        return ['date' => $this->today(), 'lines' => $out, 'totals' => $totals, 'unplanned' => $unplanned];
    }

    // ---- requisition check (M08) ----------------------------------------------------------------------------------------------

    /**
     * Compares a request for new people with the active plan of the year. Called by M08 before a requisition is saved.
     *
     * @return array{result: string, mode: string, plan_id: int|null, line_id: int|null, planned: float|null, actual: int|null, open: int|null, requested: int}
     */
    public function checkRequest(int $le, ?int $deptId, string $title, int $heads, string $date): array
    {
        $mode = $this->settings($le)['over_budget'];
        $plan = $this->active($le, (int) substr($date, 0, 4));
        $base = ['mode' => $mode, 'plan_id' => null, 'line_id' => null, 'planned' => null, 'actual' => null, 'open' => null, 'requested' => $heads];
        if ($plan === null) {
            return ['result' => 'no_plan'] + $base;
        }
        $key = self::titleKey($title);
        $dept = $deptId ?? 0;
        $best = null;
        $rank = 99;
        foreach ($this->lines((int) $plan['id']) as $l) {
            if (!self::matches($l, $dept, $key)) {
                continue;
            }
            $r = ($l['department_id'] !== null ? 0 : 2) + ((string) $l['title_key'] !== '' ? 0 : 1);
            if ($r < $rank) {
                $rank = $r;
                $best = $l;
            }
        }
        if ($best === null) {
            return ['result' => 'no_line', 'plan_id' => (int) $plan['id']] + $base;
        }
        $people = $this->people($le);
        $used = count(array_filter(array_merge($people['employed'], $people['joining']), static fn (array $x): bool => self::matches($best, $x['dept'], $x['key'])));
        $open = 0;
        foreach ($this->requisitions($le) as $r) {
            if (self::matches($best, $r['dept'], $r['key'])) {
                $open += $r['open'] + $r['pending'];
            }
        }
        $planned = (float) $best['headcount'];
        return ['result' => $used + $open + $heads > $planned ? 'over' : 'within', 'plan_id' => (int) $plan['id'], 'line_id' => (int) $best['id'], 'planned' => $planned, 'actual' => $used, 'open' => $open] + $base;
    }

    /** @param array<string, mixed> $check */
    public function recordCheck(int $requisitionId, array $check): void
    {
        $this->app->db()->run(
            'INSERT INTO mp_checks (requisition_id, plan_id, line_id, planned, actual, open_heads, requested, result, mode, checked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE plan_id = VALUES(plan_id), line_id = VALUES(line_id), planned = VALUES(planned), actual = VALUES(actual), open_heads = VALUES(open_heads),
             requested = VALUES(requested), result = VALUES(result), mode = VALUES(mode), checked_at = VALUES(checked_at)',
            [$requisitionId, $check['plan_id'], $check['line_id'], $check['planned'], $check['actual'], $check['open'], $check['requested'], $check['result'], $check['mode'], $this->now()]
        );
    }

    /** @return array<string, mixed>|null the check made for a requisition */
    public function checkFor(int $requisitionId): ?array
    {
        return $this->app->db()->one('SELECT c.*, p.public_id AS plan_pid, p.fiscal_year, p.version_no FROM mp_checks c LEFT JOIN mp_plans p ON p.id = c.plan_id WHERE c.requisition_id = ?', [$requisitionId]);
    }

    /**
     * One line for screens and approvers.
     *
     * @param array<string, mixed> $c
     */
    public function describeCheck(array $c): string
    {
        $t = $this->app->translator();
        $c['open'] ??= $c['open_heads'] ?? 0;
        return match ((string) $c['result']) {
            'within' => $t->get('manpower.check.within', ['planned' => (string) (float) $c['planned'], 'used' => (string) ((int) $c['actual'] + (int) $c['open']), 'asked' => (string) $c['requested']]),
            'over' => $t->get('manpower.check.over', ['planned' => (string) (float) $c['planned'], 'used' => (string) ((int) $c['actual'] + (int) $c['open']), 'asked' => (string) $c['requested']]),
            'no_line' => $t->get('manpower.check.no_line'),
            default => $t->get('manpower.check.no_plan'),
        };
    }

    // ---- history ---------------------------------------------------------------------------------------------------------------

    public static function snapshotJob(App $app): int
    {
        $svc = self::of($app);
        $month = substr($svc->today(), 0, 7);
        $n = 0;
        foreach (array_map('intval', array_column($app->db()->all('SELECT id FROM legal_entities'), 'id')) as $le) {
            $n += $svc->snapshot($le, $month);
        }
        return $n;
    }

    /** Headcount per unit of the month (once — the first run of the month wins). */
    public function snapshot(int $le, string $month): int
    {
        $counts = [];
        foreach ($this->people($le)['employed'] as $x) {
            $counts[$x['dept']] = ($counts[$x['dept']] ?? 0) + 1;
        }
        $n = 0;
        foreach ($counts as $dept => $heads) {
            $n += $this->app->db()->run('INSERT IGNORE INTO mp_snapshots (legal_entity_id, snap_month, department_id, headcount, taken_at) VALUES (?, ?, ?, ?, ?)', [$le, $month, $dept, $heads, $this->now()])->rowCount();
        }
        return $n;
    }

    /** @return array<string, int> month => company headcount (last 12 kept months) */
    public function history(int $le): array
    {
        $out = [];
        foreach ($this->app->db()->all('SELECT snap_month, SUM(headcount) AS n FROM mp_snapshots WHERE legal_entity_id = ? GROUP BY snap_month ORDER BY snap_month DESC LIMIT 12', [$le]) as $r) {
            $out[(string) $r['snap_month']] = (int) $r['n'];
        }
        return array_reverse($out, true);
    }
}
