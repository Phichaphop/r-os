<?php

declare(strict_types=1);

namespace Blue\Core\Workflow;

use Blue\Core\Audit\AuditLogger;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Notify\Notifier;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;
use Throwable;

/**
 * Central Workflow & Approval Engine (6.9, 6.9b, 6.30.4).
 *
 * - Definitions are versioned; an instance keeps the version it started with (changing a route never silently
 *   re-routes pending requests).
 * - Decision ≠ Execution: approval runs the subject's onApproved(); a failure leaves decision "approved" with
 *   execution "failed" so it can be retried safely (the subject is idempotent).
 * - The requester never approves their own request — excluded when assigning AND re-checked when deciding,
 *   including through delegation.
 * - No approver → fallback role; still none → "needs attention" (never auto-approved).
 */
final class WorkflowEngine
{
    /** @var array<string, WorkflowSubject> */
    private array $subjects = [];

    /** @var array<string, true> module routes that must not fall back to SYS */
    private array $noFallback = [];

    /** @var array<string, true> routes whose steps must each be decided by a different person ("distinct_steps") */
    private array $distinctSteps = [];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {
    }

    private ?\Blue\Core\Calendar\WorkCalendar $calendar = null;
    private string $timezone = 'Asia/Bangkok';

    /** SLA hours count on working days of the request's company when it has a work calendar (DEC-56). */
    public function useCalendar(\Blue\Core\Calendar\WorkCalendar $calendar, string $timezone): void
    {
        $this->calendar = $calendar;
        $this->timezone = $timezone;
    }

    /** @param array<string, mixed> $inst */
    private function dueAt(array $inst, int $hours): string
    {
        $le = $inst['legal_entity_id'] !== null ? (int) $inst['legal_entity_id'] : null;
        try {
            $due = $this->calendar?->addWorkingHours($le, $this->clock->now(), $hours, $this->timezone);
        } catch (Throwable) {
            $due = null; // calendar tables not there yet (before the 3B update) — plain hours
        }
        return ($due ?? $this->clock->now()->modify('+' . $hours . ' hours'))->format('Y-m-d H:i:s.u');
    }

    public function register(WorkflowSubject $subject): void
    {
        $this->subjects[$subject->type()] = $subject;
    }

    /** A route that must never fall back to the system administrator (module routes with "no_fallback": true). */
    public function noFallback(string $code): void
    {
        $this->noFallback[$code] = true;
    }

    /**
     * A route whose steps must each be decided by a different person (module routes with "distinct_steps": true, e.g.
     * a reviewer may not also be the approver). Declared in code, so it cannot be switched off from the route editor.
     */
    public function distinctSteps(string $code): void
    {
        $this->distinctSteps[$code] = true;
    }

    /**
     * People who approved an earlier step of the request since it was last (re)submitted.
     *
     * @param array<string, mixed> $inst
     * @return list<int>
     */
    private function earlierDeciders(array $inst, int $stepNo): array
    {
        $since = (int) ($this->db->scalar("SELECT MAX(id) FROM workflow_decisions WHERE instance_id = ? AND decision IN ('submit','resubmit')", [$inst['id']]) ?? 0);
        $out = [];
        foreach ($this->db->all("SELECT actor_id, on_behalf_of_id FROM workflow_decisions WHERE instance_id = ? AND id > ? AND step_no < ? AND decision = 'approve'", [$inst['id'], $since, $stepNo]) as $d) {
            $out[] = (int) $d['actor_id'];
            if ($d['on_behalf_of_id'] !== null) {
                $out[] = (int) $d['on_behalf_of_id'];
            }
        }
        return array_values(array_unique($out));
    }

    public function subject(string $type): ?WorkflowSubject
    {
        return $this->subjects[$type] ?? null;
    }

    // ---- definitions ------------------------------------------------------------------------------

    /**
     * Creates catalogue definitions (version 1) that do not exist yet — the core's and every module's ("workflows" in
     * module.json). Existing versions are never touched.
     *
     * @param array<string, array<string, mixed>> $modules manifests from the module registry
     */
    public static function sync(Connection $db, Clock $clock, array $modules = []): int
    {
        $n = 0;
        $all = WorkflowCatalog::DEFINITIONS;
        foreach ($modules as $m) {
            foreach ((array) ($m['workflows'] ?? []) as $wcode => $wf) {
                $all[(string) $wcode] = ['subject' => (string) $wf['subject'], 'steps' => array_map(static fn (array $st): array => [
                    'approver' => $st['approver'], 'scope' => $st['scope'], 'sla_hours' => $st['sla_hours'] ?? null, 'escalate_to' => $st['escalate_to'] ?? null,
                ], (array) $wf['steps'])];
            }
        }
        foreach ($all as $code => $def) {
            if ($db->scalar('SELECT id FROM workflow_definitions WHERE code = ?', [$code]) !== null) {
                continue;
            }
            $now = $clock->now()->format('Y-m-d H:i:s.u');
            $db->run(
                'INSERT INTO workflow_definitions (public_id, created_at, updated_at, code, module_code, subject_type) VALUES (?, ?, ?, ?, ?, ?)',
                [Ulid::generate($clock), $now, $now, $code, explode('.', $code)[0], $def['subject']]
            );
            $id = (int) $db->pdo()->lastInsertId();
            $db->run(
                'INSERT INTO workflow_versions (definition_id, version_no, steps_json, status, created_at, change_reason) VALUES (?, 1, ?, \'active\', ?, ?)',
                [$id, json_encode($def['steps']), $now, 'initial']
            );
            $n++;
        }
        return $n;
    }

