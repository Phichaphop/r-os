<?php
/**
 * Figures for the last 12 months.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, string> $companies @var string $lePid @var array<string, mixed> $st @var bool $canManage @var string $here
 */
$board = $st['board'];
$kinds = ['injury', 'damage', 'near_miss', 'hazard'];
?>
<?= $ui('page-header', ['title' => $t('safety.stats_title'), 'subtitle' => $t('safety.stats_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<?php include __DIR__ . '/_board.php'; ?>
<section class="card stack-sm" aria-labelledby="sf-fig">
  <h2 id="sf-fig" class="card__title"><?= $e($t('safety.figures')) ?></h2>
  <ul class="plain-list sf-figs">
    <li><strong><?= number_format(array_sum($st['open'])) ?></strong> <span><?= $e($t('safety.fig_open')) ?></span></li>
    <li><strong><?= number_format((int) $st['late_actions']) ?></strong> <span><?= $e($t('safety.fig_late')) ?></span></li>
    <li><strong><?= number_format((int) $st['closed']) ?></strong> <span><?= $e($t('safety.fig_closed')) ?></span></li>
    <li><strong><?= $st['days_to_close'] === null ? '—' : $e(number_format((float) $st['days_to_close'], 1)) ?></strong> <span><?= $e($t('safety.fig_days_to_close')) ?></span></li>
  </ul>
</section>
<section class="card card--flush" aria-labelledby="sf-mon">
  <div class="card__head"><h2 id="sf-mon" class="card__title"><?= $e($t('safety.by_month')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="sf-mon" tabindex="0"><table class="table">
    <thead><tr><th scope="col"><?= $e($t('safety.col.month')) ?></th><?php foreach ($kinds as $k): ?><th scope="col" class="num"><?= $e($t('safety.kind.' . $k)) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($st['months'] as $m => $c): ?><tr><th scope="row"><?= $e((string) $m) ?></th><?php foreach ($kinds as $k): ?><td class="num"><?= (int) $c[$k] ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<div class="grid grid--2">
<section class="card card--flush" aria-labelledby="sf-lev">
  <div class="card__head"><h2 id="sf-lev" class="card__title"><?= $e($t('safety.by_level')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="sf-lev" tabindex="0"><table class="table"><tbody>
    <?php foreach ($st['levels'] as $l => $c): ?><tr><th scope="row"><?= $e($t('safety.level.' . $l)) ?></th><td class="num"><?= (int) $c ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<section class="card card--flush" aria-labelledby="sf-site">
  <div class="card__head"><h2 id="sf-site" class="card__title"><?= $e($t('safety.by_site')) ?></h2></div>
  <?php if ($st['sites'] === []): ?><div class="card__pad text-sm text-muted"><?= $e($t('safety.none')) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="sf-site" tabindex="0"><table class="table"><tbody>
    <?php foreach ($st['sites'] as $s): ?><tr><th scope="row"><?= $e($s['label'] ?? $t('safety.no_site')) ?></th><td class="num"><?= (int) $s['count'] ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>
</div>
<p class="text-sm text-muted"><?= $e($t('safety.stats_note')) ?></p>
