<?php
/**
 * A vehicle request (new, or a draft / returned one): when, why, who travels, from where to where — each end a pin on
 * the map, a pasted map link or a saved place — and the estimated distance with where it comes from.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var ?string $pid @var list<array<string, mixed>> $sites @var list<array<string, mixed>> $places
 * @var array<string, mixed> $map @var array<string, string> $errors @var array<string, mixed> $old @var string $now
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$here = $pid === null ? '/vehicles/new' : '';
$canManage = false;
$action = $pid === null ? '/vehicles/new' : '/vehicles/b/' . $pid . '/edit';
$mapOn = (bool) $map['map_enabled'];
$center = $places !== [] ? $places[0]['lat'] . ',' . $places[0]['lng'] : '';
?>
<?= $ui('page-header', ['title' => $t($pid === null ? 'vehicles.new_title' : 'vehicles.edit_title'), 'subtitle' => $t('vehicles.new_subtitle'),
    'crumbs' => [[$t('vehicles.title'), $url('/vehicles')], [$t($pid === null ? 'vehicles.tab.new' : 'vehicles.edit_title'), null]]]) ?>
<?php if ($pid === null) { include __DIR__ . '/_nav.php'; } ?>
<?php if ($pid === null && count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('vehicles.pick_company')) ?>">
  <?php foreach ($companies as $cp => $code): ?><a href="<?= $e($url('/vehicles/new?le=' . rawurlencode($cp))) ?>"<?= $cp === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<form method="post" action="<?= $e($url($action)) ?>" class="stack" novalidate><?= $csrf ?>
  <input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <section class="card stack-sm" aria-labelledby="vf-w">
    <h2 id="vf-w" class="card__title"><?= $e($t('vehicles.when_why')) ?></h2>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'site', 'type' => 'select', 'label' => $t('vehicles.field.site'), 'required' => true, 'value' => $o('site'), 'options' => array_column($sites, 'code', 'public_id'), 'help' => $t('vehicles.site_help'), 'error' => $errors['site'] ?? null]) ?>
      <?= $ui('field', ['name' => 'start', 'type' => 'datetime-local', 'label' => $t('vehicles.field.start'), 'required' => true, 'value' => $o('start'), 'min' => $now, 'error' => $errors['start'] ?? null]) ?>
      <?= $ui('field', ['name' => 'end', 'type' => 'datetime-local', 'label' => $t('vehicles.field.end'), 'required' => true, 'value' => $o('end'), 'min' => $now, 'help' => $t('vehicles.end_help'), 'error' => $errors['end'] ?? null]) ?>
    </div>
    <?= $ui('field', ['name' => 'purpose', 'label' => $t('vehicles.field.purpose'), 'required' => true, 'value' => $o('purpose'), 'full' => true, 'error' => $errors['purpose'] ?? null]) ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'passenger_count', 'type' => 'number', 'label' => $t('vehicles.field.passenger_count'), 'required' => true, 'value' => $o('passenger_count', '1'), 'min' => '1', 'help' => $t('vehicles.count_help'), 'error' => $errors['passenger_count'] ?? null]) ?>
      <?= $ui('field', ['name' => 'passengers', 'type' => 'textarea', 'label' => $t('vehicles.field.passengers'), 'value' => $o('passengers'), 'help' => $t('vehicles.passengers_help'), 'error' => $errors['passengers'] ?? null]) ?>
    </div>
  </section>

  <section class="card stack-sm" aria-labelledby="vf-r">
    <h2 id="vf-r" class="card__title"><?= $e($t('vehicles.route')) ?></h2>
    <?php if ($mapOn): ?>
      <p class="text-sm text-muted"><?= $e($t('vehicles.map_help')) ?></p>
      <fieldset class="vh-pick"><legend class="field__label"><?= $e($t('vehicles.pick_for')) ?></legend>
        <label class="check"><input type="radio" name="vh_pick" value="from" checked> <span class="vh-key vh-key--from" aria-hidden="true"></span> <?= $e($t('vehicles.from')) ?></label>
        <label class="check"><input type="radio" name="vh_pick" value="to"> <span class="vh-key vh-key--to" aria-hidden="true"></span> <?= $e($t('vehicles.to')) ?></label>
      </fieldset>
      <div class="vh-map" data-vh-map="pick" data-tiles="<?= $e((string) $map['tile_url']) ?>" data-attribution="<?= $e((string) $map['tile_attribution']) ?>" data-center="<?= $e($center) ?>" data-zoom="<?= $center !== '' ? '11' : '6' ?>"
        data-l-a="<?= $e($t('vehicles.pin_a')) ?>" data-l-b="<?= $e($t('vehicles.pin_b')) ?>" data-l-link-ok="<?= $e($t('vehicles.link_ok')) ?>" data-l-link-nopoint="<?= $e($t('vehicles.link_nopoint')) ?>" data-l-link-bad="<?= $e($t('vehicles.error.link')) ?>"
        role="application" aria-label="<?= $e($t('vehicles.map_label')) ?>"></div>
    <?php else: ?>
      <?= $ui('alert', ['tone' => 'info', 'title' => $t('vehicles.map_off'), 'text' => $t('vehicles.map_off_help')]) ?>
    <?php endif; ?>
    <div class="vh-ends">
      <?php foreach (['from', 'to'] as $side): ?>
      <fieldset class="vh-end stack-sm"><legend><span class="vh-key vh-key--<?= $side ?>" aria-hidden="true"></span> <?= $e($t('vehicles.' . $side)) ?></legend>
        <?php if ($places !== []): ?>
        <div class="field">
          <label class="field__label" for="f-<?= $side ?>-place"><?= $e($t('vehicles.field.place')) ?> <span class="field__req"><?= $e($t('form.optional_tag')) ?></span></label>
          <select class="select" id="f-<?= $side ?>-place" name="<?= $side ?>_place"<?= isset($errors[$side . '_place']) ? ' aria-invalid="true"' : '' ?>>
            <option value=""><?= $e($t('vehicles.place_own')) ?></option>
            <?php foreach ($places as $p): ?><option value="<?= $e((string) $p['public_id']) ?>" data-point="<?= $e($p['lat'] . ', ' . $p['lng']) ?>"<?= $o($side . '_place') === $p['public_id'] ? ' selected' : '' ?>><?= $e((string) $p['name']) ?></option><?php endforeach; ?>
          </select>
          <?php if (isset($errors[$side . '_place'])): ?><div class="field__error"><?= $e($errors[$side . '_place']) ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="stack-sm" data-vh-own="<?= $side ?>">
          <?= $ui('field', ['name' => $side . '_name', 'id' => 'f-' . $side . '-name', 'label' => $t('vehicles.field.place_name'), 'required' => true, 'value' => $o($side . '_name'), 'error' => $errors[$side . '_name'] ?? null]) ?>
          <?= $ui('field', ['name' => $side . '_point', 'id' => 'f-' . $side . '-point', 'label' => $t('vehicles.field.point'), 'value' => $o($side . '_point'), 'placeholder' => '13.756300, 100.501800', 'help' => $t('vehicles.point_help'), 'inputmode' => 'decimal', 'error' => $errors[$side . '_point'] ?? null]) ?>
          <?= $ui('field', ['name' => $side . '_link', 'id' => 'f-' . $side . '-link', 'type' => 'url', 'label' => $t('vehicles.field.link'), 'value' => $o($side . '_link'), 'placeholder' => 'https://maps.google.com/…', 'help' => $t('vehicles.link_help'), 'error' => $errors[$side . '_link'] ?? null]) ?>
          <p class="text-sm text-muted" data-vh-link-hint="<?= $side ?>" role="status" aria-live="polite"></p>
        </div>
      </fieldset>
      <?php endforeach; ?>
    </div>
    <label class="check"><input type="checkbox" name="round_trip" value="1"<?= $o('round_trip') !== '' ? ' checked' : '' ?>> <?= $e($t('vehicles.field.round_trip')) ?></label>
    <p><?= $e($t('vehicles.estimate_now')) ?> <output class="vh-estimate" data-vh-km data-template="<?= $e($t('vehicles.km_straight', ['km' => '{km}'])) ?>" data-empty="<?= $e($t('vehicles.estimate_need_points')) ?>"><?= $e($t('vehicles.estimate_need_points')) ?></output></p>
    <p class="text-sm text-muted"><?= $e($t('vehicles.estimate_limits')) ?></p>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'est_km_manual', 'label' => $t('vehicles.field.est_km_manual'), 'value' => $o('est_km_manual'), 'inputmode' => 'decimal', 'help' => $t('vehicles.manual_help'), 'error' => $errors['est_km_manual'] ?? null]) ?>
      <?= $ui('field', ['name' => 'note', 'type' => 'textarea', 'label' => $t('vehicles.field.note'), 'value' => $o('note'), 'error' => $errors['note'] ?? null]) ?>
    </div>
  </section>
  <div class="row">
    <?= $ui('button', ['label' => $t('vehicles.send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send', 'attrs' => ['name' => 'go', 'value' => 'submit']]) ?>
    <?= $ui('button', ['label' => $t('vehicles.save_draft'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'pencil', 'attrs' => ['name' => 'go', 'value' => 'draft']]) ?>
  </div>
</form>
<?php if ($mapOn): ?>
<link rel="stylesheet" href="<?= $e($asset('ui/js/vendor/leaflet/leaflet.css')) ?>">
<script src="<?= $e($asset('ui/js/vendor/leaflet/leaflet.js')) ?>" defer></script>
<script src="<?= $e($asset('ui/js/vehicles.js')) ?>" defer></script>
<?php endif; ?>
