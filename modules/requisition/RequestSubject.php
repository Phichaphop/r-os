<?php

declare(strict_types=1);

namespace Blue\Modules\Requisition;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\ManagerRouted;
use Blue\Core\Workflow\WorkflowSubject;

/**
 * Workflow subject "rq_request" (routes requisition.request and requisition.special): the requester's line manager —
 * or, when there is none, a supply approver of the company — approves; over quota under the "special approval" rule,
 * a special approver decides after the manager.
 */
final class RequestSubject implements WorkflowSubject, DescribedSubject, ManagerRouted
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
        return 'rq_request';
    }

    public function link(string $subjectId): string
    {
        return '/requisition/r/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        RequisitionService::of($this->app)->onApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        RequisitionService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        RequisitionService::of($this->app)->onResubmitted((string) $instance['subject_id']);
    }

    public function managerUserIds(string $subjectId): array
    {
        return RequisitionService::of($this->app)->managerUserIds($subjectId);
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $db = $this->app->db();
        $r = $db->one(
            'SELECT r.*, s.code AS site_code, d.code AS dept_code, COALESCE(u.display_name, u.username) AS requester_name FROM rq_requests r JOIN sites s ON s.id = r.site_id
               JOIN users u ON u.id = r.requester_id LEFT JOIN departments d ON d.id = r.department_id WHERE r.public_id = ?',
            [(string) $instance['subject_id']]
        );
        if ($r === null) {
            return ['rows' => [], 'link' => null];
        }
        $items = [];
        foreach ($db->all('SELECT i.code, i.name, i.unit, l.qty, l.over_qty FROM rq_lines l JOIN rq_items i ON i.id = l.item_id WHERE l.request_id = ? ORDER BY i.code', [$r['id']]) as $l) {
            $items[] = $l['name'] . ' × ' . $l['qty'] . ' ' . $l['unit'] . ((int) $l['over_qty'] > 0 ? ' (' . $t->get('requisition.over_by', ['qty' => (string) $l['over_qty']]) . ')' : '');
        }
        return ['rows' => [
            [$t->get('requisition.col.ref'), (string) $r['ref']],
            [$t->get('requisition.col.requester'), (string) $r['requester_name'] . ($r['dept_code'] !== null ? ' · ' . $r['dept_code'] : '')],
            [$t->get('requisition.field.site'), (string) $r['site_code']],
            [$t->get('requisition.col.items'), implode(' · ', $items)],
            [$t->get('requisition.col.over_quota'), (int) $r['over_quota'] === 1 ? $t->get('requisition.over_yes') : $t->get('requisition.over_no')],
            [$t->get('requisition.field.note'), (string) ($r['note'] ?? '—')],
        ], 'link' => '/requisition/r/' . $r['public_id']];
    }
}
