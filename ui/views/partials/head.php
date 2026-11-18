<?php
/** @var callable $e @var callable $asset @var array<string, mixed> $v @var string $title */
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title><?= $e(($title ?? '') !== '' ? $title . ' · ' . ($v['app_name'] ?? 'Blue OS') : ($v['app_name'] ?? 'Blue OS')) ?></title>
<?php $brandV = (string) ($v['theme']['version'] ?? ''); $hasIcon = !empty($v['theme']['favicon']) || !empty($v['theme']['logo']); ?>
<link rel="icon" href="<?= $e($hasIcon ? $url('/brand/favicon') . '?v=' . $brandV : $asset('ui/icons/mark-blue.png')) ?>"<?= $hasIcon ? '' : ' type="image/png"' ?>>
<link rel="stylesheet" href="<?= $e($asset('ui/app.css')) ?>">
<?php $look = $v['theme'] ?? null; $mine = is_array($v['user_look'] ?? null) ? $v['user_look'] : null; ?>
<?php if (is_array($look) && ($look['preset'] !== 'steel' || $look['primary'] !== null || $look['menu'] !== null || $look['accent'] !== null || $look['background'] !== null || $look['font'] !== 'plex'
    || ($look['logo_tint'] ?? 'original') !== 'original')): ?>
<link rel="stylesheet" id="theme-css" href="<?= $e(($v['base_path'] ?? '') . '/theme.css?v=' . $look['version']) ?>">
<?php endif; ?>
<?php if ($mine !== null && \Blue\Core\Ui\UserLook::hasCss($mine)): ?>
<link rel="stylesheet" id="my-theme-css" href="<?= $e(($v['base_path'] ?? '') . '/me/theme.css?v=' . substr(hash('sha256', json_encode($mine) . '|' . ($look['version'] ?? '')), 0, 12)) ?>">
<?php endif; ?>
<script nonce="<?= $e($v['nonce'] ?? '') ?>">
/* Pre-paint: apply saved theme/density/sidebar before first render to avoid flicker (P0-05 §8). */
(function () {
  var d = document.documentElement, g = function (k) { try { return localStorage.getItem('blue.' + k); } catch (x) { return null; } };
  /* this device's quick toggle wins; then the person's saved setting (0.6.1); then the organization default (DEC-53) */
  var def = '<?= $e($mine !== null && $mine['theme'] !== null ? $mine['theme'] : (in_array($v['theme']['mode'] ?? 'light', ['light', 'dark', 'auto'], true) ? ($v['theme']['mode'] ?? 'light') : 'light')) ?>';
  var own = g('theme');
  var th = own === 'dark' || own === 'light' ? own : (def === 'auto' ? (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : def);
  d.setAttribute('data-theme', th);
  d.setAttribute('data-density', g('density') || 'comfortable');
  if (g('sidebar') === 'rail') d.setAttribute('data-sidebar', 'rail');
})();
</script>
<script type="module" src="<?= $e($asset('ui/js/app.js')) ?>"></script>
