<?php
/**
 * Form field: label above, required/optional as TEXT, help, error linked by aria-describedby (P0-05 §3.1).
 * Props: name, label, type (text|email|password|number|money|date|select|textarea), value, required (bool),
 *        help, error, options (value => label, for select), placeholder, disabled, readonly, prefix, autocomplete, full (bool),
 *        autofocus (bool, single-purpose screens only), inputmode, accept (file inputs), min/max,
 *        id (when one page has two fields with the same name), data (name => value → data-* attributes)
 *
 * @var array<string, mixed> $p
 * @var callable $e
 * @var callable $t
 * @var callable $ui
 */
$name = (string) ($p['name'] ?? 'field');
$id = isset($p['id']) ? (string) $p['id'] : 'f-' . preg_replace('/[^a-z0-9_-]/i', '-', $name);
$type = $p['type'] ?? 'text';
$desc = [];
if (!empty($p['help'])) { $desc[] = $id . '-help'; }
if (!empty($p['error'])) { $desc[] = $id . '-err'; }
$common = ' id="' . $e($id) . '" name="' . $e($name) . '"'
    . (!empty($p['required']) ? ' required' : '')
    . (!empty($p['disabled']) ? ' disabled' : '')
    . (!empty($p['readonly']) ? ' readonly' : '')
    . (!empty($p['error']) ? ' aria-invalid="true"' : '')
    . ($desc ? ' aria-describedby="' . $e(implode(' ', $desc)) . '"' : '')
    . (!empty($p['placeholder']) ? ' placeholder="' . $e($p['placeholder']) . '"' : '')
    . (!empty($p['autocomplete']) ? ' autocomplete="' . $e($p['autocomplete']) . '"' : '')
    . (!empty($p['inputmode']) ? ' inputmode="' . $e($p['inputmode']) . '"' : '')
    . (!empty($p['accept']) ? ' accept="' . $e($p['accept']) . '"' : '')
    . (!empty($p['min']) ? ' min="' . $e($p['min']) . '"' : '')
    . (!empty($p['max']) ? ' max="' . $e($p['max']) . '"' : '')
    . (!empty($p['autofocus']) ? ' autofocus' : '');
foreach ((array) ($p['data'] ?? []) as $dk => $dv) {
    $common .= ' data-' . $e(preg_replace('/[^a-z0-9-]/', '', (string) $dk)) . '="' . $e((string) $dv) . '"';
}
$value = (string) ($p['value'] ?? '');
?>
<div class="field<?= !empty($p['full']) ? ' field--full' : '' ?>">
  <label class="field__label" for="<?= $e($id) ?>"><?= $e($p['label'] ?? $name) ?>
    <span class="field__req"><?= $e(!empty($p['required']) ? $t('form.required_tag') : $t('form.optional_tag')) ?></span></label>
  <?php if ($type === 'select'): ?>
    <select class="select"<?= $common ?>>
      <?php foreach (($p['options'] ?? []) as $ov => $ol): ?>
        <option value="<?= $e($ov) ?>"<?= (string) $ov === $value ? ' selected' : '' ?>><?= $e($ol) ?></option>
      <?php endforeach; ?>
    </select>
  <?php elseif ($type === 'textarea'): ?>
    <textarea class="textarea"<?= $common ?>><?= $e($value) ?></textarea>
  <?php elseif (!empty($p['prefix']) || $type === 'money'): ?>
    <div class="input-group"><span class="input-group__addon"><?= $e($p['prefix'] ?? 'THB') ?></span>
      <input class="input<?= $type === 'money' ? ' input--money' : '' ?>" type="text" inputmode="decimal" value="<?= $e($value) ?>"<?= $common ?>></div>
  <?php else: ?>
    <input class="input<?= $type === 'file' ? ' input--file' : '' ?>" type="<?= $e($type) ?>"<?= $type === 'file' ? '' : ' value="' . $e($value) . '"' ?><?= $common ?>>
  <?php endif; ?>
  <?php if (!empty($p['help'])): ?><div class="field__help" id="<?= $e($id) ?>-help"><?= $e($p['help']) ?></div><?php endif; ?>
  <?php if (!empty($p['error'])): ?><div class="field__error" id="<?= $e($id) ?>-err"><?= $ui('icon', ['name' => 'alert-circle', 'size' => 'sm']) ?><?= $e($p['error']) ?></div><?php endif; ?>
</div>
