<?php
/**
 * Large file drop area (drag & drop or click) around a real <input type="file"> — the input covers the whole area,
 * so dropping files onto it and keyboard/screen-reader use work without script; app.js adds the highlight and the
 * list of chosen files. Props: name, id, label, required, multiple, accept, help, error, hint (big line), data, attrs
 *
 * @var array<string, mixed> $p @var callable $e @var callable $t @var callable $ui
 */
$name = (string) ($p['name'] ?? 'file');
$id = (string) ($p['id'] ?? 'f-' . preg_replace('/[^a-z0-9_-]/i', '-', $name));
$desc = [$id . '-hint'];
if (!empty($p['help'])) { $desc[] = $id . '-help'; }
if (!empty($p['error'])) { $desc[] = $id . '-err'; }
$extra = '';
foreach ((array) ($p['data'] ?? []) as $dk => $dv) {
    $extra .= ' data-' . $e(preg_replace('/[^a-z0-9-]/', '', (string) $dk)) . '="' . $e((string) $dv) . '"';
}
?>
<div class="field dropzone-field">
  <span class="field__label" id="<?= $e($id) ?>-label"><?= $e($p['label'] ?? $name) ?>
    <span class="field__req"><?= $e(!empty($p['required']) ? $t('form.required_tag') : $t('form.optional_tag')) ?></span></span>
  <div class="dropzone<?= !empty($p['error']) ? ' is-invalid' : '' ?>" data-dropzone>
    <input class="dropzone__input" type="file" id="<?= $e($id) ?>" name="<?= $e($name) ?>"<?= !empty($p['multiple']) ? ' multiple' : '' ?><?= !empty($p['required']) ? ' required' : '' ?>
      <?= !empty($p['accept']) ? ' accept="' . $e($p['accept']) . '"' : '' ?> aria-labelledby="<?= $e($id) ?>-label" aria-describedby="<?= $e(implode(' ', $desc)) ?>"<?= !empty($p['error']) ? ' aria-invalid="true"' : '' ?><?= $extra ?>>
    <span class="dropzone__icon" aria-hidden="true"><?= $ui('icon', ['name' => 'upload']) ?></span>
    <span class="dropzone__title"><?= $e($p['hint'] ?? $t(!empty($p['multiple']) ? 'dropzone.hint_many' : 'dropzone.hint_one')) ?></span>
    <span class="dropzone__or" id="<?= $e($id) ?>-hint"><?= $e($t('dropzone.or_click')) ?><?php $exts = array_map(static fn (string $x): string => strtoupper(ltrim(trim($x), '.')), array_filter(explode(',', (string) ($p['accept'] ?? '')), static fn (string $x): bool => str_starts_with(trim($x), '.'))); ?><?= $exts !== [] ? ' · ' . $e(implode(', ', array_unique($exts))) : '' ?></span>
    <ul class="dropzone__files" data-dropzone-files aria-live="polite"></ul>
  </div>
  <?php if (!empty($p['help'])): ?><div class="field__help" id="<?= $e($id) ?>-help"><?= $e($p['help']) ?></div><?php endif; ?>
  <?php if (!empty($p['error'])): ?><div class="field__error" id="<?= $e($id) ?>-err" role="alert"><?= $ui('icon', ['name' => 'alert-circle', 'size' => 'sm']) ?><?= $e($p['error']) ?></div><?php endif; ?>
</div>
