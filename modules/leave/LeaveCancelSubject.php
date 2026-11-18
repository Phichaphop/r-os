<?php

declare(strict_types=1);

namespace Blue\Modules\Leave;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\ManagerRouted;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "leave_cancel" (route leave.cancel): cancelling approved leave that has not started gives the days back. */
final class LeaveCancelSubject implements WorkflowSubject, ManagerRouted, DescribedSubject
{
    private function __construct(private readonly App $app)
    {
    }

    public static function create(App $app): self
    {
        return new self($app);
    }

    public function type(): string
    {
        return 'leave_cancel';
    }

    public function link(string $subjectId): string
    {
        return '/leave/r/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        LeaveService::of($this->app)->onCancelApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        LeaveService::of($this->app)->onCancelClosed((string) $instance['subject_id']);
    }

    public function onResubmitted(array $instance): void
    {
        LeaveService::of($this->app)->onCancelResubmitted((string) $instance['subject_id']);
    }

    public function managerUserIds(string $subjectId): array
    {
        return LeaveService::of($this->app)->managerUserIds($subjectId);
    }

    public function describe(array $instance): array
    {
        return LeaveRequestSubject::rows($this->app, (string) $instance['subject_id']);
    }
}
