<?php

declare(strict_types=1);

namespace Blue\Modules\Attendance;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\ManagerRouted;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "att_correction" (route attendance.correction): the line manager decides; approval changes the day result. */
final class CorrectionSubject implements WorkflowSubject, ManagerRouted, DescribedSubject
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
        return 'att_correction';
    }

    public function link(string $subjectId): string
    {
        return '/attendance/c/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        CorrectionService::of($this->app)->onApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        CorrectionService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        CorrectionService::of($this->app)->onResubmitted((string) $instance['subject_id']);
    }

    public function managerUserIds(string $subjectId): array
    {
        return CorrectionService::of($this->app)->managerUserIds($subjectId);
    }

    public function describe(array $instance): array
    {
        return CorrectionService::of($this->app)->describe((string) $instance['subject_id']);
    }
}
