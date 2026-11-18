<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array{rows: list<array<string, mixed>>, debit: int, credit: int} $tb @var string $from @var string $to @var int $le
 */
use Blue\Core\Support\Money;

$q = ['le' => $le, 'from' => $from, 'to' => $to];
?>
<?= $ui('page-header', ['title' => $t('finance.trial.title'), 'subtitle' => $t('finance.trial.subtitle'),
    'actions' => $ui('button', ['label' => $t('common.export_excel'), 'href' => $url('/finance/trial-balance') . '?' . http_build_query($q + ['export' => 'xlsx']), 'icon' => 'download'])]) ?>
<?php include __DIR__ . '/_tabs.php'; ?>
<form method="get" action="<?= $e($url('/finance/trial-balance')) ?>" class="filter-bar">
  <input type="hidden" name="le" value="<?= (int) $le ?>">
  <?= $ui('field', ['name' => 'from', 'label' => $t('finance.field.from'), 'type' => 'date', 'value' => $from]) ?>
  <?= $ui('field', ['name' => 'to', 'label' => $t('finance.field.to'), 'type' => 'date', 'value' => $to]) ?>
  <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'icon' => 'filter']) ?>
</form>
<?php if ($tb['debit'] === $tb['credit']): ?>
  <?= $ui('alert', ['tone' => 'success', 'text' => $t('finance.trial.balanced')]) ?>
<?php else: ?>
  <?= $ui('alert', ['tone' => 'danger', 'text' => $t('finance.trial.unbalanced')]) ?>
<?php endif; ?>
<div class="table-wrap" role="region" aria-label="<?= $e($t('finance.trial.title')) ?>" tabindex="0">
  <table class="table table--stack">
    <caption class="sr-only"><?= $e($t('finance.trial.title')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('finance.field.account')) ?></th><th scope="col"><?= $e($t('finance.field.name')) ?></th><th scope="col"><?= $e($t('finance.field.type')) ?></th>
      <th scope="col" class="num"><?= $e($t('finance.field.debit')) ?></th><th scope="col" class="num"><?= $e($t('finance.field.credit')) ?></th><th scope="col" class="num"><?= $e($t('finance.field.balance')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($tb['rows'] as $r): ?>
      <tr>
        <td data-label="<?= $e($t('finance.field.account')) ?>" class="mono"><?= $e($r['code']) ?></td>
        <td data-label="<?= $e($t('finance.field.name')) ?>"><?= $e($r['name']) ?></td>
        <td data-label="<?= $e($t('finance.field.type')) ?>"><?= $e($t('finance.type.' . $r['account_type'])) ?></td>
        <td data-label="<?= $e($t('finance.field.debit')) ?>" class="num"><?= $e(Money::format($r['debit_units'])) ?></td>
        <td data-label="<?= $e($t('finance.field.credit')) ?>" class="num"><?= $e(Money::format($r['credit_units'])) ?></td>
        <td data-label="<?= $e($t('finance.field.balance')) ?>" class="num"><?= $e(Money::format($r['balance_units'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($tb['rows'] === []): ?><tr><td colspan="6"><?= $ui('states', ['icon' => 'report-money', 'title' => $t('finance.trial.empty'), 'text' => $t('finance.trial.empty_text')]) ?></td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><th scope="row" colspan="3"><?= $e($t('finance.field.total')) ?></th><td class="num"><strong><?= $e(Money::format($tb['debit'])) ?></strong></td>
      <td class="num"><strong><?= $e(Money::format($tb['credit'])) ?></strong></td><td class="num"><strong><?= $e(Money::format($tb['debit'] - $tb['credit'])) ?></strong></td></tr></tfoot>
  </table>
</div>
