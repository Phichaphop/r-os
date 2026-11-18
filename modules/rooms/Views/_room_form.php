<?php
/**
 * The room form (new room / change a room): name, site, seats, what is in it and its booking rules — all required,
 * no defaults (the company's own rules).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed> $rv @var array<string, string> $rErr @var string $rAction @var string $rf @var list<array<string, mixed>> $sites @var string $csrf @var string $rLe
 */
$rvs = static fn (string $k): string => is_scalar($rv[$k] ?? null) ? (string) $rv[$k] : '';
$days = array_map('strval', (array) ($rv['open_days'] ?? []));
$names = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
$id = static fn (string $k): string => 'f-' . $rf . '-' . $k;
?>
<form method="post" action="<?= $e($url($rAction)) ?>" class="stack-sm" novalidate><?= $csrf ?>
  <?php if ($rLe !== ''): ?><input type="hidden" name="le" value="<?= $e($rLe) ?>"><?php endif; ?>
  <div class="grid grid--3">
    <?= $ui('field', ['name' => 'code', 'id' => $id('code'), 'label' => $t('rooms.field.code'), 'required' => true, 'value' => $rvs('code'), 'error' => $rErr['code'] ?? null]) ?>
    <?= $ui('field', ['name' => 'name', 'id' => $id('name'), 'label' => $t('rooms.field.name'), 'required' => true, 'value' => $rvs('name'), 'error' => $rErr['name'] ?? null]) ?>
    <?= $ui('field', ['name' => 'site', 'id' => $id('site'), 'type' => 'select', 'label' => $t('rooms.field.site'), 'value' => $rvs('site'), 'options' => ['' => $t('rooms.all_sites')] + array_column($sites, 'code', 'public_id'), 'error' => $rErr['site'] ?? null]) ?>
  </div>
  <div class="grid grid--3">
    <?= $ui('field', ['name' => 'capacity', 'id' => $id('capacity'), 'type' => 'number', 'label' => $t('rooms.field.capacity'), 'required' => true, 'value' => $rvs('capacity'), 'error' => $rErr['capacity'] ?? null]) ?>
    <?= $ui('field', ['name' => 'location', 'id' => $id('location'), 'label' => $t('rooms.field.location'), 'value' => $rvs('location'), 'error' => $rErr['location'] ?? null]) ?>
    <?= $ui('field', ['name' => 'equipment', 'id' => $id('equipment'), 'label' => $t('rooms.field.equipment'), 'value' => $rvs('equipment'), 'help' => $t('rooms.equipment_help'), 'error' => $rErr['equipment'] ?? null]) ?>
  </div>
  <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('rooms.field.open_days')) ?></legend>
    <div class="row"><?php foreach ($names as $n => $k): ?><label class="check"><input type="checkbox" name="open_days[]" value="<?= $n ?>"<?= in_array((string) $n, $days, true) ? ' checked' : '' ?>> <?= $e($t('rooms.day.' . $k)) ?></label><?php endforeach; ?></div>
    <?php if (isset($rErr['open_days'])): ?><p class="field__error"><?= $e($t($rErr['open_days'])) ?></p><?php endif; ?>
  </fieldset>
  <div class="grid grid--3">
    <?= $ui('field', ['name' => 'open_from', 'id' => $id('open_from'), 'type' => 'time', 'label' => $t('rooms.field.open_from'), 'required' => true, 'value' => $rvs('open_from'), 'error' => $rErr['open_from'] ?? null]) ?>
    <?= $ui('field', ['name' => 'open_to', 'id' => $id('open_to'), 'type' => 'time', 'label' => $t('rooms.field.open_to'), 'required' => true, 'value' => $rvs('open_to'), 'help' => $t('rooms.step_help'), 'error' => $rErr['open_to'] ?? null]) ?>
    <?= $ui('field', ['name' => 'buffer_minutes', 'id' => $id('buffer'), 'type' => 'number', 'label' => $t('rooms.field.buffer'), 'required' => true, 'value' => $rvs('buffer_minutes'), 'help' => $t('rooms.buffer_help'), 'error' => $rErr['buffer_minutes'] ?? null]) ?>
  </div>
  <div class="grid grid--3">
    <?= $ui('field', ['name' => 'max_minutes', 'id' => $id('max'), 'type' => 'number', 'label' => $t('rooms.field.max_minutes'), 'required' => true, 'value' => $rvs('max_minutes'), 'help' => $t('rooms.step_help'), 'error' => $rErr['max_minutes'] ?? null]) ?>
    <?= $ui('field', ['name' => 'advance_days', 'id' => $id('advance'), 'type' => 'number', 'label' => $t('rooms.field.advance_days'), 'required' => true, 'value' => $rvs('advance_days'), 'error' => $rErr['advance_days'] ?? null]) ?>
    <?= $ui('field', ['name' => 'release_minutes', 'id' => $id('release'), 'type' => 'number', 'label' => $t('rooms.field.release_minutes'), 'value' => $rvs('release_minutes'), 'help' => $t('rooms.release_help'), 'error' => $rErr['release_minutes'] ?? null]) ?>
  </div>
  <label class="check"><input type="checkbox" name="checkin_required" value="1"<?= $rvs('checkin_required') !== '' ? ' checked' : '' ?>> <?= $e($t('rooms.field.checkin_required')) ?></label>
  <div class="row"><?= $ui('button', ['label' => $t('rooms.save_room'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
</form>
