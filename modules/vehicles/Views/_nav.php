<?php
/**
 * Vehicle booking section links (my requests · new request · board · vehicles and drivers · report).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var string $here @var bool $canManage
 */
$links = ['/vehicles' => ['vehicles.tab.mine', 'car'], '/vehicles/new' => ['vehicles.tab.new', 'plus']];
if ($canManage) {
    $links += ['/vehicles/board' => ['vehicles.tab.board', 'list-check'], '/vehicles/admin' => ['vehicles.tab.admin', 'settings'], '/vehicles/report' => ['vehicles.tab.report', 'report-money']];
}
?>
<nav class="segmented-links" aria-label="<?= $e($t('vehicles.sections')) ?>">
  <?php foreach ($links as $href => [$label, $icon]): ?><a href="<?= $e($url($href)) ?>"<?= $href === $here ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t($label)) ?></a><?php endforeach; ?>
</nav>
