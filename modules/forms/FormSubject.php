<?php

declare(strict_types=1);

namespace Blue\Modules\Forms;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "form_version" (route forms.publish): a person with forms.approve who did not upload it publishes the version. */
final class FormSubject implements WorkflowSubject, DescribedSubject
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
        return 'form_version';
    }

    public function link(string $subjectId): string
    {
        $pid = $this->app->db()->scalar('SELECT f.public_id FROM frm_versions v JOIN frm_forms f ON f.id = v.form_id WHERE v.public_id = ?', [$subjectId]);
        return '/forms/admin/f/' . $pid;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        FormService::of($this->app)->onApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        FormService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        $this->app->db()->run("UPDATE frm_versions SET status = 'review' WHERE public_id = ? AND status = 'draft'", [(string) $instance['subject_id']]);
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $r = $this->app->db()->one('SELECT v.*, f.code, f.title, f.category, f.public_id AS form_pid FROM frm_versions v JOIN frm_forms f ON f.id = v.form_id WHERE v.public_id = ?', [(string) $instance['subject_id']]);
        if ($r === null) {
            return ['rows' => [], 'link' => null];
        }
        return ['rows' => [
            [$t->get('forms.field.code'), (string) $r['code']],
            [$t->get('forms.field.title'), (string) $r['title']],
            [$t->get('forms.field.category'), (string) $r['category']],
            [$t->get('forms.field.version'), (string) $r['version_no'] . ' · ' . $r['file_name']],
            [$t->get('forms.field.change_note'), (string) ($r['change_note'] ?? '—')],
            [$t->get('forms.field.sha256'), substr((string) $r['sha256'], 0, 16) . '…'],
        ], 'link' => '/forms/admin/f/' . $r['form_pid']];
    }
}
