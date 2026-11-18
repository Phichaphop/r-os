<?php
/** Session fields. @var callable $e @var callable $t @var callable $ui @var callable $o @var array<string, string> $courseOptions @var array<string, string> $errors */
?>
<?= $ui('field', ['name' => 'course', 'type' => 'select', 'label' => $t('training.field.course'), 'required' => true, 'value' => $o('course'), 'options' => $courseOptions, 'error' => $errors['course'] ?? null]) ?>
<div class="grid grid--4">
  <?= $ui('field', ['name' => 'start_date', 'type' => 'date', 'label' => $t('training.field.start_date'), 'required' => true, 'value' => $o('start_date'), 'error' => $errors['start_date'] ?? null]) ?>
  <?= $ui('field', ['name' => 'end_date', 'type' => 'date', 'label' => $t('training.field.end_date'), 'value' => $o('end_date'), 'error' => $errors['end_date'] ?? null, 'help' => $t('training.end_date_help')]) ?>
  <?= $ui('field', ['name' => 'start_time', 'type' => 'time', 'label' => $t('training.field.start_time'), 'required' => true, 'value' => $o('start_time', '09:00')]) ?>
  <?= $ui('field', ['name' => 'end_time', 'type' => 'time', 'label' => $t('training.field.end_time'), 'required' => true, 'value' => $o('end_time', '16:00'), 'error' => $errors['end_time'] ?? null]) ?>
</div>
<div class="grid grid--3">
  <?= $ui('field', ['name' => 'location', 'label' => $t('training.field.location'), 'required' => true, 'value' => $o('location'), 'error' => $errors['location'] ?? null]) ?>
  <?= $ui('field', ['name' => 'trainer', 'label' => $t('training.field.trainer'), 'value' => $o('trainer')]) ?>
  <?= $ui('field', ['name' => 'seats', 'type' => 'number', 'label' => $t('training.field.seats'), 'required' => true, 'value' => $o('seats', '20'), 'min' => '1', 'max' => '2000', 'error' => $errors['seats'] ?? null]) ?>
  <?= $ui('field', ['name' => 'cost', 'type' => 'money', 'label' => $t('training.field.cost'), 'value' => $o('cost'), 'error' => $errors['cost'] ?? null]) ?>
  <?= $ui('field', ['name' => 'enroll_until', 'type' => 'date', 'label' => $t('training.field.enroll_until'), 'value' => $o('enroll_until'), 'error' => $errors['enroll_until'] ?? null]) ?>
</div>
