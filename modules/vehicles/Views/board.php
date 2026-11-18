<?php
/**
 * The workplace admin's board: approved requests waiting for a vehicle, trips assigned and under way, requests still
 * waiting for approval, and the last two weeks done.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var array<string, list<array<string, mixed>>> $board
 */
$here = '/vehicles/board';
$canManage = true;
$sections = ['to_assign' => 'list-check', 'active' => 'car', 'pending' => 'hourglass', 'done' => 'history'];
?>
<?= $ui('page-header', ['title' => $t('vehicles.board_title'), 'subtitle' => $t('vehicles.board_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('vehicles.pick_company')) ?>">
  <?php foreach ($companies as $cp => $code): ?><a href="<?= $e($url('/vehicles/board?le=' . rawurlencode($cp))) ?>"<?= $cp === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?php foreach ($sections as $key => $icon): $rows = $board[$key]; ?>
<section class="card card--flush" aria-labelledby="vbd-<?= $e($key) ?>">
  <div class="card__head"><h2 id="vbd-<?= $e($key) ?>" class="card__title"><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t('vehicles.board.' . $key)) ?> (<?= count($rows) ?>)</h2></div>
  <?php if ($rows === []): ?><p class="card__pad text-sm text-muted"><?= $e($t('vehicles.board_empty')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="vbd-<?= $e($key) ?>" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('vehicles.col.ref')) ?></th><th scope="col"><?= $e($t('vehicles.col.when')) ?></th><th scope="col"><?= $e($t('vehicles.col.requester')) ?></th><th scope="col"><?= $e($t('vehicles.col.to')) ?></th>
        <th scope="col"><?= $e($t('vehicles.col.people')) ?></th><th scope="col"><?= $e($t('vehicles.col.vehicle')) ?></th><th scope="col"><?= $e($t('vehicles.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $st = (string) $r['status']; ?>
        <tr><th scope="row" data-label="<?= $e($t('vehicles.col.ref')) ?>"><a href="<?= $e($url('/vehicles/b/' . $r['public_id'])) ?>"><?= $e((string) $r['ref']) ?></a></th>
          <td data-label="<?= $e($t('vehicles.col.when')) ?>"><?= $e(substr((string) $r['starts_at'], 0, 16) . ' – ' . substr((string) $r['ends_at'], 11, 5)) ?> · <?= $e((string) $r['site_code']) ?></td>
          <td data-label="<?= $e($t('vehicles.col.requester')) ?>"><?= $e((string) $r['requester_name']) ?></td>
          <td data-label="<?= $e($t('vehicles.col.to')) ?>"><?= $e((string) $r['to_name']) ?><?= $r['est_km'] !== null ? ' · ' . $e($t('vehicles.km_value', ['km' => (string) $r['est_km']])) : '' ?></td>
          <td data-label="<?= $e($t('vehicles.col.people')) ?>"><?= (int) $r['passenger_count'] ?></td>
          <td data-label="<?= $e($t('vehicles.col.vehicle')) ?>"><?= $e($r['plate'] !== null ? $r['plate'] . ' · ' . ((int) $r['self_drive'] === 1 ? $t('vehicles.self_drive') : (string) $r['driver_name']) : '—') ?></td>
          <td data-label="<?= $e($t('vehicles.col.status')) ?>"><?php include __DIR__ . '/_status.php'; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>
