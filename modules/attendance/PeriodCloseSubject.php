<?php

declare(strict_types=1);

namespace Blue\Modules\Attendance;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "att_period_close" (route attendance.period_close): another HR person with attendance.period_approve decides (DEC-66). */
final class PeriodCloseSubject implements WorkflowSubject, DescribedSubject
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
        return 'att_period_close';
    }

    public function link(string $subjectId): string
    {
        return '/attendance/periods/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        PeriodService::of($this->app)->onCloseApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        PeriodService::of($this->app)->onCloseClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        PeriodService::of($this->app)->onCloseResubmitted((string) $instance['subject_id']);
    }

    public function describe(array $instance): array
    {
        return PeriodService::of($this->app)->describe((string) $instance['subject_id'], false);
    }
}
