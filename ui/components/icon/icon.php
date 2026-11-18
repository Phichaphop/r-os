<?php
/**
 * Icon from the self-hosted Tabler sprite.
 * Props: name (required), size ('sm'|'md'|'lg'), label (string → role=img + aria-label; omit for decorative), spin (bool)
 *
 * @var array<string, mixed> $p
 * @var callable $e
 * @var callable $asset
 */
$name = preg_replace('/[^a-z0-9-]/', '', (string) ($p['name'] ?? 'help-circle'));
$cls = 'icon' . (isset($p['size']) && $p['size'] !== 'md' ? ' icon--' . $p['size'] : '') . (!empty($p['spin']) ? ' icon--spin' : '');
$label = $p['label'] ?? null;
?>
<svg class="<?= $e($cls) ?>"<?= $label !== null ? ' role="img" aria-label="' . $e($label) . '"' : ' aria-hidden="true" focusable="false"' ?>><use href="<?= $e($asset('ui/icons/sprite.svg')) ?>#i-<?= $e($name) ?>"></use></svg>
