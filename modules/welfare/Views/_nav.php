<?php
/**
 * Welfare staff sections (what the person may use for the chosen company) + company switch.
 * @var callable $e @var callable $t @var callable $ui @var callable $url
 * @var array<string, string> $companies @var string $lePid @var string $here @var array<string, bool> $tabs
 */
$links = ['/welfare/admin' => ['welfare.tab.queue', 'clipboard-check', 'queue'], '/welfare/types' => ['welfare.tab.types', 'settings', 'types'],
    '/welfare/payout' => ['welfare.tab.payout', 'cash', 'payout'], '/welfare/report' => ['welfare.tab.report', 'report-money', 'report']];
?>
<nav class="segmented-links" aria-label="<?= $e($t('welfare.sections')) ?>">
  <?php foreach ($links as $href => [$label, $icon, $key]): if (empty($tabs[$key])) { continue; } ?><a href="<?= $e($url($href . '?le=' . rawurlencode($lePid))) ?>"<?= $href === $here ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t($label)) ?></a><?php endforeach; ?>
</nav>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('welfare.pick_company')) ?>">
  <?php foreach ($companies as $pid => $name): ?><a href="<?= $e($url($here . '?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($name) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
