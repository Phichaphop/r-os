<?php
/**
 * Event fields. Needs $fill (values), $ferr (errors), $audOptions, $audIn.
 * @var callable $e @var callable $t @var callable $ui @var array<string, mixed> $fill @var array<string, string> $ferr
 */
$fv = static fn (string $k): string => is_scalar($fill[$k] ?? null) ? (string) $fill[$k] : '';
$audError = $ferr['audience'] ?? null;
$audId = 'evs';
?>
<?= $ui('field', ['name' => 'title', 'label' => $t('events.field.title'), 'required' => true, 'value' => $fv('title'), 'full' => true, 'error' => $ferr['title'] ?? null]) ?>
<div class="grid grid--2">
  <?= $ui('field', ['name' => 'kind', 'label' => $t('events.field.kind'), 'value' => $fv('kind'), 'placeholder' => $t('events.kind_example'), 'error' => $ferr['kind'] ?? null]) ?>
  <?= $ui('field', ['name' => 'venue', 'label' => $t('events.field.venue'), 'required' => true, 'value' => $fv('venue'), 'error' => $ferr['venue'] ?? null]) ?>
</div>
<?= $ui('field', ['name' => 'description', 'type' => 'textarea', 'label' => $t('events.field.description'), 'value' => $fv('description'), 'error' => $ferr['description'] ?? null]) ?>
<div class="grid grid--3">
  <?= $ui('field', ['name' => 'starts_at', 'type' => 'datetime-local', 'label' => $t('events.field.starts_at'), 'required' => true, 'value' => $fv('starts_at'), 'error' => $ferr['starts_at'] ?? null]) ?>
  <?= $ui('field', ['name' => 'ends_at', 'type' => 'datetime-local', 'label' => $t('events.field.ends_at'), 'required' => true, 'value' => $fv('ends_at'), 'help' => $t('events.window_help')]) ?>
  <?= $ui('field', ['name' => 'reg_until', 'type' => 'datetime-local', 'label' => $t('events.field.reg_until'), 'required' => true, 'value' => $fv('reg_until'), 'help' => $t('events.reg_until_help'), 'error' => $ferr['reg_until'] ?? null]) ?>
</div>
<div class="grid grid--3">
  <?= $ui('field', ['name' => 'quota', 'type' => 'number', 'label' => $t('events.field.quota'), 'value' => $fv('quota'), 'help' => $t('events.quota_help'), 'error' => $ferr['quota'] ?? null]) ?>
  <label class="check"><input type="checkbox" name="waitlist" value="1"<?= $fv('waitlist') === '1' ? ' checked' : '' ?>> <?= $e($t('events.field.waitlist')) ?></label>
  <label class="check"><input type="checkbox" name="walk_in" value="1"<?= $fv('walk_in') === '1' ? ' checked' : '' ?>> <?= $e($t('events.field.walk_in')) ?></label>
</div>
<div class="grid grid--2">
  <?= $ui('field', ['name' => 'option_label', 'label' => $t('events.field.option_label'), 'value' => $fv('option_label'), 'placeholder' => $t('events.option_example'), 'error' => $ferr['option_label'] ?? null]) ?>
  <?= $ui('field', ['name' => 'options', 'type' => 'textarea', 'label' => $t('events.field.options'), 'value' => $fv('options'), 'help' => $t('events.options_help'), 'error' => $ferr['options'] ?? null]) ?>
</div>
<?= $ui('field', ['name' => 'cost_note', 'label' => $t('events.field.cost_note'), 'value' => $fv('cost_note'), 'help' => $t('events.cost_help'), 'error' => $ferr['cost_note'] ?? null]) ?>
<?php include __DIR__ . '/../../employees/Views/_audience.php'; ?>
