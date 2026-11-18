<?php

declare(strict_types=1);

namespace Blue\Modules\Payroll;

use Blue\Core\App;
use Blue\Core\Support\Money;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "pay_run" (route payroll.run): another person with payroll.approve approves a calculated payroll run. */
final class RunSubject implements WorkflowSubject, DescribedSubject
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
        return 'pay_run';
    }

    public function link(string $subjectId): string
    {
        return '/payroll/runs/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        PayrollService::of($this->app)->onRunApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        PayrollService::of($this->app)->onRunClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        // a returned run goes back to draft and must be calculated again — resubmitting the old result is not possible
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $r = $this->app->db()->one('SELECT r.*, l.code AS le_code FROM pay_runs r JOIN legal_entities l ON l.id = r.legal_entity_id WHERE r.public_id = ?', [(string) $instance['subject_id']]);
        if ($r === null) {
            return ['rows' => [], 'link' => null];
        }
        return ['rows' => [
            [$t->get('payroll.field.run'), $r['le_code'] . ' · ' . $r['label']],
            [$t->get('payroll.field.period'), $r['period_start'] . ' – ' . $r['period_end'] . ' · ' . $t->get('payroll.field.pay_date') . ' ' . $r['pay_date']],
            [$t->get('payroll.col.people'), (string) $r['employee_count']],
            [$t->get('payroll.col.gross'), Money::format(Money::fromDb($r['gross_total']))],
            [$t->get('payroll.col.deductions'), Money::format(Money::fromDb($r['deduction_total']))],
            [$t->get('payroll.col.net'), Money::format(Money::fromDb($r['net_total']))],
            [$t->get('payroll.col.employer'), Money::format(Money::fromDb($r['employer_total']))],
            [$t->get('payroll.col.warnings'), (string) $r['warning_count']],
        ], 'link' => '/payroll/runs/' . $r['public_id']];
    }
}
