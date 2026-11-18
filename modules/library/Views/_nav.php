<?php
/** Library section links. @var callable $e @var callable $t @var callable $ui @var callable $url @var string $here @var bool $canManage */
$links = ['/library' => ['library.tab.search', 'search'], '/library/loans' => ['library.tab.loans', 'bookmark']];
if ($canManage) {
    $links['/library/manage'] = ['library.tab.manage', 'list-check'];
    $links['/library/desk'] = ['library.tab.desk', 'arrows-sort'];
}
?>
<nav class="segmented-links" aria-label="<?= $e($t('library.sections')) ?>">
  <?php foreach ($links as $href => [$label, $icon]): ?><a href="<?= $e($url($href)) ?>"<?= $href === $here ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t($label)) ?></a><?php endforeach; ?>
</nav>
