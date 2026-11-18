<?php

declare(strict_types=1);

namespace Blue\Modules\Contractors;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\WorkflowSubject;

/**
 * Workflow subject "ct_permit" (route contractors.permit): a holder of contractors.approve (the safety officer — never
 * the requester) approves a contractor work permit after the document and overlap checks.
 */
final class PermitSubject implements WorkflowSubject, DescribedSubject
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
        return 'ct_permit';
    }

    public function link(string $subjectId): string
    {
        return '/contractors/p/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        ContractorService::of($this->app)->onApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        ContractorService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        // a returned permit goes back to draft; sending it again starts a new approval
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $db = $this->app->db();
        $p = $db->one(
            'SELECT p.*, c.name AS company_name, s.code AS site_code, COALESCE(u.display_name, u.username) AS supervisor_name FROM ct_permits p JOIN ct_companies c ON c.id = p.company_id
               JOIN sites s ON s.id = p.site_id JOIN users u ON u.id = p.supervisor_id WHERE p.public_id = ?',
            [(string) $instance['subject_id']]
        );
        if ($p === null) {
            return ['rows' => [], 'link' => null];
        }
        $workers = array_column($db->all('SELECT w.name FROM ct_permit_workers pw JOIN ct_workers w ON w.id = pw.worker_id WHERE pw.permit_id = ? ORDER BY w.name', [$p['id']]), 'name');
        $hazards = $p['hazards'] === null ? '—' : implode(', ', array_map(static fn (string $h): string => $t->get('contractors.hazard.' . $h), explode(',', (string) $p['hazards'])));
        return ['rows' => [
            [$t->get('contractors.col.ref'), (string) $p['ref']],
            [$t->get('contractors.field.company'), (string) $p['company_name']],
            [$t->get('contractors.field.work'), (string) $p['work']],
            [$t->get('contractors.field.area'), $p['site_code'] . ' · ' . $p['area']],
            [$t->get('contractors.col.when'), substr((string) $p['starts_at'], 0, 16) . ' – ' . substr((string) $p['ends_at'], 0, 16)],
            [$t->get('contractors.field.hazards'), $hazards],
            [$t->get('contractors.field.controls'), (string) ($p['controls'] ?? '—')],
            [$t->get('contractors.field.tools'), (string) ($p['tools'] ?? '—')],
            [$t->get('contractors.field.supervisor'), (string) $p['supervisor_name']],
            [$t->get('contractors.field.workers'), implode(', ', $workers)],
        ], 'link' => '/contractors/p/' . $p['public_id']];
    }
}
