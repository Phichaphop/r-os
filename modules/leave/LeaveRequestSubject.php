<?php

declare(strict_types=1);

namespace Blue\Modules\Leave;

use Blue\Core\App;
use Blue\Core\I18n\DateFormat;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\ManagerRouted;
use Blue\Core\Workflow\WorkflowSubject;
use Blue\Modules\Employees\EmployeeService;

/** Workflow subject "leave_request" (route leave.request): the line manager decides; approval posts the ledger. */
final class LeaveRequestSubject implements WorkflowSubject, ManagerRouted, DescribedSubject
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
        return 'leave_request';
    }

    public function link(string $subjectId): string
    {
        return '/leave/r/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        LeaveService::of($this->app)->onApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        LeaveService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        LeaveService::of($this->app)->onResubmitted((string) $instance['subject_id']);
    }

    public function managerUserIds(string $subjectId): array
    {
        return LeaveService::of($this->app)->managerUserIds($subjectId);
    }

    public function describe(array $instance): array
    {
        return self::rows($this->app, (string) $instance['subject_id']);
    }

    /** @return array{rows: list<array{0: string, 1: string}>, link: ?string} what the approver needs to decide (shared with the cancel route) */
    public static function rows(App $app, string $pid): array
    {
        $t = $app->translator();
        $db = $app->db();
        $r = $db->one(
            'SELECT r.*, t.name_th AS type_th, t.name_en AS type_en, t.is_statutory, e.employee_code, e.name_th, e.name_en, e.preferred_name, e.hire_date, e.status AS emp_status, e.id AS eid
               FROM lv_requests r JOIN lv_types t ON t.id = r.lv_type_id JOIN employees e ON e.id = r.employee_id WHERE r.public_id = ?',
            [$pid]
        );
        if ($r === null) {
            return ['rows' => [], 'link' => null];
        }
        $loc = $t->locale();
        $day = static fn (string $d): string => DateFormat::day($d, $loc);
        $type = $loc === 'en' && ($r['type_en'] ?? '') !== '' ? (string) $r['type_en'] : (string) $r['type_th'];
        $rows = [];
        $rows[] = [$t->get('leave.field.employee'), $r['employee_code'] . ' · ' . EmployeeService::of($app)->displayName($r)];
        $rows[] = [$t->get('leave.field.type'), $type . ((int) $r['is_statutory'] === 1 ? ' (' . $t->get('leave.statutory') . ')' : '')];
        $range = $r['start_date'] === $r['end_date'] ? $day((string) $r['start_date']) : $day((string) $r['start_date']) . ' – ' . $day((string) $r['end_date']);
        $rows[] = [$t->get('leave.field.dates'), $range . ($r['part'] !== 'full' ? ' (' . $t->get('leave.part.' . $r['part']) . ')' : '')];
        $rows[] = [$t->get('leave.field.units'), $t->get('leave.days', ['n' => LeaveService::units((float) $r['units'])])];
        $emp = EmployeeService::of($app)->byId((int) $r['eid']);
        $typeRow = $db->one('SELECT * FROM lv_types WHERE id = ?', [$r['lv_type_id']]);
        if ($emp !== null && $typeRow !== null) {
            $b = LeaveService::of($app)->balance($emp, $typeRow, (int) $r['leave_year']);
            if ($b['tracked']) {
                $after = in_array($r['status'], ['pending', 'returned'], true) ? $b['balance'] - (float) $r['units'] : $b['balance'];
                $rows[] = [$t->get('leave.field.balance_after'), $t->get('leave.balance_after', ['now' => LeaveService::units($b['balance']), 'after' => LeaveService::units($after), 'year' => (string) ($r['leave_year'] + ($loc === 'th' ? 543 : 0))])];
            } else {
                $rows[] = [$t->get('leave.field.balance_after'), $t->get('leave.not_tracked')];
            }
        }
        if (($r['reason'] ?? '') !== '') {
            $rows[] = [$t->get('leave.field.reason'), (string) $r['reason']];
        }
        $rows[] = [$t->get('leave.field.document'), $r['document_pid'] !== null ? $t->get('leave.document_attached') : $t->get('leave.document_none')];
        if (($r['cancel_reason'] ?? '') !== '' && $r['status'] === 'cancel_pending') {
            $rows[] = [$t->get('leave.field.cancel_reason'), (string) $r['cancel_reason']];
        }
        return ['rows' => $rows, 'link' => '/leave/r/' . $pid];
    }
}
