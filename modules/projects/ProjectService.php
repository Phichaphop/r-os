<?php

declare(strict_types=1);

namespace Blue\Modules\Projects;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;

/**
 * M24 project management — projects and teams (9E-2 · DEC-101).
 *
 * Whoever creates a project is its lead and invites colleagues of the same company (they accept or decline). Only active
 * members — and the approver named on the charter, read-only — can see a project; auditors and system administrators
 * see it read-only through projects.oversee (0.37.0, DEC-102 — audited); nobody else (enforced here, on every read). The charter has a goal, scope, dates, an optional budget and an optional approver: with
 * an approver the project starts only after the central approval engine says yes; without one the lead starts it.
 * Status: planning → (pending approval) → active ⇄ at risk / on hold → completed (summary + lessons, every task accepted or
 * cancelled) or closed (reason); reopening needs a reason and is kept in the history. Every status change and status
 * update is a snapshot (health, note, progress at the time).
 */
final class ProjectService
{
    public const LIVE = ['active', 'at_risk', 'on_hold'];
    public const OPEN = ['planning', 'pending_approval', 'active', 'at_risk', 'on_hold'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    public function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    public function today(): string
    {
        return $this->app->clock->now()->setTimezone($this->tz())->format('Y-m-d');
    }

    public function stamp(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    public function addDays(string $date, int $days): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->tz());
        return $d === false ? $date : $d->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    public function validDate(string $d): bool
    {
        $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d, $this->tz());
        return $x !== false && $x->format('Y-m-d') === $d;
    }

    public function emp(): EmployeeService
    {
        return EmployeeService::of($this->app);
    }

    /** The company this person works for (projects are made inside it), or null. */
    public function myCompany(Authorizer $az): ?int
    {
        $me = $this->emp()->forUser($az->userId);
        if ($me === null || $me['status'] === 'former' || $me['job'] === null) {
            return null;
        }
        $le = (int) $me['job']['legal_entity_id'];
        return $az->scope('projects.use')->coversLegalEntity($le) ? $le : null;
    }

