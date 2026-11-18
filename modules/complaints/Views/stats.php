<?php
/**
 * Anonymous figures (complaints.stats). Any count below the company's minimum group is shown as "fewer than n".
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $partial @var array<string, mixed> $v
 * @var list<array{id: int, code: string, name: string}> $companies @var int $le @var array<string, mixed> $stats
 */
$k = (int) $stats['k'];
$n = static fn (?int $x): string => $x === null ? $t('complaints.stats.fewer', ['k' => (string) $k]) : (string) $x;
$y = (int) $stats['year'];
?>
<?= $ui('page-header', ['title' => $t('complaints.stats.title'), 'subtitle' => $t('complaints.stats.subtitle', ['k' => (string) $k])]) ?>
<?= $partial('@complaints/_companies', ['companies' => $companies, 'le' => $le, 'path' => '/complaints/stats']) ?>
<nav class="segmented-links" aria-label="<?= $e($t('complaints.stats.year')) ?>">
  <?php foreach ([$y - 1, $y, $y + 1] as $yy): ?><a href="<?= $e($url('/complaints/stats?le=' . $le . '&year=' . $yy)) ?>"<?= $yy === $y ? ' aria-current="page"' : '' ?>><?= $yy + 543 ?></a><?php endforeach; ?>
</nav>
<section class="card stack-sm" aria-labelledby="ss-sum">
  <h2 id="ss-sum" class="card__title"><?= $e($t('complaints.stats.summary', ['y' => (string) ($y + 543)])) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('complaints.stats.total')) ?></dt><dd><?= $e($n($stats['total'])) ?></dd></div>
    <div><dt><?= $e($t('complaints.stats.response_days')) ?></dt><dd><?= $stats['response_days'] === null ? $e($t('complaints.stats.not_enough')) : $e((string) $stats['response_days']) ?></dd></div>
    <div><dt><?= $e($t('complaints.stats.resolve_days')) ?></dt><dd><?= $stats['resolve_days'] === null ? $e($t('complaints.stats.not_enough')) : $e((string) $stats['resolve_days']) ?></dd></div>
    <div><dt><?= $e($t('complaints.stats.rating')) ?></dt><dd><?= $stats['rating'] === null ? $e($t('complaints.stats.not_enough')) : $e((string) $stats['rating']) . ' / 5' ?></dd></div>
  </dl>
</section>
<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="ss-cat">
  <h2 id="ss-cat" class="card__title"><?= $e($t('complaints.stats.by_category')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="ss-cat" tabindex="0"><table class="table">
    <thead><tr><th scope="col"><?= $e($t('complaints.field.category')) ?></th><th scope="col" class="num"><?= $e($t('complaints.stats.count')) ?></th></tr></thead>
    <tbody><?php foreach ($stats['category'] as $cat => $x): ?><tr><th scope="row"><?= $e($t('complaints.category.' . $cat)) ?></th><td class="num"><?= $e($n($x)) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</section>
<section class="card stack-sm" aria-labelledby="ss-st">
  <h2 id="ss-st" class="card__title"><?= $e($t('complaints.stats.by_status')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="ss-st" tabindex="0"><table class="table">
    <thead><tr><th scope="col"><?= $e($t('complaints.field.status')) ?></th><th scope="col" class="num"><?= $e($t('complaints.stats.count')) ?></th></tr></thead>
    <tbody><?php foreach ($stats['status'] as $g => $x): ?><tr><th scope="row"><?= $e($t('complaints.stats.group.' . $g)) ?></th><td class="num"><?= $e($n($x)) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</section>
</div>
<section class="card stack-sm" aria-labelledby="ss-m">
  <h2 id="ss-m" class="card__title"><?= $e($t('complaints.stats.by_month')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="ss-m" tabindex="0"><table class="table">
    <thead><tr><?php for ($m = 1; $m <= 12; $m++): ?><th scope="col" class="num"><?= $e($t('complaints.month.' . $m)) ?></th><?php endfor; ?></tr></thead>
    <tbody><tr><?php foreach ($stats['month'] as $x): ?><td class="num"><?= $e($x === null ? '<' . $k : (string) $x) ?></td><?php endforeach; ?></tr></tbody>
  </table></div>
</section>
