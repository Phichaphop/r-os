<?php
/**
 * Knowledge base section links.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var string $here @var bool $canAuthor @var bool $canReview
 */
$links = ['/kb' => ['kb.tab.read', 'help-circle']];
if ($canAuthor) {
    $links['/kb/manage'] = ['kb.tab.manage', 'pencil'];
}
if ($canReview) {
    $links['/kb/settings'] = ['kb.tab.settings', 'settings'];
}
?>
<?php if (count($links) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('kb.sections')) ?>">
  <?php foreach ($links as $href => [$label, $icon]): ?><a href="<?= $e($url($href)) ?>"<?= $href === $here ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t($label)) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
