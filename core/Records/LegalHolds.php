<?php

declare(strict_types=1);

namespace Blue\Core\Records;

use Blue\Core\Audit\AuditLogger;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;
use Blue\Core\Workflow\WorkflowEngine;
use Blue\Core\Workflow\WorkflowSubject;

/**
 * Legal hold (04 §6.17.7): placing a hold takes effect IMMEDIATELY (it only stops destruction); releasing it needs a
 * second person's approval through the central workflow. A hold covers one document, every document of a related
 * record (e.g. one employee case), or a whole record class (optionally one company).
 */
final class LegalHolds implements WorkflowSubject
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly WorkflowEngine $workflow,
        private readonly DocumentService $documents,
    ) {
    }

    /**
     * @param array{scope_type: string, document?: string, related_type?: string, related_id?: string, record_class?: string,
     *              legal_entity_id?: ?int, reference: string, reason: string, review_date?: string} $in
     */
    public function place(Authorizer $az, array $in): string
    {
        $scope = $az->scope('core.records.manage');
        if ($scope->isEmpty()) {
            throw HttpException::forbidden();
        }
        $errors = [];
        $docId = null;
        $le = $in['legal_entity_id'] ?? null;
        switch ($in['scope_type']) {
            case 'document':
                $doc = $this->documents->row((string) ($in['document'] ?? ''));
                if (!$this->documents->inScope($scope, $doc)) {
                    throw HttpException::notFound();
                }
                $docId = (int) $doc['id'];
                $le = $doc['legal_entity_id'] !== null ? (int) $doc['legal_entity_id'] : null;
                break;
            case 'related':
                if (!preg_match('/^[a-z0-9_.]{2,40}$/', (string) ($in['related_type'] ?? '')) || trim((string) ($in['related_id'] ?? '')) === '') {
                    $errors['related_id'] = 'records.error.related';
                }
                break;
            case 'record_class':
                if (!in_array($in['record_class'] ?? '', DocumentCatalog::RECORD_CLASSES, true)) {
                    $errors['record_class'] = 'records.error.class';
                }
                break;
            default:
                $errors['scope_type'] = 'records.error.scope';
        }
        if ($in['scope_type'] !== 'document' && !$scope->all && ($le === null || !in_array($le, $scope->legalEntityIds, true))) {
            throw HttpException::forbidden(); // company-wide holds need organization scope or the company in scope
        }
        if (trim($in['reference']) === '') {
            $errors['reference'] = 'records.error.reference';
        }
        if (trim($in['reason']) === '') {
            $errors['reason'] = 'iam.error.reason_required';
        }
        $review = null;
        if (($in['review_date'] ?? '') !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $in['review_date']);
            if ($d === false) {
                $errors['review_date'] = 'records.error.date';
            } else {
                $review = $d->format('Y-m-d');
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $pid = Ulid::generate($this->clock);
        $now = $this->now();
        $this->db->run(
            "INSERT INTO legal_holds (public_id, created_at, created_by, updated_at, updated_by, reference_code, reason, scope_type, document_id, related_type, related_id,
                record_class, legal_entity_id, status, review_date, placed_by, placed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)",
            [$pid, $now, $az->userId, $now, $az->userId, mb_substr(trim($in['reference']), 0, 60), mb_substr(trim($in['reason']), 0, 500), $in['scope_type'], $docId,
                $in['scope_type'] === 'related' ? $in['related_type'] : null, $in['scope_type'] === 'related' ? mb_substr(trim((string) $in['related_id']), 0, 40) : null,
                $in['scope_type'] === 'record_class' ? $in['record_class'] : null, $le, $review, $az->userId, $now]
        );
        // anything already requested for disposal and now covered stays requested; execution re-checks holds
        $this->audit->record('records.hold_placed', 'success', 'legal_hold', $pid, $le, $in['reason'], ['scope' => $in['scope_type'], 'reference' => $in['reference']]);
        return $pid;
    }

    public function requestRelease(Authorizer $az, string $pid, string $reason): string
    {
        $h = $this->get($pid);
        $this->assertScope($az, $h);
        if ($h['status'] !== 'active') {
            throw new BusinessException('records.error.hold_not_active', [], 409);
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $this->db->run("UPDATE legal_holds SET status = 'release_requested', release_reason = ?, updated_at = ?, updated_by = ? WHERE id = ?", [mb_substr($reason, 0, 500), $this->now(), $az->userId, $h['id']]);
        $inst = $this->workflow->start('core.legal_hold_release', $pid, $h['reference_code'], $h['legal_entity_id'] !== null ? (int) $h['legal_entity_id'] : null, $az->userId);
        $this->db->run('UPDATE legal_holds SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE id = ?', [$inst, $h['id']]);
        $this->audit->record('records.hold_release_requested', 'success', 'legal_hold', $pid, null, $reason);
        return $inst;
    }

    /** @return array<string, mixed> */
    public function get(string $pid): array
    {
        return $this->db->one('SELECT h.*, u.display_name AS placed_name FROM legal_holds h LEFT JOIN users u ON u.id = h.placed_by WHERE h.public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /** @return list<array<string, mixed>> holds the user may see */
    public function list(Authorizer $az, string $status = ''): array
    {
        $scope = $az->scope('core.records.manage');
        if ($scope->isEmpty()) {
            $scope = $az->scope('core.records.approve');
        }
        [$sql, $params] = $scope->sql('h.legal_entity_id');
        if (!$scope->all) {
            $sql = "({$sql})";
        }
        if (in_array($status, ['active', 'release_requested', 'released'], true)) {
            $sql .= ' AND h.status = ?';
            $params[] = $status;
        }
        return $this->db->all(
            "SELECT h.*, u.display_name AS placed_name, d.public_id AS document_public_id, d.title AS document_title FROM legal_holds h
               LEFT JOIN users u ON u.id = h.placed_by LEFT JOIN documents d ON d.id = h.document_id WHERE {$sql} ORDER BY h.id DESC LIMIT 300",
            $params
        );
    }

    /** @param array<string, mixed> $h */
    private function assertScope(Authorizer $az, array $h): void
    {
        $scope = $az->scope('core.records.manage');
        if (!$scope->all && ($h['legal_entity_id'] === null || !in_array((int) $h['legal_entity_id'], $scope->legalEntityIds, true))) {
            throw HttpException::notFound();
        }
    }

    // ---- WorkflowSubject ---------------------------------------------------------------------------

    public function type(): string
    {
        return 'legal_hold';
    }

    public function link(string $subjectId): string
    {
        return '/admin/records/holds#' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        $h = $this->get((string) $instance['subject_id']);
        if ($h['status'] === 'released') {
            return;
        }
        $this->db->run("UPDATE legal_holds SET status = 'released', released_by = ?, released_at = ?, updated_at = ? WHERE id = ?", [$approverId, $this->now(), $this->now(), $h['id']]);
        $this->audit->record('records.hold_released', 'success', 'legal_hold', (string) $h['public_id'], $h['legal_entity_id'] !== null ? (int) $h['legal_entity_id'] : null, (string) $h['release_reason'], [], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        // rejected / returned / cancelled → the hold stays in force
        $this->db->run("UPDATE legal_holds SET status = 'active', updated_at = ? WHERE public_id = ? AND status = 'release_requested'", [$this->now(), $instance['subject_id']]);
    }

    public function onResubmitted(array $instance): void
    {
        $this->db->run("UPDATE legal_holds SET status = 'release_requested', updated_at = ? WHERE public_id = ? AND status = 'active'", [$this->now(), $instance['subject_id']]);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
