<?php
/**
 * My meeting room bookings and the ones I am invited to, from today.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var list<array<string, mixed>> $rows @var bool $canManage
 */
$here = '/rooms/mine';
?>
<?= $ui('page-header', ['title' => $t('rooms.mine_title'), 'subtitle' => $t('rooms.mine_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<section class="card card--flush" aria-labelledby="rm-m">
  <div class="card__head"><h2 id="rm-m" class="card__title"><?= $e($t('rooms.mine_list')) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'calendar-event', 'title' => $t('rooms.mine_none'), 'action' => $ui('button', ['label' => $t('rooms.go_book'), 'href' => $url('/rooms'), 'variant' => 'primary', 'icon' => 'plus'])]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="rm-m" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('rooms.field.title')) ?></th><th scope="col"><?= $e($t('rooms.col.when')) ?></th><th scope="col"><?= $e($t('rooms.col.room')) ?></th><th scope="col"><?= $e($t('rooms.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $x): $status = (string) $x['status']; ?>
        <tr><th scope="row" data-label="<?= $e($t('rooms.field.title')) ?>"><a href="<?= $e($url('/rooms/b/' . $x['public_id'])) ?>"><?= $e((string) $x['title']) ?></a><?= $x['mine'] ? '' : ' ' . $ui('badge', ['tone' => 'info', 'label' => $t('rooms.invited')]) ?><?= $x['series_id'] !== null ? ' ' . $ui('badge', ['tone' => 'neutral', 'label' => $t('rooms.weekly')]) : '' ?></th>
          <td data-label="<?= $e($t('rooms.col.when')) ?>"><?= $e($date(substr((string) $x['starts_at'], 0, 10), false) . ' ' . substr((string) $x['starts_at'], 11, 5) . '–' . substr((string) $x['ends_at'], 11, 5)) ?></td>
          <td data-label="<?= $e($t('rooms.col.room')) ?>"><?= $e($x['le_code'] . ' · ' . $x['room_code'] . ' · ' . $x['room_name']) ?></td>
          <td data-label="<?= $e($t('rooms.col.status')) ?>"><?php include __DIR__ . '/_status.php'; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
