<?php

declare(strict_types=1);

namespace Blue\Modules\Manpower;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "mp_plan" (route manpower.approve): another person with manpower.approve approves a manpower plan version. */
final class PlanSubject implements WorkflowSubject, DescribedSubject
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
        return 'mp_plan';
    }

    public function link(string $subjectId): string
    {
        return '/manpower/p/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        ManpowerService::of($this->app)->onApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        ManpowerService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        $this->app->db()->run("UPDATE mp_plans SET status = 'review' WHERE public_id = ? AND status = 'draft'", [(string) $instance['subject_id']]);
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $db = $this->app->db();
        $p = $db->one('SELECT p.*, l.code AS le_code FROM mp_plans p JOIN legal_entities l ON l.id = p.legal_entity_id WHERE p.public_id = ?', [(string) $instance['subject_id']]);
        if ($p === null) {
            return ['rows' => [], 'link' => null];
        }
        $rep = ManpowerService::of($this->app)->report((int) $p['id']);
        $base = $p['revision_of'] !== null ? $db->one('SELECT version_no, (SELECT COALESCE(SUM(headcount), 0) FROM mp_lines WHERE plan_id = mp_plans.id) AS heads FROM mp_plans WHERE id = ?', [$p['revision_of']]) : null;
        return ['rows' => [
            [$t->get('manpower.field.year'), $p['le_code'] . ' · ' . $p['fiscal_year'] . ' · ' . $t->get('manpower.version_n', ['n' => (string) $p['version_no']])],
            [$t->get('manpower.field.title'), (string) $p['title']],
            [$t->get('manpower.col.planned'), (string) $rep['totals']['planned'] . ($base !== null ? ' (' . $t->get('manpower.was', ['n' => (string) (float) $base['heads'], 'v' => (string) $base['version_no']]) . ')' : '')],
            [$t->get('manpower.col.actual'), (string) $rep['totals']['actual']],
            [$t->get('manpower.col.cost'), number_format((float) $rep['totals']['cost'], 2)],
            [$t->get('manpower.field.assumptions'), mb_substr((string) ($p['assumptions'] ?? ''), 0, 300)],
        ], 'link' => '/manpower/p/' . $p['public_id']];
    }
}
