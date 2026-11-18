<?php

declare(strict_types=1);

namespace Blue\Modules\Filing;

use Blue\Core\App;
use Blue\Core\Support\Money;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "fl_revision" (route filing.review): another person with filing.approve reviews a filing revision before it is exported. */
final class RevisionSubject implements WorkflowSubject, DescribedSubject
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
        return 'fl_revision';
    }

    public function link(string $subjectId): string
    {
        return '/filing/r/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        FilingService::of($this->app)->onApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        FilingService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        // a returned revision is not sent again — the maker generates a new revision
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $r = $this->app->db()->one(
            'SELECT r.*, f.kind, f.period, l.code AS le_code FROM fl_revisions r JOIN fl_filings f ON f.id = r.filing_id JOIN legal_entities l ON l.id = f.legal_entity_id WHERE r.public_id = ?',
            [(string) $instance['subject_id']]
        );
        if ($r === null) {
            return ['rows' => [], 'link' => null];
        }
        $wht = $r['kind'] === 'wht_monthly';
        $rows = [
            [$t->get('filing.field.company'), (string) $r['le_code']],
            [$t->get('filing.field.kind'), $t->get('filing.kind.' . $r['kind'])],
            [$t->get('filing.field.period'), (string) $r['period'] . ' · #' . $r['revision_no'] . ' ' . $t->get('filing.purpose.' . $r['purpose'])],
            [$t->get('filing.field.people'), (string) $r['people']],
            [$t->get($wht ? 'filing.col.income' : 'filing.col.wage_used'), Money::format(Money::fromDb($r['total_a']))],
            [$t->get($wht ? 'filing.col.wht' : 'filing.col.ee'), Money::format(Money::fromDb($r['total_b']))],
        ];
        if (!$wht) {
            $rows[] = [$t->get('filing.col.er'), Money::format(Money::fromDb($r['total_c']))];
        }
        $rows[] = [$t->get('filing.field.warnings'), (string) $r['warning_count']];
        return ['rows' => $rows, 'link' => '/filing/r/' . $r['public_id']];
    }
}
