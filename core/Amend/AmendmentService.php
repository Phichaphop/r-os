<?php

declare(strict_types=1);

namespace Blue\Core\Amend;

use Blue\Core\Audit\AuditLogger;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;
use Blue\Core\Workflow\WorkflowEngine;
use Blue\Core\Workflow\WorkflowSubject;
use Throwable;

/**
 * Amendment / cancel / reverse requests for approved records (6.28).
 * Asking never changes the original. After approval the domain handler executes the change; if that fails the
 * request is "execution_failed" (not done, nothing half-written) and can be retried safely with the same
 * idempotency key. A request against a record that changed since it was filed is refused at execution.
 */
final class AmendmentService implements WorkflowSubject
{
    public const REASON_CODES = ['data_error', 'late_information', 'policy_change', 'duplicate', 'other'];

    /** @var array<string, AmendmentHandler> */
    private array $handlers = [];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly WorkflowEngine $workflow,
    ) {
    }

    public function register(AmendmentHandler $h): void
    {
        $this->handlers[$h->targetType()] = $h;
    }

    /**
     * @param array<string, string> $changes field => proposed new value
     * @return string request public id (status submitted, waiting in the approval inbox)
     */
    public function request(int $actorId, string $targetType, string $targetId, string $action, string $reasonCode, string $reason, array $changes): string
    {
        $h = $this->handlers[$targetType] ?? throw HttpException::notFound();
        $target = $h->load($targetId, $actorId) ?? throw HttpException::notFound();
        $errors = [];
        if (!in_array($action, ['amend', 'cancel', 'reverse', 'reopen'], true)) {
            $errors['action'] = 'amend.error.action';
        }
        if (!in_array($reasonCode, self::REASON_CODES, true)) {
            $errors['reason_code'] = 'amend.error.reason_code';
        }
        if (trim($reason) === '') {
            $errors['reason'] = 'iam.error.reason_required';
        }
        foreach (array_keys($changes) as $f) {
            if (!in_array($f, $target['amendable_fields'], true)) {
                $errors['changes'] = 'amend.error.field';
            }
        }
        if ($action === 'amend' && $changes === []) {
            $errors['changes'] = 'amend.error.no_changes';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $open = $this->db->scalar(
            "SELECT public_id FROM amendment_requests WHERE target_type = ? AND target_id = ? AND status IN ('submitted','approved','returned','execution_failed')",
            [$targetType, $targetId]
        );
        if ($open !== null) {
            throw new BusinessException('amend.error.open_request', [], 409);
        }
        $pid = Ulid::generate($this->clock);
        $this->db->transactional(function () use ($pid, $actorId, $targetType, $targetId, $target, $action, $reasonCode, $reason, $changes, $h): void {
            $now = $this->now();
            $this->db->run(
                'INSERT INTO amendment_requests (public_id, created_at, created_by, updated_at, updated_by, target_type, target_id, target_revision, legal_entity_id,
                    action, reason_code, reason, requester_id, status, impact_json, idempotency_key)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'submitted\', ?, ?)',
                [$pid, $now, $actorId, $now, $actorId, $targetType, $targetId, $target['revision'], $target['legal_entity_id'], $action, $reasonCode,
                    mb_substr($reason, 0, 500), $actorId, json_encode($h->impact($targetId, $changes), JSON_UNESCAPED_UNICODE), 'amend-' . $pid]
            );
            $rid = (int) $this->db->pdo()->lastInsertId();
            foreach ($changes as $field => $new) {
                $old = $target['values'][$field] ?? null;
                $this->db->run('INSERT INTO amendment_changes (request_id, field, old_value, new_value) VALUES (?, ?, ?, ?)', [$rid, $field, $old === null ? null : (string) $old, $new]);
            }
            $inst = $this->workflow->start('core.amendment', $pid, $targetType . ' ' . $action, $target['legal_entity_id'], $actorId);
            $this->db->run('UPDATE amendment_requests SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE id = ?', [$inst, $rid]);
            $this->audit->record('amendment.requested', 'success', $targetType, $targetId, $target['legal_entity_id'], $reason, ['request' => $pid, 'action' => $action, 'fields' => array_keys($changes)]);
        });
        return $pid;
    }

    /** @return array{request: array<string,mixed>, changes: list<array<string,mixed>>} */
    public function get(string $publicId): array
    {
        $r = $this->db->one('SELECT * FROM amendment_requests WHERE public_id = ?', [$publicId]) ?? throw HttpException::notFound();
        return ['request' => $r, 'changes' => $this->db->all('SELECT field, old_value, new_value FROM amendment_changes WHERE request_id = ? ORDER BY id', [$r['id']])];
    }

    /** Closes an executed request (6.28.1 … → Close). */
    public function close(string $publicId, int $actorId): void
    {
        $n = $this->db->run(
            "UPDATE amendment_requests SET status = 'closed', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE public_id = ? AND status = 'executed'",
            [$this->now(), $actorId, $publicId]
        )->rowCount();
        if ($n !== 1) {
            throw new BusinessException('amend.error.state', [], 409);
        }
    }

    // ---- WorkflowSubject ------------------------------------------------------------------------------

    public function type(): string
    {
        return 'amendment';
    }

    public function link(string $subjectId): string
    {
        return '/approvals';
    }

    public function onApproved(array $instance, int $approverId): void
    {
        ['request' => $req, 'changes' => $changes] = $this->get((string) $instance['subject_id']);
        if (in_array($req['status'], ['executed', 'closed'], true)) {
            return; // retried after success — nothing to do
        }
        $h = $this->handlers[(string) $req['target_type']] ?? throw new \RuntimeException('No amendment handler for ' . $req['target_type']);
        $this->db->run("UPDATE amendment_requests SET status = 'approved', updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status IN ('submitted','execution_failed')", [$this->now(), $req['id']]);
        $map = [];
        foreach ($changes as $c) {
            $map[(string) $c['field']] = (string) $c['new_value'];
        }
        try {
            $ref = $h->execute((string) $req['target_id'], (int) $req['target_revision'], (string) $req['action'], $map, (string) $req['idempotency_key'], $approverId);
        } catch (Throwable $e) {
            $msg = $e instanceof BusinessException ? $e->messageKey : mb_substr($e->getMessage(), 0, 250);
            $this->db->run(
                "UPDATE amendment_requests SET status = 'execution_failed', execution_attempts = execution_attempts + 1, execution_error = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?",
                [$msg, $this->now(), $req['id']]
            );
            $this->audit->record('amendment.execution_failed', 'failure', (string) $req['target_type'], (string) $req['target_id'], $req['legal_entity_id'] !== null ? (int) $req['legal_entity_id'] : null, $msg);
            throw $e;
        }
        $this->db->run(
            "UPDATE amendment_requests SET status = 'executed', execution_attempts = execution_attempts + 1, execution_error = NULL, execution_ref = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?",
            [$ref, $this->now(), $req['id']]
        );
        $this->audit->record('amendment.executed', 'success', (string) $req['target_type'], (string) $req['target_id'], $req['legal_entity_id'] !== null ? (int) $req['legal_entity_id'] : null, null, ['request' => $req['public_id'], 'ref' => $ref]);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        $status = ['rejected' => 'rejected', 'returned' => 'returned', 'cancelled' => 'withdrawn'][$outcome] ?? null;
        if ($status !== null) {
            $this->db->run("UPDATE amendment_requests SET status = ?, updated_at = ?, row_version = row_version + 1 WHERE public_id = ? AND status IN ('submitted','returned')", [$status, $this->now(), $instance['subject_id']]);
        }
    }

    public function onResubmitted(array $instance): void
    {
        $this->db->run("UPDATE amendment_requests SET status = 'submitted', updated_at = ?, row_version = row_version + 1 WHERE public_id = ? AND status = 'returned'", [$this->now(), $instance['subject_id']]);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
