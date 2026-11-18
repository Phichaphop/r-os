<?php
/**
 * Employment fields. @var callable $e @var callable $t @var callable $ui @var array<string, mixed> $old @var array<string, string> $errors
 * @var array<string, array<int, string>> $org @var array<int, string> $managers
 */
$f = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$types = [];
foreach (\Blue\Modules\Employees\EmployeeService::TYPES as $ty) {
    $types[$ty] = $t('employees.type.' . $ty);
}
?>
<div class="grid grid--2">
  <?= $ui('field', ['name' => 'legal_entity_id', 'type' => 'select', 'label' => $t('employees.field.company'), 'required' => true, 'value' => $f('legal_entity_id'),
      'options' => ['' => '—'] + $org['legal_entity'], 'error' => $errors['legal_entity_id'] ?? null]) ?>
  <?= $ui('field', ['name' => 'site_id', 'type' => 'select', 'label' => $t('employees.field.site'), 'value' => $f('site_id'), 'options' => ['' => '—'] + $org['site'], 'error' => $errors['site_id'] ?? null]) ?>
  <?= $ui('field', ['name' => 'department_id', 'type' => 'select', 'label' => $t('employees.field.department'), 'value' => $f('department_id'), 'options' => ['' => '—'] + $org['department'],
      'error' => $errors['department_id'] ?? null]) ?>
  <?= $ui('field', ['name' => 'position_title', 'label' => $t('employees.field.position'), 'value' => $f('position_title')]) ?>
  <?= $ui('field', ['name' => 'manager_employee_id', 'type' => 'select', 'label' => $t('employees.field.manager'), 'value' => $f('manager_employee_id'),
      'options' => ['' => $t('employees.no_manager')] + $managers, 'error' => $errors['manager_employee_id'] ?? null, 'help' => $t('employees.manager_help')]) ?>
  <?= $ui('field', ['name' => 'employee_group', 'label' => $t('employees.field.group'), 'required' => true, 'value' => $f('employee_group'), 'error' => $errors['employee_group'] ?? null,
      'help' => $t('employees.group_help')]) ?>
  <?= $ui('field', ['name' => 'employment_type', 'type' => 'select', 'label' => $t('employees.field.type'), 'value' => $f('employment_type', 'permanent'), 'options' => $types]) ?>
</div>
