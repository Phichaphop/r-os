<?php

declare(strict_types=1);

namespace Blue\Core\Locks;

use Blue\Core\Audit\AuditLogger;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;
use Blue\Core\Workflow\WorkflowEngine;
use Blue\Core\Workflow\WorkflowSubject;

/**
 * Period / schedule locks (6.27).
 *
 * Enforcement is a service call every write path makes (UI, API, import, background job) — assertWritable() —
 * not a hidden button. A hard lock refuses the write (HTTP 423) and records the attempt; a soft lock lets it
 * through with a warning. Locks at a wider scope cover narrower ones (organization → legal entity → site).
 * Reopening needs a request approved by someone else (central workflow); re-locking starts a new revision —
 * the earlier revision's snapshot reference stays in lock_events.
 */
final class LockService implements WorkflowSubject
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly WorkflowEngine $workflow,
    ) {
    }

    /**
     * Refuses a write into a locked period. Returns 'soft' when a soft lock applies (caller shows a warning).
     *
     * @param 'ui'|'api'|'import'|'job'|'integration' $channel
     */
    public function assertWritable(string $lockType, string $periodRef, ?int $legalEntityId, ?int $siteId = null, string $channel = 'ui', ?int $actorId = null): ?string
    {
        $orgId = (int) ($this->db->scalar('SELECT id FROM organizations ORDER BY id LIMIT 1') ?? 0);
        $conds = ["(scope_level = 'organization' AND scope_id = ?)"];
        $params = [$lockType, $periodRef, $orgId];
        if ($legalEntityId !== null) {
            $conds[] = "(scope_level = 'legal_entity' AND scope_id = ?)";
            $params[] = $legalEntityId;
        }
        if ($siteId !== null) {
            $conds[] = "(scope_level = 'site' AND scope_id = ?)";
            $params[] = $siteId;
        }
        $locks = $this->db->all(
            "SELECT id, public_id, mode, status, revision_no FROM locks WHERE lock_type = ? AND period_ref = ? AND status IN ('locked','reopen_requested','closed') AND (" . implode(' OR ', $conds) . ')',
            $params
        );
        $soft = null;
        foreach ($locks as $l) {
            if ($l['mode'] === 'hard') {
                $this->event((int) $l['id'], 'blocked_write', (int) $l['revision_no'], $actorId, $channel, null);
                $this->audit->record('lock.write_blocked', 'denied', 'lock', (string) $l['public_id'], $legalEntityId, $channel);
                throw new BusinessException('lock.error.locked', ['period' => $periodRef], 423);
            }
            $soft = 'soft';
        }
        return $soft;
    }

    /**
     * Date-range locks (0.6.3 — e.g. attendance periods, period_ref "YYYY-MM-DD..YYYY-MM-DD"): refuses a write that
     * touches any day of [from, to] inside a locked range of this type, for the company (or the whole organization).
     * Other modules (leave) use it without knowing who owns the lock.
     */
    public function assertRangeWritable(string $lockType, string $from, string $to, ?int $legalEntityId, ?int $siteId = null, string $channel = 'ui', ?int $actorId = null): ?string
    {
        $soft = null;
        foreach ($this->lockedRanges($lockType, $legalEntityId, $siteId) as [$a, $b, $ref]) {
            if ($from <= $b && $to >= $a) {
                $soft = $this->assertWritable($lockType, $ref, $legalEntityId, $siteId, $channel, $actorId) ?? $soft;
            }
        }
        return $soft;
    }

    /**
     * Locked date ranges of a type that apply to a company/site.
     *
     * @return list<array{0: string, 1: string, 2: string}> [from, to, period_ref]
     */
    public function lockedRanges(string $lockType, ?int $legalEntityId, ?int $siteId = null): array
    {
        $orgId = (int) ($this->db->scalar('SELECT id FROM organizations ORDER BY id LIMIT 1') ?? 0);
        $key = $lockType . '|' . $legalEntityId . '|' . $siteId;
        if (isset($this->ranges[$key])) {
            return $this->ranges[$key];
        }
        $conds = ["(scope_level = 'organization' AND scope_id = ?)"];
        $params = [$lockType, $orgId];
        if ($legalEntityId !== null) {
            $conds[] = "(scope_level = 'legal_entity' AND scope_id = ?)";
            $params[] = $legalEntityId;
        }
        if ($siteId !== null) {
            $conds[] = "(scope_level = 'site' AND scope_id = ?)";
            $params[] = $siteId;
        }
        $out = [];
        foreach ($this->db->all("SELECT period_ref FROM locks WHERE lock_type = ? AND status IN ('locked','reopen_requested','closed') AND (" . implode(' OR ', $conds) . ')', $params) as $r) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$/', (string) $r['period_ref'], $m) === 1) {
                $out[] = [$m[1], $m[2], (string) $r['period_ref']];
            }
        }
        return $this->ranges[$key] = $out;
    }

    /**
     * The owning domain releases its own lock after its own approval (e.g. a close sent back, or a reopen approved
     * in the module's workflow). The lock becomes writable; the history stays.
     */
    public function release(string $lockPublicId, int $actorId, string $reason): void
    {
        $row = $this->row($lockPublicId);
        if (!in_array($row['status'], ['locked', 'reopen_requested'], true)) {
            throw new BusinessException('lock.error.state', ['status' => (string) $row['status']], 409);
        }
        $this->db->run("UPDATE locks SET status = 'reopened', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $actorId, $row['id']]);
        $this->event((int) $row['id'], 'released', (int) $row['revision_no'], $actorId, 'ui', $reason);
        $this->audit->record('lock.released', 'success', 'lock', (string) $row['public_id'], $this->leOf($row), $reason);
        $this->ranges = [];
    }

    /** Records the snapshot a lock protects (set when the locked content is approved). */
    public function attachSnapshot(string $lockPublicId, string $snapshotRef, int $actorId): void
    {
        $row = $this->row($lockPublicId);
        $this->db->run('UPDATE locks SET snapshot_ref = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$snapshotRef, $this->now(), $actorId, $row['id']]);
        $this->event((int) $row['id'], 'snapshot', (int) $row['revision_no'], $actorId, 'ui', $snapshotRef);
    }

    /** @var array<string, list<array{0: string, 1: string, 2: string}>> */
    private array $ranges = [];

    /** Locks a period (creates the lock row on first use). Each lock starts a new revision. */
    public function lock(string $lockType, string $scopeLevel, int $scopeId, string $periodRef, string $mode, int $actorId, string $reason, ?string $snapshotRef = null): string
    {
        if (!in_array($mode, ['soft', 'hard'], true) || trim($reason) === '') {
            throw new BusinessException('lock.error.input', [], 422);
        }
        return $this->db->transactional(function () use ($lockType, $scopeLevel, $scopeId, $periodRef, $mode, $actorId, $reason, $snapshotRef): string {
            $row = $this->db->one('SELECT * FROM locks WHERE lock_type = ? AND scope_level = ? AND scope_id = ? AND period_ref = ? FOR UPDATE', [$lockType, $scopeLevel, $scopeId, $periodRef]);
            $now = $this->now();
            if ($row === null) {
                $pid = Ulid::generate($this->clock);
                $this->db->run(
                    'INSERT INTO locks (public_id, created_at, created_by, updated_at, updated_by, lock_type, scope_level, scope_id, period_ref, mode, status, revision_no)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'open\', 0)',
                    [$pid, $now, $actorId, $now, $actorId, $lockType, $scopeLevel, $scopeId, $periodRef, $mode]
                );
                $row = $this->db->one('SELECT * FROM locks WHERE public_id = ?', [$pid]) ?? [];
                $this->event((int) $row['id'], 'created', 0, $actorId, 'ui', null);
            }
            if (!in_array($row['status'], ['open', 'reopened'], true)) {
                throw new BusinessException('lock.error.state', ['status' => (string) $row['status']], 409);
            }
            $rev = (int) $row['revision_no'] + 1;
            $this->db->run(
                "UPDATE locks SET status = 'locked', mode = ?, revision_no = ?, locked_by = ?, locked_at = ?, reason = ?, snapshot_ref = ?, reopen_requested_by = NULL,
                    reopen_reason = NULL, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?",
                [$mode, $rev, $actorId, $now, $reason, $snapshotRef, $now, $actorId, $row['id']]
            );
            $this->event((int) $row['id'], 'locked', $rev, $actorId, 'ui', $reason);
            $this->ranges = [];
            $this->audit->record('lock.locked', 'success', 'lock', (string) $row['public_id'], null, $reason, ['type' => $lockType, 'period' => $periodRef, 'revision' => $rev, 'mode' => $mode]);
            return (string) $row['public_id'];
        });
    }

    /** Asks to reopen; an approver other than the requester decides through the central workflow. */
    public function requestReopen(string $lockPublicId, int $actorId, string $reason, ?int $legalEntityId): string
    {
        if (trim($reason) === '') {
            throw new BusinessException('iam.error.reason_required', [], 422);
        }
        $row = $this->row($lockPublicId);
        if ($row['status'] !== 'locked') {
            throw new BusinessException('lock.error.state', ['status' => (string) $row['status']], 409);
        }
        $this->db->run(
            "UPDATE locks SET status = 'reopen_requested', reopen_requested_by = ?, reopen_reason = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?",
            [$actorId, $reason, $this->now(), $actorId, $row['id']]
        );
        $this->event((int) $row['id'], 'reopen_requested', (int) $row['revision_no'], $actorId, 'ui', $reason);
        return $this->workflow->start('core.lock_reopen', $lockPublicId, $row['lock_type'] . ' ' . $row['period_ref'], $legalEntityId ?? $this->leOf($row), $actorId);
    }

    public function close(string $lockPublicId, int $actorId, string $reason): void
    {
        $row = $this->row($lockPublicId);
        if ($row['status'] !== 'locked') {
            throw new BusinessException('lock.error.state', ['status' => (string) $row['status']], 409);
        }
        $this->db->run("UPDATE locks SET status = 'closed', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $actorId, $row['id']]);
        $this->event((int) $row['id'], 'closed', (int) $row['revision_no'], $actorId, 'ui', $reason);
    }

    /** @return array{lock: array<string,mixed>, events: list<array<string,mixed>>} */
    public function history(string $lockPublicId): array
    {
        $row = $this->row($lockPublicId);
        return ['lock' => $row, 'events' => $this->db->all('SELECT * FROM lock_events WHERE lock_id = ? ORDER BY id', [$row['id']])];
    }

    // ---- WorkflowSubject (reopen requests) --------------------------------------------------------

    public function type(): string
    {
        return 'lock_reopen';
    }

    public function link(string $subjectId): string
    {
        return '/approvals';
    }

    public function onApproved(array $instance, int $approverId): void
    {
        $row = $this->row((string) $instance['subject_id']);
        if ($row['status'] === 'reopened') {
            return;
        }
        if ($row['status'] !== 'reopen_requested') {
            throw new BusinessException('lock.error.state', ['status' => (string) $row['status']], 409);
        }
        $this->db->run("UPDATE locks SET status = 'reopened', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $approverId, $row['id']]);
        $this->event((int) $row['id'], 'reopen_approved', (int) $row['revision_no'], $approverId, 'ui', null);
        $this->audit->record('lock.reopened', 'success', 'lock', (string) $row['public_id']);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        $row = $this->db->one('SELECT * FROM locks WHERE public_id = ?', [$instance['subject_id']]);
        if ($row !== null && $row['status'] === 'reopen_requested' && $outcome !== 'returned') {
            $this->db->run("UPDATE locks SET status = 'locked', updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $row['id']]);
            $this->event((int) $row['id'], 'reopen_rejected', (int) $row['revision_no'], null, 'ui', $outcome);
        }
    }

    public function onResubmitted(array $instance): void
    {
    }

    /** @return array<string, mixed> */
    private function row(string $publicId): array
    {
        return $this->db->one('SELECT * FROM locks WHERE public_id = ?', [$publicId]) ?? throw HttpException::notFound();
    }

    /** @param array<string, mixed> $row */
    private function leOf(array $row): ?int
    {
        return match ($row['scope_level']) {
            'legal_entity' => (int) $row['scope_id'],
            'site' => (int) $this->db->scalar('SELECT legal_entity_id FROM sites WHERE id = ?', [$row['scope_id']]),
            default => null,
        };
    }

    private function event(int $lockId, string $event, int $rev, ?int $actorId, string $channel, ?string $reason): void
    {
        $this->db->run(
            'INSERT INTO lock_events (lock_id, event, revision_no, actor_id, channel, reason, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$lockId, $event, $rev, $actorId, $channel, $reason !== null ? mb_substr($reason, 0, 255) : null, $this->now()]
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
