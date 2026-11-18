<?php
/**
 * Use of a company's rooms in a month (numbers only): bookings, hours, share of the opening hours, no-shows, cancellations.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var string $month @var list<array<string, mixed>> $rows
 */
$here = '/rooms/report';
$canManage = true;
?>
<?= $ui('page-header', ['title' => $t('rooms.report_title'), 'subtitle' => $t('rooms.report_subtitle'),
    'actions' => $ui('button', ['label' => $t('rooms.export'), 'href' => $url('/rooms/report/export?le=' . rawurlencode($lePid) . '&month=' . $month), 'variant' => 'secondary', 'icon' => 'download'])]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<form method="get" action="<?= $e($url('/rooms/report')) ?>" class="card row room-filter">
  <input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <?= $ui('field', ['name' => 'month', 'type' => 'month', 'label' => $t('rooms.field.month'), 'value' => $month]) ?>
  <div class="row"><?= $ui('button', ['label' => $t('rooms.show'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'search']) ?></div>
</form>
<section class="card card--flush" aria-labelledby="rp-l">
  <div class="card__head"><h2 id="rp-l" class="card__title"><?= $e($t('rooms.report_month', ['m' => $month])) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'calendar-event', 'title' => $t('rooms.no_rooms')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="rp-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('rooms.col.room')) ?></th><th scope="col" class="num"><?= $e($t('rooms.col.bookings')) ?></th><th scope="col" class="num"><?= $e($t('rooms.col.hours')) ?></th><th scope="col" class="num"><?= $e($t('rooms.col.share')) ?></th><th scope="col" class="num"><?= $e($t('rooms.col.no_show')) ?></th><th scope="col" class="num"><?= $e($t('rooms.col.cancelled')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr><th scope="row" data-label="<?= $e($t('rooms.col.room')) ?>"><?= $e($r['code'] . ' · ' . $r['name']) ?></th>
          <td data-label="<?= $e($t('rooms.col.bookings')) ?>" class="num"><?= (int) $r['used'] ?></td>
          <td data-label="<?= $e($t('rooms.col.hours')) ?>" class="num"><?= $e(number_format((float) $r['hours'], 2)) ?></td>
          <td data-label="<?= $e($t('rooms.col.share')) ?>" class="num"><?= $e(number_format((float) $r['share'], 1)) ?> %</td>
          <td data-label="<?= $e($t('rooms.col.no_show')) ?>" class="num"><?= (int) $r['no_show'] ?></td>
          <td data-label="<?= $e($t('rooms.col.cancelled')) ?>" class="num"><?= (int) $r['cancelled'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
