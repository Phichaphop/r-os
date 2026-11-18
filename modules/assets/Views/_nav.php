<?php
/**
 * Asset register section links.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var string $here @var bool $canView @var bool $canManage @var bool $canCount
 */
$links = ['/assets/mine' => ['assets.tab.mine', 'briefcase']];
if ($canView) {
    $links += ['/assets' => ['assets.tab.register', 'database'], '/assets/report' => ['assets.tab.report', 'report-money']];
}
if (!empty($canCount)) {
    $links['/assets/campaigns'] = ['assets.tab.campaigns', 'clipboard-check'];
}
if ($canManage) {
    $links += ['/assets/new' => ['assets.tab.new', 'plus'], '/assets/labels' => ['assets.tab.labels', 'qrcode'], '/assets/types' => ['assets.tab.types', 'settings']];
}
?>
<nav class="segmented-links" aria-label="<?= $e($t('assets.sections')) ?>">
  <?php foreach ($links as $href => [$label, $icon]): ?><a href="<?= $e($url($href)) ?>"<?= $href === $here ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t($label)) ?></a><?php endforeach; ?>
</nav>
