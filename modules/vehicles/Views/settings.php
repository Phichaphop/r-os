<?php
/**
 * Map settings (system administrator): the map on or off, where the map pictures come from and their credit line,
 * and how distances are estimated (a straight line, or along roads with an OpenRouteService key — stored encrypted,
 * never shown again).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $s @var array<string, string> $errors @var array<string, mixed> $old
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $k, string $d): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$mapOn = $old !== [] ? !empty($old['map_enabled']) : (bool) $s['map_enabled'];
?>
<?= $ui('page-header', ['title' => $t('vehicles.settings_title'), 'subtitle' => $t('vehicles.settings_subtitle')]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<form method="post" action="<?= $e($url('/vehicles/settings')) ?>" class="stack" novalidate><?= $csrf ?>
<section class="card stack-sm" aria-labelledby="vs-m">
  <h2 id="vs-m" class="card__title"><?= $e($t('vehicles.settings_map')) ?></h2>
  <label class="check"><input type="checkbox" name="map_enabled" value="1"<?= $mapOn ? ' checked' : '' ?>> <?= $e($t('vehicles.field.map_enabled')) ?></label>
  <?= $ui('field', ['name' => 'tile_url', 'label' => $t('vehicles.field.tile_url'), 'required' => true, 'value' => $o('tile_url', (string) $s['tile_url']), 'help' => $t('vehicles.tile_url_help'), 'full' => true, 'error' => $errors['tile_url'] ?? null]) ?>
  <?= $ui('field', ['name' => 'tile_attribution', 'label' => $t('vehicles.field.tile_attribution'), 'required' => true, 'value' => $o('tile_attribution', (string) $s['tile_attribution']), 'help' => $t('vehicles.attribution_help'), 'full' => true, 'error' => $errors['tile_attribution'] ?? null]) ?>
  <p class="text-sm text-muted"><?= $e($t('vehicles.tile_policy_note')) ?></p>
</section>
<section class="card stack-sm" aria-labelledby="vs-r">
  <h2 id="vs-r" class="card__title"><?= $e($t('vehicles.settings_routing')) ?></h2>
  <?= $ui('field', ['name' => 'routing', 'type' => 'select', 'label' => $t('vehicles.field.routing'), 'value' => $o('routing', (string) $s['routing']),
      'options' => ['none' => $t('vehicles.routing.none'), 'ors' => $t('vehicles.routing.ors')], 'error' => $errors['routing'] ?? null]) ?>
  <?= $ui('field', ['name' => 'ors_key', 'type' => 'password', 'label' => $t('vehicles.field.ors_key'), 'value' => '', 'autocomplete' => 'off',
      'help' => $t($s['has_key'] ? 'vehicles.ors_key_set' : 'vehicles.ors_key_help'), 'error' => $errors['ors_key'] ?? null]) ?>
  <?php if ($s['has_key']): ?><label class="check"><input type="checkbox" name="clear_key" value="1"> <?= $e($t('vehicles.field.clear_key')) ?></label><?php endif; ?>
  <p class="text-sm text-muted"><?= $e($t('vehicles.routing_note')) ?></p>
</section>
<div class="row"><?= $ui('button', ['label' => $t('vehicles.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
</form>
