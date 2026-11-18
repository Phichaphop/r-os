<?php
/**
 * A month of inspections by point.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var string $month @var list<array<string, mixed>> $rows @var bool $canManage
 */
$here = '/inspection/report';
$q = '?le=' . rawurlencode($lePid) . '&month=' . rawurlencode($month);
$cols = ['due', 'on_time', 'late', 'missed', 'fails', 'open_actions'];
?>
<?= $ui('page-header', ['title' => $t('inspection.report_title'), 'subtitle' => $t('inspection.report_subtitle'),
    'actions' => $ui('button', ['label' => $t('inspection.export'), 'href' => $url('/inspection/report/export' . $q), 'variant' => 'secondary', 'icon' => 'download'])]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<form method="get" action="<?= $e($url('/inspection/report')) ?>" class="card row">
  <?php if (count($companies) > 1): ?><?= $ui('field', ['name' => 'le', 'type' => 'select', 'label' => $t('inspection.pick_company'), 'value' => $lePid, 'options' => $companies]) ?><?php else: ?><input type="hidden" name="le" value="<?= $e($lePid) ?>"><?php endif; ?>
  <?= $ui('field', ['name' => 'month', 'type' => 'month', 'label' => $t('inspection.field.month'), 'value' => $month]) ?>
  <?= $ui('button', ['label' => $t('inspection.show'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'filter']) ?>
</form>
<section class="card card--flush" aria-labelledby="irp">
  <div class="card__head"><h2 id="irp" class="card__title"><?= $e($t('inspection.by_point')) ?></h2></div>
  <?php if ($rows === []): ?><p class="card__pad text-sm text-muted"><?= $e($t('inspection.empty')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="irp" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('inspection.col.point')) ?></th><?php foreach ($cols as $c): ?><th scope="col" class="num"><?= $e($t('inspection.col.' . $c)) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr><th scope="row" data-label="<?= $e($t('inspection.col.point')) ?>"><?= $e($r['code'] . ' · ' . $r['name']) ?></th>
          <?php foreach ($cols as $c): ?><td class="num" data-label="<?= $e($t('inspection.col.' . $c)) ?>"><?= (int) $r[$c] ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<p class="text-sm text-muted"><?= $e($t('inspection.report_note')) ?></p>
