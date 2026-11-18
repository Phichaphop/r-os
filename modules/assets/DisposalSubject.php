<?php

declare(strict_types=1);

namespace Blue\Modules\Assets;

use Blue\Core\App;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\WorkflowSubject;

/**
 * Workflow subject "as_disposal" (route assets.disposal): a holder of assets.dispose — never the requester — approves
 * disposing of an asset. Approval only records the decision; accounting entries are made outside the register.
 */
final class DisposalSubject implements WorkflowSubject, DescribedSubject
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
        return 'as_disposal';
    }

    public function link(string $subjectId): string
    {
        $d = AssetService::of($this->app)->disposal($subjectId);
        return $d === null ? '/assets' : '/assets/a/' . $d['asset_pid'];
    }

    public function onApproved(array $instance, int $approverId): void
    {
        AssetService::of($this->app)->onDisposalApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        AssetService::of($this->app)->onDisposalClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        // a returned disposal request is closed (onClosed) — the admin asks again
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $d = AssetService::of($this->app)->disposal((string) $instance['subject_id']);
        if ($d === null) {
            return ['rows' => [], 'link' => null];
        }
        return ['rows' => [
            [$t->get('assets.field.code'), $d['code'] . ' · ' . $d['name']],
            [$t->get('assets.field.type'), (string) $d['type_name']],
            [$t->get('assets.field.site'), (string) $d['site_code']],
            [$t->get('assets.field.acquired_on'), (string) ($d['acquired_on'] ?? '—')],
            [$t->get('assets.field.cost'), $d['cost'] === null ? '—' : number_format((float) $d['cost'], 2)],
            [$t->get('assets.field.method'), $t->get('assets.method.' . $d['method'])],
            [$t->get('assets.field.proceeds'), $d['proceeds'] === null ? '—' : number_format((float) $d['proceeds'], 2)],
            [$t->get('assets.field.disposal_reason'), (string) $d['reason']],
        ], 'link' => '/assets/a/' . $d['asset_pid']];
    }
}
