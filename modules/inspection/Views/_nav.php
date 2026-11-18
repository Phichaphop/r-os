<?php
/**
 * Inspection section links (my rounds · board · checklists and points · report).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var string $here @var bool $canManage
 */
$links = ['/inspection' => ['inspection.tab.mine', 'clipboard-check']];
if ($canManage) {
    $links += ['/inspection/board' => ['inspection.tab.board', 'list-check'], '/inspection/admin' => ['inspection.tab.admin', 'settings'], '/inspection/report' => ['inspection.tab.report', 'report-money']];
}
?>
<nav class="segmented-links" aria-label="<?= $e($t('inspection.sections')) ?>">
  <?php foreach ($links as $href => [$label, $icon]): ?><a href="<?= $e($url($href)) ?>"<?= $href === $here ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t($label)) ?></a><?php endforeach; ?>
</nav>
