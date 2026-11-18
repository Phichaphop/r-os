<?php
/** Forum section links. @var callable $e @var callable $t @var callable $ui @var callable $url @var string $here @var bool $canModerate */
$links = ['/forum' => ['forum.tab.board', 'users'], '/forum/new' => ['forum.tab.new', 'plus']];
if ($canModerate) {
    $links['/forum/moderate'] = ['forum.tab.moderate', 'shield-check'];
}
?>
<nav class="segmented-links" aria-label="<?= $e($t('forum.sections')) ?>">
  <?php foreach ($links as $href => [$label, $icon]): ?><a href="<?= $e($url($href)) ?>"<?= $href === $here ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t($label)) ?></a><?php endforeach; ?>
</nav>
