<?php
/**
 * Item fields. Needs $fill (values), $ferr (errors), $categories, $audOptions, $audIn, $kindLocked.
 * @var callable $e @var callable $t @var callable $ui @var array<string, mixed> $fill @var array<string, string> $ferr @var list<array<string, mixed>> $categories @var bool $kindLocked
 */
$fv = static fn (string $k): string => is_scalar($fill[$k] ?? null) ? (string) $fill[$k] : '';
$copts = ['' => $t('library.no_category')];
foreach ($categories as $c) {
    $copts[(string) $c['public_id']] = (string) $c['name'];
}
$audError = $ferr['audience'] ?? null;
$audId = 'lbs';
?>
<?= $ui('field', ['name' => 'title', 'label' => $t('library.field.title'), 'required' => true, 'value' => $fv('title'), 'full' => true, 'error' => $ferr['title'] ?? null]) ?>
<div class="grid grid--3">
  <?php if ($kindLocked): ?><input type="hidden" name="kind" value="<?= $e($fv('kind')) ?>"><?= $ui('field', ['name' => 'kind_shown', 'label' => $t('library.field.kind'), 'value' => $t('library.kind.' . $fv('kind')), 'readonly' => true, 'help' => $t('library.kind_locked_help')]) ?>
  <?php else: ?><?= $ui('field', ['name' => 'kind', 'type' => 'select', 'label' => $t('library.field.kind'), 'required' => true, 'value' => $fv('kind'), 'options' => ['' => $t('library.pick'), 'digital' => $t('library.kind.digital'), 'physical' => $t('library.kind.physical')], 'error' => $ferr['kind'] ?? null]) ?><?php endif; ?>
  <?= $ui('field', ['name' => 'category', 'type' => 'select', 'label' => $t('library.field.category'), 'value' => $fv('category'), 'options' => $copts, 'error' => $ferr['category'] ?? null]) ?>
  <?= $ui('field', ['name' => 'language', 'type' => 'select', 'label' => $t('library.field.language'), 'required' => true, 'value' => $fv('language'), 'options' => ['' => $t('library.pick'), 'th' => $t('library.lang.th'), 'en' => $t('library.lang.en'), 'other' => $t('library.lang.other')], 'error' => $ferr['language'] ?? null]) ?>
</div>
<div class="grid grid--3">
  <?= $ui('field', ['name' => 'author', 'label' => $t('library.field.author'), 'value' => $fv('author'), 'error' => $ferr['author'] ?? null]) ?>
  <?= $ui('field', ['name' => 'publisher', 'label' => $t('library.field.publisher'), 'value' => $fv('publisher'), 'error' => $ferr['publisher'] ?? null]) ?>
  <?= $ui('field', ['name' => 'pub_year', 'type' => 'number', 'label' => $t('library.field.pub_year'), 'value' => $fv('pub_year'), 'help' => $t('library.year_help'), 'error' => $ferr['pub_year'] ?? null]) ?>
</div>
<?= $ui('field', ['name' => 'description', 'type' => 'textarea', 'label' => $t('library.field.description'), 'value' => $fv('description'), 'error' => $ferr['description'] ?? null]) ?>
<?= $ui('field', ['name' => 'tags', 'label' => $t('library.field.tags'), 'value' => $fv('tags'), 'help' => $t('library.tags_help'), 'error' => $ferr['tags'] ?? null]) ?>
<div class="grid grid--2">
  <?= $ui('field', ['name' => 'rights_holder', 'label' => $t('library.field.rights_holder'), 'value' => $fv('rights_holder'), 'help' => $t('library.rights_help'), 'error' => $ferr['rights_holder'] ?? null]) ?>
  <?= $ui('field', ['name' => 'license', 'label' => $t('library.field.license'), 'value' => $fv('license'), 'help' => $t('library.license_help'), 'error' => $ferr['license'] ?? null]) ?>
</div>
<fieldset class="stack-sm lb-fieldset">
  <legend><?= $e($t('library.digital_options')) ?></legend>
  <label class="check"><input type="checkbox" name="read_only" value="1"<?= $fv('read_only') === '1' ? ' checked' : '' ?>> <?= $e($t('library.field.read_only')) ?></label>
  <p class="text-sm text-muted"><?= $e($t('library.read_only_help')) ?></p>
  <?php if (isset($ferr['read_only'])): ?><p class="field__error text-sm"><?= $e($ferr['read_only']) ?></p><?php endif; ?>
</fieldset>
<fieldset class="stack-sm lb-fieldset">
  <legend><?= $e($t('library.physical_options')) ?></legend>
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'loan_days', 'type' => 'number', 'label' => $t('library.field.loan_days'), 'value' => $fv('loan_days'), 'help' => $t('library.loan_days_help'), 'error' => $ferr['loan_days'] ?? null]) ?>
    <?= $ui('field', ['name' => 'pickup_days', 'type' => 'number', 'label' => $t('library.field.pickup_days'), 'value' => $fv('pickup_days'), 'help' => $t('library.pickup_days_help'), 'error' => $ferr['pickup_days'] ?? null]) ?>
  </div>
</fieldset>
<?php include __DIR__ . '/../../employees/Views/_audience.php'; ?>
