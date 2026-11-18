<?php
/** Company switcher. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, string> $companies @var string $lePid @var string $here */
if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('safety.pick_company')) ?>">
  <?php foreach ($companies as $cp => $code): ?><a href="<?= $e($url($here . '?le=' . rawurlencode($cp))) ?>"<?= $cp === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif;
