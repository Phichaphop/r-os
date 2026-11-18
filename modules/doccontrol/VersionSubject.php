<?php

declare(strict_types=1);

namespace Blue\Modules\Doccontrol;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "dc_version" (route doccontrol.approve): another person with doccontrol.approve approves a controlled-document version. */
final class VersionSubject implements WorkflowSubject, DescribedSubject
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
        return 'dc_version';
    }

    public function link(string $subjectId): string
    {
        return '/policies/admin/d/' . $this->app->db()->scalar('SELECT d.public_id FROM dc_versions v JOIN dc_documents d ON d.id = v.document_id WHERE v.public_id = ?', [$subjectId]);
    }

    public function onApproved(array $instance, int $approverId): void
    {
        DocControlService::of($this->app)->onApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        DocControlService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        $this->app->db()->run("UPDATE dc_versions SET status = 'review' WHERE public_id = ? AND status = 'draft'", [(string) $instance['subject_id']]);
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $r = $this->app->db()->one('SELECT v.*, d.code, d.title, d.doc_type, d.public_id AS doc_pid FROM dc_versions v JOIN dc_documents d ON d.id = v.document_id WHERE v.public_id = ?', [(string) $instance['subject_id']]);
        if ($r === null) {
            return ['rows' => [], 'link' => null];
        }
        return ['rows' => [
            [$t->get('doccontrol.field.code'), $r['code'] . ' · ' . $t->get('doccontrol.type.' . $r['doc_type'])],
            [$t->get('doccontrol.field.title'), (string) $r['title']],
            [$t->get('doccontrol.field.version'), (string) $r['version_no']],
            [$t->get('doccontrol.field.effective_from'), (string) $r['effective_from']],
            [$t->get('doccontrol.field.requires_ack'), $t->get((int) $r['requires_ack'] === 1 ? 'doccontrol.yes' : 'doccontrol.no')],
            [$t->get('doccontrol.field.change_summary'), (string) $r['change_summary']],
            [$t->get('doccontrol.field.sha256'), substr((string) $r['sha256'], 0, 16) . '…'],
        ], 'link' => '/policies/admin/d/' . $r['doc_pid']];
    }
}
