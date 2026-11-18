<?php
/**
 * Welfare claims of a company for reviewers / approvers: waiting for review, waiting for approval, sent back,
 * approved not paid, paid, closed, all — "my turn" marks the claims with an open task for me.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var string $filter @var list<array<string, mixed>> $claims @var array<string, int> $counts
 * @var array<string, bool> $tabs @var string $here
 */
use Blue\Core\Support\Money;

$tones = ['draft' => 'neutral', 'submitted' => 'info', 'reviewing' => 'info', 'need_info' => 'warning', 'approved' => 'success', 'partially_approved' => 'success', 'paid' => 'success', 'rejected' => 'danger', 'cancelled' => 'neutral'];
$filters = ['open' => null, 'review' => 'review', 'approve' => 'approve', 'info' => 'info', 'approved' => 'approved', 'paid' => null, 'closed' => null, 'all' => null];
?>
<?= $ui('page-header', ['title' => $t('welfare.admin_title'), 'subtitle' => $t('welfare.admin_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<nav class="segmented-links" aria-label="<?= $e($t('welfare.filter')) ?>">
  <?php foreach ($filters as $f => $countKey): ?><a href="<?= $e($url('/welfare/admin?le=' . rawurlencode($lePid) . '&f=' . $f)) ?>"<?= $f === $filter ? ' aria-current="page"' : '' ?>><?= $e($t('welfare.f.' . $f)) ?><?= $countKey !== null && ($counts[$countKey] ?? 0) > 0 ? ' (' . (int) $counts[$countKey] . ')' : '' ?></a><?php endforeach; ?>
</nav>
<section class="card card--flush" aria-labelledby="wa-l">
  <div class="card__head"><h2 id="wa-l" class="card__title"><?= $e($t('welfare.f.' . $filter)) ?></h2></div>
  <?php if ($claims === []): ?>
    <div class="card__pad"><?= $ui('states', ['icon' => 'clipboard-check', 'title' => $t('welfare.queue_empty')]) ?></div>
  <?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="wa-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('welfare.field.claim_no')) ?></th><th scope="col"><?= $e($t('welfare.field.employee')) ?></th><th scope="col"><?= $e($t('welfare.field.type')) ?></th>
        <th scope="col"><?= $e($t('welfare.field.expense_date')) ?></th><th scope="col" class="num"><?= $e($t('welfare.field.amount')) ?></th><th scope="col"><?= $e($t('welfare.field.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($claims as $c): ?>
        <tr><th scope="row" data-label="<?= $e($t('welfare.field.claim_no')) ?>"><a href="<?= $e($url('/welfare/claims/' . $c['public_id'])) ?>"><?= $e((string) $c['claim_no']) ?></a>
          <?php if ($c['task'] !== null && $c['task']['mine']): ?> <?= $ui('badge', ['tone' => 'warning', 'label' => $t('welfare.my_turn')]) ?><?php endif; ?></th>
          <td data-label="<?= $e($t('welfare.field.employee')) ?>"><?= $e($c['employee_code'] . ' · ' . $c['employee_name']) ?></td>
          <td data-label="<?= $e($t('welfare.field.type')) ?>"><?= $e($c['type_code'] . ' · ' . $c['type_name']) ?><?php foreach ($c['flags'] as $fl): ?> <?= $ui('badge', ['tone' => 'warning', 'label' => $t('welfare.check.' . $fl)]) ?><?php endforeach; ?></td>
          <td data-label="<?= $e($t('welfare.field.expense_date')) ?>"><?= $e($date((string) $c['expense_date'], false)) ?></td>
          <td data-label="<?= $e($t('welfare.field.amount')) ?>" class="num"><?= $e(Money::format(Money::fromDb($c['amount_claimed']))) ?><?php if ($c['approved_amount'] !== null): ?><br><span class="text-sm text-muted"><?= $e($t('welfare.approved_short', ['v' => Money::format(Money::fromDb($c['approved_amount']))])) ?></span><?php endif; ?></td>
          <td data-label="<?= $e($t('welfare.field.status')) ?>"><?= $ui('badge', ['tone' => $tones[$c['status']] ?? 'neutral', 'label' => $t('welfare.status.' . $c['status'])]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
