<?php
/**
 * One vehicle booking: the request (when, why, who, the route on the map and the estimate with its source), the
 * vehicle and driver, the trip log, the actions the person may take now, and the history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $asset @var array<string, mixed> $v
 * @var array<string, mixed> $b @var ?array{vehicles: list<array<string, mixed>>, drivers: list<array<string, mixed>>} $options @var array<string, mixed> $map @var string $nowAt
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $f, string $k, string $d = ''): string => $form === $f && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$err = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$base = '/vehicles/b/' . $b['public_id'];
$st = (string) $b['status'];
$hasPoint = $b['from_lat'] !== null || $b['to_lat'] !== null;
$mapOn = (bool) $map['map_enabled'] && $hasPoint;
$sameDay = substr((string) $b['ends_at'], 0, 10) === substr((string) $b['starts_at'], 0, 10);
$money = static fn (mixed $x): string => $x === null ? '—' : number_format((float) $x, 2);
$link = static function (?string $href) use ($e, $t, $ui): string {
    return $href === null || $href === '' ? '' : ' · <a href="' . $e($href) . '" target="_blank" rel="noopener noreferrer">' . $ui('icon', ['name' => 'external-link', 'size' => 'sm']) . ' ' . $e($t('vehicles.open_link')) . '</a>';
};
?>
<?= $ui('page-header', ['title' => $t('vehicles.booking_title', ['ref' => (string) $b['ref']]), 'subtitle' => (string) $b['purpose'],
    'crumbs' => [[$t('vehicles.title'), $url('/vehicles')], [(string) $b['ref'], null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<?php if ($b['location_purged_at'] !== null): ?><?= $ui('alert', ['tone' => 'info', 'title' => $t('vehicles.purged')]) ?><?php endif; ?>
<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="vb-d">
  <h2 id="vb-d" class="card__title"><?= $e($t('vehicles.request')) ?> <?php include __DIR__ . '/_status.php'; ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('vehicles.col.when')) ?></dt><dd><?= $e($date(substr((string) $b['starts_at'], 0, 10), false) . ' ' . substr((string) $b['starts_at'], 11, 5) . ' – ' . ($sameDay ? '' : $date(substr((string) $b['ends_at'], 0, 10), false) . ' ') . substr((string) $b['ends_at'], 11, 5)) ?></dd></div>
    <div><dt><?= $e($t('vehicles.col.requester')) ?></dt><dd><?= $e((string) $b['requester_name']) ?></dd></div>
    <div><dt><?= $e($t('vehicles.field.site')) ?></dt><dd><?= $e($b['le_code'] . ' · ' . $b['site_code']) ?></dd></div>
    <div><dt><?= $e($t('vehicles.from')) ?></dt><dd><span class="vh-key vh-key--from" aria-hidden="true"></span> <?= $e((string) $b['from_name']) ?><?= $link($b['from_link']) ?></dd></div>
    <div><dt><?= $e($t('vehicles.to')) ?></dt><dd><span class="vh-key vh-key--to" aria-hidden="true"></span> <?= $e((string) $b['to_name']) ?><?= $link($b['to_link']) ?><?= (int) $b['round_trip'] === 1 ? ' · ' . $e($t('vehicles.round_trip_short')) : '' ?></dd></div>
    <div><dt><?= $e($t('vehicles.col.estimate')) ?></dt><dd>
      <?php if ($b['est_km'] === null): ?><?= $e($t('vehicles.no_estimate')) ?>
      <?php else: ?><strong><?= $e($t('vehicles.km_value', ['km' => (string) $b['est_km']])) ?></strong><?= $b['est_minutes'] !== null ? ' · ' . $e($t('vehicles.minutes_value', ['m' => (string) $b['est_minutes']])) : '' ?>
        <br><span class="text-sm text-muted"><?= $e($t('vehicles.source_line.' . $b['est_source'], ['km' => (string) ($b['straight_km'] ?? '')])) ?></span><?php endif; ?></dd></div>
    <div><dt><?= $e($t('vehicles.field.passenger_count')) ?></dt><dd><?= (int) $b['passenger_count'] ?><?= $b['passengers'] !== [] ? ' — ' . $e(implode(', ', array_map(static fn (array $p): string => (string) $p['name'], $b['passengers']))) : '' ?></dd></div>
    <?php if ($b['note'] !== null): ?><div><dt><?= $e($t('vehicles.field.note')) ?></dt><dd><?= $e((string) $b['note']) ?></dd></div><?php endif; ?>
    <?php if ($b['cancel_reason'] !== null): ?><div><dt><?= $e($t('vehicles.field.reason')) ?></dt><dd><?= $e((string) $b['cancel_reason']) ?></dd></div><?php endif; ?>
  </dl>
</section>
<section class="card stack-sm" aria-labelledby="vb-a">
  <h2 id="vb-a" class="card__title"><?= $e($t('vehicles.assignment')) ?></h2>
  <?php if ($b['vehicle_id'] === null): ?><p class="text-muted"><?= $e($t($st === 'approved' ? 'vehicles.waiting_assign' : 'vehicles.not_assigned')) ?></p><?php else: ?>
  <dl class="facts">
    <div><dt><?= $e($t('vehicles.col.vehicle')) ?></dt><dd><?= $e($b['plate'] . ' · ' . $b['vehicle_name']) ?></dd></div>
    <div><dt><?= $e($t('vehicles.col.driver')) ?></dt><dd><?= (int) $b['self_drive'] === 1 ? $e($t('vehicles.self_drive')) : $e((string) $b['driver_name']) . ($b['driver_phone'] !== null ? ' · <a href="tel:' . $e((string) $b['driver_phone']) . '">' . $e((string) $b['driver_phone']) . '</a>' : '') ?></dd></div>
  </dl>
  <?php endif; ?>
  <?php if ($b['trip_started_at'] !== null): ?>
  <h3 class="text-md"><?= $e($t('vehicles.trip')) ?></h3>
  <dl class="facts">
    <div><dt><?= $e($t('vehicles.col.trip_time')) ?></dt><dd><?= $e($date(substr((string) $b['trip_started_at'], 0, 10), false) . ' ' . substr((string) $b['trip_started_at'], 11, 5) . ' – ' . ($b['trip_ended_at'] !== null ? (substr((string) $b['trip_ended_at'], 0, 10) === substr((string) $b['trip_started_at'], 0, 10) ? '' : $date(substr((string) $b['trip_ended_at'], 0, 10), false) . ' ') . substr((string) $b['trip_ended_at'], 11, 5) : $t('vehicles.under_way'))) ?></dd></div>
    <div><dt><?= $e($t('vehicles.col.odometer')) ?></dt><dd><?= $e(number_format((int) $b['odo_start']) . ($b['odo_end'] !== null ? ' → ' . number_format((int) $b['odo_end']) . ' · ' . $t('vehicles.km_value', ['km' => (string) ((int) $b['odo_end'] - (int) $b['odo_start'])]) : '')) ?></dd></div>
    <?php if ($b['odo_end'] !== null): ?>
    <div><dt><?= $e($t('vehicles.col.fuel')) ?></dt><dd><?= $e(($b['fuel_litres'] !== null ? $t('vehicles.litres_value', ['l' => (string) $b['fuel_litres']]) . ' · ' : '') . $money($b['fuel_cost'])) ?></dd></div>
    <div><dt><?= $e($t('vehicles.col.other')) ?></dt><dd><?= $e($money($b['other_cost'])) ?></dd></div>
    <?php if ($b['incident'] !== null): ?><div><dt><?= $e($t('vehicles.field.incident')) ?></dt><dd><?= $e((string) $b['incident']) ?></dd></div><?php endif; ?>
    <?php if ($b['policy']['confirm_required']): ?><div><dt><?= $e($t('vehicles.col.confirmed')) ?></dt><dd><?= $b['confirmed_at'] !== null ? $e($date((string) $b['confirmed_at'])) : $e($t('vehicles.not_confirmed')) ?></dd></div><?php endif; ?>
    <?php endif; ?>
  </dl>
  <?php endif; ?>
</section>
</div>
<?php if ($mapOn): ?>
<section class="card stack-sm" aria-labelledby="vb-m">
  <h2 id="vb-m" class="card__title"><?= $e($t('vehicles.route')) ?></h2>
  <div class="vh-map vh-map--small" data-vh-map="view" data-tiles="<?= $e((string) $map['tile_url']) ?>" data-attribution="<?= $e((string) $map['tile_attribution']) ?>"
    data-from="<?= $e($b['from_lat'] !== null ? $b['from_lat'] . ',' . $b['from_lng'] : '') ?>" data-to="<?= $e($b['to_lat'] !== null ? $b['to_lat'] . ',' . $b['to_lng'] : '') ?>"
    data-l-a="<?= $e($t('vehicles.pin_a')) ?>" data-l-b="<?= $e($t('vehicles.pin_b')) ?>" role="region" aria-label="<?= $e($t('vehicles.map_view_label', ['from' => (string) $b['from_name'], 'to' => (string) $b['to_name']])) ?>"></div>
</section>
<?php endif; ?>

<?php if ($b['can_edit']): ?>
<section class="card stack-sm" aria-labelledby="vb-e">
  <h2 id="vb-e" class="card__title"><?= $e($t('vehicles.draft_actions')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('vehicles.draft_help')) ?></p>
  <form method="post" action="<?= $e($url($base . '/submit')) ?>" class="row"><?= $csrf ?>
    <?= $ui('button', ['label' => $t('vehicles.send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?>
    <?= $ui('button', ['label' => $t('vehicles.edit'), 'href' => $url($base . '/edit'), 'variant' => 'secondary', 'icon' => 'pencil']) ?>
  </form>
</section>
<?php endif; ?>

<?php if ($b['can_assign'] && $options !== null): ?>
<section class="card stack-sm" aria-labelledby="vb-as">
  <h2 id="vb-as" class="card__title"><?= $e($t($st === 'assigned' ? 'vehicles.reassign' : 'vehicles.assign')) ?></h2>
  <form method="post" action="<?= $e($url($base . '/assign')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <div class="grid grid--2">
      <?php
      $vOpts = ['' => $t('vehicles.pick_vehicle')];
      foreach ($options['vehicles'] as $x) {
          $vOpts[(string) $x['public_id']] = $x['plate'] . ' · ' . $x['name'] . ' (' . $t('vehicles.seats_n', ['n' => (string) $x['seats']]) . ')' . ($x['flag'] !== 'ok' ? ' — ' . $t('vehicles.flag.' . $x['flag']) : '');
      }
      $dOpts = ['' => $t('vehicles.pick_driver')];
      foreach ($options['drivers'] as $x) {
          $dOpts[(string) $x['public_id']] = $x['name'] . ' (' . substr((string) $x['work_from'], 0, 5) . '–' . substr((string) $x['work_to'], 0, 5) . ')' . ($x['flag'] !== 'ok' ? ' — ' . $t('vehicles.flag.' . $x['flag']) : '');
      }
      ?>
      <?= $ui('field', ['name' => 'vehicle', 'type' => 'select', 'label' => $t('vehicles.col.vehicle'), 'required' => true, 'value' => $o('assign', 'vehicle', (string) ($b['vehicle_pid'] ?? '')), 'options' => $vOpts, 'error' => $err('assign', 'vehicle')]) ?>
      <?= $ui('field', ['name' => 'driver', 'type' => 'select', 'label' => $t('vehicles.col.driver'), 'value' => $o('assign', 'driver', (string) ($b['driver_pid'] ?? '')), 'options' => $dOpts, 'help' => $t('vehicles.driver_help'), 'error' => $err('assign', 'driver')]) ?>
    </div>
    <label class="check"><input type="checkbox" name="self_drive" value="1"<?= $o('assign', 'self_drive', (int) $b['self_drive'] === 1 ? '1' : '') !== '' ? ' checked' : '' ?>> <?= $e($t('vehicles.field.self_drive')) ?></label>
    <label class="check"><input type="checkbox" name="outside_hours" value="1"<?= $o('assign', 'outside_hours') !== '' ? ' checked' : '' ?>> <?= $e($t('vehicles.field.outside_hours')) ?></label>
    <?= $ui('field', ['name' => 'reason', 'id' => 'f-assign-reason', 'label' => $t('vehicles.field.outside_reason'), 'value' => $o('assign', 'reason'), 'error' => $err('assign', 'reason')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t($st === 'assigned' ? 'vehicles.reassign' : 'vehicles.assign'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'car']) ?></div>
  </form>
</section>
<?php endif; ?>

<?php if ($b['can_start']): ?>
<section class="card stack-sm" aria-labelledby="vb-s">
  <h2 id="vb-s" class="card__title"><?= $e($t('vehicles.start_trip')) ?></h2>
  <form method="post" action="<?= $e($url($base . '/start')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'odo_start', 'type' => 'number', 'label' => $t('vehicles.field.odo_start'), 'required' => true, 'value' => $o('start', 'odo_start', (string) $b['odometer_km']), 'help' => $t('vehicles.odo_last', ['n' => number_format((int) $b['odometer_km'])]), 'error' => $err('start', 'odo_start')]) ?>
      <?= $ui('field', ['name' => 'started_at', 'type' => 'datetime-local', 'label' => $t('vehicles.field.started_at'), 'required' => true, 'value' => $o('start', 'started_at', $nowAt), 'error' => $err('start', 'started_at')]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('vehicles.start_trip'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'arrow-right']) ?></div>
  </form>
</section>
<?php endif; ?>

<?php if ($b['can_finish']): ?>
<section class="card stack-sm" aria-labelledby="vb-f">
  <h2 id="vb-f" class="card__title"><?= $e($t('vehicles.finish_trip')) ?></h2>
  <form method="post" action="<?= $e($url($base . '/finish')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'odo_end', 'type' => 'number', 'label' => $t('vehicles.field.odo_end'), 'required' => true, 'value' => $o('finish', 'odo_end'), 'help' => $t('vehicles.odo_started', ['n' => number_format((int) $b['odo_start'])]), 'error' => $err('finish', 'odo_end')]) ?>
      <?= $ui('field', ['name' => 'ended_at', 'type' => 'datetime-local', 'label' => $t('vehicles.field.ended_at'), 'required' => true, 'value' => $o('finish', 'ended_at', $nowAt), 'error' => $err('finish', 'ended_at')]) ?>
      <?= $ui('field', ['name' => 'fuel_litres', 'label' => $t('vehicles.field.fuel_litres'), 'value' => $o('finish', 'fuel_litres'), 'inputmode' => 'decimal', 'error' => $err('finish', 'fuel_litres')]) ?>
    </div>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'fuel_cost', 'type' => 'money', 'label' => $t('vehicles.field.fuel_cost'), 'value' => $o('finish', 'fuel_cost'), 'error' => $err('finish', 'fuel_cost')]) ?>
      <?= $ui('field', ['name' => 'other_cost', 'type' => 'money', 'label' => $t('vehicles.field.other_cost'), 'value' => $o('finish', 'other_cost'), 'help' => $t('vehicles.other_cost_help'), 'error' => $err('finish', 'other_cost')]) ?>
    </div>
    <?= $ui('field', ['name' => 'incident', 'type' => 'textarea', 'label' => $t('vehicles.field.incident'), 'value' => $o('finish', 'incident'), 'help' => $t('vehicles.incident_help'), 'error' => $err('finish', 'incident')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('vehicles.finish_trip'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
</section>
<?php endif; ?>

<?php if ($b['can_confirm']): ?>
<form method="post" action="<?= $e($url($base . '/confirm')) ?>" class="card row"><?= $csrf ?><p><?= $e($t('vehicles.confirm_help')) ?></p>
  <?= $ui('button', ['label' => $t('vehicles.confirm'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'checks']) ?></form>
<?php endif; ?>

<?php if ($b['can_no_show'] || $b['can_cancel']): ?>
<div class="grid grid--2">
<?php if ($b['can_no_show']): ?>
<section class="card stack-sm" aria-labelledby="vb-n">
  <h2 id="vb-n" class="card__title"><?= $e($t('vehicles.no_show')) ?></h2>
  <form method="post" action="<?= $e($url($base . '/noshow')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'reason', 'id' => 'f-noshow-reason', 'label' => $t('vehicles.field.reason'), 'value' => $o('noshow', 'reason'), 'error' => $err('noshow', 'reason')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('vehicles.no_show'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'circle-x']) ?></div>
  </form>
</section>
<?php endif; ?>
<?php if ($b['can_cancel']): ?>
<section class="card stack-sm" aria-labelledby="vb-c">
  <h2 id="vb-c" class="card__title"><?= $e($t('vehicles.cancel')) ?></h2>
  <form method="post" action="<?= $e($url($base . '/cancel')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'reason', 'id' => 'f-cancel-reason', 'label' => $t('vehicles.field.reason'), 'required' => !$b['mine'], 'value' => $o('cancel', 'reason'), 'help' => $t($b['mine'] ? 'vehicles.cancel_help_mine' : 'vehicles.cancel_help_admin'), 'error' => $err('cancel', 'reason')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('vehicles.cancel'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'x']) ?></div>
  </form>
</section>
<?php endif; ?>
</div>
<?php endif; ?>

<section class="card stack-sm" aria-labelledby="vb-h">
  <h2 id="vb-h" class="card__title"><?= $e($t('vehicles.history')) ?></h2>
  <ul class="plain-list stack-sm"><?php foreach ($b['events'] as $ev): ?><li><?= $e($t('vehicles.event.' . $ev['event'])) ?> · <?= $e((string) ($ev['actor'] ?? $t('vehicles.system'))) ?> <span class="text-sm text-muted"><?= $e($date((string) $ev['created_at'])) ?></span><?= $ev['note'] !== null ? ' — ' . $e((string) $ev['note']) : '' ?></li><?php endforeach; ?></ul>
</section>
<?php if ($mapOn): ?>
<link rel="stylesheet" href="<?= $e($asset('ui/js/vendor/leaflet/leaflet.css')) ?>">
<script src="<?= $e($asset('ui/js/vendor/leaflet/leaflet.js')) ?>" defer></script>
<script src="<?= $e($asset('ui/js/vehicles.js')) ?>" defer></script>
<?php endif; ?>
