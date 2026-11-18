<?php
/** GPS check-in month report (no coordinates). @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var list<array<string, mixed>> $rows @var string $month */
$cols = ['punches' => $t('gps.col.punches'), 'inside' => $t('gps.col.inside'), 'outside' => $t('gps.col.outside'), 'waiting' => $t('gps.col.waiting'), 'rejected' => $t('gps.col.rejected'), 'offline' => $t('gps.col.offline')];
?>
<?= $ui('page-header', ['title' => $t('gps.report_title'), 'subtitle' => $t('gps.report_subtitle'), 'crumbs' => [[$t('gps.title'), $url('/gps')], [$t('gps.report_title'), null]],
    'actions' => $ui('button', ['label' => $t('gps.export_xlsx'), 'href' => $url('/gps/report?month=' . rawurlencode($month) . '&format=xlsx'), 'variant' => 'secondary', 'icon' => 'download'])]) ?>
<form method="get" action="<?= $e($url('/gps/report')) ?>" class="row row--tight">
  <label class="sr-only" for="rp-m"><?= $e($t('gps.field.month')) ?></label><input class="input" type="month" id="rp-m" name="month" value="<?= $e($month) ?>">
  <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'size' => 'sm', 'icon' => 'filter']) ?>
</form>
<section class="card stack-sm" aria-labelledby="rp-h">
  <h2 id="rp-h" class="card__title"><?= $e($t('gps.report_for', ['month' => $month])) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('gps.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="rp-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('gps.field.employee')) ?></th><?php foreach ($cols as $lab): ?><th scope="col"><?= $e($lab) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('gps.field.employee')) ?>"><?= $e((string) $x['label']) ?></th>
          <?php foreach ($cols as $k => $lab): ?><td class="num" data-label="<?= $e($lab) ?>"><?= (int) $x[$k] ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <p class="text-sm text-muted"><?= $ui('icon', ['name' => 'lock', 'size' => 'sm']) ?> <?= $e($t('gps.report_privacy')) ?></p>
</section>
