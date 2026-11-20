<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array{rows: list<array<string, mixed>>, total: int} $list @var array<string, mixed> $f @var int $page @var int $per @var array<int, string> $companies @var bool $canPrepare
 */
use Blue\Core\Support\Money;

$statuses = ['draft', 'in_review', 'returned', 'approved', 'file_generated', 'sent', 'reconciled', 'cancelled'];
$types = ['payroll', 'claim', 'ap'];
?>
<?= $ui('page-header', ['title' => $t('payment.title'), 'subtitle' => $t('payment.subtitle'),
    'actions' => $ui('button', ['label' => $t('payment.format.title'), 'href' => $url('/payments/formats'), 'icon' => 'file-text'])
        . ($canPrepare ? ' ' . $ui('button', ['label' => $t('payment.new.title'), 'href' => $url('/payments/new'), 'variant' => 'primary', 'icon' => 'plus']) : '')]) ?>
<form method="get" action="<?= $e($url('/payments')) ?>" class="filter-bar" role="search">
  <?= $ui('field', ['name' => 'q', 'label' => $t('common.search'), 'value' => (string) $f['q']]) ?>
  <?= $ui('field', ['name' => 'status', 'label' => $t('payment.field.status'), 'type' => 'select', 'value' => (string) $f['status'],
      'options' => ['' => $t('common.all')] + array_combine($statuses, array_map(static fn ($s) => $t('payment.status.' . $s), $statuses))]) ?>
  <?= $ui('field', ['name' => 'type', 'label' => $t('payment.field.type'), 'type' => 'select', 'value' => (string) $f['type'],
      'options' => ['' => $t('common.all')] + array_combine($types, array_map(static fn ($s) => $t('payment.type.' . $s), $types))]) ?>
  <?php if (count($companies) > 1): ?><?= $ui('field', ['name' => 'legal_entity', 'label' => $t('finance.field.company'), 'type' => 'select', 'value' => (string) ($f['legal_entity'] ?: ''), 'options' => ['' => $t('common.all')] + $companies]) ?><?php endif; ?>
  <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'icon' => 'filter']) ?>
</form>
<div class="table-wrap" role="region" aria-label="<?= $e($t('payment.title')) ?>" tabindex="0">
  <table class="table table--stack">
    <caption class="sr-only"><?= $e($t('payment.title')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('payment.field.batch_no')) ?></th><th scope="col"><?= $e($t('payment.field.type')) ?></th><th scope="col"><?= $e($t('finance.field.company')) ?></th>
      <th scope="col"><?= $e($t('payment.field.pay_date')) ?></th><th scope="col" class="num"><?= $e($t('payment.field.items')) ?></th><th scope="col" class="num"><?= $e($t('payment.field.total')) ?></th><th scope="col"><?= $e($t('payment.field.status')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($list['rows'] as $b): $st = (string) $b['status']; ?>
      <tr>
        <td data-label="<?= $e($t('payment.field.batch_no')) ?>"><a class="mono" href="<?= $e($url('/payments/' . $b['public_id'])) ?>"><?= $e($b['batch_no']) ?></a>
          <?php if ((int) $b['is_demo'] === 1): ?> <?= $ui('badge', ['tone' => 'neutral', 'label' => 'DEMO']) ?><?php endif; ?>
          <?php if ($b['period_label'] !== null): ?><div class="text-sm text-muted"><?= $e($b['period_label']) ?></div><?php endif; ?></td>
        <td data-label="<?= $e($t('payment.field.type')) ?>"><?= $e($t('payment.type.' . $b['payment_type'])) ?></td>
        <td data-label="<?= $e($t('finance.field.company')) ?>"><?= $e($b['le_code']) ?></td>
        <td data-label="<?= $e($t('payment.field.pay_date')) ?>"><?= $e((string) $b['pay_date']) ?></td>
        <td data-label="<?= $e($t('payment.field.items')) ?>" class="num"><?= (int) $b['item_count'] ?></td>
        <td data-label="<?= $e($t('payment.field.total')) ?>" class="num"><?= $e(Money::format(Money::fromDb($b['total_amount']))) ?></td>
        <td data-label="<?= $e($t('payment.field.status')) ?>"><?php include __DIR__ . '/_status.php'; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($list['rows'] === []): ?><tr><td colspan="7"><?= $ui('states', ['icon' => 'cash', 'title' => $t('payment.empty'), 'text' => $t('payment.empty_text')]) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?= $ui('pagination', ['path' => '/payments', 'query' => array_filter($f, static fn ($x) => $x !== '' && $x !== 0), 'page' => $page, 'per' => $per, 'total' => $list['total']]) ?>
