<?php
/**
 * Master fields of an asset (new and edit). Set before including: $fv (values), $ferr (errors), $isNew.
 * @var callable $e @var callable $t @var callable $ui @var array<string, mixed> $fv @var array<string, string> $ferr @var bool $isNew
 * @var list<array<string, mixed>> $types @var list<array<string, mixed>> $sites @var list<array<string, mixed>> $depts
 */
$g = static fn (string $k): string => is_scalar($fv[$k] ?? null) ? (string) $fv[$k] : '';
?>
<div class="grid grid--3">
  <?= $ui('field', ['name' => 'type', 'type' => 'select', 'label' => $t('assets.field.type'), 'required' => true, 'value' => $g('type'), 'options' => ['' => $t('assets.pick')] + array_column($types, 'name', 'public_id'), 'error' => $ferr['type'] ?? null]) ?>
  <?= $ui('field', ['name' => 'code', 'label' => $t('assets.field.code'), 'required' => !$isNew, 'value' => $g('code'), 'help' => $isNew ? $t('assets.code_help_new') : $t('assets.code_help'), 'error' => $ferr['code'] ?? null]) ?>
  <?= $ui('field', ['name' => 'name', 'label' => $t('assets.field.name'), 'required' => true, 'value' => $g('name'), 'error' => $ferr['name'] ?? null]) ?>
  <?= $ui('field', ['name' => 'serial_no', 'label' => $t('assets.field.serial_no'), 'value' => $g('serial_no'), 'error' => $ferr['serial_no'] ?? null]) ?>
  <?= $ui('field', ['name' => 'manufacturer', 'label' => $t('assets.field.manufacturer'), 'value' => $g('manufacturer'), 'error' => $ferr['manufacturer'] ?? null]) ?>
  <?= $ui('field', ['name' => 'model', 'label' => $t('assets.field.model'), 'value' => $g('model'), 'error' => $ferr['model'] ?? null]) ?>
  <?= $ui('field', ['name' => 'acquired_on', 'type' => 'date', 'label' => $t('assets.field.acquired_on'), 'value' => $g('acquired_on'), 'error' => $ferr['acquired_on'] ?? null]) ?>
  <?= $ui('field', ['name' => 'cost', 'type' => 'money', 'label' => $t('assets.field.cost'), 'value' => $g('cost'), 'help' => $t('assets.cost_help'), 'error' => $ferr['cost'] ?? null]) ?>
  <?= $ui('field', ['name' => 'warranty_until', 'type' => 'date', 'label' => $t('assets.field.warranty_until'), 'value' => $g('warranty_until'), 'error' => $ferr['warranty_until'] ?? null]) ?>
  <?= $ui('field', ['name' => 'supplier', 'label' => $t('assets.field.supplier'), 'value' => $g('supplier'), 'error' => $ferr['supplier'] ?? null]) ?>
  <?= $ui('field', ['name' => 'purchase_ref', 'label' => $t('assets.field.purchase_ref'), 'value' => $g('purchase_ref'), 'help' => $t('assets.purchase_ref_help'), 'error' => $ferr['purchase_ref'] ?? null]) ?>
  <?= $ui('field', ['name' => 'site', 'type' => 'select', 'label' => $t('assets.field.site'), 'required' => true, 'value' => $g('site'), 'options' => ['' => $t('assets.pick')] + array_column($sites, 'code', 'public_id'), 'error' => $ferr['site'] ?? null]) ?>
  <?= $ui('field', ['name' => 'location', 'label' => $t('assets.field.location'), 'value' => $g('location'), 'help' => $t('assets.location_help'), 'error' => $ferr['location'] ?? null]) ?>
  <?= $ui('field', ['name' => 'department', 'type' => 'select', 'label' => $t('assets.field.department'), 'value' => $g('department'), 'options' => ['' => '—'] + array_column($depts, 'code', 'public_id'), 'error' => $ferr['department'] ?? null]) ?>
  <?php if ($isNew): ?>
  <?= $ui('field', ['name' => 'condition', 'type' => 'select', 'label' => $t('assets.field.condition'), 'value' => $g('condition') ?: 'good', 'options' => array_combine(['good', 'fair', 'poor', 'broken'], array_map(static fn (string $c): string => $t('assets.condition.' . $c), ['good', 'fair', 'poor', 'broken'])), 'error' => $ferr['condition'] ?? null]) ?>
  <?php endif; ?>
</div>
<?= $ui('field', ['name' => 'notes', 'type' => 'textarea', 'label' => $t('assets.field.notes'), 'value' => $g('notes'), 'error' => $ferr['notes'] ?? null]) ?>
