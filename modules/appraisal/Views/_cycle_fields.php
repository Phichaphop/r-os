<?php
/**
 * Cycle fields (new + edit while draft). Needs $cv (value getter), $errors, $formOptions, $audOptions, $audIn, $e, $t, $ui.
 * @var callable $e @var callable $t @var callable $ui @var callable $cv @var array<string, string> $errors @var array<string, string> $formOptions
 * @var array{sites: array<string, string>, departments: array<string, string>} $audOptions @var array{sites?: list<string>, departments?: list<string>, groups?: string} $audIn
 */
$periods = [];
foreach (['mid_year', 'year_end', 'probation', 'other'] as $p) {
    $periods[$p] = $t('appraisal.period.' . $p);
}
?>
<div class="grid grid--3">
  <?= $ui('field', ['name' => 'code', 'label' => $t('appraisal.field.code'), 'required' => true, 'value' => $cv('code'), 'placeholder' => 'Y2026', 'error' => $errors['code'] ?? null]) ?>
  <?= $ui('field', ['name' => 'name', 'label' => $t('appraisal.field.name'), 'required' => true, 'value' => $cv('name'), 'error' => $errors['name'] ?? null]) ?>
  <?= $ui('field', ['name' => 'period', 'type' => 'select', 'label' => $t('appraisal.field.period'), 'value' => $cv('period', 'year_end'), 'options' => $periods]) ?>
  <?= $ui('field', ['name' => 'form', 'type' => 'select', 'label' => $t('appraisal.field.form'), 'required' => true, 'value' => $cv('form'), 'options' => ['' => '—'] + $formOptions, 'error' => $errors['form'] ?? null]) ?>
  <?= $ui('field', ['name' => 'self_due', 'type' => 'date', 'label' => $t('appraisal.field.self_due'), 'required' => true, 'value' => $cv('self_due'), 'error' => $errors['self_due'] ?? null]) ?>
  <?= $ui('field', ['name' => 'manager_due', 'type' => 'date', 'label' => $t('appraisal.field.manager_due'), 'required' => true, 'value' => $cv('manager_due'), 'error' => $errors['manager_due'] ?? null]) ?>
  <?= $ui('field', ['name' => 'final_due', 'type' => 'date', 'label' => $t('appraisal.field.final_due'), 'value' => $cv('final_due'), 'help' => $t('appraisal.final_due_help'), 'error' => $errors['final_due'] ?? null]) ?>
  <?= $ui('field', ['name' => 'results_on', 'type' => 'date', 'label' => $t('appraisal.field.results_on'), 'required' => true, 'value' => $cv('results_on'), 'help' => $t('appraisal.results_help'), 'error' => $errors['results_on'] ?? null]) ?>
</div>
<label class="check"><input type="checkbox" name="final_review" value="1"<?= $cv('final_review') === '1' ? ' checked' : '' ?>> <?= $e($t('appraisal.field.final_review')) ?></label>
<p class="text-sm text-muted"><?= $e($t('appraisal.final_review_help')) ?></p>
<?php $audError = $errors['audience'] ?? ($errors['groups'] ?? null); $audId = 'apc'; include __DIR__ . '/../../employees/Views/_audience.php'; ?>
