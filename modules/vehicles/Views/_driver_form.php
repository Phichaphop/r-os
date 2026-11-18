<?php
/**
 * Driver form (new / change): name, phone, the account that logs trips, site, licence class and expiry (no licence
 * number), working hours and days — the company's own, no defaults.
 * @var callable $e @var callable $t @var callable $ui @var callable $url
 * @var array<string, mixed> $dv @var array<string, string> $dErr @var string $dAction @var string $df @var list<array<string, mixed>> $sites @var string $csrf @var string $dLe @var bool $dEdit
 */
$dvs = static fn (string $k): string => is_scalar($dv[$k] ?? null) ? (string) $dv[$k] : '';
$days = array_map('strval', (array) ($dv['work_days'] ?? []));
$names = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
$id = static fn (string $k): string => 'f-' . $df . '-' . $k;
?>
<form method="post" action="<?= $e($url($dAction)) ?>" class="stack-sm" novalidate><?= $csrf ?>
  <?php if ($dLe !== ''): ?><input type="hidden" name="le" value="<?= $e($dLe) ?>"><?php endif; ?>
  <div class="grid grid--3">
    <?= $ui('field', ['name' => 'name', 'id' => $id('name'), 'label' => $t('vehicles.field.driver_name'), 'required' => true, 'value' => $dvs('name'), 'error' => $dErr['name'] ?? null]) ?>
    <?= $ui('field', ['name' => 'phone', 'id' => $id('phone'), 'type' => 'tel', 'label' => $t('vehicles.field.phone'), 'value' => $dvs('phone'), 'error' => $dErr['phone'] ?? null]) ?>
    <?= $ui('field', ['name' => 'account', 'id' => $id('account'), 'label' => $t('vehicles.field.account'), 'value' => $dvs('account'), 'help' => $t('vehicles.account_help'), 'error' => $dErr['account'] ?? null]) ?>
  </div>
  <div class="grid grid--3">
    <?= $ui('field', ['name' => 'site', 'id' => $id('site'), 'type' => 'select', 'label' => $t('vehicles.field.site'), 'value' => $dvs('site'), 'options' => ['' => $t('vehicles.all_sites')] + array_column($sites, 'code', 'public_id'), 'error' => $dErr['site'] ?? null]) ?>
    <?= $ui('field', ['name' => 'licence_class', 'id' => $id('licence_class'), 'label' => $t('vehicles.field.licence_class'), 'value' => $dvs('licence_class'), 'help' => $t('vehicles.licence_help'), 'error' => $dErr['licence_class'] ?? null]) ?>
    <?= $ui('field', ['name' => 'licence_expiry', 'id' => $id('licence_expiry'), 'type' => 'date', 'label' => $t('vehicles.field.licence_expiry'), 'required' => true, 'value' => $dvs('licence_expiry'), 'error' => $dErr['licence_expiry'] ?? null]) ?>
  </div>
  <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('vehicles.field.work_days')) ?></legend>
    <div class="row"><?php foreach ($names as $n => $k): ?><label class="check"><input type="checkbox" name="work_days[]" value="<?= $n ?>"<?= in_array((string) $n, $days, true) ? ' checked' : '' ?>> <?= $e($t('vehicles.day.' . $k)) ?></label><?php endforeach; ?></div>
    <?php if (isset($dErr['work_days'])): ?><p class="field__error"><?= $e($dErr['work_days']) ?></p><?php endif; ?>
  </fieldset>
  <div class="grid grid--3">
    <?= $ui('field', ['name' => 'work_from', 'id' => $id('work_from'), 'type' => 'time', 'label' => $t('vehicles.field.work_from'), 'required' => true, 'value' => $dvs('work_from'), 'error' => $dErr['work_from'] ?? null]) ?>
    <?= $ui('field', ['name' => 'work_to', 'id' => $id('work_to'), 'type' => 'time', 'label' => $t('vehicles.field.work_to'), 'required' => true, 'value' => $dvs('work_to'), 'error' => $dErr['work_to'] ?? null]) ?>
  </div>
  <?php if ($dEdit): ?><label class="check"><input type="checkbox" name="active" value="1"<?= $dvs('active') !== '' && $dvs('active') !== '0' ? ' checked' : '' ?>> <?= $e($t('vehicles.field.driver_active')) ?></label><?php endif; ?>
  <div class="row"><?= $ui('button', ['label' => $t('vehicles.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
</form>
