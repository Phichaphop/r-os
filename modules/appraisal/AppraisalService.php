<?php

declare(strict_types=1);

namespace Blue\Modules\Appraisal;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\Audience;
use Blue\Modules\Employees\EmployeeService;
use Blue\Modules\Employees\PersonnelFileService;

/**
 * M38 Performance appraisal (phase 5E-4, DEC-79).
 *
 *  form       scale (1 … N), rating bands the company defines (score from → label; none = score only) and weighted
 *             items — KPIs and competencies — whose weights must total 100.
 *  cycle      mid-year / year-end / probation / other: a form, a target group, deadlines (self, manager, final review),
 *             the day results are shown, and whether a final review (calibration) step is used. Opening the cycle
 *             freezes the form into it (later form edits never change an open cycle) and creates one appraisal per
 *             person in the group, with their line manager as evaluator.
 *  steps      self review (the employee scores each item, notes, comment) → manager review (scores, notes, strengths,
 *             development plan, an optional pay/bonus PROPOSAL that HR sees — nothing changes pay) → final review
 *             (when used: approve or return to the manager; may set a calibrated score with a reason) → finalized →
 *             the employee acknowledges, or acknowledges with an appeal (reason) that HR closes with an outcome or
 *             reopens to the manager.
 *  score      score = Σ(item score × weight) ÷ Σ weight, shown with the formula; every item must be scored before
 *             submitting; the label comes from the bands.
 *  who sees   the employee: their own self review, and the manager's part only once finalized AND the results day has
 *             come; the evaluator: their people (the self review once submitted); the final reviewer: after the
 *             manager; HR of the company: everything, reports and export (audited). Changing the evaluator needs HR
 *             and a reason; HR may skip the self review with a reason. Every change is kept in the history.
 */
final class AppraisalService
{
    public const PERIODS = ['mid_year', 'year_end', 'probation', 'other'];
    public const SECTIONS = ['kpi', 'competency'];

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

