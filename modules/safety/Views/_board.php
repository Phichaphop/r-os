<?php
/** "Days without an accident" board. @var callable $e @var callable $t @var callable $ui @var callable $date @var array{company: ?int, last: ?string, sites: list<array{label: string, days: ?int}>} $board */
?>
<section class="card sf-board" aria-labelledby="sf-board">
  <h2 id="sf-board" class="card__title"><?= $e($t('safety.board_title')) ?></h2>
  <p class="sf-board__days"><?php if ($board['company'] === null): ?><?= $e($t('safety.board_none')) ?><?php else: ?><strong class="sf-board__num"><?= number_format($board['company']) ?></strong> <span><?= $e($t('safety.board_days')) ?></span><?php endif; ?></p>
  <?php if ($board['sites'] !== []): ?>
  <ul class="plain-list sf-board__sites">
    <?php foreach ($board['sites'] as $s): ?><li><span><?= $e($s['label']) ?></span> <strong><?= $s['days'] === null ? $e($t('safety.board_site_none')) : $e($t('safety.days_n', ['n' => number_format($s['days'])])) ?></strong></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <p class="text-sm text-muted"><?= $e($t('safety.board_note')) ?></p>
</section>
