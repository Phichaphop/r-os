<?php
/**
 * Helpdesk section links (my tickets · new ticket · work queue · categories and teams · report).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var string $here @var bool $staff @var bool $canManage
 */
$links = ['/helpdesk' => ['helpdesk.tab.mine', 'inbox'], '/helpdesk/new' => ['helpdesk.tab.new', 'plus']];
if ($staff) {
    $links['/helpdesk/queue'] = ['helpdesk.tab.queue', 'list-check'];
}
if ($canManage) {
    $links += ['/helpdesk/admin' => ['helpdesk.tab.admin', 'settings'], '/helpdesk/report' => ['helpdesk.tab.report', 'report-money']];
}
?>
<nav class="segmented-links" aria-label="<?= $e($t('helpdesk.sections')) ?>">
  <?php foreach ($links as $href => [$label, $icon]): ?><a href="<?= $e($url($href)) ?>"<?= $href === $here ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t($label)) ?></a><?php endforeach; ?>
</nav>
