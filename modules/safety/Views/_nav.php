<?php
/** Safety section links. @var callable $e @var callable $t @var callable $ui @var callable $url @var string $here @var bool $canManage */
$links = ['/safety' => ['safety.tab.home', 'shield-check'], '/safety/report' => ['safety.tab.report', 'alert-triangle'], '/safety/forms' => ['safety.tab.forms', 'printer']];
if ($canManage) {
    $links['/safety/manage'] = ['safety.tab.manage', 'list-check'];
    $links['/safety/stats'] = ['safety.tab.stats', 'chart-bar'];
    $links['/safety/policy'] = ['safety.tab.policy', 'file-text'];
}
?>
<nav class="segmented-links" aria-label="<?= $e($t('safety.sections')) ?>">
  <?php foreach ($links as $href => [$label, $icon]): ?><a href="<?= $e($url($href)) ?>"<?= $href === $here ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t($label)) ?></a><?php endforeach; ?>
</nav>
