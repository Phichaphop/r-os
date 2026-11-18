<?php
/**
 * Logo + system name on the navy areas (side bar, sign-in bar) — 0.37.0. Hiding the name makes the logo larger;
 * /brand/logo serves the uploaded logo or the built-in Blue logo; a tint draws it as a white / light-accent silhouette (CSS in theme.css, Appearance::logoCss).
 * @var callable $e @var callable $url @var callable $asset @var array<string, mixed> $v @var string $brandSize
 */
$brandTheme = (array) ($v['theme'] ?? []);
$brandShowName = ($brandTheme['show_name'] ?? true) !== false;
$brandTint = in_array($brandTheme['logo_tint'] ?? 'original', ['light', 'accent'], true);
$brandPx = ['menu' => 32, 'minimal' => 36, 'hero' => 96][$brandSize ?? 'menu'] ?? 32;
?>
<img class="brand-logo<?= ($brandSize ?? 'menu') === 'hero' ? ' brand-logo--hero' : ($brandShowName ? '' : ' brand-logo--large') ?><?= $brandTint ? ' brand-logo--tint' : '' ?>" src="<?= $e($url('/brand/logo') . '?v=' . ($brandTheme['version'] ?? '')) ?>" alt="<?= $brandShowName ? '' : $e($v['app_name'] ?? 'Blue OS') ?>" width="<?= $brandPx ?>" height="<?= $brandPx ?>">
