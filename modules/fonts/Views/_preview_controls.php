<?php
/** @var callable $e @var callable $t */
?>
<div class="font-controls" data-font-controls>
  <div class="field font-controls__text">
    <label class="field__label" for="font-text"><?= $e($t('fonts.preview.text')) ?></label>
    <input class="input" id="font-text" data-font-text maxlength="200" placeholder="<?= $e($t('fonts.preview.default')) ?>">
  </div>
  <div class="field font-controls__size">
    <label class="field__label" for="font-size"><?= $e($t('fonts.preview.size')) ?> <output data-font-size-out>32</output> px</label>
    <input type="range" id="font-size" min="12" max="96" step="2" value="32" data-font-size>
  </div>
</div>
