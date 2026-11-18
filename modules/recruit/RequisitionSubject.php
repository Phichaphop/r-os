<?php

declare(strict_types=1);

namespace Blue\Modules\Recruit;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\ManagerRouted;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "rec_requisition" (route recruit.requisition): the requester's line manager, then recruit.approve. */
final class RequisitionSubject implements WorkflowSubject, DescribedSubject, ManagerRouted
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
        return 'rec_requisition';
    }

    public function link(string $subjectId): string
    {
        return '/recruit/r/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        RecruitService::of($this->app)->onRequisitionApproved((string) $instance['subject_id']);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        RecruitService::of($this->app)->onRequisitionClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        $this->app->db()->run("UPDATE rec_requisitions SET status = 'pending' WHERE public_id = ? AND status = 'returned'", [(string) $instance['subject_id']]);
    }

    public function managerUserIds(string $subjectId): array
    {
        return RecruitService::of($this->app)->managerUserIds($subjectId);
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $r = $this->app->db()->one(
            'SELECT r.*, l.code AS le_code, d.code AS dept_code FROM rec_requisitions r JOIN legal_entities l ON l.id = r.legal_entity_id LEFT JOIN departments d ON d.id = r.department_id WHERE r.public_id = ?',
            [(string) $instance['subject_id']]
        );
        if ($r === null) {
            return ['rows' => [], 'link' => null];
        }
        $money = static fn (mixed $v): string => $v !== null ? number_format((float) $v, 2) : '—';
        return ['rows' => [
            [$t->get('recruit.field.position_title'), (string) $r['position_title'] . ' × ' . (int) $r['headcount']],
            [$t->get('recruit.field.department'), $r['le_code'] . ($r['dept_code'] !== null ? ' · ' . $r['dept_code'] : '')],
            [$t->get('recruit.field.reason'), $t->get('recruit.reason.' . $r['reason']) . ($r['replaces'] !== null ? ' — ' . $r['replaces'] : '')],
            [$t->get('recruit.field.employment_type'), $t->get('employees.type.' . $r['employment_type'])],
            [$t->get('recruit.field.salary_range'), $money($r['salary_min']) . ' – ' . $money($r['salary_max'])],
            [$t->get('recruit.field.needed_by'), (string) ($r['needed_by'] ?? '—')],
            [$t->get('recruit.field.justification'), (string) $r['justification']],
            ...(($b = RecruitService::of($this->app)->budgetNote((int) $r['id'])) !== null ? [[$t->get('recruit.field.budget'), $b]] : []),
        ], 'link' => '/recruit/r/' . $r['public_id']];
    }
}
