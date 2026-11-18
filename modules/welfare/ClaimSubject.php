<?php

declare(strict_types=1);

namespace Blue\Modules\Welfare;

use Blue\Core\App;
use Blue\Core\Support\Money;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\WorkflowSubject;

/**
 * Workflow subject "wf_claim" (route welfare.claim, two steps with "distinct_steps"): step 1 a reviewer
 * (welfare.review) checks the documents, step 2 another person (welfare.approve) approves the amount.
 */
final class ClaimSubject implements WorkflowSubject, DescribedSubject
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
        return 'wf_claim';
    }

    public function link(string $subjectId): string
    {
        return '/welfare/claims/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        ClaimService::of($this->app)->onApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        ClaimService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        ClaimService::of($this->app)->onResubmitted((string) $instance['subject_id']);
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $c = $this->app->db()->one(
            'SELECT c.*, t.name_th AS type_name, t.code AS type_code, e.employee_code, e.name_th AS employee_name FROM wf_claims c JOIN wf_types t ON t.id = c.type_id JOIN employees e ON e.id = c.employee_id WHERE c.public_id = ?',
            [(string) $instance['subject_id']]
        );
        if ($c === null) {
            return ['rows' => [], 'link' => null];
        }
        $check = ClaimService::of($this->app)->check($c);
        $flags = array_map(static fn (string $k): string => $t->get('welfare.check.' . $k), array_merge($check['blocking'], $check['warnings']));
        return ['rows' => [
            [$t->get('welfare.field.claim_no'), (string) $c['claim_no']],
            [$t->get('welfare.field.employee'), $c['employee_code'] . ' · ' . $c['employee_name']],
            [$t->get('welfare.field.type'), $c['type_code'] . ' · ' . $c['type_name']],
            [$t->get('welfare.field.expense_date'), (string) $c['expense_date']],
            [$t->get('welfare.field.amount'), Money::format(Money::fromDb($c['amount_claimed']))],
            [$t->get('welfare.check.eligible'), Money::format($check['eligible'])],
            [$t->get('welfare.check.flags'), $flags === [] ? '—' : implode(' · ', $flags)],
        ], 'link' => '/welfare/claims/' . $c['public_id']];
    }
}
