<?php

declare(strict_types=1);

namespace Blue\Core\Authz;

use Blue\Core\Audit\AuditLogger;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;
use Blue\Core\Workflow\WorkflowEngine;
use Blue\Core\Workflow\WorkflowSubject;

/**
 * "Request access" (6.4): a user asks for a role + data scope; an administrator who covers that company approves
 * in the central inbox; approval GRANTS through IamService, so every phase 2 rule still applies (no self-grant,
 * scope inside the granter's own, high-risk roles still need a second system admin).
 */
final class AccessRequests implements WorkflowSubject
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly WorkflowEngine $workflow,
        private readonly IamService $iam,
    ) {
    }

    /** @param string|null $moduleCode the app the request was made from on Home (AppHub key), null = general form */
    public function create(int $userId, string $roleCode, string $scopeLevel, int $scopeId, string $reason, ?string $moduleCode = null): string
    {
        $errors = [];
        if (!isset(Catalog::ROLES[$roleCode])) {
            $errors['role'] = 'form.invalid_choice';
        }
        $le = match ($scopeLevel) {
            'legal_entity' => $this->db->scalar('SELECT id FROM legal_entities WHERE id = ?', [$scopeId]),
            'site' => $this->db->scalar('SELECT legal_entity_id FROM sites WHERE id = ?', [$scopeId]),
            'department' => $this->db->scalar('SELECT legal_entity_id FROM departments WHERE id = ?', [$scopeId]),
            'organization' => $this->db->scalar('SELECT id FROM organizations WHERE id = ?', [$scopeId]) !== null ? 0 : null,
            default => null,
        };
        if ($le === null) {
            $errors['scope'] = 'iam.error.scope_required';
        }
        if (trim($reason) === '') {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $open = $this->db->scalar("SELECT public_id FROM access_requests WHERE user_id = ? AND role_code = ? AND status IN ('pending','returned')", [$userId, $roleCode]);
        if ($open !== null) {
            throw new BusinessException('access.error.open_request', [], 409);
        }
        $leId = (int) $le === 0 ? null : (int) $le;
        $pid = Ulid::generate($this->clock);
        $moduleCode = $moduleCode !== null && preg_match('/^[a-z][a-z0-9_.]{1,59}$/', $moduleCode) === 1 ? $moduleCode : null;
        $this->db->transactional(function () use ($pid, $userId, $roleCode, $scopeLevel, $scopeId, $leId, $reason, $moduleCode): void {
            $now = $this->now();
            $this->db->run(
                'INSERT INTO access_requests (public_id, created_at, created_by, updated_at, updated_by, user_id, role_code, module_code, scope_level, scope_id, legal_entity_id, reason, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\')',
                [$pid, $now, $userId, $now, $userId, $userId, $roleCode, $moduleCode, $scopeLevel, $scopeId, $leId, mb_substr($reason, 0, 255)]
            );
            $name = (string) $this->db->scalar('SELECT display_name FROM users WHERE id = ?', [$userId]);
            $inst = $this->workflow->start('core.access_request', $pid, $name . ' → ' . $roleCode, $leId, $userId);
            $this->db->run('UPDATE access_requests SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE public_id = ?', [$inst, $pid]);
            $this->audit->record('access.requested', 'success', 'access_request', $pid, $leId, $reason, ['role' => $roleCode, 'scope' => "{$scopeLevel}:{$scopeId}", 'app' => $moduleCode]);
        });
        return $pid;
    }

    /** @return array<string, mixed>|null the request behind a workflow instance (for the approval page) */
    public function bySubject(string $publicId): ?array
    {
        return $this->db->one('SELECT r.*, u.display_name, u.username FROM access_requests r JOIN users u ON u.id = r.user_id WHERE r.public_id = ?', [$publicId]);
    }

    public function type(): string
    {
        return 'access_request';
    }

    public function link(string $subjectId): string
    {
        return '/approvals';
    }

    public function onApproved(array $instance, int $approverId): void
    {
        $req = $this->bySubject((string) $instance['subject_id']) ?? throw HttpException::notFound();
        if ($req['assignment_id'] !== null) {
            return; // already granted (retry)
        }
        $userPid = (string) $this->db->scalar('SELECT public_id FROM users WHERE id = ?', [$req['user_id']]);
        $az = new Authorizer($this->db, $this->clock, $approverId);
        $res = $this->iam->grant($az, $userPid, (string) $req['role_code'], [[(string) $req['scope_level'], (int) $req['scope_id']]], $this->clock->now()->format('Y-m-d'), null, 'access request ' . $req['public_id'] . ': ' . $req['reason']);
        $aid = (int) $this->db->scalar('SELECT id FROM user_role_assignments WHERE public_id = ?', [$res['public_id']]);
        $this->db->run("UPDATE access_requests SET status = 'approved', assignment_id = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$aid, $this->now(), $approverId, $req['id']]);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        $this->db->run(
            "UPDATE access_requests SET status = ?, updated_at = ?, row_version = row_version + 1 WHERE public_id = ? AND status IN ('pending','returned')",
            [$outcome, $this->now(), $instance['subject_id']]
        );
    }

    public function onResubmitted(array $instance): void
    {
        $this->db->run("UPDATE access_requests SET status = 'pending', updated_at = ?, row_version = row_version + 1 WHERE public_id = ? AND status = 'returned'", [$this->now(), $instance['subject_id']]);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
