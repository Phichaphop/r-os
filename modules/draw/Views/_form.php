<?php
/**
 * Event fields (0.11.1: the essentials first, the rest folded away). @var callable $e @var callable $t @var callable $ui @var array<string, mixed> $old @var array<string, string> $errors
 */
$f = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$seqOn = $f('prize_mode', 'prizes') === 'sequence';
?>
<?= $ui('field', ['name' => 'name', 'label' => $t('draw.field.name'), 'required' => true, 'value' => $f('name'), 'error' => $errors['name'] ?? null, 'placeholder' => $t('draw.name_example')]) ?>
<fieldset class="stack-sm" data-draw-prize-mode>
  <legend class="field__label"><?= $e($t('draw.field.prize_mode')) ?></legend>
  <div class="grid grid--2">
    <?php foreach (['prizes' => 'gift', 'sequence' => 'list-check'] as $pm => $icon): ?>
    <label class="choice choice--block"><input type="radio" name="prize_mode" value="<?= $pm ?>"<?= $f('prize_mode', 'prizes') === $pm ? ' checked' : '' ?>>
      <span><strong><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t('draw.prize_mode.' . $pm)) ?></strong><br>
        <span class="text-sm text-muted"><?= $e($t('draw.prize_mode.' . $pm . '_help')) ?></span></span></label>
    <?php endforeach; ?>
  </div>
</fieldset>
<div class="grid grid--2" data-draw-seq<?= $seqOn ? '' : ' hidden' ?>>
  <?= $ui('field', ['name' => 'seq_count', 'type' => 'number', 'label' => $t('draw.field.seq_count'), 'value' => $f('seq_count'), 'error' => $errors['seq_count'] ?? null,
      'help' => $t('draw.seq_count_help'), 'min' => '1', 'max' => '5000', 'inputmode' => 'numeric']) ?>
</div>
<div class="stack-sm">
  <input type="hidden" name="no_repeat" value="0">
  <label class="check"><input type="checkbox" name="no_repeat" value="1"<?= $f('no_repeat', '1') === '1' ? ' checked' : '' ?>>
    <span data-draw-when="prizes"<?= $seqOn ? ' hidden' : '' ?>><?= $e($t('draw.field.no_repeat')) ?></span>
    <span data-draw-when="sequence"<?= $seqOn ? '' : ' hidden' ?>><?= $e($t('draw.field.no_repeat_seq')) ?></span></label>
  <p class="text-sm text-muted" data-draw-when="prizes"<?= $seqOn ? ' hidden' : '' ?>><?= $e($t('draw.no_repeat_help')) ?></p>
  <p class="text-sm text-muted" data-draw-when="sequence"<?= $seqOn ? '' : ' hidden' ?>><?= $e($t('draw.no_repeat_seq_help')) ?></p>
</div>
<details class="draw-more"<?= isset($errors['event_date']) ? ' open' : '' ?>>
  <summary><?= $e($t('draw.more_settings')) ?></summary>
  <div class="stack">
    <?= $ui('field', ['name' => 'mode', 'type' => 'select', 'label' => $t('draw.field.mode'), 'value' => $f('mode', 'wheel'), 'options' => ['wheel' => $t('draw.mode.wheel'), 'race' => $t('draw.mode.race')], 'help' => $t('draw.mode_help')]) ?>
    <?= $ui('field', ['name' => 'description', 'type' => 'textarea', 'label' => $t('draw.field.description'), 'value' => $f('description')]) ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'event_date', 'type' => 'date', 'label' => $t('draw.field.event_date'), 'value' => $f('event_date'), 'error' => $errors['event_date'] ?? null]) ?>
      <?= $ui('field', ['name' => 'event_time', 'label' => $t('draw.field.event_time'), 'value' => $f('event_time'), 'placeholder' => '18:30', 'inputmode' => 'numeric']) ?>
    </div>
  </div>
</details>
