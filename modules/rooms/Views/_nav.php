<?php
/**
 * Meeting room section links (the day · my bookings · rooms and rules · use report).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var string $here @var bool $canManage
 */
$links = ['/rooms' => ['rooms.tab.day', 'calendar-event'], '/rooms/mine' => ['rooms.tab.mine', 'user']];
if ($canManage) {
    $links += ['/rooms/admin' => ['rooms.tab.admin', 'settings'], '/rooms/report' => ['rooms.tab.report', 'report-money']];
}
?>
<nav class="segmented-links" aria-label="<?= $e($t('rooms.sections')) ?>">
  <?php foreach ($links as $href => [$label, $icon]): ?><a href="<?= $e($url($href)) ?>"<?= $href === $here ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t($label)) ?></a><?php endforeach; ?>
</nav>
