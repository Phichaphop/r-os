<?php

declare(strict_types=1);

namespace Blue\Modules\Vehicles;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\ManagerRouted;
use Blue\Core\Workflow\WorkflowSubject;

/**
 * Workflow subject "vh_booking" (route vehicles.request): the requester's line manager — or, when there is none, a
 * vehicle approver of the company — approves a vehicle request before the workplace admin assigns a vehicle.
 */
final class BookingSubject implements WorkflowSubject, DescribedSubject, ManagerRouted
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
        return 'vh_booking';
    }

    public function link(string $subjectId): string
    {
        return '/vehicles/b/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        VehicleService::of($this->app)->onApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        VehicleService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        VehicleService::of($this->app)->onResubmitted((string) $instance['subject_id']);
    }

    public function managerUserIds(string $subjectId): array
    {
        return VehicleService::of($this->app)->managerUserIds($subjectId);
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $b = $this->app->db()->one(
            'SELECT b.*, s.code AS site_code, COALESCE(u.display_name, u.username) AS requester_name FROM vh_bookings b JOIN sites s ON s.id = b.site_id JOIN users u ON u.id = b.requester_id WHERE b.public_id = ?',
            [(string) $instance['subject_id']]
        );
        if ($b === null) {
            return ['rows' => [], 'link' => null];
        }
        $km = $b['est_km'] !== null ? $t->get('vehicles.km_value', ['km' => (string) $b['est_km']]) . ' (' . $t->get('vehicles.source.' . $b['est_source']) . ')' : '—';
        return ['rows' => [
            [$t->get('vehicles.col.ref'), (string) $b['ref']],
            [$t->get('vehicles.col.requester'), (string) $b['requester_name']],
            [$t->get('vehicles.col.when'), substr((string) $b['starts_at'], 0, 16) . ' – ' . substr((string) $b['ends_at'], 0, 16)],
            [$t->get('vehicles.field.purpose'), (string) $b['purpose']],
            [$t->get('vehicles.col.route'), $b['from_name'] . ' → ' . $b['to_name'] . ((int) $b['round_trip'] === 1 ? ' (' . $t->get('vehicles.round_trip_short') . ')' : '')],
            [$t->get('vehicles.field.passenger_count'), (string) $b['passenger_count']],
            [$t->get('vehicles.col.estimate'), $km],
            [$t->get('vehicles.field.site'), (string) $b['site_code']],
        ], 'link' => '/vehicles/b/' . $b['public_id']];
    }
}
