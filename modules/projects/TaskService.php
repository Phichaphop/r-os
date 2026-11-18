<?php

declare(strict_types=1);

namespace Blue\Modules\Projects;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\FileType;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Support\Ulid;

/**
 * M24 project management — tasks (9E-2 · DEC-101).
 *
 * Leads plan tasks and subtasks (one level) with an assignee from the team, dates, acceptance criteria and dependencies on
 * other tasks of the project (a loop is refused). The assignee updates their own plan (dates), progress, risk and status
 * (to do ⇄ in progress) and submits work as a revision — a note, a link and/or files; every revision is kept. A lead accepts
 * it (progress 100) or returns it with feedback; returned work is submitted again. Warnings, never blocks: a dependency not
 * accepted yet, a task starting before a dependency is due, dates outside the project, an assignee with overlapping work.
 * Members comment on tasks. Reminders: when assigned, returned or accepted; the day before the due date; once when late.
 */
final class TaskService
{
    private const MAX_FILES = 5;

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function ps(): ProjectService
    {
        return ProjectService::of($this->app);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array{member: bool, lead: bool, approver: bool, oversee: bool}} task, project, roles */
    private function guarded(Authorizer $az, string $tpid): array
    {
        $t = $this->app->db()->one('SELECT * FROM pj_tasks WHERE public_id = ?', [$tpid]) ?? throw HttpException::notFound();
        $p = $this->app->db()->one('SELECT * FROM pj_projects WHERE id = ?', [$t['project_id']]) ?? throw HttpException::notFound();
        [, $r] = $this->ps()->guarded($az, (string) $p['public_id']);
        return [$t, $p, $r];
    }

    /** @return ?int a task id of this project from its public id */
    private function taskId(int $projectId, string $pid): ?int
    {
        $id = $this->app->db()->scalar('SELECT id FROM pj_tasks WHERE public_id = ? AND project_id = ?', [$pid, $projectId]);
        return $id === null ? null : (int) $id;
    }

