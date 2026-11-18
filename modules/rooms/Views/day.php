<?php
/**
 * The rooms of a company on a day: pick a site and a day, see every room's timeline, click a free slot to book it.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $sites @var string $sitePid
 * @var string $day @var string $today @var array{rooms: list<array<string, mixed>>, slots: list<string>, rows: array<int, list<array<string, mixed>>>} $grid @var bool $canManage
 */
use Blue\Modules\Rooms\RoomService;

$here = '/rooms';
$d = RoomService::ymd($day);
$prev = $d !== null ? $d->modify('-1 day')->format('Y-m-d') : $today;
$next = $d !== null ? $d->modify('+1 day')->format('Y-m-d') : $today;
$q = static fn (string $day): string => '/rooms?le=' . rawurlencode($lePid) . '&site=' . rawurlencode($sitePid) . '&date=' . $day;
?>
<?= $ui('page-header', ['title' => $t('rooms.title'), 'subtitle' => $t('rooms.subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('rooms.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/rooms?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<form method="get" action="<?= $e($url('/rooms')) ?>" class="card row room-filter">
  <input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <?= $ui('field', ['name' => 'site', 'type' => 'select', 'label' => $t('rooms.field.site'), 'value' => $sitePid, 'options' => ['' => $t('rooms.all_sites')] + array_column($sites, 'code', 'public_id')]) ?>
  <?= $ui('field', ['name' => 'date', 'type' => 'date', 'label' => $t('rooms.field.date'), 'value' => $day]) ?>
  <div class="row"><?= $ui('button', ['label' => $t('rooms.show'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'search']) ?></div>
</form>
<section class="card stack-sm" aria-labelledby="rm-d">
  <div class="row cal-nav">
    <?= $ui('button', ['label' => $t('rooms.prev_day'), 'href' => $url($q($prev)), 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-left']) ?>
    <h2 id="rm-d" class="card__title"><?= $e($date((string) $day, false)) ?><?= $day === $today ? ' · ' . $e($t('rooms.today')) : '' ?></h2>
    <?= $ui('button', ['label' => $t('rooms.next_day'), 'href' => $url($q($next)), 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-right']) ?>
  </div>
  <?php if ($grid['rooms'] === []): ?>
    <?= $ui('states', ['icon' => 'calendar-event', 'title' => $t('rooms.no_rooms')]) ?>
  <?php else: $slots = $grid['slots']; $tlRooms = $grid['rooms']; $tlRows = $grid['rows']; $tlLabel = $t('rooms.grid_label', ['d' => (string) $day]); $tlDay = $day; include __DIR__ . '/_timeline.php'; endif; ?>
</section>
