<?php

declare(strict_types=1);

namespace Blue\Modules\Announcements;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "announcement" (route announcements.publish): important / critical announcements need another person's approval. */
final class AnnouncementSubject implements WorkflowSubject, DescribedSubject
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
        return 'announcement';
    }

    public function link(string $subjectId): string
    {
        return '/announcements/admin/p/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        AnnouncementService::of($this->app)->onApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        AnnouncementService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        $this->app->db()->run("UPDATE ann_posts SET status = 'review' WHERE public_id = ? AND status = 'draft'", [(string) $instance['subject_id']]);
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $svc = AnnouncementService::of($this->app);
        $p = $this->app->db()->one('SELECT * FROM ann_posts WHERE public_id = ?', [(string) $instance['subject_id']]);
        if ($p === null) {
            return ['rows' => [], 'link' => null];
        }
        return ['rows' => [
            [$t->get('announcements.field.title'), (string) $p['title']],
            [$t->get('announcements.field.priority'), $t->get('announcements.priority.' . $p['priority'])],
            [$t->get('announcements.field.window'), str_replace('T', ' ', $svc->local((string) $p['starts_at'])) . ($p['ends_at'] !== null ? ' – ' . str_replace('T', ' ', $svc->local((string) $p['ends_at'])) : '')],
            [$t->get('employees.audience'), \Blue\Modules\Employees\Audience::describe($this->app, \Blue\Modules\Employees\Audience::decode((string) $p['audience_json']))],
            [$t->get('announcements.field.body'), mb_substr((string) $p['body'], 0, 600)],
        ], 'link' => '/announcements/admin/p/' . $p['public_id']];
    }
}