    /** @return list<int> */
    public function companies(Authorizer $az): array
    {
        $scope = $az->scope('appraisal.manage');
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $scope->coversLegalEntity($id)));
    }

    private function hr(Authorizer $az, int $le): bool
    {
        return $az->scope('appraisal.manage')->coversLegalEntity($le);
    }

    // ---- forms ----------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function forms(int $le): array
    {
        return $this->app->db()->all('SELECT f.*, (SELECT COUNT(*) FROM apr_cycles c WHERE c.form_id = f.id) AS cycles FROM apr_forms f WHERE f.legal_entity_id = ? ORDER BY f.status, f.code', [$le]);
    }

    /**
     * @param array<string, mixed> $in code, name, scale_max, bands (text "4.5 | label" per line), items: list<array{section, title, weight, description}>
     */
    public function saveForm(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        if (!$this->hr($az, $le)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $cur = $pid !== null ? $db->one('SELECT * FROM apr_forms WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]) : null;
        if ($pid !== null && $cur === null) {
            throw HttpException::notFound();
        }
        $errors = [];
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,29}$/', $code) !== 1) {
            $errors['code'] = 'appraisal.error.code';
        } elseif ($db->scalar('SELECT id FROM apr_forms WHERE legal_entity_id = ? AND code = ? AND id <> ?', [$le, $code, (int) ($cur['id'] ?? 0)]) !== null) {
            $errors['code'] = 'appraisal.error.code_taken';
        }
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 150);
        if (mb_strlen($name) < 3) {
            $errors['name'] = 'appraisal.error.name';
        }
        $scale = (int) ($in['scale_max'] ?? 5);
        if ($scale < 3 || $scale > 10) {
            $errors['scale_max'] = 'appraisal.error.scale';
        }
        $bands = [];
        foreach (preg_split('/\R/', (string) ($in['bands'] ?? '')) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $p = array_map('trim', explode('|', $line, 2));
            if (count($p) !== 2 || preg_match('/^\d{1,2}(\.\d{1,2})?$/', $p[0]) !== 1 || $p[1] === '' || (float) $p[0] > $scale) {
                $errors['bands'] = 'appraisal.error.bands';
                break;
            }
            $bands[] = ['from' => (float) $p[0], 'label' => mb_substr($p[1], 0, 80)];
        }
        usort($bands, static fn (array $a, array $b): int => $b['from'] <=> $a['from']);
        $items = [];
        $total = 0.0;
        foreach ((array) ($in['items'] ?? []) as $i => $it) {
            $it = (array) $it;
            $title = mb_substr(trim((string) ($it['title'] ?? '')), 0, 200);
            $w = str_replace(',', '', trim((string) ($it['weight'] ?? '')));
            if ($title === '' && $w === '') {
                continue;
            }
            if (mb_strlen($title) < 2 || preg_match('/^\d{1,3}(\.\d{1,2})?$/', $w) !== 1 || (float) $w <= 0) {
                $errors['item_' . $i] = 'appraisal.error.item';
                continue;
            }
            $items[] = ['section' => in_array($it['section'] ?? '', self::SECTIONS, true) ? (string) $it['section'] : 'kpi', 'title' => $title, 'weight' => (float) $w,
                'description' => mb_substr(trim((string) ($it['description'] ?? '')), 0, 1000)];
            $total += (float) $w;
        }
        if ($items === []) {
            $errors['items'] = 'appraisal.error.no_items';
        } elseif (abs($total - 100.0) > 0.001) {
            $errors['items'] = 'appraisal.error.weights';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $now = $this->now();
        $vals = [$code, $name, $scale, $bands !== [] ? json_encode($bands, JSON_UNESCAPED_UNICODE) : null, (string) json_encode($items, JSON_UNESCAPED_UNICODE)];
        if ($cur !== null) {
            $db->run(
                'UPDATE apr_forms SET code = ?, name = ?, scale_max = ?, bands_json = ?, items_json = ?, status = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                [...$vals, ($in['status'] ?? '') === 'archived' ? 'archived' : 'active', $now, $az->userId, $cur['id']]
            );
            $this->app->audit()->record('appraisal.form_saved', 'success', 'apr_form', (string) $pid, $le, null, ['code' => $code]);
            return (string) $pid;
        }
        $pid = Ulid::generate($this->app->clock);
        $db->run(
            'INSERT INTO apr_forms (code, name, scale_max, bands_json, items_json, public_id, created_at, created_by, updated_at, updated_by, legal_entity_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [...$vals, $pid, $now, $az->userId, $now, $az->userId, $le]
        );
        $this->app->audit()->record('appraisal.form_created', 'success', 'apr_form', $pid, $le, null, ['code' => $code]);
        return $pid;
    }

    // ---- cycles ---------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function cycles(int $le): array
    {
        return $this->app->db()->all(
            "SELECT c.*, f.code AS form_code, (SELECT COUNT(*) FROM apr_appraisals a WHERE a.cycle_id = c.id) AS people,
                    (SELECT COUNT(*) FROM apr_appraisals a WHERE a.cycle_id = c.id AND a.status IN ('finalized','acknowledged')) AS done
               FROM apr_cycles c JOIN apr_forms f ON f.id = c.form_id WHERE c.legal_entity_id = ? ORDER BY c.id DESC",
            [$le]
        );
    }

    /** @return array<string, mixed> a cycle of a company the person manages */
    public function managedCycle(Authorizer $az, string $pid): array
    {
        $c = $this->app->db()->one('SELECT c.*, f.code AS form_code, f.public_id AS form_pid FROM apr_cycles c JOIN apr_forms f ON f.id = c.form_id WHERE c.public_id = ?', [$pid]);
        if ($c === null || !$this->hr($az, (int) $c['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        return $c;
    }

    /** @param array<string, mixed> $in code, name, period, form, self_due, manager_due, final_due, results_on, final_review + audience fields */
    public function saveCycle(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        if (!$this->hr($az, $le)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $cur = $pid !== null ? $db->one('SELECT * FROM apr_cycles WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]) : null;
        if ($pid !== null && $cur === null) {
            throw HttpException::notFound();
        }
        if ($cur !== null && $cur['status'] !== 'draft') {
            throw new BusinessException('appraisal.error.cycle_locked', [], 409);
        }
        $errors = [];
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,29}$/', $code) !== 1) {
            $errors['code'] = 'appraisal.error.code';
        } elseif ($db->scalar('SELECT id FROM apr_cycles WHERE legal_entity_id = ? AND code = ? AND id <> ?', [$le, $code, (int) ($cur['id'] ?? 0)]) !== null) {
            $errors['code'] = 'appraisal.error.code_taken';
        }
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 150);
        if (mb_strlen($name) < 3) {
            $errors['name'] = 'appraisal.error.name';
        }
        $form = $db->one("SELECT * FROM apr_forms WHERE public_id = ? AND legal_entity_id = ? AND status = 'active'", [(string) ($in['form'] ?? ''), $le]);
        if ($form === null) {
            $errors['form'] = 'appraisal.error.form';
        }
        $d = [];
        foreach (['self_due', 'manager_due', 'final_due', 'results_on'] as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v === '' && $k === 'final_due') {
                $d[$k] = null;
                continue;
            }
            if (!$this->isDate($v)) {
                $errors[$k] = 'form.invalid_date';
            }
            $d[$k] = $v;
        }
        $final = ($in['final_review'] ?? '') === '1';
        if (!isset($errors['self_due'], $errors['manager_due']) && $this->isDate((string) $d['self_due']) && $this->isDate((string) $d['manager_due']) && $d['manager_due'] < $d['self_due']) {
            $errors['manager_due'] = 'appraisal.error.dates';
        }
        if ($final && $d['final_due'] === null) {
            $errors['final_due'] = 'appraisal.error.final_due';
        }
        try {
            $aud = Audience::fromInput($this->app, $le, $in);
        } catch (ValidationException $e) {
            $errors += $e->errors;
            $aud = Audience::empty();
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $period = in_array($in['period'] ?? '', self::PERIODS, true) ? (string) $in['period'] : 'other';
        $vals = [$code, $name, $period, (int) $form['id'], Audience::encode($aud), $d['self_due'], $d['manager_due'], $d['final_due'], $d['results_on'], $final ? 1 : 0];
        $now = $this->now();
        if ($cur !== null) {
            $db->run(
                'UPDATE apr_cycles SET code = ?, name = ?, period = ?, form_id = ?, audience_json = ?, self_due = ?, manager_due = ?, final_due = ?, results_on = ?, final_review = ?, updated_at = ?, updated_by = ? WHERE id = ?',
                [...$vals, $now, $az->userId, $cur['id']]
            );
            return (string) $pid;
        }
        $pid = Ulid::generate($this->app->clock);
        $db->run('INSERT INTO apr_cycles (code, name, period, form_id, audience_json, self_due, manager_due, final_due, results_on, final_review, public_id, created_at, created_by, updated_at, updated_by, legal_entity_id)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$vals, $pid, $now, $az->userId, $now, $az->userId, $le]);
        $this->app->audit()->record('appraisal.cycle_created', 'success', 'apr_cycle', $pid, $le, null, ['code' => $code]);
        return $pid;
    }

    /** Opens a draft cycle: freezes the form and creates one appraisal per person of the target group. Returns how many. */
    public function open(Authorizer $az, string $pid): int
    {
        $c = $this->managedCycle($az, $pid);
        if ($c['status'] !== 'draft') {
            throw new BusinessException('appraisal.error.state', [], 409);
        }
        $db = $this->app->db();
        $form = $db->one('SELECT * FROM apr_forms WHERE id = ?', [$c['form_id']]);
        $snapshot = (string) json_encode(['code' => $form['code'], 'name' => $form['name'], 'scale_max' => (int) $form['scale_max'], 'bands' => json_decode((string) ($form['bands_json'] ?? '[]'), true) ?: [],
            'items' => json_decode((string) $form['items_json'], true), 'version' => (int) $form['row_version']], JSON_UNESCAPED_UNICODE);
        $le = (int) $c['legal_entity_id'];
        $members = Audience::members($this->app, $le, Audience::decode((string) $c['audience_json']), $this->today());
        $emp = EmployeeService::of($this->app);
        $now = $this->now();
        $n = 0;
        $db->transactional(function () use ($db, $c, $snapshot, $members, $emp, $now, &$n, $az): void {
            $db->run("UPDATE apr_cycles SET status = 'open', form_snapshot = ?, opened_at = ?, updated_at = ?, updated_by = ? WHERE id = ?", [$snapshot, $now, $now, $az->userId, $c['id']]);
            foreach ($members as $empId => $uid) {
                $status = (string) $db->scalar('SELECT status FROM employees WHERE id = ?', [$empId]);
                if (!in_array($status, ['active', 'on_leave'], true)) {
                    continue;
                }
                $mgr = $emp->managerUserIds($empId, $this->today())[0] ?? null;
                $apid = Ulid::generate($this->app->clock);
                $db->run(
                    'INSERT IGNORE INTO apr_appraisals (public_id, cycle_id, employee_id, user_id, manager_user_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$apid, $c['id'], $empId, $uid, $mgr, $uid !== null ? 'self_review' : 'manager_review', $now, $now]
                );
                $id = (int) $db->scalar('SELECT id FROM apr_appraisals WHERE public_id = ?', [$apid]);
                $this->event($id, 'created', $uid === null ? 'no account — self review skipped' : null, $az->userId);
                $n++;
            }
        });
        foreach ($db->all('SELECT public_id, user_id, manager_user_id, status FROM apr_appraisals WHERE cycle_id = ?', [$c['id']]) as $a) {
            $to = $a['status'] === 'self_review' ? $a['user_id'] : $a['manager_user_id'];
            if ($to !== null) {
                $this->app->notifier()->notify((int) $to, 'appraisal.task', 'appraisal.notify.' . $a['status'], ['cycle' => (string) $c['name']], '/appraisal/a/' . $a['public_id'], 'apr.start:' . $a['public_id']);
            }
        }
        $this->app->audit()->record('appraisal.cycle_opened', 'success', 'apr_cycle', $pid, $le, null, ['people' => $n]);
        return $n;
    }

    public function close(Authorizer $az, string $pid): void
    {
        $c = $this->managedCycle($az, $pid);
        if ($c['status'] !== 'open') {
            throw new BusinessException('appraisal.error.state', [], 409);
        }
        $this->app->db()->run("UPDATE apr_cycles SET status = 'closed', closed_at = ?, updated_at = ? WHERE id = ?", [$this->now(), $this->now(), $c['id']]);
        $this->app->audit()->record('appraisal.cycle_closed', 'success', 'apr_cycle', $pid, (int) $c['legal_entity_id']);
    }

    /** @return list<array<string, mixed>> appraisals of a cycle (HR) */
    public function roster(int $cycleId): array
    {
        return $this->app->db()->all(
            "SELECT a.*, e.employee_code, e.name_th, d.code AS dept_code, m.display_name AS manager_name, r.display_name AS reviewer_name
               FROM apr_appraisals a JOIN employees e ON e.id = a.employee_id
               LEFT JOIN employment_history h ON h.employee_id = e.id AND h.effective_to IS NULL LEFT JOIN departments d ON d.id = h.department_id
               LEFT JOIN users m ON m.id = a.manager_user_id LEFT JOIN users r ON r.id = a.reviewer_user_id
              WHERE a.cycle_id = ? ORDER BY d.code, e.employee_code",
            [$cycleId]
        );
    }

    /**
     * Status counts, average final score per unit and the rating distribution of a cycle.
     *
     * @return array{status: array<string, int>, units: list<array{unit: string, people: int, avg: float|null}>, bands: array<string, int>, appeals: int}
     */
    public function summary(int $cycleId): array
    {
        $rows = $this->roster($cycleId);
        $status = array_fill_keys(['self_review', 'manager_review', 'final_review', 'finalized', 'acknowledged'], 0);
        $units = [];
        $bands = [];
        $appeals = 0;
        foreach ($rows as $r) {
            $status[(string) $r['status']]++;
            $u = (string) ($r['dept_code'] ?? '—');
            $units[$u] ??= ['unit' => $u, 'people' => 0, 'sum' => 0.0, 'n' => 0];
            $units[$u]['people']++;
            if ($r['final_score'] !== null) {
                $units[$u]['sum'] += (float) $r['final_score'];
                $units[$u]['n']++;
                $label = (string) ($r['rating_label'] ?? '—');
                $bands[$label] = ($bands[$label] ?? 0) + 1;
            }
            $appeals += (int) $r['appeal'] === 1 && $r['appeal_status'] === 'open' ? 1 : 0;
        }
        return ['status' => $status, 'units' => array_values(array_map(static fn (array $u): array => ['unit' => $u['unit'], 'people' => $u['people'], 'avg' => $u['n'] > 0 ? round($u['sum'] / $u['n'], 2) : null], $units)),
            'bands' => $bands, 'appeals' => $appeals];
    }

    // ---- one appraisal --------------------------------------------------------------------------------------------------------

    /**
     * An appraisal with what the person may see.
     *
     * @return array<string, mixed> appraisal + cycle + form + scores + role flags (self, manager, reviewer, hr, show_manager)
     */
    public function view(Authorizer $az, string $pid): array
    {
        $db = $this->app->db();
        $a = $db->one(
            'SELECT a.*, c.name AS cycle_name, c.public_id AS cycle_pid, c.form_snapshot, c.self_due, c.manager_due, c.final_due, c.results_on, c.final_review, c.status AS cycle_status,
                    c.legal_entity_id, c.period, e.employee_code, e.name_th, e.public_id AS emp_pid, m.display_name AS manager_name, r.display_name AS reviewer_name
               FROM apr_appraisals a JOIN apr_cycles c ON c.id = a.cycle_id JOIN employees e ON e.id = a.employee_id
               LEFT JOIN users m ON m.id = a.manager_user_id LEFT JOIN users r ON r.id = a.reviewer_user_id WHERE a.public_id = ?',
            [$pid]
        );
        if ($a === null) {
            throw HttpException::notFound();
        }
        $a['is_self'] = $a['user_id'] !== null && (int) $a['user_id'] === $az->userId;
        $a['is_manager'] = $a['manager_user_id'] !== null && (int) $a['manager_user_id'] === $az->userId;
        $a['is_reviewer'] = $a['reviewer_user_id'] !== null && (int) $a['reviewer_user_id'] === $az->userId && in_array($a['status'], ['final_review', 'finalized', 'acknowledged'], true);
        $a['is_hr'] = $this->hr($az, (int) $a['legal_entity_id']);
        if (!$a['is_self'] && !$a['is_manager'] && !$a['is_reviewer'] && !$a['is_hr']) {
            throw HttpException::notFound();
        }
        $a['form'] = (array) json_decode((string) $a['form_snapshot'], true);
        // the person appraised sees the evaluator's part only once finalized and the results day has come — even when they are HR
        $a['released'] = in_array($a['status'], ['finalized', 'acknowledged'], true) && $this->today() >= (string) $a['results_on'];
        $a['show_manager'] = $a['is_self'] ? $a['released'] : true;
        $a['show_self'] = $a['is_self'] || $a['status'] !== 'self_review';
        if ($a['is_self']) {
            $a['is_hr'] = false; // nobody runs HR actions on their own appraisal
        }
        $scores = [];
        foreach ($db->all('SELECT * FROM apr_scores WHERE appraisal_id = ?', [$a['id']]) as $s) {
            $scores[(int) $s['item_no']] = $s;
        }
        $a['scores'] = $scores;
        if (!$a['show_manager']) {
            foreach (['manager_score', 'final_score', 'rating_label', 'manager_comment', 'strengths', 'development_plan', 'reviewer_comment', 'calibration_reason'] as $k) {
                $a[$k] = null;
            }
            foreach ($a['scores'] as &$s) {
                $s['manager_score'] = null;
                $s['manager_note'] = null;
            }
            unset($s);
        }
        if (!$a['show_self']) {
            $a['self_comment'] = null;
            $a['self_score'] = null;
            foreach ($a['scores'] as &$s) {
                $s['self_score'] = null;
                $s['self_note'] = null;
            }
            unset($s);
        }
        if ($a['is_self']) {
            $a['comp_proposal'] = null;
        }
        if ($a['is_hr']) {
            $this->app->audit()->record('appraisal.viewed', 'success', 'apr_appraisal', $pid, (int) $a['legal_entity_id']);
        }
        return $a;
    }

    /** @return list<array<string, mixed>> my own appraisals and the ones I evaluate or review */
    public function inbox(Authorizer $az): array
    {
        return $this->app->db()->all(
            "SELECT a.*, c.name AS cycle_name, c.self_due, c.manager_due, c.final_due, c.results_on, e.employee_code, e.name_th,
                    CASE WHEN a.user_id = ? THEN 'self' WHEN a.manager_user_id = ? THEN 'manager' ELSE 'reviewer' END AS my_role
               FROM apr_appraisals a JOIN apr_cycles c ON c.id = a.cycle_id JOIN employees e ON e.id = a.employee_id
              WHERE a.user_id = ? OR a.manager_user_id = ? OR (a.reviewer_user_id = ? AND a.status IN ('final_review','finalized','acknowledged'))
              ORDER BY c.id DESC, e.employee_code",
            [$az->userId, $az->userId, $az->userId, $az->userId, $az->userId]
        );
    }

    /** @return list<array<string, mixed>> earlier finished appraisals of the same person (to compare) */
    public function history(int $employeeId, int $exceptId, bool $own): array
    {
        $rows = $this->app->db()->all(
            "SELECT a.public_id, a.final_score, a.rating_label, a.status, c.name AS cycle_name, c.results_on FROM apr_appraisals a JOIN apr_cycles c ON c.id = a.cycle_id
              WHERE a.employee_id = ? AND a.id <> ? AND a.status IN ('finalized','acknowledged') ORDER BY c.id DESC LIMIT 10",
            [$employeeId, $exceptId]
        );
        return $own ? array_values(array_filter($rows, fn (array $r): bool => $this->today() >= (string) $r['results_on'])) : $rows;
    }

    /**
     * Saves the self review (draft) and submits it when asked.
     *
     * @param array<int, array{score?: string, note?: string}> $items
     */
    public function saveSelf(Authorizer $az, string $pid, array $items, string $comment, bool $submit): void
    {
        $a = $this->view($az, $pid);
        if (!$a['is_self'] || $a['status'] !== 'self_review' || $a['cycle_status'] !== 'open') {
            throw new BusinessException('appraisal.error.state', [], 409);
        }
        $this->storeScores($a, $items, 'self', $submit);
        $db = $this->app->db();
        $db->run('UPDATE apr_appraisals SET self_comment = ?, updated_at = ? WHERE id = ?', [mb_substr(trim($comment), 0, 4000) ?: null, $this->now(), $a['id']]);
        if ($submit) {
            $score = $this->score($a, 'self');
            $db->run("UPDATE apr_appraisals SET status = 'manager_review', self_score = ?, self_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$score, $this->now(), $this->now(), $a['id']]);
            $this->event((int) $a['id'], 'self_submitted', null, $az->userId);
            if ($a['manager_user_id'] !== null) {
                $this->app->notifier()->notify((int) $a['manager_user_id'], 'appraisal.task', 'appraisal.notify.manager_review', ['cycle' => (string) $a['cycle_name']], '/appraisal/a/' . $pid, 'apr.mgr:' . $pid);
            }
        }
    }

    /**
     * Saves the manager review and submits it when asked (to final review, or finalized when the cycle has none).
     *
     * @param array<int, array{score?: string, note?: string}> $items
     * @param array<string, string> $text manager_comment, strengths, development_plan, comp_proposal
     */
    public function saveManager(Authorizer $az, string $pid, array $items, array $text, bool $submit): void
    {
        $a = $this->view($az, $pid);
        if (!$a['is_manager'] || $a['status'] !== 'manager_review' || $a['cycle_status'] !== 'open') {
            throw new BusinessException('appraisal.error.state', [], 409);
        }
        if ((int) $a['user_id'] === $az->userId) {
            throw new BusinessException('appraisal.error.own', [], 409);
        }
        $this->storeScores($a, $items, 'manager', $submit);
        $errors = [];
        if ($submit && mb_strlen(trim($text['manager_comment'] ?? '')) < 5) {
            $errors['manager_comment'] = 'appraisal.error.comment';
        }
        if ($submit && mb_strlen(trim($text['development_plan'] ?? '')) < 5) {
            $errors['development_plan'] = 'appraisal.error.plan';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $db = $this->app->db();
        $db->run('UPDATE apr_appraisals SET manager_comment = ?, strengths = ?, development_plan = ?, comp_proposal = ?, updated_at = ? WHERE id = ?', [
            mb_substr(trim($text['manager_comment'] ?? ''), 0, 4000) ?: null, mb_substr(trim($text['strengths'] ?? ''), 0, 4000) ?: null,
            mb_substr(trim($text['development_plan'] ?? ''), 0, 4000) ?: null, mb_substr(trim($text['comp_proposal'] ?? ''), 0, 500) ?: null, $this->now(), $a['id'],
        ]);
        if (!$submit) {
            return;
        }
        $score = $this->score($a, 'manager');
        $label = $this->band($a['form'], $score);
        if ((int) $a['final_review'] === 1) {
            $reviewer = $a['reviewer_user_id'] !== null ? (int) $a['reviewer_user_id'] : $this->defaultReviewer($a, $az->userId);
            $db->run(
                "UPDATE apr_appraisals SET status = 'final_review', manager_score = ?, final_score = ?, rating_label = ?, reviewer_user_id = ?, manager_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?",
                [$score, $score, $label, $reviewer, $this->now(), $this->now(), $a['id']]
            );
            $this->event((int) $a['id'], 'manager_submitted', null, $az->userId);
            if ($reviewer !== null) {
                $this->app->notifier()->notify($reviewer, 'appraisal.task', 'appraisal.notify.final_review', ['cycle' => (string) $a['cycle_name']], '/appraisal/a/' . $pid, 'apr.rev:' . $pid . ':' . $this->now());
            }
            return;
        }
        $db->run(
            "UPDATE apr_appraisals SET status = 'finalized', manager_score = ?, final_score = ?, rating_label = ?, manager_at = ?, finalized_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?",
            [$score, $score, $label, $this->now(), $this->now(), $this->now(), $a['id']]
        );
        $this->event((int) $a['id'], 'manager_submitted', null, $az->userId);
        $this->finalized($a);
    }

    /**
     * The manager's own line manager (if any and not the manager), else nobody — HR assigns.
     *
     * @param array<string, mixed> $a
     */
    private function defaultReviewer(array $a, int $managerUserId): ?int
    {
        $empId = $this->app->db()->scalar('SELECT employee_id FROM users WHERE id = ?', [$managerUserId]);
        if ($empId === null) {
            return null;
        }
        $up = EmployeeService::of($this->app)->managerUserIds((int) $empId, $this->today())[0] ?? null;
        return $up !== null && $up !== (int) $a['user_id'] && $up !== $managerUserId ? $up : null;
    }

    /** Final review: approve (optionally with a calibrated score + reason) or return to the manager with a note. */
    public function finalReview(Authorizer $az, string $pid, string $decision, string $comment, string $calibrated, string $reason): void
    {
        $a = $this->view($az, $pid);
        $isReviewer = $a['reviewer_user_id'] !== null && (int) $a['reviewer_user_id'] === $az->userId;
        if ($a['status'] !== 'final_review' || (!$isReviewer && !$a['is_hr']) || $a['cycle_status'] !== 'open' || in_array($az->userId, [(int) $a['user_id'], (int) $a['manager_user_id']], true)) {
            throw new BusinessException('appraisal.error.state', [], 409);
        }
        $db = $this->app->db();
        $comment = mb_substr(trim($comment), 0, 4000);
        if ($decision === 'return') {
            if (mb_strlen($comment) < 5) {
                throw new ValidationException(['reviewer_comment' => 'appraisal.error.comment']);
            }
            $db->run("UPDATE apr_appraisals SET status = 'manager_review', reviewer_comment = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$comment, $this->now(), $a['id']]);
            $this->event((int) $a['id'], 'returned', $comment, $az->userId);
            if ($a['manager_user_id'] !== null) {
                $this->app->notifier()->notify((int) $a['manager_user_id'], 'appraisal.task', 'appraisal.notify.returned', ['cycle' => (string) $a['cycle_name']], '/appraisal/a/' . $pid, 'apr.ret:' . $pid . ':' . $this->now());
            }
            return;
        }
        $score = (float) $a['manager_score'];
        $cal = trim($calibrated);
        if ($cal !== '') {
            $max = (int) ($a['form']['scale_max'] ?? 5);
            if (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $cal) !== 1 || (float) $cal < 1 || (float) $cal > $max) {
                throw new ValidationException(['calibrated' => 'appraisal.error.calibrated']);
            }
            if ((float) $cal !== $score && mb_strlen(trim($reason)) < 5) {
                throw new ValidationException(['calibration_reason' => 'appraisal.error.reason']);
            }
            $score = (float) $cal;
        }
        $db->run(
            "UPDATE apr_appraisals SET status = 'finalized', final_score = ?, rating_label = ?, reviewer_comment = ?, calibration_reason = ?, reviewer_user_id = ?, finalized_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?",
            [$score, $this->band($a['form'], $score), $comment !== '' ? $comment : null, $score !== (float) $a['manager_score'] ? mb_substr(trim($reason), 0, 500) : null, $az->userId, $this->now(), $this->now(), $a['id']]
        );
        $this->event((int) $a['id'], 'finalized', $score !== (float) $a['manager_score'] ? 'calibrated ' . $a['manager_score'] . ' → ' . $score . ' · ' . trim($reason) : null, $az->userId);
        $this->finalized($a);
    }

    /** @param array<string, mixed> $a */
    private function finalized(array $a): void
    {
        if ($a['user_id'] !== null) {
            $this->app->notifier()->notify((int) $a['user_id'], 'appraisal.result', 'appraisal.notify.finalized', ['cycle' => (string) $a['cycle_name'], 'date' => (string) $a['results_on']], '/appraisal/a/' . $a['public_id'], 'apr.fin:' . $a['public_id']);
        }
    }

    /** The employee acknowledges the result, or acknowledges with an appeal and its reason. */
    public function acknowledge(Authorizer $az, string $pid, bool $appeal, string $comment): void
    {
        $a = $this->view($az, $pid);
        if (!$a['is_self'] || $a['status'] !== 'finalized' || !$a['show_manager']) {
            throw new BusinessException('appraisal.error.state', [], 409);
        }
        $comment = mb_substr(trim($comment), 0, 4000);
        if ($appeal && mb_strlen($comment) < 10) {
            throw new ValidationException(['employee_comment' => 'appraisal.error.appeal']);
        }
        $this->app->db()->run(
            "UPDATE apr_appraisals SET status = 'acknowledged', employee_comment = ?, appeal = ?, appeal_status = ?, acknowledged_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?",
            [$comment !== '' ? $comment : null, $appeal ? 1 : 0, $appeal ? 'open' : null, $this->now(), $this->now(), $a['id']]
        );
        $this->event((int) $a['id'], $appeal ? 'appealed' : 'acknowledged', $appeal ? $comment : null, $az->userId);
        if ($appeal) {
            foreach (PersonnelFileService::of($this->app)->holders('appraisal.manage', (int) $a['legal_entity_id']) as $uid) {
                $this->app->notifier()->notify($uid, 'appraisal.appeal', 'appraisal.notify.appeal', ['name' => (string) $a['name_th'], 'cycle' => (string) $a['cycle_name']], '/appraisal/a/' . $pid, 'apr.appeal:' . $pid . ':' . $uid);
            }
        }
    }

    // ---- HR actions -------------------------------------------------------------------------------------------------------------

    public function changeEvaluator(Authorizer $az, string $pid, string $role, string $username, string $reason): void
    {
        $a = $this->view($az, $pid);
        if (!$a['is_hr'] || in_array($a['status'], ['finalized', 'acknowledged'], true)) {
            throw new BusinessException('appraisal.error.state', [], 409);
        }
        $reason = mb_substr(trim($reason), 0, 300);
        $uid = $this->app->db()->scalar("SELECT id FROM users WHERE username = ? AND status = 'active'", [trim($username)]);
        $errors = [];
        if ($uid === null || (int) $uid === (int) $a['user_id']) {
            $errors['username'] = 'appraisal.error.user';
        }
        if (mb_strlen($reason) < 5) {
            $errors['reason'] = 'appraisal.error.reason';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $col = $role === 'reviewer' ? 'reviewer_user_id' : 'manager_user_id';
        if ($role === 'reviewer' && (int) $uid === (int) $a['manager_user_id']) {
            throw new ValidationException(['username' => 'appraisal.error.user']);
        }
        $this->app->db()->run("UPDATE apr_appraisals SET {$col} = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [(int) $uid, $this->now(), $a['id']]);
        $this->event((int) $a['id'], $role === 'reviewer' ? 'reviewer_changed' : 'manager_changed', trim($username) . ' · ' . $reason, $az->userId);
        $this->app->audit()->record('appraisal.evaluator_changed', 'success', 'apr_appraisal', $pid, (int) $a['legal_entity_id'], $reason, ['role' => $role]);
        $mine = ($role === 'reviewer' && $a['status'] === 'final_review') || ($role === 'manager' && $a['status'] === 'manager_review');
        if ($mine) {
            $this->app->notifier()->notify((int) $uid, 'appraisal.task', 'appraisal.notify.' . $a['status'], ['cycle' => (string) $a['cycle_name']], '/appraisal/a/' . $pid, 'apr.chg:' . $pid . ':' . $uid);
        }
    }

    public function skipSelf(Authorizer $az, string $pid, string $reason): void
    {
        $a = $this->view($az, $pid);
        if (!$a['is_hr'] || $a['status'] !== 'self_review') {
            throw new BusinessException('appraisal.error.state', [], 409);
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new ValidationException(['reason' => 'appraisal.error.reason']);
        }
        $this->app->db()->run("UPDATE apr_appraisals SET status = 'manager_review', updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $a['id']]);
        $this->event((int) $a['id'], 'self_skipped', mb_substr(trim($reason), 0, 300), $az->userId);
    }

    /** Appeal: HR closes it with an outcome, or reopens the appraisal to the manager. */
    public function resolveAppeal(Authorizer $az, string $pid, string $action, string $outcome): void
    {
        $a = $this->view($az, $pid);
        if (!$a['is_hr'] || (int) $a['appeal'] !== 1 || $a['appeal_status'] !== 'open') {
            throw new BusinessException('appraisal.error.state', [], 409);
        }
        $outcome = mb_substr(trim($outcome), 0, 500);
        if (mb_strlen($outcome) < 5) {
            throw new ValidationException(['outcome' => 'appraisal.error.reason']);
        }
        $db = $this->app->db();
        if ($action === 'reopen') {
            $db->run(
                "UPDATE apr_appraisals SET status = 'manager_review', appeal_status = 'closed', appeal_outcome = ?, finalized_at = NULL, acknowledged_at = NULL, updated_at = ?, row_version = row_version + 1 WHERE id = ?",
                [$outcome, $this->now(), $a['id']]
            );
            $this->event((int) $a['id'], 'appeal_reopened', $outcome, $az->userId);
            if ($a['manager_user_id'] !== null) {
                $this->app->notifier()->notify((int) $a['manager_user_id'], 'appraisal.task', 'appraisal.notify.returned', ['cycle' => (string) $a['cycle_name']], '/appraisal/a/' . $pid, 'apr.reopen:' . $pid);
            }
        } else {
            $db->run("UPDATE apr_appraisals SET appeal_status = 'closed', appeal_outcome = ?, updated_at = ? WHERE id = ?", [$outcome, $this->now(), $a['id']]);
            $this->event((int) $a['id'], 'appeal_closed', $outcome, $az->userId);
            if ($a['user_id'] !== null) {
                $this->app->notifier()->notify((int) $a['user_id'], 'appraisal.result', 'appraisal.notify.appeal_closed', ['cycle' => (string) $a['cycle_name']], '/appraisal/a/' . $pid, 'apr.appealx:' . $pid);
            }
        }
        $this->app->audit()->record('appraisal.appeal_' . ($action === 'reopen' ? 'reopened' : 'closed'), 'success', 'apr_appraisal', $pid, (int) $a['legal_entity_id'], $outcome);
    }

    /** @return list<array<string, mixed>> */
    public function events(int $appraisalId): array
    {
        return $this->app->db()->all('SELECT e.*, u.display_name AS actor FROM apr_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.appraisal_id = ? ORDER BY e.id DESC', [$appraisalId]);
    }

    /** Daily-ish: reminders 2 days before and after each deadline (once per day per person). */
    public static function remindersJob(App $app): int
    {
        $svc = self::of($app);
        $today = $svc->today();
        $soon = EmployeeService::ymd($today)->modify('+2 days')->format('Y-m-d');
        $n = 0;
        foreach (
            $app->db()->all("SELECT a.public_id, a.status, a.user_id, a.manager_user_id, a.reviewer_user_id, c.name, c.self_due, c.manager_due, c.final_due FROM apr_appraisals a JOIN apr_cycles c ON c.id = a.cycle_id
                              WHERE c.status = 'open' AND a.status IN ('self_review','manager_review','final_review')") as $r
        ) {
            [$who, $due] = match ($r['status']) {
                'self_review' => [$r['user_id'], $r['self_due']],
                'manager_review' => [$r['manager_user_id'], $r['manager_due']],
                default => [$r['reviewer_user_id'], $r['final_due']],
            };
            if ($who === null || $due === null || $due > $soon) {
                continue;
            }
            $app->notifier()->notify((int) $who, 'appraisal.reminder', $due < $today ? 'appraisal.notify.overdue' : 'appraisal.notify.due', ['cycle' => (string) $r['name'], 'date' => (string) $due], '/appraisal/a/' . $r['public_id'], 'apr.remind:' . $r['public_id'] . ':' . $today);
            $n++;
        }
        return $n;
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $a
     * @param array<int, array{score?: string, note?: string}> $items
     */
    private function storeScores(array $a, array $items, string $who, bool $submit): void
    {
        $db = $this->app->db();
        $max = (int) ($a['form']['scale_max'] ?? 5);
        $errors = [];
        foreach ((array) ($a['form']['items'] ?? []) as $no => $item) {
            $raw = trim((string) ($items[$no]['score'] ?? ''));
            $note = mb_substr(trim((string) ($items[$no]['note'] ?? '')), 0, 1000);
            if ($raw !== '' && (!ctype_digit($raw) || (int) $raw < 1 || (int) $raw > $max)) {
                $errors['item_' . $no] = 'appraisal.error.score';
                continue;
            }
            if ($raw === '' && $submit) {
                $errors['item_' . $no] = 'appraisal.error.score_missing';
            }
            $db->run(
                "INSERT INTO apr_scores (appraisal_id, item_no, {$who}_score, {$who}_note) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE {$who}_score = VALUES({$who}_score), {$who}_note = VALUES({$who}_note)",
                [$a['id'], $no, $raw !== '' ? (int) $raw : null, $note !== '' ? $note : null]
            );
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Weighted score of one side: Σ(score × weight) ÷ Σ weight.
     *
     * @param array<string, mixed> $a
     */
    private function score(array $a, string $who): float
    {
        $sum = 0.0;
        $w = 0.0;
        $scores = [];
        foreach ($this->app->db()->all('SELECT item_no, ' . ($who === 'self' ? 'self_score' : 'manager_score') . ' AS s FROM apr_scores WHERE appraisal_id = ?', [$a['id']]) as $r) {
            $scores[(int) $r['item_no']] = $r['s'];
        }
        foreach ((array) ($a['form']['items'] ?? []) as $no => $item) {
            if (($scores[$no] ?? null) !== null) {
                $sum += (float) $scores[$no] * (float) $item['weight'];
                $w += (float) $item['weight'];
            }
        }
        return $w > 0 ? round($sum / $w, 3) : 0.0;
    }

    /** @param array<string, mixed> $form */
    public function band(array $form, float $score): ?string
    {
        foreach ((array) ($form['bands'] ?? []) as $b) {
            if ($score >= (float) $b['from']) {
                return (string) $b['label'];
            }
        }
        return null;
    }

    private function event(int $appraisalId, string $action, ?string $note, ?int $actor): void
    {
        $this->app->db()->run('INSERT INTO apr_events (appraisal_id, action, note, actor_id, occurred_at) VALUES (?, ?, ?, ?, ?)', [$appraisalId, $action, $note !== null ? mb_substr($note, 0, 500) : null, $actor, $this->now()]);
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
