<?php
/**
 * Rooms of a company (workplace admin): the list with their status and a form for a new room.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $rooms @var list<array<string, mixed>> $sites
 * @var array<string, string> $errors @var array<string, mixed> $old
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$here = '/rooms/admin';
$canManage = true;
?>
<?= $ui('page-header', ['title' => $t('rooms.admin_title'), 'subtitle' => $t('rooms.admin_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('rooms.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/rooms/admin?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<section class="card card--flush" aria-labelledby="ra-l">
  <div class="card__head"><h2 id="ra-l" class="card__title"><?= $e($t('rooms.rooms_list')) ?></h2></div>
  <?php if ($rooms === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'calendar-event', 'title' => $t('rooms.no_rooms')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ra-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('rooms.col.room')) ?></th><th scope="col"><?= $e($t('rooms.field.site')) ?></th><th scope="col"><?= $e($t('rooms.field.capacity')) ?></th><th scope="col"><?= $e($t('rooms.field.hours')) ?></th><th scope="col"><?= $e($t('rooms.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rooms as $r): ?>
        <tr><th scope="row" data-label="<?= $e($t('rooms.col.room')) ?>"><a href="<?= $e($url('/rooms/r/' . $r['public_id'])) ?>"><?= $e($r['code'] . ' · ' . $r['name']) ?></a></th>
          <td data-label="<?= $e($t('rooms.field.site')) ?>"><?= $e((string) ($r['site_code'] ?? $t('rooms.all_sites'))) ?></td>
          <td data-label="<?= $e($t('rooms.field.capacity')) ?>"><?= (int) $r['capacity'] ?></td>
          <td data-label="<?= $e($t('rooms.field.hours')) ?>"><?= $e(substr((string) $r['open_from'], 0, 5) . '–' . substr((string) $r['open_to'], 0, 5)) ?></td>
          <td data-label="<?= $e($t('rooms.col.status')) ?>"><?= $ui('badge', ['tone' => $r['status'] === 'active' ? 'success' : 'warning', 'label' => $t('rooms.room_status.' . $r['status'])]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="ra-n">
  <h2 id="ra-n" class="card__title"><?= $e($t('rooms.new_room')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('rooms.no_defaults')) ?></p>
  <?php $rf = 'new'; $rv = $old; $rErr = $errors; $rAction = '/rooms/admin/rooms'; $rLe = $lePid; include __DIR__ . '/_room_form.php'; ?>
</section>
