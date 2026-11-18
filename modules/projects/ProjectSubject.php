<?php

declare(strict_types=1);

namespace Blue\Modules\Projects;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\ManagerRouted;
use Blue\Core\Workflow\WorkflowSubject;

/**
 * Workflow subject "pj_project" (route projects.start): the approver named on the project charter decides whether the
 * project may start ("manager" step routed to that person; if they are no longer active → projects.approve of the company).
 */
final class ProjectSubject implements WorkflowSubject, DescribedSubject, ManagerRouted
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
        return 'pj_project';
    }

    public function link(string $subjectId): string
    {
        return '/projects/p/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        ProjectService::of($this->app)->onApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        ProjectService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        // start() already moved the project back to "pending approval"
    }

    public function managerUserIds(string $subjectId): array
    {
        return ProjectService::of($this->app)->approverIds($subjectId);
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $p = $this->app->db()->one('SELECT * FROM pj_projects WHERE public_id = ?', [(string) $instance['subject_id']]);
        if ($p === null) {
            return ['rows' => [], 'link' => null];
        }
        $svc = ProjectService::of($this->app);
        return ['rows' => [
            [$t->get('projects.col.ref'), (string) $p['ref']],
            [$t->get('projects.field.name'), (string) $p['name']],
            [$t->get('projects.field.goal'), mb_substr((string) $p['goal'], 0, 300)],
            [$t->get('projects.col.dates'), $p['starts_on'] . ' – ' . $p['ends_on']],
            [$t->get('projects.field.budget'), $p['budget'] === null ? '—' : number_format((float) $p['budget'], 2)],
            [$t->get('projects.col.lead'), $svc->name((int) $p['created_by'])],
        ], 'link' => '/projects/p/' . $p['public_id']];
    }
}
