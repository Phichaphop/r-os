<?php
/**
 * Office supply section links (catalogue · cart · my requests · issue desk · stock · items and rules · report).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var string $here @var bool $canIssue @var bool $canManage
 */
$links = ['/requisition' => ['requisition.tab.catalog', 'shopping-cart'], '/requisition/cart' => ['requisition.tab.cart', 'clipboard-check'], '/requisition/mine' => ['requisition.tab.mine', 'inbox']];
if ($canIssue) {
    $links['/requisition/issue'] = ['requisition.tab.issue', 'qrcode'];
}
if ($canManage) {
    $links += ['/requisition/stock' => ['requisition.tab.stock', 'database'], '/requisition/admin' => ['requisition.tab.admin', 'settings'], '/requisition/report' => ['requisition.tab.report', 'report-money']];
}
?>
<nav class="segmented-links" aria-label="<?= $e($t('requisition.sections')) ?>">
  <?php foreach ($links as $href => [$label, $icon]): ?><a href="<?= $e($url($href)) ?>"<?= $href === $here ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t($label)) ?></a><?php endforeach; ?>
</nav>
