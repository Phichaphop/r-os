<?php
/** Person fields. @var callable $e @var callable $t @var callable $ui @var array<string, mixed> $old @var array<string, string> $errors @var bool $codeLocked */
$f = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
?>
<div class="grid grid--2">
  <?= $ui('field', ['name' => 'employee_code', 'label' => $t('employees.field.code'), 'required' => true, 'value' => $f('employee_code'), 'error' => $errors['employee_code'] ?? null,
      'help' => $t('employees.code_help'), 'readonly' => !empty($codeLocked)]) ?>
  <?= $ui('field', ['name' => 'name_th', 'label' => $t('employees.field.name_th'), 'required' => true, 'value' => $f('name_th'), 'error' => $errors['name_th'] ?? null]) ?>
  <?= $ui('field', ['name' => 'name_en', 'label' => $t('employees.field.name_en'), 'value' => $f('name_en')]) ?>
  <?= $ui('field', ['name' => 'preferred_name', 'label' => $t('employees.field.preferred_name'), 'value' => $f('preferred_name')]) ?>
  <?= $ui('field', ['name' => 'hire_date', 'type' => 'date', 'label' => $t('employees.field.hire_date'), 'required' => true, 'value' => $f('hire_date'), 'error' => $errors['hire_date'] ?? null,
      'help' => $t('employees.hire_date_help')]) ?>
</div>
