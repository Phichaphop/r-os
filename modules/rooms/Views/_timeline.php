<?php
/**
 * The 15-minute timeline of one day: one row per room (a booking spans its slots). Free slots link to the room's
 * booking form at that time; busy slots of other people show only "booked".
 * @var callable $e @var callable $t @var callable $url @var list<string> $slots @var list<array<string, mixed>> $tlRooms
 * @var array<int, list<array<string, mixed>>> $tlRows @var string $tlDay @var string $tlLabel
 */
$hours = [];
foreach ($slots as $i => $s) {
    if ($i === 0 || substr($s, 3, 2) === '00') {
        $hours[] = [$s, 0];
    }
    $hours[count($hours) - 1][1]++;
}
?>
<div class="table-wrap room-grid-wrap" role="region" aria-label="<?= $e($tlLabel) ?>" tabindex="0">
  <table class="room-grid">
    <caption class="sr-only"><?= $e($tlLabel) ?></caption>
    <colgroup><col class="room-grid__col-room"><col span="<?= (int) max(1, count($slots)) ?>" class="room-grid__col-slot"></colgroup>
    <thead><tr><th scope="col" class="room-grid__room"><?= $e($t('rooms.col.room')) ?></th>
      <?php foreach ($hours as [$label, $span]): ?><th scope="colgroup" colspan="<?= (int) $span ?>" class="room-grid__hour"><?= $e($label) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($tlRooms as $room): $cells = $tlRows[(int) $room['id']] ?? []; ?>
      <tr><th scope="row" class="room-grid__room"><a href="<?= $e($url('/rooms/r/' . $room['public_id'] . '?date=' . $tlDay)) ?>"><?= $e($room['code'] . ' · ' . $room['name']) ?></a>
        <span class="text-sm text-muted"><?= $e($t('rooms.seats', ['n' => (string) $room['capacity']])) ?><?= $room['site_code'] !== null ? ' · ' . $e((string) $room['site_code']) : '' ?></span></th>
      <?php foreach ($cells as $c): ?>
        <?php if ($c['kind'] === 'booked'): ?>
          <td colspan="<?= (int) $c['span'] ?>" class="room-slot room-slot--booked<?= $c['mine'] ? ' room-slot--mine' : '' ?>">
            <?php if ($c['pid'] !== null): ?><a href="<?= $e($url('/rooms/b/' . $c['pid'])) ?>" title="<?= $e($c['time'] . ' ' . $c['title'] . ' — ' . $c['booker']) ?>"><span class="room-slot__time"><?= $e((string) $c['time']) ?></span> <?= $e((string) $c['title']) ?><span class="sr-only"> — <?= $e((string) $c['booker']) ?></span></a>
            <?php else: ?><span class="room-slot__time"><?= $e((string) $c['time']) ?></span> <?= $e($t('rooms.busy')) ?><?php endif; ?></td>
        <?php elseif ($c['kind'] === 'free'): ?>
          <td class="room-slot room-slot--free"><a href="<?= $e($url('/rooms/r/' . $room['public_id'] . '?date=' . $tlDay . '&start=' . $c['time'])) ?>"><span class="sr-only"><?= $e($t('rooms.book_at', ['t' => (string) $c['time']])) ?></span></a></td>
        <?php else: ?>
          <td class="room-slot room-slot--closed"><span class="sr-only"><?= $e($t('rooms.not_open')) ?></span></td>
        <?php endif; ?>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="text-sm text-muted room-legend"><span class="room-key room-key--free"></span> <?= $e($t('rooms.legend.free')) ?> · <span class="room-key room-key--booked"></span> <?= $e($t('rooms.legend.booked')) ?> · <span class="room-key room-key--mine"></span> <?= $e($t('rooms.legend.mine')) ?> · <span class="room-key room-key--closed"></span> <?= $e($t('rooms.legend.closed')) ?></p>
