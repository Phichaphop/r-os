<?php
/**
 * A month of vehicle use (workplace admin): per vehicle and per driver, and the requests by status — numbers only.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var string $month
 * @var array{vehicles: list<array<string, mixed>>, drivers: list<array<string, mixed>>, statuses: array<string, int>} $report
 */
$here = '/vehicles/report';
$canManage = true;
$q = '?le=' . rawurlencode($lePid) . '&month=' . rawurlencode($month);
?>
<?= $ui('page-header', ['title' => $t('vehicles.report_title'), 'subtitle' => $t('vehicles.report_subtitle'),
    'actions' => $ui('button', ['label' => $t('vehicles.export'), 'href' => $url('/vehicles/report/export' . $q), 'variant' => 'secondary', 'icon' => 'download'])]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<form method="get" action="<?= $e($url('/vehicles/report')) ?>" class="card row">
  <?php if (count($companies) > 1): ?>
    <?= $ui('field', ['name' => 'le', 'type' => 'select', 'label' => $t('vehicles.pick_company'), 'value' => $lePid, 'options' => $companies]) ?>
  <?php else: ?><input type="hidden" name="le" value="<?= $e($lePid) ?>"><?php endif; ?>
  <?= $ui('field', ['name' => 'month', 'type' => 'month', 'label' => $t('vehicles.field.month'), 'value' => $month]) ?>
  <?= $ui('button', ['label' => $t('vehicles.show'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'filter']) ?>
</form>
<section class="card stack-sm" aria-labelledby="vr-s">
  <h2 id="vr-s" class="card__title"><?= $e($t('vehicles.requests_by_status')) ?></h2>
  <?php if ($report['statuses'] === []): ?><p class="text-sm text-muted"><?= $e($t('vehicles.board_empty')) ?></p><?php else: ?>
  <p><?php foreach ($report['statuses'] as $st => $n): ?><?= $ui('badge', ['tone' => 'neutral', 'label' => $t('vehicles.status.' . $st) . ' ' . $n]) ?> <?php endforeach; ?></p>
  <?php endif; ?>
</section>
<section class="card card--flush" aria-labelledby="vr-v">
  <div class="card__head"><h2 id="vr-v" class="card__title"><?= $e($t('vehicles.by_vehicle')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="vr-v" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('vehicles.col.vehicle')) ?></th><th scope="col" class="num"><?= $e($t('vehicles.col.trips')) ?></th><th scope="col" class="num"><?= $e($t('vehicles.col.km')) ?></th><th scope="col" class="num"><?= $e($t('vehicles.col.litres')) ?></th>
        <th scope="col" class="num"><?= $e($t('vehicles.col.fuel')) ?></th><th scope="col" class="num"><?= $e($t('vehicles.col.other')) ?></th><th scope="col" class="num"><?= $e($t('vehicles.col.incidents')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($report['vehicles'] as $r): ?>
        <tr><th scope="row" data-label="<?= $e($t('vehicles.col.vehicle')) ?>"><?= $e($r['plate'] . ' · ' . $r['name']) ?></th>
          <td class="num" data-label="<?= $e($t('vehicles.col.trips')) ?>"><?= (int) $r['trips'] ?></td>
          <td class="num" data-label="<?= $e($t('vehicles.col.km')) ?>"><?= $e(number_format((int) $r['km'])) ?></td>
          <td class="num" data-label="<?= $e($t('vehicles.col.litres')) ?>"><?= $e(number_format((float) $r['litres'], 2)) ?></td>
          <td class="num" data-label="<?= $e($t('vehicles.col.fuel')) ?>"><?= $e(number_format((float) $r['fuel'], 2)) ?></td>
          <td class="num" data-label="<?= $e($t('vehicles.col.other')) ?>"><?= $e(number_format((float) $r['other'], 2)) ?></td>
          <td class="num" data-label="<?= $e($t('vehicles.col.incidents')) ?>"><?= (int) $r['incidents'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<section class="card card--flush" aria-labelledby="vr-d">
  <div class="card__head"><h2 id="vr-d" class="card__title"><?= $e($t('vehicles.by_driver')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="vr-d" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('vehicles.col.driver')) ?></th><th scope="col" class="num"><?= $e($t('vehicles.col.trips')) ?></th><th scope="col" class="num"><?= $e($t('vehicles.col.hours')) ?></th><th scope="col" class="num"><?= $e($t('vehicles.col.km')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($report['drivers'] as $r): ?>
        <tr><th scope="row" data-label="<?= $e($t('vehicles.col.driver')) ?>"><?= $e((string) $r['name']) ?></th>
          <td class="num" data-label="<?= $e($t('vehicles.col.trips')) ?>"><?= (int) $r['trips'] ?></td>
          <td class="num" data-label="<?= $e($t('vehicles.col.hours')) ?>"><?= $e(number_format((int) $r['minutes'] / 60, 2)) ?></td>
          <td class="num" data-label="<?= $e($t('vehicles.col.km')) ?>"><?= $e(number_format((int) $r['km'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<p class="text-sm text-muted"><?= $e($t('vehicles.report_note')) ?></p>
