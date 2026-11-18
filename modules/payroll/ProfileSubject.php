<?php

declare(strict_types=1);

namespace Blue\Modules\Payroll;

use Blue\Core\App;
use Blue\Core\Support\Money;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "pay_profile" (route payroll.profile): another person with payroll.approve approves a pay profile change. */
final class ProfileSubject implements WorkflowSubject, DescribedSubject
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
        return 'pay_profile';
    }

    public function link(string $subjectId): string
    {
        $emp = $this->app->db()->scalar('SELECT e.public_id FROM pay_profiles p JOIN employees e ON e.id = p.employee_id WHERE p.public_id = ?', [$subjectId]);
        return '/payroll/people/' . (string) $emp;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        PayrollService::of($this->app)->onProfileApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        PayrollService::of($this->app)->onProfileClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $db = $this->app->db();
        $p = $db->one('SELECT p.*, e.employee_code, e.name_th, e.public_id AS employee_pid FROM pay_profiles p JOIN employees e ON e.id = p.employee_id WHERE p.public_id = ?', [(string) $instance['subject_id']]);
        if ($p === null) {
            return ['rows' => [], 'link' => null];
        }
        $prev = $db->one(
            "SELECT * FROM pay_profiles WHERE employee_id = ? AND legal_entity_id = ? AND status = 'approved' AND effective_from <= ? ORDER BY effective_from DESC, id DESC LIMIT 1",
            [$p['employee_id'], $p['legal_entity_id'], $p['effective_from']]
        );
        $amount = static fn (array $x): string => $t->get('payroll.basis.' . $x['pay_basis']) . ' ' . Money::format(Money::fromDb($x['base_amount']));
        $items = implode(', ', array_map(static fn (array $i): string => $i['code'] . ' ' . Money::format(Money::fromDb($i['amount'])), (array) json_decode((string) ($p['items_json'] ?? '[]'), true))) ?: '—';
        $rows = [
            [$t->get('payroll.field.employee'), $p['employee_code'] . ' · ' . $p['name_th']],
            [$t->get('payroll.field.effective_from'), (string) $p['effective_from']],
            [$t->get('payroll.field.pay'), $amount($p) . ($prev !== null ? ' (' . $t->get('payroll.was', ['v' => $amount($prev)]) . ')' : '')],
            [$t->get('payroll.field.items'), $items],
            [$t->get('payroll.field.bank'), $p['account_last4'] !== null ? $p['bank_code'] . ' · ••••' . $p['account_last4'] . ($prev !== null && $prev['account_enc'] !== $p['account_enc'] ? ' · ' . $t->get('payroll.bank_changed') : '') : '—'],
            [$t->get('payroll.field.reason'), (string) $p['reason']],
        ];
        return ['rows' => $rows, 'link' => '/payroll/people/' . $p['employee_pid']];
    }
}
