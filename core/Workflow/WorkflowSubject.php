<?php

declare(strict_types=1);

namespace Blue\Core\Workflow;

/**
 * A kind of record that goes through the central approval engine (6.9, 6.30.4).
 * The engine owns the DECISION; the subject owns the EXECUTION (posting, granting, applying a change).
 * onApproved() must be idempotent: the engine may call it again when an administrator retries a failed execution.
 */
interface WorkflowSubject
{
    /** subject_type stored on workflow_instances */
    public function type(): string;

    /** Link (relative path) to the subject's detail page, re-checked for permission when opened. */
    public function link(string $subjectId): string;

    /**
     * Carries out the approved change. Throw to report a failed execution (the decision stays "approved",
     * execution becomes "failed" and can be retried).
     *
     * @param array<string, mixed> $instance workflow_instances row
     */
    public function onApproved(array $instance, int $approverId): void;

    /**
     * Called when a request is rejected, returned for edit (it may be resubmitted later) or cancelled.
     *
     * @param array<string, mixed> $instance
     * @param 'rejected'|'returned'|'cancelled' $outcome
     */
    public function onClosed(array $instance, string $outcome): void;

    /** @param array<string, mixed> $instance a returned request was sent again */
    public function onResubmitted(array $instance): void;
}
