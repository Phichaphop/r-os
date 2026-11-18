<?php
/**
 * My welfare: the limit left of each welfare type this year (approved and waiting amounts) and my claims.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed>|null $me @var int $year @var list<array<string, mixed>> $balances @var list<array<string, mixed>> $claims @var bool $staff @var string $today
 */
use Blue\Core\Support\Money;

$m = static fn (?int $u): string => $u === null ? '—' : Money::format($u);
$tones = ['draft' => 'neutral', 'submitted' => 'info', 'reviewing' => 'info', 'need_info' => 'warning', 'approved' => 'success', 'partially_approved' => 'success', 'paid' => 'success', 'rejected' => 'danger', 'cancelled' => 'neutral'];
$now = (int) substr($today, 0, 4);
?>
<?= $ui('page-header', ['title' => $t('welfare.title'), 'subtitle' => $t('welfare.subtitle'),
    'actions' => ($me !== null && $balances !== [] ? $ui('button', ['label' => $t('welfare.new'), 'href' => $url('/welfare/new'), 'variant' => 'primary', 'icon' => 'plus']) : '')
        . ($staff ? ' ' . $ui('button', ['label' => $t('welfare.nav_admin'), 'href' => $url('/welfare/admin'), 'variant' => 'secondary', 'icon' => 'clipboard-check']) : '')]) ?>
<?php if ($me === null): ?>
  <?= $ui('states', ['icon' => 'gift', 'title' => $t('welfare.error.no_employee')]) ?>
<?php else: ?>
<nav class="segmented-links" aria-label="<?= $e($t('welfare.year')) ?>">
  <?php foreach ([$now - 1, $now] as $y): ?><a href="<?= $e($url('/welfare?year=' . $y)) ?>"<?= $y === $year ? ' aria-current="page"' : '' ?>><?= (int) $y ?></a><?php endforeach; ?>
</nav>
<section class="stack-sm" aria-labelledby="wf-b">
  <h2 id="wf-b" class="section-title"><?= $e($t('welfare.balances', ['year' => (string) $year])) ?></h2>
  <?php if ($balances === []): ?><p class="text-sm text-muted"><?= $e($t('welfare.no_types')) ?></p><?php endif; ?>
  <div class="grid grid--3">
  <?php foreach ($balances as $b): $ty = $b['type']; ?>
    <article class="card stat-card stack-sm">
      <h3 class="stat__label"><?= $e($ty['code'] . ' · ' . $ty['name_th']) ?></h3>
      <?php if ($b['limit'] !== null): ?>
        <p class="stat__value"><?= $e($m($b['remaining'])) ?></p>
        <p class="stat__meta"><?= $e($t('welfare.remaining_of', ['limit' => $m($b['limit'])])) ?></p>
        <meter class="meter-bar" min="0" max="<?= $e(Money::format((int) $b['limit'], 2, false)) ?>" value="<?= $e(Money::format((int) ($b['used'] + $b['pending']), 2, false)) ?>" aria-label="<?= $e($t('welfare.used_meter')) ?>"></meter>
      <?php else: ?>
        <p><strong><?= $e($t('welfare.per_claim', ['v' => $m($ty['limit_event'] !== null ? Money::fromDb($ty['limit_event']) : null)])) ?></strong></p>
      <?php endif; ?>
      <dl class="facts facts--compact">
        <div><dt><?= $e($t('welfare.used')) ?></dt><dd><?= $e($m($b['used'])) ?></dd></div>
        <div><dt><?= $e($t('welfare.pending')) ?></dt><dd><?= $e($m($b['pending'])) ?></dd></div>
        <?php if ($ty['max_claims'] !== null): ?><div><dt><?= $e($t('welfare.claims_count')) ?></dt><dd><?= $e($t('welfare.n_of_max', ['n' => (string) $b['count'], 'max' => (string) $ty['max_claims']])) ?></dd></div><?php endif; ?>
      </dl>
      <?php if ($year === $now): ?><div class="row"><?= $ui('button', ['label' => $t('welfare.claim_this'), 'href' => $url('/welfare/new?type=' . rawurlencode((string) $ty['code'])), 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'plus']) ?></div><?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>
  <p class="text-sm text-muted"><?= $e($t('welfare.balance_help')) ?></p>
</section>
<section class="card card--flush" aria-labelledby="wf-c">
  <div class="card__head"><h2 id="wf-c" class="card__title"><?= $e($t('welfare.my_claims')) ?></h2></div>
  <?php if ($claims === []): ?>
    <div class="card__pad"><?= $ui('states', ['icon' => 'receipt', 'title' => $t('welfare.no_claims')]) ?></div>
  <?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="wf-c" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('welfare.field.claim_no')) ?></th><th scope="col"><?= $e($t('welfare.field.type')) ?></th><th scope="col"><?= $e($t('welfare.field.expense_date')) ?></th>
        <th scope="col" class="num"><?= $e($t('welfare.field.amount')) ?></th><th scope="col" class="num"><?= $e($t('welfare.field.approved')) ?></th><th scope="col"><?= $e($t('welfare.field.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($claims as $c): ?>
        <tr><th scope="row" data-label="<?= $e($t('welfare.field.claim_no')) ?>"><a href="<?= $e($url('/welfare/claims/' . $c['public_id'])) ?>"><?= $e((string) ($c['claim_no'] ?? $t('welfare.not_sent'))) ?></a></th>
          <td data-label="<?= $e($t('welfare.field.type')) ?>"><?= $e($c['type_code'] . ' · ' . $c['type_name']) ?></td>
          <td data-label="<?= $e($t('welfare.field.expense_date')) ?>"><?= $e($date((string) $c['expense_date'], false)) ?></td>
          <td data-label="<?= $e($t('welfare.field.amount')) ?>" class="num"><?= $e(Money::format(Money::fromDb($c['amount_claimed']))) ?></td>
          <td data-label="<?= $e($t('welfare.field.approved')) ?>" class="num"><?= $c['approved_amount'] !== null ? $e(Money::format(Money::fromDb($c['approved_amount']))) : '—' ?></td>
          <td data-label="<?= $e($t('welfare.field.status')) ?>"><?= $ui('badge', ['tone' => $tones[$c['status']] ?? 'neutral', 'label' => $t('welfare.status.' . $c['status'])]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
