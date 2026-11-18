<?php
/**
 * Family fields (create + edit). @var callable $e @var callable $t @var callable $ui @var array<string, mixed> $old @var array<string, string> $errors
 */
use Blue\Modules\Fonts\FontsService;

$cats = [];
foreach (FontsService::CATEGORIES as $c) {
    $cats[$c] = $t('fonts.category.' . $c);
}
$fv = static fn (string $k): string => (string) ($old[$k] ?? '');
$err = static fn (string $k): ?string => isset($errors[$k]) ? $t($errors[$k]) : null;
?>
<div class="grid grid--2">
  <?= $ui('field', ['name' => 'name', 'label' => $t('fonts.field.name'), 'required' => true, 'value' => $fv('name'), 'error' => $err('name'), 'help' => $t('fonts.help.name')]) ?>
  <?= $ui('field', ['name' => 'category', 'type' => 'select', 'label' => $t('fonts.field.category'), 'required' => true, 'value' => $fv('category') ?: 'sans', 'options' => $cats, 'error' => $err('category')]) ?>
</div>
<?= $ui('field', ['name' => 'description', 'type' => 'textarea', 'label' => $t('fonts.field.description'), 'value' => $fv('description'), 'help' => $t('fonts.help.description')]) ?>
<div class="grid grid--2">
  <?= $ui('field', ['name' => 'dos', 'type' => 'textarea', 'label' => $t('fonts.field.dos'), 'value' => $fv('dos'), 'help' => $t('fonts.help.dos')]) ?>
  <?= $ui('field', ['name' => 'donts', 'type' => 'textarea', 'label' => $t('fonts.field.donts'), 'value' => $fv('donts'), 'help' => $t('fonts.help.donts')]) ?>
</div>
<fieldset class="stack-sm">
  <legend class="field__label"><?= $e($t('fonts.license.title')) ?></legend>
  <p class="text-sm text-muted"><?= $e($t('fonts.help.license')) ?></p>
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'license_name', 'label' => $t('fonts.field.license_name'), 'value' => $fv('license_name'), 'error' => $err('license_name'), 'help' => $t('fonts.help.license_name')]) ?>
    <?= $ui('field', ['name' => 'license_source', 'label' => $t('fonts.field.license_source'), 'value' => $fv('license_source'), 'error' => $err('license_source'), 'help' => $t('fonts.help.license_source')]) ?>
  </div>
  <?= $ui('field', ['name' => 'license_terms', 'type' => 'textarea', 'label' => $t('fonts.field.license_terms'), 'value' => $fv('license_terms'), 'help' => $t('fonts.help.license_terms')]) ?>
  <label class="check"><input type="checkbox" name="restricted" value="1"<?= $fv('restricted') === '1' ? ' checked' : '' ?>> <?= $e($t('fonts.field.restricted')) ?></label>
  <p class="text-sm text-muted"><?= $e($t('fonts.help.restricted')) ?></p>
</fieldset>
