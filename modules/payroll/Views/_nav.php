<?php
/**
 * Company switch + the payroll section links (runs · people · components · tax · Payroll Checker reviews · rules).
 * @var callable $e @var callable $t @var callable $ui @var callable $url
 * @var array<string, string> $companies @var string $lePid @var string $here
 */
$links = ['/payroll' => ['payroll.tab.runs', 'report-money'], '/payroll/people' => ['payroll.tab.people', 'users'], '/payroll/components' => ['payroll.tab.components', 'components'], '/payroll/tax' => ['payroll.tab.tax', 'receipt'], '/payroll/reviews' => ['payroll.tab.reviews', 'clipboard-check'], '/payroll/settings' => ['payroll.tab.settings', 'settings']];
?>
<nav class="segmented-links" aria-label="<?= $e($t('payroll.sections')) ?>">
  <?php foreach ($links as $href => [$label, $icon]): ?><a href="<?= $e($url($href . '?le=' . rawurlencode($lePid))) ?>"<?= $href === $here ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t($label)) ?></a><?php endforeach; ?>
</nav>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('payroll.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url($here . '?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