    /** Would task $taskId depending on $dependsOn close a loop? (Walks the dependencies of $dependsOn.) */
    public function makesLoop(int $taskId, int $dependsOn): bool
    {
        $seen = [];
        $stack = [$dependsOn];
        while ($stack !== []) {
            $cur = array_pop($stack);
            if ($cur === $taskId) {
                return true;
            }
            if (isset($seen[$cur]) || count($seen) > 5000) {
                continue;
            }
            $seen[$cur] = true;
            foreach ($this->app->db()->all('SELECT depends_on_id FROM pj_deps WHERE task_id = ?', [$cur]) as $r) {
                $stack[] = (int) $r['depends_on_id'];
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $p project
     * @param array<string, mixed> $in
     * @return array{title: string, description: ?string, criteria: ?string, parent: ?int, assignee: ?int, starts_on: ?string, due_on: ?string, deps: list<int>}
     */
    private function fields(array $p, array $in, ?int $selfId): array
    {
        $ps = $this->ps();
        $db = $this->app->db();
        $v = ['title' => trim((string) ($in['title'] ?? '')), 'description' => trim(str_replace("\r\n", "\n", (string) ($in['description'] ?? ''))), 'criteria' => trim(str_replace("\r\n", "\n", (string) ($in['criteria'] ?? ''))),
            'starts_on' => trim((string) ($in['starts_on'] ?? '')), 'due_on' => trim((string) ($in['due_on'] ?? ''))];
        $e = [];
        if (mb_strlen($v['title']) < 3 || mb_strlen($v['title']) > 200) {
            $e['title'] = 'projects.error.task_title';
        }
        if (mb_strlen($v['description']) > 5000 || mb_strlen($v['criteria']) > 3000) {
            $e['description'] = 'projects.error.too_long';
        }
        foreach (['starts_on', 'due_on'] as $k) {
            if ($v[$k] !== '' && !$ps->validDate($v[$k])) {
                $e[$k] = 'projects.error.date';
            }
        }
        if (!isset($e['starts_on'], $e['due_on']) && $v['starts_on'] !== '' && $v['due_on'] !== '' && $v['due_on'] < $v['starts_on']) {
            $e['due_on'] = 'projects.error.due_before_start';
        }
        $parent = null;
        if ((string) ($in['parent'] ?? '') !== '') {
            $parent = $this->taskId((int) $p['id'], (string) $in['parent']);
            if (
                $parent === null || $parent === $selfId || $db->scalar('SELECT parent_id FROM pj_tasks WHERE id = ?', [$parent]) !== null
                || ($selfId !== null && $db->scalar('SELECT id FROM pj_tasks WHERE parent_id = ? LIMIT 1', [$selfId]) !== null)
            ) {
                $e['parent'] = 'projects.error.parent';
            }
        }
        $assignee = null;
        if ((string) ($in['assignee'] ?? '') !== '') {
            $assignee = (int) $db->scalar("SELECT m.user_id FROM pj_members m JOIN users u ON u.id = m.user_id WHERE m.project_id = ? AND m.status = 'active' AND u.username = ?", [$p['id'], (string) $in['assignee']]);
            if ($assignee === 0) {
                $e['assignee'] = 'projects.error.not_member';
                $assignee = null;
            }
        }
        $deps = [];
        foreach ((array) ($in['deps'] ?? []) as $d) {
            $d = (string) $d;
            if ($d === '') {
                continue;
            }
            $id = $this->taskId((int) $p['id'], $d);
            if ($id === null || $id === $selfId) {
                $e['deps'] = 'projects.error.dep';
                continue;
            }
            if ($selfId !== null && $this->makesLoop($selfId, $id)) {
                $e['deps'] = 'projects.error.dep_loop';
                continue;
            }
            $deps[$id] = $id;
        }
        if (count($deps) > 20) {
            $e['deps'] = 'projects.error.too_many';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        return ['title' => $v['title'], 'description' => $v['description'] ?: null, 'criteria' => $v['criteria'] ?: null, 'parent' => $parent, 'assignee' => $assignee,
            'starts_on' => $v['starts_on'] ?: null, 'due_on' => $v['due_on'] ?: null, 'deps' => array_values($deps)];
    }

    /**
     * A lead adds a task (or subtask).
     *
     * @param array<string, mixed> $in title, description, criteria, parent, assignee (user name), starts_on, due_on, deps[]
     */
    public function create(Authorizer $az, string $ppid, array $in): string
    {
        $p = $this->ps()->led($az, $ppid);
        $this->ps()->requireOpen($p);
        $db = $this->app->db();
        if ((int) $db->scalar('SELECT COUNT(*) FROM pj_tasks WHERE project_id = ?', [$p['id']]) >= 500) {
            throw new BusinessException('projects.error.too_many_tasks', [], 409);
        }
        $f = $this->fields($p, $in, null);
        $tpid = Ulid::generate($this->app->clock);
        $now = $this->ps()->stamp();
        $db->transactional(function () use ($db, $p, $f, $tpid, $now, $az): void {
            $sort = 1 + (int) $db->scalar('SELECT COALESCE(MAX(sort), 0) FROM pj_tasks WHERE project_id = ?', [$p['id']]);
            $db->run(
                'INSERT INTO pj_tasks (public_id, project_id, parent_id, title, description, criteria, assignee_id, starts_on, due_on, sort, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$tpid, $p['id'], $f['parent'], $f['title'], $f['description'], $f['criteria'], $f['assignee'], $f['starts_on'], $f['due_on'], $sort, $az->userId, $now, $now]
            );
            $id = (int) $db->pdo()->lastInsertId();
            foreach ($f['deps'] as $d) {
                $db->run('INSERT INTO pj_deps (task_id, depends_on_id) VALUES (?, ?)', [$id, $d]);
            }
            $this->ps()->event((int) $p['id'], $id, 'task_added', $az->userId, $f['title']);
        });
        if ($f['assignee'] !== null && $f['assignee'] !== $az->userId) {
            $this->tellAssigned($f['assignee'], $p, $tpid, $f['title'], $f['due_on']);
        }
        return $tpid;
    }

    /** @param array<string, mixed> $p */
    private function tellAssigned(int $uid, array $p, string $tpid, string $title, ?string $due): void
    {
        $this->app->notifier()->notify($uid, 'projects.task', 'projects.notify.assigned', ['task' => mb_substr($title, 0, 80), 'project' => (string) $p['name'], 'due' => $due ?? '—'], '/projects/t/' . $tpid, 'pj.as:' . $tpid . ':' . $uid);
    }

    /**
     * A lead edits a task (dependencies are replaced; a loop is refused).
     *
     * @param array<string, mixed> $in
     */
    public function edit(Authorizer $az, string $tpid, array $in): void
    {
        [$t, $p, $r] = $this->guarded($az, $tpid);
        if (!$r['lead']) {
            throw HttpException::forbidden();
        }
        $this->ps()->requireOpen($p);
        if (in_array($t['status'], ['accepted', 'cancelled'], true)) {
            throw new BusinessException('projects.error.task_final', [], 409);
        }
        $f = $this->fields($p, $in, (int) $t['id']);
        $db = $this->app->db();
        $db->transactional(function () use ($db, $t, $f): void {
            $db->run(
                'UPDATE pj_tasks SET parent_id = ?, title = ?, description = ?, criteria = ?, assignee_id = ?, starts_on = ?, due_on = ?, reminded = IF(due_on <=> ?, reminded, 0), overdue_notified = IF(due_on <=> ?, overdue_notified, 0), updated_at = ? WHERE id = ?',
                [$f['parent'], $f['title'], $f['description'], $f['criteria'], $f['assignee'], $f['starts_on'], $f['due_on'], $f['due_on'], $f['due_on'], $this->ps()->stamp(), $t['id']]
            );
            $db->run('DELETE FROM pj_deps WHERE task_id = ?', [$t['id']]);
            foreach ($f['deps'] as $d) {
                $db->run('INSERT INTO pj_deps (task_id, depends_on_id) VALUES (?, ?)', [$t['id'], $d]);
            }
        });
        $this->ps()->event((int) $p['id'], (int) $t['id'], 'task_edited', $az->userId);
        if ($f['assignee'] !== null && $f['assignee'] !== ($t['assignee_id'] === null ? null : (int) $t['assignee_id']) && $f['assignee'] !== $az->userId) {
            $this->tellAssigned($f['assignee'], $p, $tpid, $f['title'], $f['due_on']);
        }
    }

    /**
     * The assignee's own update: dates, progress (0–99 until accepted), risk, to do ⇄ in progress.
     *
     * @param array<string, mixed> $in starts_on, due_on, progress, risk, status
     */
    public function updateMine(Authorizer $az, string $tpid, array $in): void
    {
        [$t, $p] = $this->guarded($az, $tpid);
        if ($t['assignee_id'] === null || (int) $t['assignee_id'] !== $az->userId) {
            throw HttpException::forbidden();
        }
        if (!in_array($p['status'], ProjectService::LIVE, true)) {
            throw new BusinessException('projects.error.not_live', [], 409);
        }
        if (!in_array($t['status'], ['todo', 'in_progress', 'returned'], true)) {
            throw new BusinessException('projects.error.task_locked', [], 409);
        }
        $ps = $this->ps();
        $s = trim((string) ($in['starts_on'] ?? ''));
        $d = trim((string) ($in['due_on'] ?? ''));
        $prog = trim((string) ($in['progress'] ?? '0'));
        $risk = trim((string) ($in['risk'] ?? ''));
        $status = (string) ($in['status'] ?? $t['status']);
        $e = [];
        if ($s !== '' && !$ps->validDate($s)) {
            $e['starts_on'] = 'projects.error.date';
        }
        if ($d !== '' && !$ps->validDate($d)) {
            $e['due_on'] = 'projects.error.date';
        } elseif ($s !== '' && $d !== '' && $d < $s) {
            $e['due_on'] = 'projects.error.due_before_start';
        }
        if (preg_match('/^\d{1,2}$/', $prog) !== 1) {
            $e['progress'] = 'projects.error.progress';
        }
        if (mb_strlen($risk) > 500) {
            $e['risk'] = 'projects.error.too_long';
        }
        if (!in_array($status, ['todo', 'in_progress', $t['status']], true)) {
            $e['status'] = 'projects.error.choice';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $this->app->db()->run(
            'UPDATE pj_tasks SET starts_on = ?, due_on = ?, progress = ?, risk = ?, status = ?, reminded = IF(due_on <=> ?, reminded, 0), overdue_notified = IF(due_on <=> ?, overdue_notified, 0), updated_at = ? WHERE id = ?',
            [$s ?: null, $d ?: null, (int) $prog, $risk ?: null, $status, $d ?: null, $d ?: null, $ps->stamp(), $t['id']]
        );
        $note = $t['status'] !== $status ? $t['status'] . ' → ' . $status : null;
        $ps->event((int) $p['id'], (int) $t['id'], 'task_updated', $az->userId, trim(($note ?? '') . ' ' . $prog . '%'));
    }

    /** A lead cancels a task (reason). */
    public function cancel(Authorizer $az, string $tpid, string $reason): void
    {
        [$t, $p, $r] = $this->guarded($az, $tpid);
        if (!$r['lead']) {
            throw HttpException::forbidden();
        }
        $this->ps()->requireOpen($p);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new ValidationException(['reason' => 'projects.error.note']);
        }
        if (in_array($t['status'], ['accepted', 'cancelled'], true)) {
            throw new BusinessException('projects.error.task_final', [], 409);
        }
        $this->app->db()->run("UPDATE pj_tasks SET status = 'cancelled', updated_at = ? WHERE id = ?", [$this->ps()->stamp(), $t['id']]);
        $this->ps()->event((int) $p['id'], (int) $t['id'], 'task_cancelled', $az->userId, $reason);
    }

    /**
     * The assignee submits work: a note, a link and/or files (at least one). Each submission is a new revision.
     *
     * @param list<UploadedFile> $files
     */
    public function submit(Authorizer $az, string $tpid, string $note, string $link, array $files): int
    {
        [$t, $p] = $this->guarded($az, $tpid);
        if ($t['assignee_id'] === null || (int) $t['assignee_id'] !== $az->userId) {
            throw HttpException::forbidden();
        }
        if (!in_array($p['status'], ProjectService::LIVE, true)) {
            throw new BusinessException('projects.error.not_live', [], 409);
        }
        if (!in_array($t['status'], ['todo', 'in_progress', 'returned'], true)) {
            throw new BusinessException('projects.error.task_locked', [], 409);
        }
        $note = trim(str_replace("\r\n", "\n", $note));
        $link = trim($link);
        $e = [];
        if ($note === '' && $link === '' && $files === []) {
            $e['note'] = 'projects.error.empty_submission';
        }
        if (mb_strlen($note) > 5000) {
            $e['note'] = 'projects.error.too_long';
        }
        if ($link !== '' && (mb_strlen($link) > 500 || preg_match('~^https?://[^\s<>"]+$~i', $link) !== 1)) {
            $e['link'] = 'projects.error.link';
        }
        if (count($files) > self::MAX_FILES) {
            $e['files'] = 'projects.error.too_many_files';
        }
        foreach ($files as $f) {
            if (!$f->ok()) {
                $e['files'] = $f->errorKey();
            }
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $stored = [];
        foreach ($files as $f) {
            try {
                $d = $this->app->documents()->storeGenerated($az->userId, 'projects.file', $f->bytes(), $f->safeName(), ['title' => 'Project work', 'legal_entity_id' => (int) $p['legal_entity_id']], 'upload', true);
            } catch (ValidationException $x) {
                throw new ValidationException(['files' => (string) (array_values($x->errors)[0] ?? 'projects.error.file')]);
            }
            $stored[] = [(string) $d['document'], mb_substr($f->safeName(), 0, 200), (string) (FileType::sniff($f->bytes(), $f->safeName())['mime'] ?? 'application/octet-stream')];
        }
        $db = $this->app->db();
        $now = $this->ps()->stamp();
        $rev = $db->transactional(function () use ($db, $t, $p, $note, $link, $stored, $az, $now): int {
            $db->one('SELECT id FROM pj_tasks WHERE id = ? FOR UPDATE', [$t['id']]);
            $rev = 1 + (int) $db->scalar('SELECT COALESCE(MAX(rev), 0) FROM pj_subs WHERE task_id = ?', [$t['id']]);
            $db->run('INSERT INTO pj_subs (task_id, rev, note, link, submitted_by, submitted_at) VALUES (?, ?, ?, ?, ?, ?)', [$t['id'], $rev, $note ?: null, $link ?: null, $az->userId, $now]);
            $sid = (int) $db->pdo()->lastInsertId();
            foreach ($stored as [$doc, $name, $mime]) {
                $db->run('INSERT INTO pj_files (project_id, sub_id, document_pid, filename, mime, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$p['id'], $sid, $doc, $name, $mime, $az->userId, $now]);
            }
            $db->run("UPDATE pj_tasks SET status = 'submitted', updated_at = ? WHERE id = ?", [$now, $t['id']]);
            return $rev;
        });
        $this->ps()->event((int) $p['id'], (int) $t['id'], 'submitted', $az->userId, 'rev ' . $rev);
        foreach ($this->ps()->leads((int) $p['id']) as $u) {
            if ($u !== $az->userId) {
                $this->app->notifier()->notify($u, 'projects.task', 'projects.notify.submitted', ['task' => mb_substr((string) $t['title'], 0, 80), 'who' => $this->ps()->name($az->userId)], '/projects/t/' . $tpid, 'pj.sb:' . $tpid . ':' . $rev . ':' . $u);
            }
        }
        return $rev;
    }

    /** A lead accepts the latest revision (progress 100) or returns it with feedback. */
    public function decide(Authorizer $az, string $tpid, bool $accept, string $feedback): void
    {
        [$t, $p, $r] = $this->guarded($az, $tpid);
        if (!$r['lead']) {
            throw HttpException::forbidden();
        }
        if ($t['status'] !== 'submitted') {
            throw new BusinessException('projects.error.not_submitted', [], 409);
        }
        $feedback = trim(str_replace("\r\n", "\n", $feedback));
        if ((!$accept && mb_strlen($feedback) < 5) || mb_strlen($feedback) > 3000) {
            throw new ValidationException(['feedback' => 'projects.error.feedback']);
        }
        $db = $this->app->db();
        $now = $this->ps()->stamp();
        $db->transactional(function () use ($db, $t, $accept, $feedback, $az, $now): void {
            $sub = $db->one('SELECT id FROM pj_subs WHERE task_id = ? AND decision IS NULL ORDER BY rev DESC LIMIT 1 FOR UPDATE', [$t['id']]);
            if ($sub === null) {
                throw new BusinessException('projects.error.not_submitted', [], 409);
            }
            $db->run('UPDATE pj_subs SET decision = ?, feedback = ?, decided_by = ?, decided_at = ? WHERE id = ?', [$accept ? 'accepted' : 'returned', $feedback ?: null, $az->userId, $now, $sub['id']]);
            if ($accept) {
                $db->run("UPDATE pj_tasks SET status = 'accepted', progress = 100, accepted_at = ?, updated_at = ? WHERE id = ?", [$now, $now, $t['id']]);
            } else {
                $db->run("UPDATE pj_tasks SET status = 'returned', updated_at = ? WHERE id = ?", [$now, $t['id']]);
            }
        });
        $this->ps()->event((int) $p['id'], (int) $t['id'], $accept ? 'accepted' : 'returned', $az->userId, $feedback ?: null);
        $this->app->audit()->record($accept ? 'projects.task_accepted' : 'projects.task_returned', 'success', 'pj_task', $tpid, (int) $p['legal_entity_id']);
        if ($t['assignee_id'] !== null && (int) $t['assignee_id'] !== $az->userId) {
            $this->app->notifier()->notify((int) $t['assignee_id'], 'projects.task', $accept ? 'projects.notify.accepted' : 'projects.notify.returned', ['task' => mb_substr((string) $t['title'], 0, 80), 'feedback' => mb_substr($feedback, 0, 120)], '/projects/t/' . $tpid, 'pj.dc:' . $tpid . ':' . $now);
        }
    }

    /** A member comments on a task. */
    public function comment(Authorizer $az, string $tpid, string $body): void
    {
        [$t, $p, $r] = $this->guarded($az, $tpid);
        if (!$r['member']) {
            throw HttpException::forbidden();
        }
        $body = trim(str_replace("\r\n", "\n", $body));
        if (mb_strlen($body) < 1 || mb_strlen($body) > 2000) {
            throw new ValidationException(['body' => 'projects.error.comment']);
        }
        $db = $this->app->db();
        if ((int) $db->scalar('SELECT COUNT(*) FROM pj_comments WHERE user_id = ? AND created_at >= ?', [$az->userId, $this->app->clock->now()->modify('-1 hour')->format('Y-m-d H:i:s.u')]) >= 60) {
            throw new BusinessException('projects.error.slow_down', [], 429);
        }
        $db->run('INSERT INTO pj_comments (task_id, user_id, body, created_at) VALUES (?, ?, ?, ?)', [$t['id'], $az->userId, $body, $this->ps()->stamp()]);
        foreach (array_unique(array_filter([$t['assignee_id'] === null ? 0 : (int) $t['assignee_id'], ...$this->ps()->leads((int) $p['id'])])) as $u) {
            if ($u !== $az->userId) {
                $this->app->notifier()->notify($u, 'projects.task', 'projects.notify.comment', ['task' => mb_substr((string) $t['title'], 0, 80), 'who' => $this->ps()->name($az->userId)], '/projects/t/' . $tpid, 'pj.cm:' . $tpid . ':' . $u . ':' . substr($this->ps()->stamp(), 0, 13));
            }
        }
    }

    /**
     * One task: project, roles, dependencies (+ what waits on it), subtasks, revisions with files, comments, warnings.
     *
     * @return array<string, mixed>
     */
    public function task(Authorizer $az, string $tpid): array
    {
        [$t, $p, $r] = $this->guarded($az, $tpid);
        $db = $this->app->db();
        $ps = $this->ps();
        $id = (int) $t['id'];
        $t['project'] = $p;
        $t['roles'] = $r;
        $t['mine'] = $t['assignee_id'] !== null && (int) $t['assignee_id'] === $az->userId;
        $t['assignee'] = $t['assignee_id'] === null ? null : (string) $db->scalar('SELECT username FROM users WHERE id = ?', [$t['assignee_id']]);
        $t['assignee_name'] = $t['assignee_id'] === null ? null : $ps->name((int) $t['assignee_id']);
        $t['parent'] = $t['parent_id'] === null ? null : $db->one('SELECT public_id, title FROM pj_tasks WHERE id = ?', [$t['parent_id']]);
        $t['deps'] = $db->all('SELECT t.public_id, t.title, t.status, t.due_on FROM pj_deps d JOIN pj_tasks t ON t.id = d.depends_on_id WHERE d.task_id = ? ORDER BY t.id', [$id]);
        $t['waiting'] = $db->all('SELECT t.public_id, t.title, t.status FROM pj_deps d JOIN pj_tasks t ON t.id = d.task_id WHERE d.depends_on_id = ? ORDER BY t.id', [$id]);
        $t['subtasks'] = $db->all('SELECT public_id, title, status FROM pj_tasks WHERE parent_id = ? ORDER BY sort, id', [$id]);
        $subs = $db->all('SELECT s.*, u.username AS by_name, d.username AS decided_name FROM pj_subs s LEFT JOIN users u ON u.id = s.submitted_by LEFT JOIN users d ON d.id = s.decided_by WHERE s.task_id = ? ORDER BY s.rev DESC', [$id]);
        foreach ($subs as &$s) {
            $s['files'] = $db->all('SELECT id, filename FROM pj_files WHERE sub_id = ? ORDER BY id', [$s['id']]);
        }
        unset($s);
        $t['subs'] = $subs;
        $t['comments'] = $db->all('SELECT c.*, u.username FROM pj_comments c JOIN users u ON u.id = c.user_id WHERE c.task_id = ? ORDER BY c.id', [$id]);
        $t['warnings'] = $this->warnings($t, $p);
        $t['others'] = $db->all('SELECT public_id, title FROM pj_tasks WHERE project_id = ? AND id <> ? AND status <> \'cancelled\' ORDER BY sort, id', [$p['id'], $id]);
        $t['tops'] = $db->all('SELECT public_id, title FROM pj_tasks WHERE project_id = ? AND id <> ? AND parent_id IS NULL ORDER BY sort, id', [$p['id'], $id]);
        $t['members'] = $db->all("SELECT u.username FROM pj_members m JOIN users u ON u.id = m.user_id WHERE m.project_id = ? AND m.status = 'active' ORDER BY u.username", [$p['id']]);
        $t['today'] = $ps->today();
        return $t;
    }

    /**
     * Warnings shown on a task (never a block).
     *
     * @param array<string, mixed> $t
     * @param array<string, mixed> $p
     * @return list<array{0: string, 1: array<string, string>}> translation key + params
     */
    public function warnings(array $t, array $p): array
    {
        $w = [];
        if (in_array($t['status'], ['accepted', 'cancelled'], true)) {
            return $w;
        }
        foreach ($t['deps'] as $d) {
            if ($d['status'] !== 'accepted' && $d['status'] !== 'cancelled') {
                $w[] = ['projects.warn.dep_open', ['task' => (string) $d['title']]];
            }
            if ($t['starts_on'] !== null && $d['due_on'] !== null && (string) $t['starts_on'] <= (string) $d['due_on']) {
                $w[] = ['projects.warn.starts_before_dep', ['task' => (string) $d['title'], 'due' => (string) $d['due_on']]];
            }
        }
        if (($t['due_on'] !== null && (string) $t['due_on'] > (string) $p['ends_on']) || ($t['starts_on'] !== null && (string) $t['starts_on'] < (string) $p['starts_on'])) {
            $w[] = ['projects.warn.outside_project', ['from' => (string) $p['starts_on'], 'to' => (string) $p['ends_on']]];
        }
        if ($t['due_on'] !== null && (string) $t['due_on'] < $this->ps()->today() && $t['status'] !== 'submitted') {
            $w[] = ['projects.warn.late', ['due' => (string) $t['due_on']]];
        }
        if ($t['assignee_id'] !== null && $t['starts_on'] !== null && $t['due_on'] !== null) {
            $n = (int) $this->app->db()->scalar(
                "SELECT COUNT(*) FROM pj_tasks x JOIN pj_members m ON m.project_id = x.project_id AND m.user_id = x.assignee_id AND m.status = 'active'
                 WHERE x.assignee_id = ? AND x.id <> ? AND x.status IN ('todo','in_progress','returned') AND x.starts_on <= ? AND x.due_on >= ?",
                [$t['assignee_id'], $t['id'], $t['due_on'], $t['starts_on']]
            );
            if ($n >= 3) {
                $w[] = ['projects.warn.busy', ['n' => (string) $n]];
            }
        }
        return $w;
    }

    /** @return array{bytes: string, mime: string, filename: string} a submitted file, for members (and the approver) */
    public function file(Authorizer $az, string $tpid, int $fileId): array
    {
        [$t] = $this->guarded($az, $tpid);
        $f = $this->app->db()->one('SELECT f.* FROM pj_files f JOIN pj_subs s ON s.id = f.sub_id WHERE f.id = ? AND s.task_id = ?', [$fileId, $t['id']]) ?? throw HttpException::notFound();
        $doc = $this->app->documents()->moduleRead((string) $f['document_pid'], 'projects', $az->userId, 'project work');
        return ['bytes' => $doc['bytes'], 'mime' => $doc['mime'], 'filename' => (string) $f['filename']];
    }

    /**
     * My open tasks across my projects (live projects only).
     *
     * @return list<array<string, mixed>>
     */
    public function myTasks(Authorizer $az): array
    {
        return $this->app->db()->all(
            "SELECT t.public_id, t.title, t.status, t.due_on, t.progress, p.public_id AS project_pid, p.name AS project FROM pj_tasks t JOIN pj_projects p ON p.id = t.project_id
             JOIN pj_members m ON m.project_id = p.id AND m.user_id = t.assignee_id AND m.status = 'active'
             WHERE t.assignee_id = ? AND t.status IN ('todo','in_progress','returned','submitted') AND p.status IN ('active','at_risk','on_hold') ORDER BY t.due_on IS NULL, t.due_on LIMIT 200",
            [$az->userId]
        );
    }

    /** Hourly: the day before the due date (once); once when late (the assignee and the leads). */
    public function tick(): int
    {
        $db = $this->app->db();
        $ps = $this->ps();
        $today = $ps->today();
        $n = 0;
        $live = "p.status IN ('active','at_risk','on_hold') AND t.status IN ('todo','in_progress','returned') AND t.assignee_id IS NOT NULL";
        foreach ($db->all("SELECT t.*, p.name AS project FROM pj_tasks t JOIN pj_projects p ON p.id = t.project_id WHERE {$live} AND t.reminded = 0 AND t.due_on = ? LIMIT 500", [$ps->addDays($today, 1)]) as $t) {
            $db->run('UPDATE pj_tasks SET reminded = 1 WHERE id = ?', [$t['id']]);
            $this->app->notifier()->notify((int) $t['assignee_id'], 'projects.task', 'projects.notify.due', ['task' => mb_substr((string) $t['title'], 0, 80), 'due' => (string) $t['due_on']], '/projects/t/' . $t['public_id'], 'pj.du:' . $t['public_id'] . ':' . $t['due_on']);
            $n++;
        }
        foreach ($db->all("SELECT t.*, p.name AS project FROM pj_tasks t JOIN pj_projects p ON p.id = t.project_id WHERE {$live} AND t.overdue_notified = 0 AND t.due_on < ? LIMIT 500", [$today]) as $t) {
            $db->run('UPDATE pj_tasks SET overdue_notified = 1 WHERE id = ?', [$t['id']]);
            foreach (array_unique([(int) $t['assignee_id'], ...$ps->leads((int) $t['project_id'])]) as $u) {
                $this->app->notifier()->notify($u, 'projects.task', 'projects.notify.late', ['task' => mb_substr((string) $t['title'], 0, 80), 'due' => (string) $t['due_on']], '/projects/t/' . $t['public_id'], 'pj.lt:' . $t['public_id'] . ':' . $t['due_on'] . ':' . $u);
                $n++;
            }
        }
        return $n;
    }

    public static function tickJob(App $app): string
    {
        return 'projects: ' . self::of($app)->tick();
    }
}
