<?php
/**
 * Button / link-button (P0-05 §3.1).
 * Props: label, variant (primary|secondary|ghost|danger|on-navy), size (sm|md|lg), icon, iconOnly (bool),
 *        class (extra classes), href (renders <a>), type (button|submit), disabled (bool), disabledReason, loading (bool), attrs (array)
 *
 * @var array<string, mixed> $p
 * @var callable $e
 * @var callable $ui
 */
$variant = $p['variant'] ?? 'secondary';
$size = $p['size'] ?? 'md';
$iconOnly = !empty($p['iconOnly']);
$cls = "btn btn--{$variant}" . ($size !== 'md' ? " btn--{$size}" : '') . ($iconOnly ? ' btn--icon' : '')
    . (!empty($p['class']) ? ' ' . $p['class'] : '');
$attrs = '';
foreach (($p['attrs'] ?? []) as $k => $v) {
    $attrs .= ' ' . $e($k) . '="' . $e($v) . '"';
}
if ($iconOnly) {
    $attrs .= ' aria-label="' . $e($p['label'] ?? '') . '" title="' . $e($p['label'] ?? '') . '"';
}
if (!empty($p['loading'])) {
    $attrs .= ' aria-busy="true" disabled';
}
$inner = '';
if (!empty($p['loading'])) {
    $inner .= $ui('icon', ['name' => 'loader-2', 'spin' => true]);
} elseif (!empty($p['icon'])) {
    $inner .= $ui('icon', ['name' => $p['icon']]);
}
if (!$iconOnly) {
    $inner .= '<span class="btn__label">' . $e($p['label'] ?? '') . '</span>';
}
if (!empty($p['href']) && empty($p['disabled'])): ?>
<a class="<?= $e($cls) ?>" href="<?= $e($p['href']) ?>"<?= $attrs ?>><?= $inner ?></a>
<?php elseif (!empty($p['disabled'])): ?>
<span class="tooltip"><button type="button" class="<?= $e($cls) ?>" aria-disabled="true"<?= $attrs ?><?= !empty($p['disabledReason']) ? ' aria-describedby="' . $e(md5((string) $p['disabledReason'])) . '"' : '' ?>><?= $inner ?></button><?php if (!empty($p['disabledReason'])): ?><span class="tooltip__bubble" role="tooltip" id="<?= $e(md5((string) $p['disabledReason'])) ?>"><?= $e($p['disabledReason']) ?></span><?php endif; ?></span>
<?php else: ?>
<button type="<?= $e($p['type'] ?? 'button') ?>" class="<?= $e($cls) ?>"<?= $attrs ?>><?= $inner ?></button>
<?php endif;
