<?php
/** Weekly pattern checkboxes. @var callable $e @var callable $t @var string $pattern 7 chars Sun..Sat @var string $legend */
?>
<fieldset class="field field--full">
  <legend class="field__label"><?= $e($legend) ?></legend>
  <div class="row weekdays">
    <?php for ($i = 0; $i < 7; $i++): ?>
      <label class="choice"><input type="checkbox" name="wd<?= $i ?>" value="1"<?= ($pattern[$i] ?? '0') === '1' ? ' checked' : '' ?>> <?= $e($t('calendar.weekday.' . $i)) ?></label>
    <?php endfor; ?>
  </div>
</fieldset>
