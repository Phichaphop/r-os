<?php
/**
 * Vehicles, drivers, saved places, vehicle types and the company policy (workplace admin).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $asset @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $vehicles @var list<array<string, mixed>> $drivers
 * @var list<array<string, mixed>> $places @var list<array<string, mixed>> $types @var list<array<string, mixed>> $sites @var array<string, mixed> $policy
 * @var array<string, mixed> $map @var string $today @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$here = '/vehicles/admin';
$canManage = true;
$fo = static fn (string $f, string $k, string $d = ''): string => $form === $f && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$fe = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$le = '<input type="hidden" name="le" value="' . $e($lePid) . '">';
$tone = ['available' => 'success', 'maintenance' => 'warning', 'inactive' => 'neutral'];
$dayNames = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
?>
<?= $ui('page-header', ['title' => $t('vehicles.admin_title'), 'subtitle' => $t('vehicles.admin_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('vehicles.pick_company')) ?>">
  <?php foreach ($companies as $cp => $code): ?><a href="<?= $e($url('/vehicles/admin?le=' . rawurlencode($cp))) ?>"<?= $cp === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>

<section class="card card--flush" aria-labelledby="va-vehicle">
  <div class="card__head"><h2 id="va-vehicle" class="card__title"><?= $e($t('vehicles.vehicles')) ?></h2></div>
  <?php if ($vehicles === []): ?><p class="card__pad text-sm text-muted"><?= $e($t('vehicles.no_vehicles')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="va-vehicle" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('vehicles.col.plate')) ?></th><th scope="col"><?= $e($t('vehicles.field.type')) ?></th><th scope="col"><?= $e($t('vehicles.field.site')) ?></th><th scope="col"><?= $e($t('vehicles.field.seats')) ?></th><th scope="col"><?= $e($t('vehicles.col.odometer')) ?></th><th scope="col"><?= $e($t('vehicles.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($vehicles as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('vehicles.col.plate')) ?>"><a href="<?= $e($url('/vehicles/v/' . $x['public_id'])) ?>"><?= $e($x['plate'] . ' · ' . $x['name']) ?></a></th>
          <td data-label="<?= $e($t('vehicles.field.type')) ?>"><?= $e((string) ($x['type_name'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('vehicles.field.site')) ?>"><?= $e((string) ($x['site_code'] ?? $t('vehicles.all_sites'))) ?></td>
          <td data-label="<?= $e($t('vehicles.field.seats')) ?>"><?= (int) $x['seats'] ?></td>
          <td data-label="<?= $e($t('vehicles.col.odometer')) ?>"><?= $e(number_format((int) $x['odometer_km'])) ?></td>
          <td data-label="<?= $e($t('vehicles.col.status')) ?>"><?= $ui('badge', ['tone' => $tone[$x['status']] ?? 'neutral', 'label' => $t('vehicles.vstatus.' . $x['status'])]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div class="card__pad stack-sm">
    <h3 class="text-md"><?= $e($t('vehicles.add_vehicle')) ?></h3>
    <form method="post" action="<?= $e($url('/vehicles/admin/vehicle')) ?>" class="stack-sm" novalidate><?= $csrf . $le ?>
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'plate', 'id' => 'f-nv-plate', 'label' => $t('vehicles.field.plate'), 'required' => true, 'value' => $fo('vehicle', 'plate'), 'error' => $fe('vehicle', 'plate')]) ?>
        <?= $ui('field', ['name' => 'name', 'id' => 'f-nv-name', 'label' => $t('vehicles.field.vehicle_name'), 'required' => true, 'value' => $fo('vehicle', 'name'), 'help' => $t('vehicles.vehicle_name_help'), 'error' => $fe('vehicle', 'name')]) ?>
        <?= $ui('field', ['name' => 'type', 'id' => 'f-nv-type', 'type' => 'select', 'label' => $t('vehicles.field.type'), 'value' => $fo('vehicle', 'type'), 'options' => ['' => '—'] + array_column($types, 'name', 'public_id'), 'error' => $fe('vehicle', 'type')]) ?>
      </div>
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'site', 'id' => 'f-nv-site', 'type' => 'select', 'label' => $t('vehicles.field.site'), 'value' => $fo('vehicle', 'site'), 'options' => ['' => $t('vehicles.all_sites')] + array_column($sites, 'code', 'public_id'), 'error' => $fe('vehicle', 'site')]) ?>
        <?= $ui('field', ['name' => 'seats', 'id' => 'f-nv-seats', 'type' => 'number', 'label' => $t('vehicles.field.seats'), 'required' => true, 'value' => $fo('vehicle', 'seats'), 'help' => $t('vehicles.seats_help'), 'error' => $fe('vehicle', 'seats')]) ?>
        <?= $ui('field', ['name' => 'odometer_km', 'id' => 'f-nv-odo', 'type' => 'number', 'label' => $t('vehicles.field.odometer'), 'required' => true, 'value' => $fo('vehicle', 'odometer_km'), 'error' => $fe('vehicle', 'odometer_km')]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('vehicles.add_vehicle'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
    </form>
  </div>
</section>

<section class="card card--flush" aria-labelledby="va-driver">
  <div class="card__head"><h2 id="va-driver" class="card__title"><?= $e($t('vehicles.drivers')) ?></h2></div>
  <?php if ($drivers === []): ?><p class="card__pad text-sm text-muted"><?= $e($t('vehicles.no_drivers')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="va-driver" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('vehicles.col.driver')) ?></th><th scope="col"><?= $e($t('vehicles.field.account')) ?></th><th scope="col"><?= $e($t('vehicles.field.licence_expiry')) ?></th><th scope="col"><?= $e($t('vehicles.col.work')) ?></th><th scope="col"><?= $e($t('vehicles.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($drivers as $x): $expired = (string) $x['licence_expiry'] < $today; ?>
        <tr><th scope="row" data-label="<?= $e($t('vehicles.col.driver')) ?>"><a href="<?= $e($url('/vehicles/d/' . $x['public_id'])) ?>"><?= $e((string) $x['name']) ?></a></th>
          <td data-label="<?= $e($t('vehicles.field.account')) ?>"><?= $e((string) ($x['username'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('vehicles.field.licence_expiry')) ?>"><?= $e($date((string) $x['licence_expiry'], false)) ?><?= $expired ? ' ' . $ui('badge', ['tone' => 'danger', 'label' => $t('vehicles.flag.expired')]) : '' ?></td>
          <td data-label="<?= $e($t('vehicles.col.work')) ?>"><?= $e(implode(' ', array_map(static fn (string $d): string => $t('vehicles.day.' . ($dayNames[(int) $d] ?? 'mon')), explode(',', (string) $x['work_days']))) . ' · ' . substr((string) $x['work_from'], 0, 5) . '–' . substr((string) $x['work_to'], 0, 5)) ?></td>
          <td data-label="<?= $e($t('vehicles.col.status')) ?>"><?= $ui('badge', ['tone' => (int) $x['active'] === 1 ? 'success' : 'neutral', 'label' => $t((int) $x['active'] === 1 ? 'vehicles.active' : 'vehicles.inactive')]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div class="card__pad stack-sm">
    <h3 class="text-md"><?= $e($t('vehicles.add_driver')) ?></h3>
    <p class="text-sm text-muted"><?= $e($t('vehicles.no_defaults')) ?></p>
    <?php $dv = $form === 'driver' ? $old : []; $dErr = $form === 'driver' ? $errors : []; $dAction = '/vehicles/admin/driver'; $df = 'nd'; $dLe = $lePid; $dEdit = false; include __DIR__ . '/_driver_form.php'; ?>
  </div>
</section>

<section class="card card--flush" aria-labelledby="va-place">
  <div class="card__head"><h2 id="va-place" class="card__title"><?= $e($t('vehicles.places')) ?></h2></div>
  <p class="card__pad text-sm text-muted"><?= $e($t('vehicles.places_help')) ?></p>
  <?php if ($places !== []): ?>
  <div class="table-wrap" role="region" aria-labelledby="va-place" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('vehicles.field.place_name')) ?></th><th scope="col"><?= $e($t('vehicles.field.address')) ?></th><th scope="col"><?= $e($t('vehicles.field.point')) ?></th><th scope="col"><?= $e($t('vehicles.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($places as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('vehicles.field.place_name')) ?>"><?= $e((string) $x['name']) ?></th>
          <td data-label="<?= $e($t('vehicles.field.address')) ?>"><?= $e((string) ($x['address'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('vehicles.field.point')) ?>"><?= $e($x['lat'] . ', ' . $x['lng']) ?></td>
          <td data-label="<?= $e($t('vehicles.col.status')) ?>"><form method="post" action="<?= $e($url('/vehicles/p/' . $x['public_id'] . '/' . ((int) $x['active'] === 1 ? 'hide' : 'show'))) ?>" class="row"><?= $csrf ?>
            <?= $ui('badge', ['tone' => (int) $x['active'] === 1 ? 'success' : 'neutral', 'label' => $t((int) $x['active'] === 1 ? 'vehicles.shown' : 'vehicles.hidden')]) ?>
            <?= $ui('button', ['label' => $t((int) $x['active'] === 1 ? 'vehicles.hide' : 'vehicles.show'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm']) ?></form></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div class="card__pad stack-sm">
    <h3 class="text-md"><?= $e($t('vehicles.add_place')) ?></h3>
    <form method="post" action="<?= $e($url('/vehicles/admin/place')) ?>" class="stack-sm" novalidate><?= $csrf . $le ?>
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'name', 'id' => 'f-np-name', 'label' => $t('vehicles.field.place_name'), 'required' => true, 'value' => $fo('place', 'name'), 'error' => $fe('place', 'place_name')]) ?>
        <?= $ui('field', ['name' => 'address', 'id' => 'f-np-address', 'label' => $t('vehicles.field.address'), 'value' => $fo('place', 'address'), 'error' => $fe('place', 'address')]) ?>
        <?= $ui('field', ['name' => 'point', 'id' => 'f-np-point', 'label' => $t('vehicles.field.point'), 'required' => true, 'value' => $fo('place', 'point'), 'placeholder' => '13.756300, 100.501800', 'help' => $t('vehicles.place_point_help'), 'inputmode' => 'decimal', 'error' => $fe('place', 'place_point')]) ?>
      </div>
      <?php if ($map['map_enabled']): ?>
      <div class="vh-map vh-map--small" data-vh-map="place" data-point="f-np-point" data-tiles="<?= $e((string) $map['tile_url']) ?>" data-attribution="<?= $e((string) $map['tile_attribution']) ?>"
        data-center="<?= $e($places !== [] ? $places[0]['lat'] . ',' . $places[0]['lng'] : '') ?>" data-zoom="<?= $places !== [] ? '11' : '6' ?>" role="application" aria-label="<?= $e($t('vehicles.place_map_label')) ?>"></div>
      <?php endif; ?>
      <div class="row"><?= $ui('button', ['label' => $t('vehicles.add_place'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'map-pin']) ?></div>
    </form>
  </div>
</section>

<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="va-type">
  <h2 id="va-type" class="card__title"><?= $e($t('vehicles.types')) ?></h2>
  <p class="text-sm"><?= $types === [] ? $e($t('vehicles.no_types')) : $e(implode(' · ', array_column($types, 'name'))) ?></p>
  <form method="post" action="<?= $e($url('/vehicles/admin/type')) ?>" class="stack-sm" novalidate><?= $csrf . $le ?>
    <?= $ui('field', ['name' => 'type_name', 'label' => $t('vehicles.field.type_name'), 'required' => true, 'value' => $fo('type', 'type_name'), 'error' => $fe('type', 'type_name')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('vehicles.add_type'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'plus']) ?></div>
  </form>
</section>
<section class="card stack-sm" aria-labelledby="va-policy">
  <h2 id="va-policy" class="card__title"><?= $e($t('vehicles.policy')) ?></h2>
  <form method="post" action="<?= $e($url('/vehicles/admin/policy')) ?>" class="stack-sm" novalidate><?= $csrf . $le ?>
    <label class="check"><input type="checkbox" name="confirm_required" value="1"<?= $policy['confirm_required'] ? ' checked' : '' ?>> <?= $e($t('vehicles.field.confirm_required')) ?></label>
    <?= $ui('field', ['name' => 'location_keep_days', 'type' => 'number', 'label' => $t('vehicles.field.location_keep_days'), 'value' => $fo('policy', 'location_keep_days', $policy['location_keep_days'] !== null ? (string) $policy['location_keep_days'] : ''), 'help' => $t('vehicles.keep_days_help'), 'error' => $fe('policy', 'location_keep_days')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('vehicles.save'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'circle-check']) ?></div>
  </form>
</section>
</div>
<?php if ($map['map_enabled']): ?>
<link rel="stylesheet" href="<?= $e($asset('ui/js/vendor/leaflet/leaflet.css')) ?>">
<script src="<?= $e($asset('ui/js/vendor/leaflet/leaflet.js')) ?>" defer></script>
<script src="<?= $e($asset('ui/js/vehicles.js')) ?>" defer></script>
<?php endif; ?>
