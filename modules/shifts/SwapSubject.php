<?php

declare(strict_types=1);

namespace Blue\Modules\Shifts;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\ManagerRouted;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "sh_swap" (route shifts.swap): the line manager decides, else shifts.approve of the branch/unit. */
final class SwapSubject implements WorkflowSubject, ManagerRouted, DescribedSubject
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
        return 'sh_swap';
    }

    public function link(string $subjectId): string
    {
        return '/shifts';
    }

    public function onApproved(array $instance, int $approverId): void
    {
        SwapService::of($this->app)->onApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        SwapService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
    }

    public function managerUserIds(string $subjectId): array
    {
        return SwapService::of($this->app)->managerUserIds($subjectId);
    }

    public function describe(array $instance): array
    {
        return SwapService::of($this->app)->describe((string) $instance['subject_id']);
    }
}
