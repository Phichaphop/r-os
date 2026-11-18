<?php
/**
 * Stock of a site: on hand, reserved, available, reorder level; receive or count (stock take) one item, import from
 * Excel, and the latest movements.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $sites @var int $siteId @var string $sitePid
 * @var list<array<string, mixed>> $items @var list<array<string, mixed>> $ledger @var array<string, string> $errors @var array<string, mixed> $old @var list<string> $importErrors
 * @var string $here @var bool $canIssue @var bool $canManage
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$hid = '<input type="hidden" name="le" value="' . $e($lePid) . '"><input type="hidden" name="site" value="' . $e($sitePid) . '">';
$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$itemOpts = ['' => $t('requisition.pick')];
foreach ($items as $i) {
    $itemOpts[(string) $i['public_id']] = $i['code'] . ' · ' . $i['name'];
}
?>
<?= $ui('page-header', ['title' => $t('requisition.stock_title'), 'subtitle' => $t('requisition.stock_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<nav class="segmented-links" aria-label="<?= $e($t('requisition.pick_site')) ?>">
  <?php foreach ($sites as $s): ?><a href="<?= $e($url('/requisition/stock?le=' . rawurlencode($lePid) . '&site=' . $s['public_id'])) ?>"<?= (int) $s['id'] === $siteId ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'map-pin', 'size' => 'sm']) ?> <?= $e((string) $s['code']) ?></a><?php endforeach; ?>
</nav>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'html' => $importErrors === [] ? '' : '<ul>' . implode('', array_map(static fn (string $x): string => '<li>' . $e($x) . '</li>', $importErrors)) . '</ul>']) ?><?php endif; ?>
<section class="card card--flush" aria-labelledby="rs-h">
  <div class="card__head"><h2 id="rs-h" class="card__title"><?= $e($t('requisition.stock_of_site')) ?></h2></div>
  <?php if ($items === []): ?><div class="card__pad"><p class="text-muted"><?= $e($t('requisition.no_items')) ?></p></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="rs-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('requisition.field.code')) ?></th><th scope="col"><?= $e($t('requisition.field.name')) ?></th><th scope="col"><?= $e($t('requisition.col.on_hand')) ?></th><th scope="col"><?= $e($t('requisition.col.reserved')) ?></th><th scope="col"><?= $e($t('requisition.col.available')) ?></th><th scope="col"><?= $e($t('requisition.field.reorder_level')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($items as $i): ?>
        <tr><th scope="row" data-label="<?= $e($t('requisition.field.code')) ?>"><?= $e((string) $i['code']) ?></th>
          <td data-label="<?= $e($t('requisition.field.name')) ?>"><?= $e((string) $i['name']) ?><?= (int) $i['active'] === 1 ? '' : ' ' . $ui('badge', ['tone' => 'neutral', 'label' => $t('requisition.inactive')]) ?></td>
          <td data-label="<?= $e($t('requisition.col.on_hand')) ?>"><?= $e($i['on_hand'] . ' ' . $i['unit']) ?></td>
          <td data-label="<?= $e($t('requisition.col.reserved')) ?>"><?= (int) $i['reserved'] ?></td>
          <td data-label="<?= $e($t('requisition.col.available')) ?>"><?= (int) $i['available'] ?><?= $i['low'] ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('requisition.low')]) : '' ?></td>
          <td data-label="<?= $e($t('requisition.field.reorder_level')) ?>"><?= $i['reorder_level'] === null ? '—' : (int) $i['reorder_level'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php if ($items !== []): ?>
<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="rs-a-h">
  <h2 id="rs-a-h" class="card__title"><?= $e($t('requisition.adjust_title')) ?></h2>
  <form method="post" action="<?= $e($url('/requisition/stock/adjust')) ?>" class="stack-sm" novalidate><?= $csrf . $hid ?>
    <?= $ui('field', ['name' => 'kind', 'type' => 'select', 'label' => $t('requisition.field.kind'), 'value' => $o('kind', 'receive'), 'options' => ['receive' => $t('requisition.kind_help.receive'), 'count' => $t('requisition.kind_help.count')]]) ?>
    <?= $ui('field', ['name' => 'item', 'type' => 'select', 'label' => $t('requisition.field.name'), 'required' => true, 'value' => $o('item'), 'options' => $itemOpts, 'error' => $errors['item'] ?? null]) ?>
    <?= $ui('field', ['name' => 'qty', 'type' => 'number', 'label' => $t('requisition.col.qty'), 'required' => true, 'value' => $o('qty'), 'error' => $errors['qty'] ?? null]) ?>
    <?= $ui('field', ['name' => 'note', 'label' => $t('requisition.field.note'), 'value' => $o('note'), 'help' => $t('requisition.count_note_help'), 'error' => $errors['note'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('requisition.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
</section>
<section class="card stack-sm" aria-labelledby="rs-i-h">
  <h2 id="rs-i-h" class="card__title"><?= $e($t('requisition.import_title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('requisition.import_help')) ?></p>
  <form method="post" action="<?= $e($url('/requisition/stock/import')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate><?= $csrf . $hid ?>
    <?= $ui('field', ['name' => 'import_kind', 'type' => 'select', 'label' => $t('requisition.field.kind'), 'value' => $o('import_kind', 'receive'), 'options' => ['receive' => $t('requisition.kind_help.receive'), 'count' => $t('requisition.kind_help.count')]]) ?>
    <?= $ui('field', ['name' => 'file', 'type' => 'file', 'label' => $t('requisition.field.file'), 'required' => true, 'accept' => '.xlsx', 'error' => $errors['file'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('requisition.import'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'upload']) ?></div>
  </form>
</section>
</div>
<?php endif; ?>
<section class="card card--flush" aria-labelledby="rs-l-h">
  <div class="card__head"><h2 id="rs-l-h" class="card__title"><?= $e($t('requisition.latest_moves')) ?></h2></div>
  <?php $rows = $ledger; include __DIR__ . '/_ledger.php'; ?>
</section>
