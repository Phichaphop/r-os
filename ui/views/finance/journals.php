<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array{rows: list<array<string, mixed>>, total: int} $list @var array<string, mixed> $f @var int $page @var int $per @var int $le @var bool $canManage
 */
$recent = $list['rows'];
?>
<?= $ui('page-header', ['title' => $t('finance.journals.title'), 'subtitle' => $t('finance.journals.subtitle'),
    'actions' => $canManage ? $ui('button', ['label' => $t('finance.journal.new'), 'href' => $url('/finance/journals/new?le=' . $le), 'variant' => 'primary', 'icon' => 'plus']) : '']) ?>
<?php include __DIR__ . '/_tabs.php'; ?>
<form method="get" action="<?= $e($url('/finance/journals')) ?>" class="filter-bar" role="search">
  <input type="hidden" name="le" value="<?= (int) $le ?>">
  <?= $ui('field', ['name' => 'q', 'label' => $t('common.search'), 'value' => (string) $f['q']]) ?>
  <?= $ui('field', ['name' => 'status', 'label' => $t('finance.field.status'), 'type' => 'select', 'value' => (string) $f['status'],
      'options' => ['' => $t('common.all')] + array_combine(['draft', 'in_review', 'returned', 'posted', 'reversed', 'cancelled'], array_map(static fn ($s) => $t('finance.jstatus.' . $s), ['draft', 'in_review', 'returned', 'posted', 'reversed', 'cancelled']))]) ?>
  <?= $ui('field', ['name' => 'from', 'label' => $t('finance.field.from'), 'type' => 'date', 'value' => (string) $f['from']]) ?>
  <?= $ui('field', ['name' => 'to', 'label' => $t('finance.field.to'), 'type' => 'date', 'value' => (string) $f['to']]) ?>
  <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'icon' => 'filter']) ?>
</form>
<?php include __DIR__ . '/_journal_table.php'; ?>
<?= $ui('pagination', ['path' => '/finance/journals', 'query' => ['le' => $le] + array_filter($f, static fn ($x) => $x !== '' && $x !== 0), 'page' => $page, 'per' => $per, 'total' => $list['total']]) ?>
