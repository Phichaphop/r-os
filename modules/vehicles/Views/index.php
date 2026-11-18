<?php
/**
 * My vehicle requests, and — for a driver — the trips I drive.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $rows @var list<array<string, mixed>> $jobs @var bool $canManage
 */
$here = '/vehicles';
$when = static fn (array $r): string => substr((string) $r['starts_at'], 0, 16) . ' – ' . (substr((string) $r['ends_at'], 0, 10) === substr((string) $r['starts_at'], 0, 10) ? substr((string) $r['ends_at'], 11, 5) : substr((string) $r['ends_at'], 0, 16));
?>
<?= $ui('page-header', ['title' => $t('vehicles.title'), 'subtitle' => $t('vehicles.subtitle'),
    'actions' => $ui('button', ['label' => $t('vehicles.tab.new'), 'href' => $url('/vehicles/new'), 'variant' => 'primary', 'icon' => 'plus'])]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($jobs !== []): ?>
<section class="card card--flush" aria-labelledby="vi-j">
  <div class="card__head"><h2 id="vi-j" class="card__title"><?= $e($t('vehicles.my_jobs')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="vi-j" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('vehicles.col.ref')) ?></th><th scope="col"><?= $e($t('vehicles.col.when')) ?></th><th scope="col"><?= $e($t('vehicles.col.route')) ?></th><th scope="col"><?= $e($t('vehicles.col.plate')) ?></th><th scope="col"><?= $e($t('vehicles.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($jobs as $r): $st = (string) $r['status']; ?>
        <tr><th scope="row" data-label="<?= $e($t('vehicles.col.ref')) ?>"><a href="<?= $e($url('/vehicles/b/' . $r['public_id'])) ?>"><?= $e((string) $r['ref']) ?></a></th>
          <td data-label="<?= $e($t('vehicles.col.when')) ?>"><?= $e($when($r)) ?></td>
          <td data-label="<?= $e($t('vehicles.col.route')) ?>"><?= $e($r['from_name'] . ' → ' . $r['to_name']) ?> · <?= $e($t('vehicles.people_n', ['n' => (string) $r['passenger_count']])) ?></td>
          <td data-label="<?= $e($t('vehicles.col.plate')) ?>"><?= $e((string) ($r['plate'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('vehicles.col.status')) ?>"><?php include __DIR__ . '/_status.php'; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
<section class="card card--flush" aria-labelledby="vi-m">
  <div class="card__head"><h2 id="vi-m" class="card__title"><?= $e($t('vehicles.my_requests')) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'car', 'title' => $t('vehicles.none'), 'text' => $t('vehicles.none_help')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="vi-m" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('vehicles.col.ref')) ?></th><th scope="col"><?= $e($t('vehicles.col.when')) ?></th><th scope="col"><?= $e($t('vehicles.col.to')) ?></th><th scope="col"><?= $e($t('vehicles.col.plate')) ?></th><th scope="col"><?= $e($t('vehicles.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $st = (string) $r['status']; ?>
        <tr><th scope="row" data-label="<?= $e($t('vehicles.col.ref')) ?>"><a href="<?= $e($url('/vehicles/b/' . $r['public_id'])) ?>"><?= $e((string) $r['ref']) ?></a></th>
          <td data-label="<?= $e($t('vehicles.col.when')) ?>"><?= $e($when($r)) ?></td>
          <td data-label="<?= $e($t('vehicles.col.to')) ?>"><?= $e((string) $r['to_name']) ?></td>
          <td data-label="<?= $e($t('vehicles.col.plate')) ?>"><?= $e((string) ($r['plate'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('vehicles.col.status')) ?>"><?php include __DIR__ . '/_status.php'; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
