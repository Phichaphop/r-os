<?php
/** Charter fields. Needs $fill, $ferr. @var callable $e @var callable $t @var callable $ui @var array<string, mixed> $fill @var array<string, string> $ferr */
$fv = static fn (string $k): string => is_scalar($fill[$k] ?? null) ? (string) $fill[$k] : '';
?>
<?= $ui('field', ['name' => 'name', 'id' => 'f-pj-name', 'label' => $t('projects.field.name'), 'required' => true, 'value' => $fv('name'), 'full' => true, 'error' => $ferr['name'] ?? null]) ?>
<?= $ui('field', ['name' => 'goal', 'id' => 'f-pj-goal', 'type' => 'textarea', 'label' => $t('projects.field.goal'), 'required' => true, 'value' => $fv('goal'), 'error' => $ferr['goal'] ?? null]) ?>
<?= $ui('field', ['name' => 'scope', 'id' => 'f-pj-scope', 'type' => 'textarea', 'label' => $t('projects.field.scope'), 'value' => $fv('scope'), 'help' => $t('projects.scope_help'), 'error' => $ferr['scope'] ?? null]) ?>
<div class="grid grid--3">
  <?= $ui('field', ['name' => 'starts_on', 'id' => 'f-pj-start', 'type' => 'date', 'label' => $t('projects.field.starts_on'), 'required' => true, 'value' => $fv('starts_on'), 'error' => $ferr['starts_on'] ?? null]) ?>
  <?= $ui('field', ['name' => 'ends_on', 'id' => 'f-pj-end', 'type' => 'date', 'label' => $t('projects.field.ends_on'), 'required' => true, 'value' => $fv('ends_on'), 'error' => $ferr['ends_on'] ?? null]) ?>
  <?= $ui('field', ['name' => 'budget', 'id' => 'f-pj-budget', 'label' => $t('projects.field.budget'), 'value' => $fv('budget'), 'inputmode' => 'decimal', 'help' => $t('projects.budget_help'), 'error' => $ferr['budget'] ?? null]) ?>
</div>
<?= $ui('field', ['name' => 'approver', 'id' => 'f-pj-approver', 'label' => $t('projects.field.approver'), 'value' => $fv('approver'), 'help' => $t('projects.approver_help'), 'error' => $ferr['approver'] ?? null]) ?>