    /**
     * Publishes a new version of a route; running instances keep their version.
     *
     * @param list<array<string, mixed>> $steps
     */
    public function publishVersion(string $code, array $steps, string $reason, int $actorId): int
    {
        $def = $this->db->one('SELECT * FROM workflow_definitions WHERE code = ?', [$code]) ?? throw HttpException::notFound();
        $errors = [];
        if (trim($reason) === '') {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ($steps === [] || count($steps) > 5) {
            $errors['steps'] = 'workflow.error.steps';
        }
        foreach ($steps as $s) {
            if (
                !in_array($s['approver']['type'] ?? '', ['permission', 'role', 'manager'], true) || ($s['approver']['value'] ?? '') === ''
                || !in_array($s['scope'] ?? '', ['subject', 'any'], true)
            ) {
                $errors['steps'] = 'workflow.error.steps';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $this->db->transactional(function () use ($def, $steps, $reason, $actorId, $code): int {
            $no = (int) $this->db->scalar('SELECT COALESCE(MAX(version_no), 0) + 1 FROM workflow_versions WHERE definition_id = ? FOR UPDATE', [$def['id']]);
            $this->db->run("UPDATE workflow_versions SET status = 'retired' WHERE definition_id = ? AND status = 'active'", [$def['id']]);
            $this->db->run(
                'INSERT INTO workflow_versions (definition_id, version_no, steps_json, status, created_by, created_at, change_reason) VALUES (?, ?, ?, \'active\', ?, ?, ?)',
                [$def['id'], $no, json_encode(array_values($steps)), $actorId, $this->now(), $reason]
            );
            $this->audit->record('workflow.version_published', 'success', 'workflow_definition', $code, null, $reason, ['version' => $no, 'steps' => $steps]);
            return $no;
        });
    }

    /** @return list<array<string, mixed>> definitions with their active version */
    public function definitions(): array
    {
        return $this->db->all(
            "SELECT d.code, d.subject_type, d.enabled, v.version_no, v.steps_json, v.created_at, v.change_reason,
                    (SELECT COUNT(*) FROM workflow_instances i JOIN workflow_versions v2 ON v2.id = i.version_id
                      WHERE v2.definition_id = d.id AND i.status IN ('pending','returned','needs_attention')) AS open_count
               FROM workflow_definitions d JOIN workflow_versions v ON v.definition_id = d.id AND v.status = 'active'
              ORDER BY d.code"
        );
    }

    // ---- lifecycle ------------------------------------------------------------------------------

    /** Starts a request. One open request per subject (double submit / refresh safe). */
    public function start(string $code, string $subjectId, string $title, ?int $legalEntityId, int $requesterId, ?int $siteId = null, ?int $departmentId = null): string
    {
        return $this->db->transactional(function () use ($code, $subjectId, $title, $legalEntityId, $requesterId, $siteId, $departmentId): string {
            $ver = $this->db->one(
                "SELECT v.id, d.subject_type, d.enabled FROM workflow_definitions d JOIN workflow_versions v ON v.definition_id = d.id AND v.status = 'active' WHERE d.code = ?",
                [$code]
            );
            if ($ver === null || (int) $ver['enabled'] !== 1) {
                throw new BusinessException('workflow.error.not_available', [], 409);
            }
            $open = $this->db->one(
                "SELECT public_id FROM workflow_instances WHERE subject_type = ? AND subject_id = ? AND status IN ('pending','returned','needs_attention') FOR UPDATE",
                [$ver['subject_type'], $subjectId]
            );
            if ($open !== null) {
                return (string) $open['public_id'];
            }
            $pid = Ulid::generate($this->clock);
            $now = $this->now();
            $this->db->run(
                'INSERT INTO workflow_instances (public_id, created_at, created_by, updated_at, updated_by, version_id, subject_type, subject_id, title,
                     legal_entity_id, site_id, department_id, requester_id, current_step, status, submitted_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, \'pending\', ?)',
                [$pid, $now, $requesterId, $now, $requesterId, $ver['id'], $ver['subject_type'], $subjectId, mb_substr($title, 0, 200), $legalEntityId, $siteId, $departmentId, $requesterId, $now]
            );
            $inst = $this->instanceRow($pid);
            $this->decision($inst, 1, $requesterId, null, 'submit', null);
            $this->assign($inst, 1);
            return $pid;
        });
    }

    /** approve | reject | return — by an assignee of the current step (never the requester). */
    public function decide(string $instancePublicId, int $actorId, string $decision, string $comment = ''): void
    {
        if (!in_array($decision, ['approve', 'reject', 'return'], true)) {
            throw new BusinessException('workflow.error.bad_decision', [], 400);
        }
        if ($decision !== 'approve' && trim($comment) === '') {
            throw new ValidationException(['comment' => 'workflow.error.comment_required']);
        }
        $executeAfter = null;
        $expired = false;
        $denied = null;
        $this->db->transactional(function () use ($instancePublicId, $actorId, $decision, $comment, &$executeAfter, &$expired, &$denied): void {
            $inst = $this->db->one('SELECT * FROM workflow_instances WHERE public_id = ? FOR UPDATE', [$instancePublicId]) ?? throw HttpException::notFound();
            if ($inst['status'] !== 'pending') {
                throw new BusinessException('workflow.error.not_pending', [], 409);
            }
            $task = $this->db->one(
                "SELECT * FROM workflow_tasks WHERE instance_id = ? AND step_no = ? AND assignee_id = ? AND status = 'open'",
                [$inst['id'], $inst['current_step'], $actorId]
            );
            if ($task === null) {
                $denied = ['workflow.decide_denied', HttpException::forbidden(), $this->le($inst)];
                return;
            }
            $onBehalf = $task['via_delegation_from'] !== null ? (int) $task['via_delegation_from'] : null;
            if ($actorId === (int) $inst['requester_id'] || $onBehalf === (int) $inst['requester_id']) {
                $denied = ['workflow.self_approval_blocked', new BusinessException('workflow.error.self_approval', [], 403), $this->le($inst)];
                return;
            }
            if (isset($this->distinctSteps[$this->codeOf((int) $inst['version_id'])]) && (int) $inst['current_step'] > 1) {
                $earlier = $this->earlierDeciders($inst, (int) $inst['current_step']);
                if (in_array($actorId, $earlier, true) || ($onBehalf !== null && in_array($onBehalf, $earlier, true))) {
                    $denied = ['workflow.same_person_blocked', new BusinessException('workflow.error.same_person', [], 403), $this->le($inst)];
                    return;
                }
            }
            if ($onBehalf !== null && !$this->delegationActive($onBehalf, $actorId, $inst)) {
                // delegation ended: hand the task back to the delegator and refuse
                $this->db->run("UPDATE workflow_tasks SET status = 'void' WHERE id = ?", [$task['id']]);
                $this->addTask($inst, (int) $inst['current_step'], $onBehalf, null, false);
                $expired = true; // committed below, then refused
                return;
            }
            $this->db->run("UPDATE workflow_tasks SET status = 'done' WHERE id = ?", [$task['id']]);
            $this->db->run("UPDATE workflow_tasks SET status = 'void' WHERE instance_id = ? AND step_no = ? AND status = 'open'", [$inst['id'], $inst['current_step']]);
            $this->decision($inst, (int) $inst['current_step'], $actorId, $onBehalf, $decision, $comment);
            $now = $this->now();
            if ($decision === 'approve') {
                $steps = $this->steps((int) $inst['version_id']);
                if ((int) $inst['current_step'] < count($steps)) {
                    $next = (int) $inst['current_step'] + 1;
                    $this->db->run('UPDATE workflow_instances SET current_step = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$next, $now, $actorId, $inst['id']]);
                    $inst['current_step'] = $next;
                    $this->assign($inst, $next);
                    return;
                }
                $this->db->run("UPDATE workflow_instances SET status = 'approved', finished_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$now, $now, $actorId, $inst['id']]);
                $executeAfter = $inst;
                return;
            }
            $status = $decision === 'reject' ? 'rejected' : 'returned';
            $this->db->run(
                'UPDATE workflow_instances SET status = ?, finished_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                [$status, $decision === 'reject' ? $now : null, $now, $actorId, $inst['id']]
            );
            $this->notifier->notify((int) $inst['requester_id'], 'workflow.' . $status, 'notify.workflow.' . $status, ['title' => (string) $inst['title']], '/requests/' . $inst['public_id']);
            $this->subject((string) $inst['subject_type'])?->onClosed($inst, $status);
        });
        if ($denied !== null) {
            // recorded outside the (empty) transaction so the evidence survives the refusal
            $this->audit->record($denied[0], 'denied', 'workflow_instance', $instancePublicId, $denied[2]);
            throw $denied[1];
        }
        if ($expired) {
            throw new BusinessException('workflow.error.delegation_expired', [], 403);
        }
        if ($executeAfter !== null) {
            $this->execute($executeAfter, $actorId);
        }
    }

    /** Requester withdraws a request that is still pending or returned. */
    public function cancel(string $instancePublicId, int $actorId, string $reason): void
    {
        $this->db->transactional(function () use ($instancePublicId, $actorId, $reason): void {
            $inst = $this->db->one('SELECT * FROM workflow_instances WHERE public_id = ? FOR UPDATE', [$instancePublicId]) ?? throw HttpException::notFound();
            if ((int) $inst['requester_id'] !== $actorId) {
                throw HttpException::forbidden();
            }
            if (!in_array($inst['status'], ['pending', 'returned', 'needs_attention'], true)) {
                throw new BusinessException('workflow.error.not_pending', [], 409);
            }
            $now = $this->now();
            $this->db->run("UPDATE workflow_tasks SET status = 'void' WHERE instance_id = ? AND status = 'open'", [$inst['id']]);
            $this->db->run("UPDATE workflow_instances SET status = 'cancelled', finished_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$now, $now, $inst['id']]);
            $this->decision($inst, (int) $inst['current_step'], $actorId, null, 'cancel', $reason);
            $this->subject((string) $inst['subject_type'])?->onClosed($inst, 'cancelled');
        });
    }

    /** Requester sends a returned request again — same workflow version, back to step 1. */
    public function resubmit(string $instancePublicId, int $actorId, string $comment = ''): void
    {
        $this->db->transactional(function () use ($instancePublicId, $actorId, $comment): void {
            $inst = $this->db->one('SELECT * FROM workflow_instances WHERE public_id = ? FOR UPDATE', [$instancePublicId]) ?? throw HttpException::notFound();
            if ((int) $inst['requester_id'] !== $actorId) {
                throw HttpException::forbidden();
            }
            if ($inst['status'] !== 'returned') {
                throw new BusinessException('workflow.error.not_returned', [], 409);
            }
            $this->db->run("UPDATE workflow_instances SET status = 'pending', current_step = 1, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $inst['id']]);
            $this->db->run("UPDATE workflow_tasks SET status = 'void' WHERE instance_id = ? AND status = 'open'", [$inst['id']]);
            $inst['current_step'] = 1;
            $inst['status'] = 'pending';
            $this->decision($inst, 1, $actorId, null, 'resubmit', $comment);
            $this->subject((string) $inst['subject_type'])?->onResubmitted($inst);
            $this->assign($inst, 1);
        });
    }

    /** Retries a failed execution (6.28.1 "Execution Failed" → safe retry). */
    public function retryExecution(string $instancePublicId, Authorizer $az): void
    {
        if (!$az->scope('core.workflow.manage')->all) {
            throw HttpException::forbidden();
        }
        $inst = $this->instanceRow($instancePublicId);
        if ($inst['status'] !== 'approved' || $inst['execution_status'] !== 'failed') {
            throw new BusinessException('workflow.error.nothing_to_retry', [], 409);
        }
        $this->execute($inst, $az->userId);
    }

    /** @param array<string, mixed> $inst */
    private function execute(array $inst, int $actorId): void
    {
        $subject = $this->subject((string) $inst['subject_type']);
        $now = $this->now();
        try {
            if ($subject === null) {
                throw new \RuntimeException('No handler registered for ' . $inst['subject_type']);
            }
            $subject->onApproved($inst, $actorId);
            $this->db->run(
                "UPDATE workflow_instances SET execution_status = 'succeeded', execution_error = NULL, execution_attempts = execution_attempts + 1, updated_at = ? WHERE id = ?",
                [$now, $inst['id']]
            );
            $this->decision($inst, (int) $inst['current_step'], $actorId, null, 'execute_succeeded', null);
            $this->notifier->notify((int) $inst['requester_id'], 'workflow.approved', 'notify.workflow.approved', ['title' => (string) $inst['title']], '/requests/' . $inst['public_id']);
        } catch (Throwable $e) {
            $msg = $e instanceof BusinessException ? $e->messageKey : mb_substr($e->getMessage(), 0, 250);
            $this->db->run(
                "UPDATE workflow_instances SET execution_status = 'failed', execution_error = ?, execution_attempts = execution_attempts + 1, updated_at = ? WHERE id = ?",
                [$msg, $now, $inst['id']]
            );
            $this->decision($inst, (int) $inst['current_step'], $actorId, null, 'execute_failed', $msg);
            $this->audit->record('workflow.execution_failed', 'failure', 'workflow_instance', (string) $inst['public_id'], $this->le($inst), $msg);
            $this->notifyManagers('workflow.execution_failed', 'notify.workflow.execution_failed', $inst);
        }
    }

    // ---- assignment, delegation, escalation -------------------------------------------------------

    /** @param array<string, mixed> $inst */
    private function assign(array $inst, int $stepNo): void
    {
        $steps = $this->steps((int) $inst['version_id']);
        $step = $steps[$stepNo - 1] ?? null;
        $users = $step === null ? [] : $this->approvers($step['approver'], (string) ($step['scope'] ?? 'subject'), $inst);
        $sla = $step['sla_hours'] ?? null;
        $due = $sla !== null ? $this->dueAt($inst, (int) $sla) : null;
        $code = $this->codeOf((int) $inst['version_id']);
        if (isset($this->distinctSteps[$code]) && $stepNo > 1) {
            $users = array_values(array_diff($users, $this->earlierDeciders($inst, $stepNo)));
        }
        if ($users === [] && !in_array($code, WorkflowCatalog::NO_FALLBACK, true) && !isset($this->noFallback[$code])) {
            $users = $this->approvers(WorkflowCatalog::FALLBACK, 'any', $inst);
        }
        if ($users === []) {
            $this->db->run("UPDATE workflow_instances SET status = 'needs_attention', updated_at = ? WHERE id = ?", [$this->now(), $inst['id']]);
            $this->audit->record('workflow.no_approver', 'failure', 'workflow_instance', (string) $inst['public_id'], $this->le($inst));
            return;
        }
        foreach ($users as $uid) {
            $this->addTask($inst, $stepNo, $uid, $due, false);
        }
    }

    /**
     * Creates a task, routing it to an active delegate when the approver has one (and the delegate is not the requester).
     *
     * @param array<string, mixed> $inst
     */
    private function addTask(array $inst, int $stepNo, int $approverId, ?string $due, bool $escalation): void
    {
        $assignee = $approverId;
        $via = null;
        $code = $this->codeOf((int) $inst['version_id']);
        $now = $this->now();
        $del = $this->db->one(
            "SELECT to_user_id FROM workflow_delegations WHERE from_user_id = ? AND status = 'active' AND valid_from <= ? AND valid_to > ?
               AND (workflow_code IS NULL OR workflow_code = ?) ORDER BY valid_from DESC LIMIT 1",
            [$approverId, $now, $now, $code]
        );
        if ($del !== null && (int) $del['to_user_id'] !== (int) $inst['requester_id']) {
            $assignee = (int) $del['to_user_id'];
            $via = $approverId;
        }
        $this->db->run(
            'INSERT INTO workflow_tasks (instance_id, step_no, assignee_id, via_delegation_from, is_escalation, status, due_at, created_at)
             VALUES (?, ?, ?, ?, ?, \'open\', ?, ?)
             ON DUPLICATE KEY UPDATE status = \'open\', due_at = VALUES(due_at), is_escalation = GREATEST(is_escalation, VALUES(is_escalation)), via_delegation_from = VALUES(via_delegation_from)',
            [$inst['id'], $stepNo, $assignee, $via, $escalation ? 1 : 0, $due, $now]
        );
        $this->notifier->notify($assignee, 'workflow.task', 'notify.workflow.task', ['title' => (string) $inst['title']], '/approvals/' . $inst['public_id'], 'wf-task-' . $inst['public_id'] . '-' . $stepNo);
    }

    /**
     * Users who may decide a step: active users holding the permission/role whose data scope covers the subject's
     * company (scope = subject), excluding the requester.
     *
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $inst
     * @return list<int>
     */
    private function approvers(array $spec, string $scope, array $inst): array
    {
        if (($spec['type'] ?? '') === 'manager') {
            // the subject's line manager(s) (ManagerRouted); none → the fallback permission in the subject's company
            $subject = $this->subjects[(string) $inst['subject_type']] ?? null;
            $managers = $subject instanceof ManagerRouted ? $subject->managerUserIds((string) $inst['subject_id']) : [];
            $managers = array_values(array_unique(array_filter(array_map('intval', $managers), fn (int $u): bool => $u !== (int) $inst['requester_id']
                && (string) ($this->db->scalar('SELECT status FROM users WHERE id = ?', [$u]) ?? '') === 'active')));
            if ($managers !== []) {
                return $managers;
            }
            return $this->approvers(['type' => 'permission', 'value' => (string) $spec['value']], 'subject', $inst);
        }
        $today = $this->clock->now()->format('Y-m-d');
        $base = "FROM user_role_assignments a JOIN users u ON u.id = a.user_id
                 WHERE a.status = 'active' AND a.valid_from <= ? AND (a.valid_to IS NULL OR a.valid_to >= ?) AND u.status = 'active'";
        if (($spec['type'] ?? '') === 'role') {
            $rows = $this->db->all("SELECT DISTINCT a.user_id, a.id AS aid {$base} AND a.role_id = (SELECT id FROM roles WHERE code = ?)", [$today, $today, $spec['value']]);
        } else {
            $rows = $this->db->all(
                "SELECT DISTINCT a.user_id, a.id AS aid {$base} AND a.role_id IN (SELECT role_id FROM role_permissions WHERE permission_code = ?)",
                [$today, $today, $spec['value']]
            );
        }
        $le = $inst['legal_entity_id'] !== null ? (int) $inst['legal_entity_id'] : null;
        $site = isset($inst['site_id']) && $inst['site_id'] !== null ? (int) $inst['site_id'] : null;
        // the subject's unit and the units above it, nearest first (0.8.2, DEC-69)
        $units = [];
        $cur = isset($inst['department_id']) && $inst['department_id'] !== null ? (int) $inst['department_id'] : null;
        for ($i = 0; $cur !== null && $i < 12 && !in_array($cur, $units, true); $i++) {
            $units[] = $cur;
            $p = $this->db->scalar('SELECT parent_id FROM departments WHERE id = ?', [$cur]);
            $cur = $p !== null ? (int) $p : null;
        }
        // how closely each candidate's rights fit the subject: company/organization 1, branch 2, unit 100 − distance up the chart
        $fit = [];
        foreach ($rows as $row) {
            $uid = (int) $row['user_id'];
            if ($uid === (int) $inst['requester_id']) {
                continue;
            }
            $levels = $this->db->all('SELECT scope_level, scope_id FROM access_scopes WHERE assignment_id = ?', [$row['aid']]);
            $best = 0;
            foreach ($levels as $l) {
                $id = (int) $l['scope_id'];
                $score = match (true) {
                    $scope === 'any', $l['scope_level'] === 'organization' => 1,
                    $l['scope_level'] === 'legal_entity' && $le !== null && $id === $le => 1,
                    $l['scope_level'] === 'site' && $site !== null && $id === $site => 2,
                    $l['scope_level'] === 'department' && in_array($id, $units, true) => 100 - (int) array_search($id, $units, true),
                    default => 0,
                };
                $best = max($best, $score);
            }
            if ($best > 0) {
                $fit[$uid] = max($fit[$uid] ?? 0, $best);
            }
        }
        if ($fit === []) {
            return [];
        }
        // most specific wins: the branch/unit approver gets the request; company-wide approvers only when there is none
        $top = max($fit);
        return array_values(array_keys(array_filter($fit, static fn (int $f): bool => $f === $top)));
    }

    /** @param array<string, mixed> $inst */
    private function delegationActive(int $from, int $to, array $inst): bool
    {
        $now = $this->now();
        return $this->db->scalar(
            "SELECT id FROM workflow_delegations WHERE from_user_id = ? AND to_user_id = ? AND status = 'active' AND valid_from <= ? AND valid_to > ?
               AND (workflow_code IS NULL OR workflow_code = ?)",
            [$from, $to, $now, $now, $this->codeOf((int) $inst['version_id'])]
        ) !== null;
    }

    /** Delegates one's own approvals for a period (6.30.3). The delegate gains no permissions of their own. */
    public function delegate(int $fromUserId, int $toUserId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $reason, ?string $workflowCode = null): string
    {
        $errors = [];
        if ($toUserId === $fromUserId) {
            $errors['to_user'] = 'workflow.error.delegate_self';
        }
        if ($to <= $from) {
            $errors['valid_to'] = 'workflow.error.dates';
        }
        if (trim($reason) === '') {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ((string) ($this->db->scalar('SELECT status FROM users WHERE id = ?', [$toUserId]) ?? '') !== 'active') {
            $errors['to_user'] = 'workflow.error.delegate_inactive';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $pid = Ulid::generate($this->clock);
        $now = $this->now();
        $this->db->run(
            'INSERT INTO workflow_delegations (public_id, created_at, created_by, updated_at, updated_by, from_user_id, to_user_id, workflow_code, valid_from, valid_to, reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $fromUserId, $now, $fromUserId, $fromUserId, $toUserId, $workflowCode, $from->format('Y-m-d H:i:s.u'), $to->format('Y-m-d H:i:s.u'), $reason]
        );
        $this->audit->record('workflow.delegation_created', 'success', 'workflow_delegation', $pid, null, $reason, ['to_user_id' => $toUserId, 'from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM)]);
        return $pid;
    }

    public function revokeDelegation(string $publicId, int $actorId): void
    {
        $n = $this->db->run(
            "UPDATE workflow_delegations SET status = 'revoked', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE public_id = ? AND from_user_id = ? AND status = 'active'",
            [$this->now(), $actorId, $publicId, $actorId]
        )->rowCount();
        if ($n !== 1) {
            throw HttpException::notFound();
        }
        $this->audit->record('workflow.delegation_revoked', 'success', 'workflow_delegation', $publicId);
    }

    /** @return list<array<string, mixed>> */
    public function delegationsOf(int $userId): array
    {
        return $this->db->all(
            "SELECT d.public_id, d.workflow_code, d.valid_from, d.valid_to, d.reason, d.status, u.display_name AS to_name, u.username AS to_username
               FROM workflow_delegations d JOIN users u ON u.id = d.to_user_id WHERE d.from_user_id = ? ORDER BY d.valid_from DESC LIMIT 20",
            [$userId]
        );
    }

    /**
     * Background job: escalates open tasks past their SLA and returns expired delegations to the delegator.
     * Each task escalates once (6.30.13 escalation; SLA counts calendar hours until the work calendar lands in 3B).
     */
    public function escalateDue(): int
    {
        $now = $this->now();
        $n = 0;
        $due = $this->db->all(
            "SELECT t.id, t.instance_id, t.step_no FROM workflow_tasks t JOIN workflow_instances i ON i.id = t.instance_id
              WHERE t.status = 'open' AND t.is_escalation = 0 AND t.due_at IS NOT NULL AND t.due_at < ? AND i.status = 'pending' AND i.current_step = t.step_no
                AND NOT EXISTS (SELECT 1 FROM workflow_tasks e WHERE e.instance_id = t.instance_id AND e.step_no = t.step_no AND e.is_escalation = 1)",
            [$now]
        );
        foreach ($due as $t) {
            $inst = $this->db->one('SELECT * FROM workflow_instances WHERE id = ?', [$t['instance_id']]);
            if ($inst === null) {
                continue;
            }
            $step = $this->steps((int) $inst['version_id'])[(int) $t['step_no'] - 1] ?? [];
            $target = $step['escalate_to'] ?? null;
            if (!is_array($target)) {
                continue;
            }
            foreach ($this->approvers($target, 'any', $inst) as $uid) {
                $this->addTask($inst, (int) $t['step_no'], $uid, null, true);
            }
            $this->decision($inst, (int) $t['step_no'], (int) $inst['requester_id'], null, 'escalate', 'SLA');
            $n++;
        }
        // delegations that ended: give tasks back to the delegator
        foreach (
            $this->db->all(
                "SELECT t.id, t.instance_id, t.step_no, t.via_delegation_from, t.assignee_id FROM workflow_tasks t JOIN workflow_instances i ON i.id = t.instance_id
              WHERE t.status = 'open' AND t.via_delegation_from IS NOT NULL AND i.status = 'pending'"
            ) as $t
        ) {
            $inst = $this->db->one('SELECT * FROM workflow_instances WHERE id = ?', [$t['instance_id']]);
            if ($inst !== null && !$this->delegationActive((int) $t['via_delegation_from'], (int) $t['assignee_id'], $inst)) {
                $this->db->run("UPDATE workflow_tasks SET status = 'void' WHERE id = ?", [$t['id']]);
                $this->addTask($inst, (int) $t['step_no'], (int) $t['via_delegation_from'], null, false);
                $n++;
            }
        }
        return $n;
    }

    // ---- read models ------------------------------------------------------------------------------

    public function openTaskCount(int $userId): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(DISTINCT t.instance_id) FROM workflow_tasks t JOIN workflow_instances i ON i.id = t.instance_id
              WHERE t.assignee_id = ? AND t.status = 'open' AND i.status = 'pending' AND i.current_step = t.step_no",
            [$userId]
        );
    }

    /** @return array{rows:list<array<string,mixed>>, total:int} */
    public function inbox(int $userId, int $page, int $per): array
    {
        $where = "t.assignee_id = ? AND t.status = 'open' AND i.status = 'pending' AND i.current_step = t.step_no";
        $total = (int) $this->db->scalar("SELECT COUNT(DISTINCT i.id) FROM workflow_tasks t JOIN workflow_instances i ON i.id = t.instance_id WHERE {$where}", [$userId]);
        $offset = ($page - 1) * $per;
        $rows = $this->db->all(
            "SELECT i.public_id, i.title, i.subject_type, i.submitted_at, i.current_step, i.legal_entity_id, u.display_name AS requester_name,
                    MIN(t.due_at) AS due_at, MAX(t.is_escalation) AS escalated, MAX(t.via_delegation_from) AS delegated_from
               FROM workflow_tasks t JOIN workflow_instances i ON i.id = t.instance_id JOIN users u ON u.id = i.requester_id
              WHERE {$where} GROUP BY i.id ORDER BY i.submitted_at LIMIT {$per} OFFSET {$offset}",
            [$userId]
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array{rows:list<array<string,mixed>>, total:int} */
    public function myRequests(int $userId, string $status, int $page, int $per): array
    {
        $where = 'i.requester_id = ?';
        $params = [$userId];
        if (in_array($status, ['pending', 'approved', 'rejected', 'returned', 'cancelled', 'needs_attention'], true)) {
            $where .= ' AND i.status = ?';
            $params[] = $status;
        }
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM workflow_instances i WHERE {$where}", $params);
        $offset = ($page - 1) * $per;
        $rows = $this->db->all(
            "SELECT i.public_id, i.title, i.subject_type, i.status, i.execution_status, i.submitted_at, i.finished_at, i.current_step,
                    (SELECT GROUP_CONCAT(DISTINCT u.display_name SEPARATOR ', ') FROM workflow_tasks t JOIN users u ON u.id = t.assignee_id
                      WHERE t.instance_id = i.id AND t.status = 'open' AND t.step_no = i.current_step) AS waiting_on
               FROM workflow_instances i WHERE {$where} ORDER BY i.submitted_at DESC, i.id DESC LIMIT {$per} OFFSET {$offset}",
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Detail for someone involved: the requester, any assignee (past or present) or a workflow manager.
     *
     * @return array{instance: array<string,mixed>, decisions: list<array<string,mixed>>, steps: list<array<string,mixed>>, version_no: int, can_decide: bool, open_tasks: list<array<string,mixed>>}
     */
    public function detail(string $publicId, Authorizer $az): array
    {
        $inst = $this->db->one(
            'SELECT i.*, u.display_name AS requester_name FROM workflow_instances i JOIN users u ON u.id = i.requester_id WHERE i.public_id = ?',
            [$publicId]
        );
        $involved = $inst !== null && ((int) $inst['requester_id'] === $az->userId
            || $this->db->scalar('SELECT id FROM workflow_tasks WHERE instance_id = ? AND assignee_id = ? LIMIT 1', [$inst['id'], $az->userId]) !== null
            || $az->scope('core.workflow.manage')->all);
        if (!$involved) {
            if ($inst !== null) {
                $this->audit->record('authz.out_of_scope', 'denied', 'workflow_instance', $publicId);
            }
            throw HttpException::notFound();
        }
        $decisions = $this->db->all(
            'SELECT d.step_no, d.decision, d.comment, d.decided_at, u.display_name AS actor_name, b.display_name AS on_behalf_name
               FROM workflow_decisions d JOIN users u ON u.id = d.actor_id LEFT JOIN users b ON b.id = d.on_behalf_of_id
              WHERE d.instance_id = ? ORDER BY d.decided_at, d.id',
            [$inst['id']]
        );
        $open = $this->db->all(
            "SELECT t.step_no, t.due_at, t.is_escalation, u.display_name, t.assignee_id FROM workflow_tasks t JOIN users u ON u.id = t.assignee_id
              WHERE t.instance_id = ? AND t.status = 'open' AND t.step_no = ?",
            [$inst['id'], $inst['current_step']]
        );
        $canDecide = $inst['status'] === 'pending' && array_filter($open, static fn (array $t): bool => (int) $t['assignee_id'] === $az->userId) !== [];
        return [
            'instance' => $inst,
            'decisions' => $decisions,
            'steps' => $this->steps((int) $inst['version_id']),
            'version_no' => (int) $this->db->scalar('SELECT version_no FROM workflow_versions WHERE id = ?', [$inst['version_id']]),
            'can_decide' => $canDecide,
            'open_tasks' => $open,
        ];
    }

    /** @return array<string, mixed> */
    public function instanceRow(string $publicId): array
    {
        return $this->db->one('SELECT * FROM workflow_instances WHERE public_id = ?', [$publicId]) ?? throw HttpException::notFound();
    }

    // ---- helpers ------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function steps(int $versionId): array
    {
        $json = (string) $this->db->scalar('SELECT steps_json FROM workflow_versions WHERE id = ?', [$versionId]);
        $steps = json_decode($json, true);
        return is_array($steps) ? array_values($steps) : [];
    }

    private function codeOf(int $versionId): string
    {
        return (string) $this->db->scalar('SELECT d.code FROM workflow_versions v JOIN workflow_definitions d ON d.id = v.definition_id WHERE v.id = ?', [$versionId]);
    }

    /** @param array<string, mixed> $inst */
    private function decision(array $inst, int $step, int $actorId, ?int $onBehalf, string $decision, ?string $comment): void
    {
        $this->db->run(
            'INSERT INTO workflow_decisions (instance_id, step_no, version_id, actor_id, on_behalf_of_id, decision, comment, correlation_id, decided_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$inst['id'], $step, $inst['version_id'], $actorId, $onBehalf, $decision, $comment !== null ? mb_substr($comment, 0, 500) : null, null, $this->now()]
        );
        $this->audit->record('workflow.' . $decision, 'success', 'workflow_instance', (string) $inst['public_id'], $this->le($inst), $comment, ['step' => $step, 'on_behalf_of' => $onBehalf]);
    }

    /** @param array<string, mixed> $inst */
    private function notifyManagers(string $event, string $key, array $inst): void
    {
        foreach ($this->approvers(['type' => 'permission', 'value' => 'core.workflow.manage'], 'any', ['legal_entity_id' => null, 'requester_id' => 0]) as $uid) {
            $this->notifier->notify($uid, $event, $key, ['title' => (string) $inst['title']], '/approvals/' . $inst['public_id'], $event . '-' . $inst['public_id'] . '-' . $inst['execution_attempts']);
        }
    }

    /** @param array<string, mixed> $inst */
    private function le(array $inst): ?int
    {
        return $inst['legal_entity_id'] !== null ? (int) $inst['legal_entity_id'] : null;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
