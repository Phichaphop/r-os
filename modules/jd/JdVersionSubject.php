<?php

declare(strict_types=1);

namespace Blue\Modules\Jd;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "jd_version" (route jd.approve): another person with jd.approve approves a job description version. */
final class JdVersionSubject implements WorkflowSubject, DescribedSubject
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
        return 'jd_version';
    }

    public function link(string $subjectId): string
    {
        return '/jd/admin/p/' . $this->app->db()->scalar('SELECT p.public_id FROM jd_versions v JOIN jd_profiles p ON p.id = v.profile_id WHERE v.public_id = ?', [$subjectId]);
    }

    public function onApproved(array $instance, int $approverId): void
    {
        JdService::of($this->app)->onApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        JdService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        $this->app->db()->run("UPDATE jd_versions SET status = 'review' WHERE public_id = ? AND status = 'draft'", [(string) $instance['subject_id']]);
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $r = $this->app->db()->one('SELECT v.*, p.code, p.position_title, p.public_id AS profile_pid FROM jd_versions v JOIN jd_profiles p ON p.id = v.profile_id WHERE v.public_id = ?', [(string) $instance['subject_id']]);
        if ($r === null) {
            return ['rows' => [], 'link' => null];
        }
        $c = (array) json_decode((string) $r['content_json'], true);
        return ['rows' => [
            [$t->get('jd.field.code'), (string) $r['code']],
            [$t->get('jd.field.position_title'), (string) $r['position_title']],
            [$t->get('jd.field.version'), (string) $r['version_no']],
            [$t->get('jd.field.effective_from'), (string) $r['effective_from']],
            [$t->get('jd.field.change_summary'), (string) $r['change_summary']],
            [$t->get('jd.section.summary'), mb_substr((string) ($c['th']['summary'] ?? ''), 0, 300)],
        ], 'link' => '/jd/admin/p/' . $r['profile_pid']];
    }
}
