<?php
/** @var callable $e @var callable $t @var callable $url @var string $tab */
$tabs = ['rules' => '/admin/records', 'holds' => '/admin/records/holds', 'disposition' => '/admin/records/disposition'];
?>
<nav class="tabs__list subnav" aria-label="<?= $e($t('records.title')) ?>">
  <?php foreach ($tabs as $k => $href): ?>
    <a class="tabs__tab" href="<?= $e($url($href)) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= $e($t('records.tab.' . $k)) ?></a>
  <?php endforeach; ?>
</nav>
