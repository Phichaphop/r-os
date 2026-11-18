<?php
/** Company fields (set $cv values, $cerr errors). @var callable $t @var callable $ui @var array<string, mixed> $cv @var array<string, string> $cerr */
$g = static fn (string $k): string => is_scalar($cv[$k] ?? null) ? (string) $cv[$k] : '';
?>
<div class="grid grid--3">
  <?= $ui('field', ['name' => 'name', 'label' => $t('contractors.field.company_name'), 'required' => true, 'value' => $g('name'), 'error' => $cerr['name'] ?? null]) ?>
  <?= $ui('field', ['name' => 'contact_name', 'label' => $t('contractors.field.contact_name'), 'value' => $g('contact_name'), 'error' => $cerr['contact_name'] ?? null]) ?>
  <?= $ui('field', ['name' => 'contact_phone', 'type' => 'tel', 'label' => $t('contractors.field.contact_phone'), 'value' => $g('contact_phone')]) ?>
  <?= $ui('field', ['name' => 'contact_email', 'type' => 'email', 'label' => $t('contractors.field.contact_email'), 'value' => $g('contact_email'), 'error' => $cerr['contact_email'] ?? null]) ?>
  <?= $ui('field', ['name' => 'owner', 'label' => $t('contractors.field.owner'), 'value' => $g('owner'), 'help' => $t('contractors.owner_help'), 'error' => $cerr['owner'] ?? null]) ?>
</div>
