<?php

declare(strict_types=1);

namespace Blue\Modules\Overtime;

use Blue\Core\App;
use Blue\Core\I18n\DateFormat;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\ManagerRouted;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "ot_request" (route overtime.request): line manager, else overtime.approve of the branch/unit. */
final class OvertimeSubject implements WorkflowSubject, ManagerRouted, DescribedSubject
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
        return 'ot_request';
    }

    public function link(string $subjectId): string
    {
        return '/overtime/r/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        OvertimeService::of($this->app)->onApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        OvertimeService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        $this->app->db()->run("UPDATE ot_requests SET status = 'pending' WHERE public_id = ? AND status = 'returned'", [(string) $instance['subject_id']]);
    }

    public function managerUserIds(string $subjectId): array
    {
        return OvertimeService::of($this->app)->managerUserIds($subjectId);
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $r = $this->app->db()->one(
            'SELECT r.*, e.employee_code, e.name_th, d.code AS cost_code FROM ot_requests r JOIN employees e ON e.id = r.employee_id LEFT JOIN departments d ON d.id = r.cost_center_id WHERE r.public_id = ?',
            [(string) $instance['subject_id']]
        );
        if ($r === null) {
            return ['rows' => [], 'link' => null];
        }
        $loc = $t->locale();
        $rows = [
            [$t->get('overtime.field.employee'), $r['employee_code'] . ' · ' . $r['name_th']],
            [$t->get('overtime.field.when'), DateFormat::day((string) $r['work_date'], $loc) . ' ' . $r['start_local'] . '–' . $r['end_local'] . ($r['end_local'] <= $r['start_local'] ? ' ' . $t->get('overtime.next_day') : '')],
            [$t->get('overtime.field.minutes'), $t->get('overtime.hm', ['h' => (string) intdiv((int) $r['minutes_requested'], 60), 'm' => str_pad((string) ((int) $r['minutes_requested'] % 60), 2, '0', STR_PAD_LEFT)])],
            [$t->get('overtime.field.day_type'), $t->get('overtime.day_type.' . $r['day_type']) . ($r['shift_code'] !== null ? ' · ' . $t->get('overtime.shift', ['code' => (string) $r['shift_code']]) : '')],
            [$t->get('overtime.field.cost_center'), (string) ($r['cost_code'] ?? '—')],
            [$t->get('overtime.field.reason'), (string) $r['reason']],
        ];
        if ((int) $r['cap_warning'] === 1) {
            $rows[] = [$t->get('overtime.field.cap'), $t->get('overtime.cap_warning')];
        }
        return ['rows' => $rows, 'link' => '/overtime/r/' . $r['public_id']];
    }
}
