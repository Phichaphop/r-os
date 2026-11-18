<?php
/** Event section links. @var callable $e @var callable $t @var callable $ui @var callable $url @var string $here @var bool $canManage */
$links = ['/events' => ['events.tab.mine', 'calendar-event']];
if ($canManage) {
    $links['/events/manage'] = ['events.tab.manage', 'list-check'];
}
?>
<?php if (count($links) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('events.sections')) ?>">
  <?php foreach ($links as $href => [$label, $icon]): ?><a href="<?= $e($url($href)) ?>"<?= $href === $here ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t($label)) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