    public function event(int $project, ?int $task, string $event, ?int $actor, ?string $note = null): void
    {
        $this->app->db()->run('INSERT INTO pj_events (project_id, task_id, event, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$project, $task, $event, $actor, $note === null ? null : mb_substr($note, 0, 1000), $this->stamp()]);
    }

    public function name(int $userId): string
    {
        $u = $this->app->db()->one('SELECT username, display_name, employee_id FROM users WHERE id = ?', [$userId]);
        if ($u === null) {
            return '—';
        }
        if ($u['employee_id'] !== null && ($e = $this->emp()->byId((int) $u['employee_id'])) !== null) {
            return $this->emp()->displayName($e);
        }
        return (string) ($u['display_name'] ?: $u['username']);
    }

    /** A colleague (user name or employee code) who works for company $le, or null. */
    public function colleague(int $le, string $who): ?int
    {
        $who = trim((string) preg_replace('/\s+—.*$/u', '', trim($who)));
        if ($who === '') {
            return null;
        }
        $db = $this->app->db();
        $id = $db->scalar("SELECT id FROM users WHERE username = ? AND status = 'active'", [$who])
            ?? $db->scalar("SELECT u.id FROM users u JOIN employees e ON e.id = u.employee_id WHERE e.employee_code = ? AND u.status = 'active' LIMIT 1", [mb_substr($who, 0, 30)]);
        if ($id === null) {
            return null;
        }
        $az = new Authorizer($db, $this->app->clock, (int) $id);
        return $this->myCompany($az) === $le ? (int) $id : null;
    }

    // ---- access --------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function row(string $pid): array
    {
        return $this->app->db()->one('SELECT * FROM pj_projects WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /** @return array<string, mixed>|null my active membership */
    public function membership(int $projectId, int $userId): ?array
    {
        return $this->app->db()->one("SELECT * FROM pj_members WHERE project_id = ? AND user_id = ? AND status = 'active'", [$projectId, $userId]);
    }

    /**
     * The project if this person may see it: an active member, the named approver (read only), or — 0.37.0 (DEC-102) —
     * an auditor / system administrator with projects.oversee over the company (read only; every such read is audited).
     * Everyone else: 404.
     *
     * @return array{0: array<string, mixed>, 1: array{member: bool, lead: bool, approver: bool, oversee: bool}}
     */
    public function guarded(Authorizer $az, string $pid): array
    {
        $p = $this->row($pid);
        $m = $this->membership((int) $p['id'], $az->userId);
        $r = ['member' => $m !== null, 'lead' => $m !== null && $m['role'] === 'lead', 'approver' => $p['approver_id'] !== null && (int) $p['approver_id'] === $az->userId, 'oversee' => false];
        if (!$r['member'] && !$r['approver']) {
            if (!$az->scope('projects.oversee')->coversLegalEntity((int) $p['legal_entity_id'])) {
                throw HttpException::notFound();
            }
            $r['oversee'] = true;
            $this->app->audit()->record('projects.oversee_viewed', 'success', 'pj_project', $pid);
        }
        return [$p, $r];
    }

    /** @return array<string, mixed> the project, for one of its leads */
    public function led(Authorizer $az, string $pid): array
    {
        [$p, $r] = $this->guarded($az, $pid);
        if (!$r['lead']) {
            throw HttpException::forbidden();
        }
        return $p;
    }

    /** @param array<string, mixed> $p */
    public function requireOpen(array $p): void
    {
        if (!in_array($p['status'], self::OPEN, true)) {
            throw new BusinessException('projects.error.finished', [], 409);
        }
    }

    /** @return list<int> user ids of the active leads */
    public function leads(int $projectId): array
    {
        return array_map('intval', array_column($this->app->db()->all("SELECT user_id FROM pj_members WHERE project_id = ? AND role = 'lead' AND status = 'active'", [$projectId]), 'user_id'));
    }

    private function nextRef(int $le): string
    {
        $prefix = 'PJ' . $this->app->clock->now()->setTimezone($this->tz())->format('ym') . '-';
        $last = (string) ($this->app->db()->scalar('SELECT MAX(ref) FROM pj_projects WHERE legal_entity_id = ? AND ref LIKE ?', [$le, $prefix . '%']) ?? '');
        return $prefix . str_pad((string) ((int) substr($last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }

    // ---- charter -------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $in name, goal, scope, starts_on, ends_on, budget, approver
     * @return array{name: string, goal: string, scope: ?string, starts_on: string, ends_on: string, budget: ?string, approver: ?int}
     */
    private function charter(int $le, array $in, int $actor): array
    {
        $v = ['name' => trim((string) ($in['name'] ?? '')), 'goal' => trim(str_replace("\r\n", "\n", (string) ($in['goal'] ?? ''))), 'scope' => trim(str_replace("\r\n", "\n", (string) ($in['scope'] ?? ''))),
            'starts_on' => trim((string) ($in['starts_on'] ?? '')), 'ends_on' => trim((string) ($in['ends_on'] ?? '')), 'budget' => str_replace(',', '', trim((string) ($in['budget'] ?? ''))), 'approver' => null];
        $e = [];
        if (mb_strlen($v['name']) < 3 || mb_strlen($v['name']) > 160) {
            $e['name'] = 'projects.error.name';
        }
        if (mb_strlen($v['goal']) < 10 || mb_strlen($v['goal']) > 3000) {
            $e['goal'] = 'projects.error.goal';
        }
        if (mb_strlen($v['scope']) > 3000) {
            $e['scope'] = 'projects.error.too_long';
        }
        if (!$this->validDate($v['starts_on'])) {
            $e['starts_on'] = 'projects.error.date';
        }
        if (!$this->validDate($v['ends_on'])) {
            $e['ends_on'] = 'projects.error.date';
        } elseif (!isset($e['starts_on']) && ($v['ends_on'] < $v['starts_on'] || $v['ends_on'] > $this->addDays($v['starts_on'], 5 * 366))) {
            $e['ends_on'] = 'projects.error.ends_on';
        }
        if ($v['budget'] !== '' && (preg_match('/^\d{1,13}(\.\d{1,2})?$/', $v['budget']) !== 1)) {
            $e['budget'] = 'projects.error.budget';
        }
        $who = trim((string) ($in['approver'] ?? ''));
        if ($who !== '') {
            $v['approver'] = $this->colleague($le, $who);
            if ($v['approver'] === null) {
                $e['approver'] = 'projects.error.unknown_person';
            } elseif ($v['approver'] === $actor) {
                $e['approver'] = 'projects.error.approver_self';
            }
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        return ['name' => $v['name'], 'goal' => $v['goal'], 'scope' => $v['scope'] === '' ? null : $v['scope'], 'starts_on' => $v['starts_on'], 'ends_on' => $v['ends_on'],
            'budget' => $v['budget'] === '' ? null : $v['budget'], 'approver' => $v['approver']];
    }

    /**
     * New project: the creator is its lead.
     *
     * @param array<string, mixed> $in
     */
    public function create(Authorizer $az, array $in): string
    {
        $le = $this->myCompany($az);
        if ($le === null) {
            throw new BusinessException('projects.error.employees_only', [], 403);
        }
        $c = $this->charter($le, $in, $az->userId);
        $db = $this->app->db();
        $pid = Ulid::generate($this->app->clock);
        $now = $this->stamp();
        $db->transactional(function () use ($db, $le, $pid, $now, $c, $az): void {
            $db->one('SELECT id FROM legal_entities WHERE id = ? FOR UPDATE', [$le]);
            $db->run(
                'INSERT INTO pj_projects (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, ref, name, goal, scope, starts_on, ends_on, budget, approver_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, $this->nextRef($le), $c['name'], $c['goal'], $c['scope'], $c['starts_on'], $c['ends_on'], $c['budget'], $c['approver']]
            );
            $id = (int) $db->pdo()->lastInsertId();
            $db->run("INSERT INTO pj_members (project_id, user_id, role, status, invited_by, invited_at, joined_at) VALUES (?, ?, 'lead', 'active', ?, ?, ?)", [$id, $az->userId, $az->userId, $now, $now]);
            $this->event($id, null, 'created', $az->userId);
        });
        $this->app->audit()->record('projects.created', 'success', 'pj_project', $pid, $le);
        return $pid;
    }

    /** @param array<string, mixed> $in */
    public function update(Authorizer $az, string $pid, array $in): void
    {
        $p = $this->led($az, $pid);
        $this->requireOpen($p);
        if ($p['status'] === 'pending_approval') {
            throw new BusinessException('projects.error.waiting_approval', [], 409);
        }
        $c = $this->charter((int) $p['legal_entity_id'], $in, $az->userId);
        if (in_array($p['status'], self::LIVE, true) && $c['approver'] !== ($p['approver_id'] === null ? null : (int) $p['approver_id'])) {
            throw new ValidationException(['approver' => 'projects.error.approver_locked']);
        }
        $this->app->db()->run(
            'UPDATE pj_projects SET name = ?, goal = ?, scope = ?, starts_on = ?, ends_on = ?, budget = ?, approver_id = ?, updated_at = ?, updated_by = ? WHERE id = ?',
            [$c['name'], $c['goal'], $c['scope'], $c['starts_on'], $c['ends_on'], $c['budget'], $c['approver'], $this->stamp(), $az->userId, $p['id']]
        );
        $this->event((int) $p['id'], null, 'charter_changed', $az->userId);
        $this->app->audit()->record('projects.updated', 'success', 'pj_project', $pid, (int) $p['legal_entity_id']);
    }

    // ---- team ----------------------------------------------------------------------------------------------------------------

    public function invite(Authorizer $az, string $pid, string $who): void
    {
        $p = $this->led($az, $pid);
        $this->requireOpen($p);
        $uid = $this->colleague((int) $p['legal_entity_id'], $who);
        if ($uid === null) {
            throw new ValidationException(['who' => 'projects.error.unknown_person']);
        }
        $db = $this->app->db();
        $cur = $db->one('SELECT * FROM pj_members WHERE project_id = ? AND user_id = ?', [$p['id'], $uid]);
        if ($cur !== null && in_array($cur['status'], ['active', 'invited'], true)) {
            throw new ValidationException(['who' => 'projects.error.already_member']);
        }
        if ((int) $db->scalar("SELECT COUNT(*) FROM pj_members WHERE project_id = ? AND status IN ('active','invited')", [$p['id']]) >= 100) {
            throw new ValidationException(['who' => 'projects.error.team_full']);
        }
        $now = $this->stamp();
        $db->run(
            "INSERT INTO pj_members (project_id, user_id, role, status, invited_by, invited_at) VALUES (?, ?, 'member', 'invited', ?, ?) ON DUPLICATE KEY UPDATE role = 'member', status = 'invited', invited_by = VALUES(invited_by), invited_at = VALUES(invited_at), joined_at = NULL",
            [$p['id'], $uid, $az->userId, $now]
        );
        $this->event((int) $p['id'], null, 'invited', $az->userId, $this->name($uid));
        $this->app->notifier()->notify($uid, 'projects.team', 'projects.notify.invited', ['project' => (string) $p['name']], '/projects', 'pj.inv:' . $pid . ':' . $uid . ':' . $now);
    }

    /** The invited person accepts or declines. */
    public function respond(Authorizer $az, string $pid, bool $accept): void
    {
        $p = $this->row($pid);
        $db = $this->app->db();
        $n = $db->run("UPDATE pj_members SET status = ?, joined_at = ? WHERE project_id = ? AND user_id = ? AND status = 'invited'", [$accept ? 'active' : 'declined', $accept ? $this->stamp() : null, $p['id'], $az->userId])->rowCount();
        if ($n === 0) {
            throw HttpException::notFound();
        }
        $this->event((int) $p['id'], null, $accept ? 'joined' : 'declined', $az->userId);
        foreach ($this->leads((int) $p['id']) as $u) {
            $this->app->notifier()->notify($u, 'projects.team', $accept ? 'projects.notify.joined' : 'projects.notify.declined', ['who' => $this->name($az->userId), 'project' => (string) $p['name']], '/projects/p/' . $pid, 'pj.rs:' . $pid . ':' . $az->userId . ':' . $u . ':' . ($accept ? 1 : 0));
        }
    }

    /** Remove a member (their open tasks lose the assignee), make a member a lead, or step a lead down (one lead must stay). */
    public function setMember(Authorizer $az, string $pid, int $userId, string $action): void
    {
        $p = $this->led($az, $pid);
        $this->requireOpen($p);
        $db = $this->app->db();
        $m = $db->one("SELECT * FROM pj_members WHERE project_id = ? AND user_id = ? AND status IN ('active','invited')", [$p['id'], $userId]) ?? throw HttpException::notFound();
        $leads = $this->leads((int) $p['id']);
        if (($action === 'remove' || $action === 'member') && $m['role'] === 'lead' && count($leads) <= 1) {
            throw new BusinessException('projects.error.last_lead', [], 409);
        }
        switch ($action) {
            case 'remove':
                $db->transactional(function () use ($db, $p, $userId): void {
                    $db->run("UPDATE pj_members SET status = 'removed' WHERE project_id = ? AND user_id = ?", [$p['id'], $userId]);
                    $db->run("UPDATE pj_tasks SET assignee_id = NULL, updated_at = ? WHERE project_id = ? AND assignee_id = ? AND status NOT IN ('accepted','cancelled')", [$this->stamp(), $p['id'], $userId]);
                });
                break;
            case 'lead':
                if ($m['status'] !== 'active') {
                    throw new BusinessException('projects.error.not_active_member', [], 409);
                }
                $db->run("UPDATE pj_members SET role = 'lead' WHERE project_id = ? AND user_id = ?", [$p['id'], $userId]);
                break;
            case 'member':
                $db->run("UPDATE pj_members SET role = 'member' WHERE project_id = ? AND user_id = ?", [$p['id'], $userId]);
                break;
            default:
                throw HttpException::notFound();
        }
        $this->event((int) $p['id'], null, 'member_' . $action, $az->userId, $this->name($userId));
        $this->app->audit()->record('projects.member_' . $action, 'success', 'pj_project', $pid, (int) $p['legal_entity_id']);
    }

    // ---- start and approval --------------------------------------------------------------------------------------------------

    /** Start: with an approver → the central approval engine (the approver decides); without → active at once. */
    public function start(Authorizer $az, string $pid): void
    {
        $p = $this->led($az, $pid);
        if ($p['status'] !== 'planning') {
            throw new BusinessException('projects.error.not_planning', [], 409);
        }
        $db = $this->app->db();
        if ($p['approver_id'] === null) {
            $this->activate($p, $az->userId);
            return;
        }
        $wf = $this->app->workflow();
        $title = $this->app->translator()->get('projects.wf_title', ['ref' => (string) $p['ref'], 'name' => (string) $p['name']]);
        if ($p['workflow_id'] !== null && (string) $db->scalar('SELECT status FROM workflow_instances WHERE public_id = ?', [$p['workflow_id']]) === 'returned') {
            $db->run("UPDATE pj_projects SET status = 'pending_approval', updated_at = ? WHERE id = ?", [$this->stamp(), $p['id']]);
            $wf->resubmit((string) $p['workflow_id'], $az->userId);
        } else {
            $db->run("UPDATE pj_projects SET status = 'pending_approval', updated_at = ? WHERE id = ?", [$this->stamp(), $p['id']]);
            $inst = $wf->start('projects.start', $pid, $title, (int) $p['legal_entity_id'], $az->userId);
            $db->run('UPDATE pj_projects SET workflow_id = ? WHERE id = ?', [$inst, $p['id']]);
        }
        $this->event((int) $p['id'], null, 'approval_requested', $az->userId);
        $this->app->audit()->record('projects.approval_requested', 'success', 'pj_project', $pid, (int) $p['legal_entity_id']);
    }

    /** @param array<string, mixed> $p */
    private function activate(array $p, ?int $by): void
    {
        $now = $this->stamp();
        $this->app->db()->run("UPDATE pj_projects SET status = 'active', updated_at = ? WHERE id = ? AND status IN ('planning','pending_approval')", [$now, $p['id']]);
        $this->snapshot((int) $p['id'], 'on_track', (string) $p['status'], 'active', $this->app->translator()->get('projects.started_note'), $by ?? (int) $p['created_by']);
        $this->event((int) $p['id'], null, 'started', $by);
        $this->app->audit()->record('projects.started', 'success', 'pj_project', (string) $p['public_id'], (int) $p['legal_entity_id']);
    }

    /** @return list<int> the named approver (the engine routes the "manager" step to them; none → projects.approve) */
    public function approverIds(string $pid): array
    {
        $a = $this->app->db()->scalar('SELECT approver_id FROM pj_projects WHERE public_id = ?', [$pid]);
        return $a === null ? [] : [(int) $a];
    }

    public function onApproved(string $pid, int $approverId): void
    {
        $p = $this->app->db()->one('SELECT * FROM pj_projects WHERE public_id = ?', [$pid]);
        if ($p === null || $p['status'] !== 'pending_approval') {
            return;
        }
        $this->app->db()->run('UPDATE pj_projects SET approved_by = ? WHERE id = ?', [$approverId, $p['id']]);
        $this->activate($p, $approverId);
        foreach ($this->leads((int) $p['id']) as $u) {
            $this->app->notifier()->notify($u, 'projects.project', 'projects.notify.approved', ['project' => (string) $p['name']], '/projects/p/' . $pid, 'pj.ap:' . $pid . ':' . $u . ':' . $p['workflow_id']);
        }
    }

    public function onClosed(string $pid, string $outcome): void
    {
        $p = $this->app->db()->one('SELECT * FROM pj_projects WHERE public_id = ?', [$pid]);
        if ($p === null || $p['status'] !== 'pending_approval') {
            return;
        }
        $this->app->db()->run("UPDATE pj_projects SET status = 'planning', updated_at = ? WHERE id = ?", [$this->stamp(), $p['id']]);
        $this->event((int) $p['id'], null, 'approval_' . $outcome, null);
        foreach ($this->leads((int) $p['id']) as $u) {
            $this->app->notifier()->notify($u, 'projects.project', 'projects.notify.approval_' . $outcome, ['project' => (string) $p['name']], '/projects/p/' . $pid, 'pj.ac:' . $pid . ':' . $u . ':' . $outcome . ':' . $this->stamp());
        }
    }

    // ---- status --------------------------------------------------------------------------------------------------------------

    private function snapshot(int $project, string $health, ?string $from, ?string $to, string $note, int $by): void
    {
        $this->app->db()->run(
            'INSERT INTO pj_updates (project_id, health, status_from, status_to, note, progress, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$project, $health, $from, $to, mb_substr($note, 0, 3000), $this->progress($project), $by, $this->stamp()]
        );
    }

    /**
     * A status update by a lead: health (on track / at risk / off track) and a note; optionally move between active,
     * at risk and on hold.
     */
    public function statusUpdate(Authorizer $az, string $pid, string $health, string $note, string $to = ''): void
    {
        $p = $this->led($az, $pid);
        if (!in_array($p['status'], self::LIVE, true)) {
            throw new BusinessException('projects.error.not_live', [], 409);
        }
        $note = trim(str_replace("\r\n", "\n", $note));
        $e = [];
        if (!in_array($health, ['on_track', 'at_risk', 'off_track'], true)) {
            $e['health'] = 'projects.error.choice';
        }
        if (mb_strlen($note) < 5 || mb_strlen($note) > 3000) {
            $e['note'] = 'projects.error.note';
        }
        if ($to !== '' && !in_array($to, self::LIVE, true)) {
            $e['status'] = 'projects.error.choice';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $to = $to === '' ? (string) $p['status'] : $to;
        if ($to !== $p['status']) {
            $this->app->db()->run('UPDATE pj_projects SET status = ?, updated_at = ?, updated_by = ? WHERE id = ?', [$to, $this->stamp(), $az->userId, $p['id']]);
            $this->event((int) $p['id'], null, 'status_' . $to, $az->userId, $note);
        }
        $this->snapshot((int) $p['id'], $health, (string) $p['status'], $to, $note, $az->userId);
        $this->app->audit()->record('projects.status_update', 'success', 'pj_project', $pid, (int) $p['legal_entity_id'], null, ['health' => $health, 'to' => $to]);
    }

    /** Complete: every task accepted or cancelled (at least one accepted); summary and lessons learned. */
    public function complete(Authorizer $az, string $pid, string $summary, string $lessons): void
    {
        $p = $this->led($az, $pid);
        if (!in_array($p['status'], self::LIVE, true)) {
            throw new BusinessException('projects.error.not_live', [], 409);
        }
        $db = $this->app->db();
        $summary = trim(str_replace("\r\n", "\n", $summary));
        $lessons = trim(str_replace("\r\n", "\n", $lessons));
        $e = [];
        if (mb_strlen($summary) < 10 || mb_strlen($summary) > 5000) {
            $e['summary'] = 'projects.error.summary';
        }
        if (mb_strlen($lessons) < 10 || mb_strlen($lessons) > 5000) {
            $e['lessons'] = 'projects.error.summary';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        if ((int) $db->scalar("SELECT COUNT(*) FROM pj_tasks WHERE project_id = ? AND status NOT IN ('accepted','cancelled')", [$p['id']]) > 0) {
            throw new BusinessException('projects.error.tasks_open', [], 409);
        }
        if ((int) $db->scalar("SELECT COUNT(*) FROM pj_tasks WHERE project_id = ? AND status = 'accepted'", [$p['id']]) === 0) {
            throw new BusinessException('projects.error.nothing_delivered', [], 409);
        }
        $now = $this->stamp();
        $db->run("UPDATE pj_projects SET status = 'completed', summary = ?, lessons = ?, close_reason = NULL, closed_at = ?, updated_at = ?, updated_by = ? WHERE id = ?", [$summary, $lessons, $now, $now, $az->userId, $p['id']]);
        $this->snapshot((int) $p['id'], 'on_track', (string) $p['status'], 'completed', $summary, $az->userId);
        $this->event((int) $p['id'], null, 'completed', $az->userId);
        $this->app->audit()->record('projects.completed', 'success', 'pj_project', $pid, (int) $p['legal_entity_id']);
        $this->tellTeam($p, 'projects.notify.completed', $az->userId);
    }

    /** Close without completing (cancelled, merged…) — a reason. */
    public function close(Authorizer $az, string $pid, string $reason): void
    {
        $p = $this->led($az, $pid);
        $this->requireOpen($p);
        if ($p['status'] === 'pending_approval') {
            throw new BusinessException('projects.error.waiting_approval', [], 409);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
            throw new ValidationException(['reason' => 'projects.error.reason']);
        }
        $now = $this->stamp();
        $this->app->db()->run("UPDATE pj_projects SET status = 'closed', close_reason = ?, closed_at = ?, updated_at = ?, updated_by = ? WHERE id = ?", [$reason, $now, $now, $az->userId, $p['id']]);
        $this->snapshot((int) $p['id'], 'off_track', (string) $p['status'], 'closed', $reason, $az->userId);
        $this->event((int) $p['id'], null, 'closed', $az->userId, $reason);
        $this->app->audit()->record('projects.closed', 'success', 'pj_project', $pid, (int) $p['legal_entity_id'], $reason);
        $this->tellTeam($p, 'projects.notify.closed', $az->userId);
    }

    /** Reopen a completed or closed project — a reason, kept in the history. */
    public function reopen(Authorizer $az, string $pid, string $reason): void
    {
        $p = $this->led($az, $pid);
        if (!in_array($p['status'], ['completed', 'closed'], true)) {
            throw new BusinessException('projects.error.not_finished', [], 409);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
            throw new ValidationException(['reason' => 'projects.error.reason']);
        }
        $this->app->db()->run("UPDATE pj_projects SET status = 'active', closed_at = NULL, updated_at = ?, updated_by = ? WHERE id = ?", [$this->stamp(), $az->userId, $p['id']]);
        $this->snapshot((int) $p['id'], 'at_risk', (string) $p['status'], 'active', $reason, $az->userId);
        $this->event((int) $p['id'], null, 'reopened', $az->userId, $reason);
        $this->app->audit()->record('projects.reopened', 'success', 'pj_project', $pid, (int) $p['legal_entity_id'], $reason);
    }

    /** @param array<string, mixed> $p */
    private function tellTeam(array $p, string $key, int $except): void
    {
        foreach ($this->app->db()->all("SELECT user_id FROM pj_members WHERE project_id = ? AND status = 'active' AND user_id <> ?", [$p['id'], $except]) as $m) {
            $this->app->notifier()->notify((int) $m['user_id'], 'projects.project', $key, ['project' => (string) $p['name']], '/projects/p/' . $p['public_id'], 'pj.tt:' . $p['public_id'] . ':' . $key . ':' . $m['user_id'] . ':' . $this->stamp());
        }
    }

    // ---- reading -------------------------------------------------------------------------------------------------------------

    /** Average progress of the tasks that count (accepted = 100, cancelled left out), 0–100. */
    public function progress(int $projectId): int
    {
        $r = $this->app->db()->one("SELECT COUNT(*) AS n, SUM(IF(status = 'accepted', 100, progress)) AS s FROM pj_tasks WHERE project_id = ? AND status <> 'cancelled'", [$projectId]);
        $n = (int) ($r['n'] ?? 0);
        return $n === 0 ? 0 : (int) round(((int) $r['s']) / $n);
    }

    /**
     * My projects (active member), invitations waiting, projects I approve.
     *
     * @return array{projects: list<array<string, mixed>>, invites: list<array<string, mixed>>, approving: list<array<string, mixed>>}
     */
    public function mine(Authorizer $az): array
    {
        $db = $this->app->db();
        $projects = $db->all(
            "SELECT p.*, m.role,
                    (SELECT COUNT(*) FROM pj_tasks t WHERE t.project_id = p.id AND t.status NOT IN ('accepted','cancelled')) AS open_tasks,
                    (SELECT COUNT(*) FROM pj_tasks t WHERE t.project_id = p.id AND t.status NOT IN ('accepted','cancelled','submitted') AND t.due_on < ?) AS late_tasks,
                    (SELECT COUNT(*) FROM pj_tasks t WHERE t.project_id = p.id AND t.assignee_id = ? AND t.status NOT IN ('accepted','cancelled')) AS my_tasks
             FROM pj_projects p JOIN pj_members m ON m.project_id = p.id WHERE m.user_id = ? AND m.status = 'active' ORDER BY FIELD(p.status, 'at_risk', 'active', 'on_hold', 'pending_approval', 'planning', 'completed', 'closed'), p.ends_on",
            [$this->today(), $az->userId, $az->userId]
        );
        foreach ($projects as &$p) {
            $p['progress'] = $this->progress((int) $p['id']);
        }
        unset($p);
        return [
            'projects' => $projects,
            'invites' => $db->all("SELECT p.public_id, p.ref, p.name, p.goal, m.invited_by FROM pj_members m JOIN pj_projects p ON p.id = m.project_id WHERE m.user_id = ? AND m.status = 'invited' ORDER BY m.invited_at DESC", [$az->userId]),
            'approving' => $db->all("SELECT public_id, ref, name, status FROM pj_projects WHERE approver_id = ? AND status IN ('pending_approval','planning','active','at_risk','on_hold') ORDER BY id DESC", [$az->userId]),
            'overseeing' => $this->overseeing($az),
        ];
    }

    /**
     * 0.37.0 (DEC-102): every project of the companies an auditor / system administrator oversees (read only), newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function overseeing(Authorizer $az): array
    {
        $scope = $az->scope('projects.oversee');
        if ($scope->isEmpty()) {
            return [];
        }
        [$where, $params] = $scope->sql('legal_entity_id');
        return $this->app->db()->all('SELECT public_id, ref, name, status, starts_on, ends_on FROM pj_projects WHERE ' . $where . ' ORDER BY id DESC LIMIT 200', $params);
    }

    /**
     * The project page: charter, team, tasks, figures, workload, updates, history.
     *
     * @return array<string, mixed>
     */
    public function project(Authorizer $az, string $pid): array
    {
        [$p, $r] = $this->guarded($az, $pid);
        $db = $this->app->db();
        $id = (int) $p['id'];
        $today = $this->today();
        $p['roles'] = $r;
        $p['approver'] = $p['approver_id'] === null ? null : $this->name((int) $p['approver_id']);
        $p['approver_username'] = $p['approver_id'] === null ? null : (string) $db->scalar('SELECT username FROM users WHERE id = ?', [$p['approver_id']]);
        $p['members'] = $db->all("SELECT m.*, u.username FROM pj_members m JOIN users u ON u.id = m.user_id WHERE m.project_id = ? AND m.status IN ('active','invited') ORDER BY m.role = 'lead' DESC, m.status, u.username", [$id]);
        foreach ($p['members'] as &$m) {
            $m['name'] = $this->name((int) $m['user_id']);
        }
        unset($m);
        $tasks = $db->all('SELECT t.*, u.username AS assignee FROM pj_tasks t LEFT JOIN users u ON u.id = t.assignee_id WHERE t.project_id = ? ORDER BY COALESCE(t.parent_id, t.id), t.parent_id IS NOT NULL, t.sort, t.id', [$id]);
        $deps = [];
        foreach ($db->all('SELECT d.task_id, d.depends_on_id FROM pj_deps d JOIN pj_tasks t ON t.id = d.task_id WHERE t.project_id = ?', [$id]) as $d) {
            $deps[(int) $d['task_id']][] = (int) $d['depends_on_id'];
        }
        $byId = [];
        foreach ($tasks as $t) {
            $byId[(int) $t['id']] = $t;
        }
        foreach ($tasks as &$t) {
            $t['late'] = !in_array($t['status'], ['accepted', 'cancelled', 'submitted'], true) && $t['due_on'] !== null && (string) $t['due_on'] < $today;
            $t['deps'] = array_values(array_filter(array_map(static fn (int $x): ?array => $byId[$x] ?? null, $deps[(int) $t['id']] ?? [])));
            $t['blocked'] = array_filter($t['deps'], static fn (array $d): bool => $d['status'] !== 'accepted') !== [];
        }
        unset($t);
        $p['tasks'] = $tasks;
        $p['progress'] = $this->progress($id);
        $counts = array_fill_keys(['todo', 'in_progress', 'submitted', 'returned', 'accepted', 'cancelled'], 0);
        foreach ($tasks as $t) {
            $counts[(string) $t['status']]++;
        }
        $p['counts'] = $counts;
        $p['late'] = count(array_filter($tasks, static fn (array $t): bool => $t['late']));
        // workload: open tasks per active member, late ones, due within 7 days
        $week = $this->addDays($today, 7);
        $load = [];
        foreach ($p['members'] as $m) {
            if ($m['status'] === 'active') {
                $load[(int) $m['user_id']] = ['name' => $m['name'], 'open' => 0, 'late' => 0, 'week' => 0];
            }
        }
        foreach ($tasks as $t) {
            if ($t['assignee_id'] === null || !isset($load[(int) $t['assignee_id']]) || in_array($t['status'], ['accepted', 'cancelled'], true)) {
                continue;
            }
            $u = (int) $t['assignee_id'];
            $load[$u]['open']++;
            $load[$u]['late'] += $t['late'] ? 1 : 0;
            $load[$u]['week'] += $t['due_on'] !== null && (string) $t['due_on'] >= $today && (string) $t['due_on'] <= $week ? 1 : 0;
        }
        $p['workload'] = array_values($load);
        $p['updates'] = $db->all('SELECT x.*, u.username FROM pj_updates x LEFT JOIN users u ON u.id = x.created_by WHERE x.project_id = ? ORDER BY x.id DESC LIMIT 30', [$id]);
        $p['events'] = $db->all('SELECT e.*, u.username, t.title AS task_title FROM pj_events e LEFT JOIN users u ON u.id = e.actor_id LEFT JOIN pj_tasks t ON t.id = e.task_id WHERE e.project_id = ? ORDER BY e.id DESC LIMIT 100', [$id]);
        $p['today'] = $today;
        $p['active_members'] = array_values(array_filter($p['members'], static fn (array $m): bool => $m['status'] === 'active'));
        return $p;
    }

    /**
     * Bars for the timeline: each task with dates as [offset, length] in days from the first day shown.
     *
     * @param list<array<string, mixed>> $tasks
     * @return array{from: string, days: int, bars: list<array{task: array<string, mixed>, x: int, w: int}>, today: ?int}
     */
    public function timeline(string $start, string $end, array $tasks): array
    {
        $from = $start;
        $to = $end;
        foreach ($tasks as $t) {
            if ($t['status'] === 'cancelled') {
                continue;
            }
            foreach (['starts_on', 'due_on'] as $k) {
                if ($t[$k] !== null) {
                    $from = min($from, (string) $t[$k]);
                    $to = max($to, (string) $t[$k]);
                }
            }
        }
        $a = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $this->tz());
        $b = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $this->tz());
        $days = $a === false || $b === false ? 1 : (int) $a->diff($b)->days + 1;
        $off = function (string $d) use ($a): int {
            $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d, $this->tz());
            return $a === false || $x === false ? 0 : (int) $a->diff($x)->days * ($x < $a ? -1 : 1);
        };
        $bars = [];
        foreach ($tasks as $t) {
            if ($t['status'] === 'cancelled' || ($t['starts_on'] === null && $t['due_on'] === null)) {
                continue;
            }
            $s = (string) ($t['starts_on'] ?? $t['due_on']);
            $e = (string) ($t['due_on'] ?? $t['starts_on']);
            $bars[] = ['task' => $t, 'x' => $off($s), 'w' => max(1, $off($e) - $off($s) + 1)];
        }
        $today = $this->today();
        return ['from' => $from, 'days' => $days, 'bars' => $bars, 'today' => $today >= $from && $today <= $to ? $off($today) : null];
    }
}
