<?php

declare(strict_types=1);

namespace Blue\Modules\Attendance;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "att_period_reopen" (route attendance.period_reopen): another HR person with attendance.period_approve decides (DEC-66). */
final class PeriodReopenSubject implements WorkflowSubject, DescribedSubject
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
        return 'att_period_reopen';
    }

    public function link(string $subjectId): string
    {
        return '/attendance/periods/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        PeriodService::of($this->app)->onReopenApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        if ($outcome !== 'returned') {
            PeriodService::of($this->app)->onReopenClosed((string) $instance['subject_id']);
        }
    }

    public function onResubmitted(array $instance): void
    {
        // a returned reopen request stays "reopen pending" until it is sent again or withdrawn
    }

    public function describe(array $instance): array
    {
        return PeriodService::of($this->app)->describe((string) $instance['subject_id'], true);
    }
}
