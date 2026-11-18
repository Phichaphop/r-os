<?php
/**
 * Survey fields (create / edit draft). Needs $sv (values), $serr (errors), $audOptions, $audIn.
 * @var callable $e @var callable $t @var callable $ui @var array<string, mixed> $sv @var array<string, string> $serr
 */
$sval = static fn (string $k): string => is_scalar($sv[$k] ?? null) ? (string) $sv[$k] : '';
$audError = $serr['audience'] ?? null;
$audId = 'kzs';
?>
<?= $ui('field', ['name' => 'title', 'id' => 'f-s-title', 'label' => $t('kaizen.field.survey_title'), 'required' => true, 'value' => $sval('title'), 'error' => $serr['title'] ?? null]) ?>
<?= $ui('field', ['name' => 'intro', 'id' => 'f-s-intro', 'type' => 'textarea', 'label' => $t('kaizen.field.intro'), 'value' => $sval('intro'), 'help' => $t('kaizen.intro_help'), 'error' => $serr['intro'] ?? null]) ?>
<div class="grid grid--3">
  <?= $ui('field', ['name' => 'opens_on', 'type' => 'date', 'label' => $t('kaizen.field.opens_on'), 'required' => true, 'value' => $sval('opens_on'), 'error' => $serr['opens_on'] ?? null]) ?>
  <?= $ui('field', ['name' => 'closes_on', 'type' => 'date', 'label' => $t('kaizen.field.closes_on'), 'required' => true, 'value' => $sval('closes_on')]) ?>
  <?= $ui('field', ['name' => 'min_results', 'type' => 'number', 'label' => $t('kaizen.field.min_results'), 'required' => true, 'value' => $sval('min_results'), 'help' => $t('kaizen.min_results_help'), 'error' => $serr['min_results'] ?? null]) ?>
</div>
<label class="check"><input type="checkbox" name="anonymous" value="1"<?= $sval('anonymous') === '1' ? ' checked' : '' ?>> <?= $e($t('kaizen.field.survey_anonymous')) ?></label>
<p class="field__help"><?= $e($t('kaizen.survey_anonymous_help')) ?></p>
<?php include __DIR__ . '/../../employees/Views/_audience.php'; ?>
