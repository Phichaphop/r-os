<?php

declare(strict_types=1);

namespace Blue\Modules\Training;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\ManagerRouted;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "trn_enrollment" (route training.enroll): the employee's line manager approves enrolling in a course that needs it. */
final class EnrollmentSubject implements WorkflowSubject, DescribedSubject, ManagerRouted
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
        return 'trn_enrollment';
    }

    public function link(string $subjectId): string
    {
        return '/training/s/' . $this->app->db()->scalar('SELECT s.public_id FROM trn_enrollments e JOIN trn_sessions s ON s.id = e.session_id WHERE e.public_id = ?', [$subjectId]);
    }

    public function onApproved(array $instance, int $approverId): void
    {
        TrainingService::of($this->app)->onApproved((string) $instance['subject_id']);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        TrainingService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
    }

    public function managerUserIds(string $subjectId): array
    {
        return TrainingService::of($this->app)->managerUserIds($subjectId);
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $r = $this->app->db()->one(
            'SELECT e.*, p.employee_code, p.name_th, s.start_date, s.end_date, s.start_time, s.end_time, s.location, s.cost, s.public_id AS session_pid, c.title, c.hours
               FROM trn_enrollments e JOIN employees p ON p.id = e.employee_id JOIN trn_sessions s ON s.id = e.session_id JOIN trn_courses c ON c.id = s.course_id WHERE e.public_id = ?',
            [(string) $instance['subject_id']]
        );
        if ($r === null) {
            return ['rows' => [], 'link' => null];
        }
        return ['rows' => [
            [$t->get('training.col.employee'), $r['employee_code'] . ' ' . $r['name_th']],
            [$t->get('training.field.course'), (string) $r['title'] . ' (' . rtrim(rtrim((string) $r['hours'], '0'), '.') . ' ' . $t->get('training.hours_unit') . ')'],
            [$t->get('training.col.when'), $r['start_date'] . ($r['end_date'] !== $r['start_date'] ? ' – ' . $r['end_date'] : '') . ' · ' . $r['start_time'] . '–' . $r['end_time']],
            [$t->get('training.field.location'), (string) $r['location']],
            [$t->get('training.field.cost'), $r['cost'] !== null ? number_format((float) $r['cost'], 2) : '—'],
        ], 'link' => '/training/s/' . $r['session_pid']];
    }
}
