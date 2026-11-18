<?php

declare(strict_types=1);

namespace Blue\Modules\Employees;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "emp_change" (route employees.change): another HR person with employees.approve decides (DEC-67). */
final class ChangeSubject implements WorkflowSubject, DescribedSubject
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
        return 'emp_change';
    }

    public function link(string $subjectId): string
    {
        $emp = $this->app->db()->scalar('SELECT e.public_id FROM emp_change_requests r JOIN employees e ON e.id = r.employee_id WHERE r.public_id = ?', [$subjectId]);
        return '/employees/' . $emp . '?tab=changes';
    }

    public function onApproved(array $instance, int $approverId): void
    {
        ChangeService::of($this->app)->onApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        ChangeService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        ChangeService::of($this->app)->onResubmitted((string) $instance['subject_id']);
    }

    public function describe(array $instance): array
    {
        return ChangeService::of($this->app)->describe((string) $instance['subject_id']);
    }
}
