<?php

declare(strict_types=1);

namespace Blue\Modules\Eid;

use Blue\Core\App;
use Blue\Core\Support\Money;
use Blue\Core\Workflow\DescribedSubject;
use Blue\Core\Workflow\WorkflowSubject;

/** Workflow subject "wallet_topup" (route eid.wallet_topup): a finance checker / wallet admin who is not the maker approves. */
final class TopupSubject implements WorkflowSubject, DescribedSubject
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
        return 'wallet_topup';
    }

    public function link(string $subjectId): string
    {
        return '/eid/wallet/topup/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        WalletService::of($this->app)->onApproved((string) $instance['subject_id'], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        WalletService::of($this->app)->onClosed((string) $instance['subject_id'], $outcome);
    }

    public function onResubmitted(array $instance): void
    {
        $this->app->db()->run("UPDATE eid_topups SET status = 'pending' WHERE public_id = ? AND status = 'returned'", [(string) $instance['subject_id']]);
    }

    public function describe(array $instance): array
    {
        $t = $this->app->translator();
        $r = $this->app->db()->one('SELECT t.*, l.code AS le_code FROM eid_topups t JOIN legal_entities l ON l.id = t.legal_entity_id WHERE t.public_id = ?', [(string) $instance['subject_id']]);
        if ($r === null) {
            return ['rows' => [], 'link' => null];
        }
        return ['rows' => [
            [$t->get('eid.field.company'), (string) $r['le_code']],
            [$t->get('eid.field.topup'), $t->get('eid.topup_title', ['n' => (string) $r['topup_no']])],
            [$t->get('eid.field.lines'), (string) $r['line_count']],
            [$t->get('eid.field.total'), Money::format(Money::fromDb($r['total_amount'])) . ' ' . $r['currency_code']],
            [$t->get('eid.field.reason'), (string) $r['reason']],
        ], 'link' => '/eid/wallet/topup/' . $r['public_id']];
    }
}
