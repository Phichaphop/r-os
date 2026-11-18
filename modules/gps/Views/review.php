<?php
/** Punches waiting for review. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var list<array<string, mixed>> $rows */
$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
?>
<?= $ui('page-header', ['title' => $t('gps.review_title'), 'subtitle' => $t('gps.review_subtitle'), 'crumbs' => [[$t('gps.title'), $url('/gps')], [$t('gps.review_title'), null]]]) ?>
<section class="card stack-sm" aria-labelledby="rv-h">
  <h2 id="rv-h" class="card__title"><?= $e($t('gps.review_n', ['n' => (string) count($rows)])) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('gps.review_none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="rv-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('gps.field.employee')) ?></th><th scope="col"><?= $e($t('gps.field.when')) ?></th><th scope="col"><?= $e($t('gps.field.direction')) ?></th>
        <th scope="col"><?= $e($t('gps.field.flags')) ?></th><th scope="col"><?= $e($t('gps.field.distance')) ?></th><th scope="col"><?= $e($t('gps.field.note')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $p): ?>
        <tr><th scope="row" data-label="<?= $e($t('gps.field.employee')) ?>"><a href="<?= $e($url('/gps/p/' . $p['public_id'])) ?>"><?= $e($p['employee_code'] . ' · ' . $p['name_th']) ?></a></th>
          <td data-label="<?= $e($t('gps.field.when')) ?>"><?= $e($gpsWhen($p)) ?></td>
          <td data-label="<?= $e($t('gps.field.direction')) ?>"><?= $e($t('gps.dir.' . $p['direction'])) ?></td>
          <td data-label="<?= $e($t('gps.field.flags')) ?>"><?= $e($gpsFlags((string) $p['flags'])) ?></td>
          <td data-label="<?= $e($t('gps.field.distance')) ?>" class="num"><?= $e($p['distance_m'] !== null ? $t('gps.metres', ['m' => (string) (int) $p['distance_m']]) : '—') ?></td>
          <td data-label="<?= $e($t('gps.field.note')) ?>"><?= $e((string) ($p['employee_note'] ?? '—')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
