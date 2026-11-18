<?php
/**
 * Contractor section links.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var string $here @var bool $canView @var bool $canManage @var bool $canGate
 */
$links = [];
if ($canView) {
    $links += ['/contractors' => ['contractors.tab.companies', 'id-badge-2'], '/contractors/permits' => ['contractors.tab.permits', 'clipboard-check']];
}
if ($canGate) {
    $links['/contractors/gate'] = ['contractors.tab.gate', 'shield-check'];
}
if ($canManage) {
    $links['/contractors/settings'] = ['contractors.tab.settings', 'settings'];
}
?>
<?php if ($links !== []): ?>
<nav class="segmented-links" aria-label="<?= $e($t('contractors.sections')) ?>">
  <?php foreach ($links as $href => [$label, $icon]): ?><a href="<?= $e($url($href)) ?>"<?= $href === $here ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t($label)) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
