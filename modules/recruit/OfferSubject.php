<?php

declare(strict_types=1);

namespace Blue\Modules\Recruit;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "rec_offer" (route recruit.offer): someone with recruit.approve (C&B) — never the recruiter — approves the offer. */
final class OfferSubject implements WorkflowSubject, DescribedSubject
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
        return 'rec_offer';
    }

    public function link(string $subjectId): string
    {
        return '/recruit/c/' . $this->app->db()->scalar('SELECT c.public_id FROM rec_offers o JOIN rec_candidates c ON c.id = o.candidate_id WHERE o.public_id = ?', [$subjectId]);
    }

    public function onApproved(array $instance, int $approverId): void
    {
        RecruitService::of($this->app)->onOfferDecided((string) $instance['subject_id'], 'approved');
    }

    public function onClosed(array $instance, string $outcome): void
    {
        RecruitService::of($this->app)->onOfferDecided((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        $this->app->db()->run("UPDATE rec_offers SET status = 'pending' WHERE public_id = ? AND status = 'returned'", [(string) $instance['subject_id']]);
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $o = $this->app->db()->one(
            'SELECT o.*, c.name, c.expected_salary, c.public_id AS cand_pid, r.salary_min, r.salary_max FROM rec_offers o JOIN rec_candidates c ON c.id = o.candidate_id
               JOIN rec_postings p ON p.id = c.posting_id JOIN rec_requisitions r ON r.id = p.requisition_id WHERE o.public_id = ?',
            [(string) $instance['subject_id']]
        );
        if ($o === null) {
            return ['rows' => [], 'link' => null];
        }
        $money = static fn (mixed $v): string => $v !== null ? number_format((float) $v, 2) : '—';
        return ['rows' => [
            [$t->get('recruit.field.candidate'), (string) $o['name']],
            [$t->get('recruit.field.position_title'), (string) $o['position_title'] . ' · ' . $t->get('employees.type.' . $o['employment_type'])],
            [$t->get('recruit.field.salary'), $money($o['salary'])],
            [$t->get('recruit.field.salary_range'), $money($o['salary_min']) . ' – ' . $money($o['salary_max'])],
            [$t->get('recruit.field.expected_salary'), $money($o['expected_salary'])],
            [$t->get('recruit.field.start_date'), (string) $o['start_date']],
            [$t->get('recruit.field.note'), (string) ($o['note'] ?? '—')],
        ], 'link' => '/recruit/c/' . $o['cand_pid']];
    }
}
