<?php
/** @var callable $e @var callable $t @var callable $url @var list<array{id: int, code: string, name: string}> $companies @var int $le @var string $path */
if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('complaints.company')) ?>">
  <?php foreach ($companies as $c): ?><a href="<?= $e($url($path . '?le=' . $c['id'])) ?>"<?= $c['id'] === $le ? ' aria-current="page"' : '' ?>><?= $e($c['code']) ?></a><?php endforeach; ?>
</nav>
<?php endif;
